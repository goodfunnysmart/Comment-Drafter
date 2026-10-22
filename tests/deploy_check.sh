#!/bin/bash
# Run: bash tests/deploy_check.sh
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

fail() {
  printf 'FAIL: %s\n' "$1" >&2
  exit 1
}

assert_file() {
  [[ -f "$1" ]] || fail "missing file $1"
}

run_deploy() {
  local home="$1"
  local repo="${2:-}"
  local site_root="${3:-}"
  local code
  set +e
  if [[ -n "$site_root" && -n "$repo" ]]; then
    HOME="$home" CPANEL_DEPLOY_REPO="$repo" SITE_ROOT="$site_root" /bin/bash "$ROOT/deploy/cpanel-deploy.sh" >"$WORK/deploy.out" 2>"$WORK/deploy.err"
  elif [[ -n "$site_root" ]]; then
    env -u CPANEL_DEPLOY_REPO HOME="$home" SITE_ROOT="$site_root" /bin/bash "$ROOT/deploy/cpanel-deploy.sh" >"$WORK/deploy.out" 2>"$WORK/deploy.err"
  elif [[ -n "$repo" ]]; then
    env -u SITE_ROOT HOME="$home" CPANEL_DEPLOY_REPO="$repo" /bin/bash "$ROOT/deploy/cpanel-deploy.sh" >"$WORK/deploy.out" 2>"$WORK/deploy.err"
  else
    env -u CPANEL_DEPLOY_REPO -u SITE_ROOT HOME="$home" /bin/bash "$ROOT/deploy/cpanel-deploy.sh" >"$WORK/deploy.out" 2>"$WORK/deploy.err"
  fi
  code=$?
  set -e
  printf '%s' "$code"
}

grep -q '/bin/bash deploy/cpanel-deploy.sh' "$ROOT/.cpanel.yml" || fail ".cpanel.yml does not run the deploy script"
if grep -v -E '^[[:space:]]*#' "$ROOT/.cpanel.yml" | grep -q '\*'; then
  fail ".cpanel.yml uses a wildcard"
fi
if grep -v -E '^[[:space:]]*#' "$ROOT/.cpanel.yml" | grep -E -q '/home/[A-Za-z0-9._-]+'; then
  fail ".cpanel.yml hard-codes a home directory"
fi
if grep -E -q 'rm[[:space:]]+-rf|--delete' "$ROOT/deploy/cpanel-deploy.sh"; then
  fail "deploy script can delete destination trees"
fi
if grep -E -q 'cp[[:space:]].*config\.php' "$ROOT/deploy/cpanel-deploy.sh"; then
  fail "deploy script copies config.php"
fi
git check-ignore -q public/private-path.php || fail "public/private-path.php is not ignored"
git check-ignore -q private/config.php || fail "private/config.php is not ignored"
grep -q 'cp:ppd' "$ROOT/public/.htaccess" && fail "repository .htaccess contains a Directory Privacy marker"

# Empty HOME must fail before any copy.
set +e
HOME="" /bin/bash "$ROOT/deploy/cpanel-deploy.sh" >/dev/null 2>"$WORK/empty-home.err"
code=$?
HOME="/" /bin/bash "$ROOT/deploy/cpanel-deploy.sh" >/dev/null 2>"$WORK/root-home.err"
root_code=$?
set -e
[[ "$code" -ne 0 ]] || fail "empty HOME was accepted"
[[ "$root_code" -ne 0 ]] || fail "HOME=/ was accepted"
grep -q 'HOME' "$WORK/empty-home.err" || fail "empty HOME error was not explained"

HOME_A="$WORK/home-a"
mkdir -p "$HOME_A"
code="$(run_deploy "$HOME_A")"
[[ "$code" == "0" ]] || fail "first deploy failed: $(cat "$WORK/deploy.err")"
PUBLIC="$HOME_A/betterschools.com.au/comments"
PRIVATE="$HOME_A/comments-private"
[[ ! -e "$HOME_A/public_html" ]] || fail "deploy created public_html"
[[ ! -e "$PUBLIC/studio.css" ]] || fail "deploy published studio.css"
for name in index.php generate.php private_path.php app.css app.js dark.css skin.js .htaccess; do
  assert_file "$PUBLIC/$name"
  if [[ "$name" != ".htaccess" ]]; then
    cmp -s "$ROOT/public/$name" "$PUBLIC/$name" || fail "public $name differs"
  fi
done
for name in logo-classic.webp logo-dark.webp; do
  assert_file "$PUBLIC/assets/$name"
  cmp -s "$ROOT/public/assets/$name" "$PUBLIC/assets/$name" || fail "asset $name differs"
  [[ "$(stat -c '%a' "$PUBLIC/assets/$name")" == "644" ]] || fail "asset $name mode is not 644"
  [[ "$(stat -c '%s' "$PUBLIC/assets/$name")" -lt 100000 ]] || fail "asset $name is too large"
done
grep -q 'AddType image/webp .webp' "$PUBLIC/.htaccess" || fail "webp type was not deployed"
for name in bootstrap.php bank.json instructions.txt config.example.php; do
  assert_file "$PRIVATE/$name"
  cmp -s "$ROOT/private/$name" "$PRIVATE/$name" || fail "private $name differs"
done
[[ ! -e "$PRIVATE/config.php" ]] || fail "deploy created config.php"
[[ "$(stat -c '%a' "$PRIVATE")" == "700" ]] || fail "private directory mode is not 700"
[[ "$(stat -c '%a' "$PRIVATE/bank.json")" == "600" ]] || fail "bank.json mode is not 600"
[[ "$(stat -c '%a' "$PRIVATE/instructions.txt")" == "600" ]] || fail "instructions.txt mode is not 600"
[[ "$(stat -c '%a' "$PUBLIC/index.php")" == "644" ]] || fail "index.php mode is not 644"
[[ "$(stat -c '%a' "$PUBLIC/.htaccess")" == "644" ]] || fail ".htaccess mode is not 644"
grep -q 'Options -Indexes' "$PUBLIC/.htaccess" || fail "deployed .htaccess lost the application rules"
grep -q 'cp:ppd' "$PUBLIC/.htaccess" && fail "first deploy invented a Directory Privacy block"
php -l "$PUBLIC/private-path.php" >/dev/null
hint="$(php -r 'echo require $argv[1];' "$PUBLIC/private-path.php")"
[[ "$hint" == "$PRIVATE" ]] || fail "private-path.php returned [$hint]"
grep -q 'config.php is not in the private directory' "$WORK/deploy.out" || fail "missing config.php was not reported"

# Old upload leftovers are reported and kept.
mkdir -p "$PUBLIC/private" "$PUBLIC/public" "$PUBLIC/tests"
printf 'secret bank\n' > "$PUBLIC/private/bank.json"
printf 'old readme\n' > "$PUBLIC/README.md"
printf 'old studio\n' > "$PUBLIC/studio.css"
printf 'old key\n' > "$PRIVATE/config.php"
before_key="$(sha256sum "$PRIVATE/config.php")"
auth_block=$'#----------------------------------------------------------------cp:ppd\n# Section managed by cPanel: Password Protected Directories     -cp:ppd\n# - Do not edit this section of the htaccess file!              -cp:ppd\n#----------------------------------------------------------------cp:ppd\nAuthType Basic\nAuthName "Comments"\nAuthUserFile "/home/example/.htpasswds/betterschools.com.au/comments/passwd"\nRequire valid-user\n#----------------------------------------------------------------cp:ppd\n# End section managed by cPanel: Password Protected Directories -cp:ppd\n#----------------------------------------------------------------cp:ppd'
printf '%s\nHAND_EDIT keep-me-not\n' "$auth_block" > "$PUBLIC/.htaccess"
code="$(run_deploy "$HOME_A")"
[[ "$code" == "0" ]] || fail "second deploy failed: $(cat "$WORK/deploy.err")"
[[ "$(sha256sum "$PRIVATE/config.php")" == "$before_key" ]] || fail "config.php contents changed"
[[ "$(stat -c '%a' "$PRIVATE/config.php")" == "600" ]] || fail "existing config.php was not kept at mode 600"
grep -q 'Left existing config.php in place.' "$WORK/deploy.out" || fail "existing config.php was not reported"
[[ "$(grep -c 'AuthUserFile "/home/example/.htpasswds/betterschools.com.au/comments/passwd"' "$PUBLIC/.htaccess")" == "1" ]] || fail "Directory Privacy block was duplicated or dropped"
grep -q 'Directories     -cp:ppd' "$PUBLIC/.htaccess" || fail "Directory Privacy spacing was not preserved"
grep -q 'End section managed by cPanel: Password Protected Directories -cp:ppd' "$PUBLIC/.htaccess" || fail "Directory Privacy end marker was dropped"
grep -q 'Options -Indexes' "$PUBLIC/.htaccess" || fail "application rules were dropped while merging"
grep -q 'HAND_EDIT' "$PUBLIC/.htaccess" && fail "hand edits outside the Directory Privacy block should be replaced by the repository rules"
[[ "$(head -n 1 "$PUBLIC/.htaccess")" == "Options -Indexes" ]] || fail "application rules are not at the top of .htaccess"
cmp -s "$PUBLIC/private/bank.json" <(printf 'secret bank\n') || fail "old exposed bank.json was deleted or changed"
[[ -d "$PUBLIC/public" && -f "$PUBLIC/README.md" ]] || fail "old upload paths were deleted"
grep -q 'WARNING:' "$WORK/deploy.out" || fail "old upload was not warned about"
[[ ! -e "$PUBLIC/studio.css" ]] || fail "leftover studio.css was not removed"
mkdir "$PUBLIC/studio.css"
code="$(run_deploy "$HOME_A")"
[[ "$code" == "0" ]] || fail "deploy failed while a studio.css directory was present: $(cat "$WORK/deploy.err")"
[[ -d "$PUBLIC/studio.css" ]] || fail "deploy removed a studio.css directory"
rmdir "$PUBLIC/studio.css"
# A second merge must not duplicate the block.
code="$(run_deploy "$HOME_A")"
[[ "$code" == "0" ]] || fail "third deploy failed"
[[ "$(grep -c 'AuthUserFile "/home/example/.htpasswds/betterschools.com.au/comments/passwd"' "$PUBLIC/.htaccess")" == "1" ]] || fail "redeploy duplicated Directory Privacy"
[[ "$(sha256sum "$PRIVATE/config.php")" == "$before_key" ]] || fail "config.php changed on the third deploy"

# Authentication that cPanel did not mark must stop the deploy without a rewrite.
plain_auth=$'AuthType Basic\nAuthName "Comments"\nAuthUserFile "/home/example/.htpasswds/betterschools.com.au/comments/passwd"\nRequire valid-user\n'
printf '%s' "$plain_auth" > "$PUBLIC/.htaccess"
plain_hash="$(sha256sum "$PUBLIC/.htaccess")"
set +e
env -u CPANEL_DEPLOY_REPO -u SITE_ROOT HOME="$HOME_A" /bin/bash "$ROOT/deploy/cpanel-deploy.sh" >"$WORK/plain.out" 2>"$WORK/plain.err"
plain_code=$?
set -e
[[ "$plain_code" -ne 0 ]] || fail "unmarked authentication was overwritten"
[[ "$(sha256sum "$PUBLIC/.htaccess")" == "$plain_hash" ]] || fail "unmarked .htaccess changed"
[[ "$(sha256sum "$PRIVATE/config.php")" == "$before_key" ]] || fail "failed deploy changed config.php"
grep -q 'outside a cPanel cp:ppd block' "$WORK/plain.err" || fail "unmarked authentication error was not explained"

# A broken cp:ppd block must also leave the file untouched.
printf '%s\n' '#----------------------------------------------------------------cp:ppd' '# Section managed by cPanel: Password Protected Directories     -cp:ppd' > "$PUBLIC/.htaccess"
broken_hash="$(sha256sum "$PUBLIC/.htaccess")"
set +e
env -u CPANEL_DEPLOY_REPO -u SITE_ROOT HOME="$HOME_A" /bin/bash "$ROOT/deploy/cpanel-deploy.sh" >"$WORK/broken.out" 2>"$WORK/broken.err"
broken_code=$?
set -e
[[ "$broken_code" -ne 0 ]] || fail "incomplete Directory Privacy block was accepted"
[[ "$(sha256sum "$PUBLIC/.htaccess")" == "$broken_hash" ]] || fail "incomplete .htaccess changed"
grep -q 'incomplete' "$WORK/broken.err" || fail "incomplete block error was not explained"

# A config.php that exists only in a checkout must not be copied onto the server.
FIXTURE="$WORK/fixture"
mkdir -p "$FIXTURE"
cp -a "$ROOT/public" "$ROOT/private" "$FIXTURE/"
printf 'SOURCE_SENTINEL\n' > "$FIXTURE/private/config.php"
HOME_B="$WORK/home-b"
mkdir -p "$HOME_B/comments-private"
printf 'DEST_SENTINEL\n' > "$HOME_B/comments-private/config.php"
dest_hash="$(sha256sum "$HOME_B/comments-private/config.php")"
code="$(run_deploy "$HOME_B" "$FIXTURE")"
[[ "$code" == "0" ]] || fail "fixture deploy failed: $(cat "$WORK/deploy.err" "$WORK/deploy.out")"
[[ "$(sha256sum "$HOME_B/comments-private/config.php")" == "$dest_hash" ]] || fail "source config.php replaced the server file"
grep -q 'SOURCE_SENTINEL' "$HOME_B/comments-private/config.php" && fail "source sentinel was copied"
grep -q 'SOURCE_SENTINEL' "$HOME_B/betterschools.com.au/comments/.htaccess" && fail "source sentinel leaked into .htaccess"
[[ ! -f "$HOME_B/betterschools.com.au/comments/config.php" ]] || fail "config.php was published"
[[ ! -e "$HOME_B/public_html" ]] || fail "fixture deploy created public_html"

# Privacy-only .htaccess (Directory Privacy enabled before the first deploy) is kept.
HOME_C="$WORK/home-c"
mkdir -p "$HOME_C/betterschools.com.au/comments"
printf '%s\n' "$auth_block" > "$HOME_C/betterschools.com.au/comments/.htaccess"
code="$(run_deploy "$HOME_C")"
[[ "$code" == "0" ]] || fail "privacy-first deploy failed: $(cat "$WORK/deploy.err")"
[[ "$(head -n 1 "$HOME_C/betterschools.com.au/comments/.htaccess")" == "Options -Indexes" ]] || fail "privacy-first deploy lost application rules"
[[ "$(grep -c 'Require valid-user' "$HOME_C/betterschools.com.au/comments/.htaccess")" == "1" ]] || fail "privacy-first deploy lost Require valid-user"

# An explicit document root still keeps private files beside $HOME.
HOME_D="$WORK/home-d"
mkdir -p "$HOME_D"
code="$(run_deploy "$HOME_D" "" "$HOME_D/custom-root")"
[[ "$code" == "0" ]] || fail "SITE_ROOT override failed: $(cat "$WORK/deploy.err")"
assert_file "$HOME_D/custom-root/comments/index.php"
assert_file "$HOME_D/comments-private/bootstrap.php"
[[ ! -e "$HOME_D/betterschools.com.au" ]] || fail "SITE_ROOT override also created betterschools.com.au"
[[ ! -e "$HOME_D/public_html" ]] || fail "SITE_ROOT override created public_html"
hint="$(php -r 'echo require $argv[1];' "$HOME_D/custom-root/comments/private-path.php")"
[[ "$hint" == "$HOME_D/comments-private" ]] || fail "override private-path.php returned [$hint]"

# The mistaken public_html target must fail before any site file is written.
HOME_E="$WORK/home-e"
mkdir -p "$HOME_E"
code="$(run_deploy "$HOME_E" "" "$HOME_E/public_html")"
[[ "$code" != "0" ]] || fail "SITE_ROOT inside public_html was accepted"
[[ ! -e "$HOME_E/public_html" ]] || fail "refused public_html SITE_ROOT created a directory"
grep -q 'public_html' "$WORK/deploy.err" || fail "public_html SITE_ROOT error was not explained"

# A clone inside the domain document root must fail before writing the site.
HOME_F="$WORK/home-f"
mkdir -p "$HOME_F" "$WORK/betterschools.com.au/repo"
code="$(run_deploy "$HOME_F" "$WORK/betterschools.com.au/repo")"
[[ "$code" != "0" ]] || fail "clone inside betterschools.com.au was accepted"
[[ ! -f "$HOME_F/betterschools.com.au/comments/index.php" ]] || fail "clone inside betterschools.com.au wrote site files"
grep -q 'document root' "$WORK/deploy.err" || fail "document-root clone error was not explained"

# Direct HTTP access to the generated hint must not reveal the path.
php -S 127.0.0.1:8766 -t "$PUBLIC" >"$WORK/php-hint.log" 2>&1 &
hint_pid=$!
cleanup_server() {
  kill "$hint_pid" >/dev/null 2>&1 || true
  wait "$hint_pid" 2>/dev/null || true
}
trap 'cleanup_server; rm -rf "$WORK"' EXIT
ready=0
for _ in 1 2 3 4 5 6 7 8 9 10; do
  if php -r 'exit(@fsockopen("127.0.0.1", 8766) ? 0 : 1);'; then
    ready=1
    break
  fi
  sleep 0.1
done
[[ "$ready" == "1" ]] || fail "hint server did not start"
body="$(php -r 'echo @file_get_contents("http://127.0.0.1:8766/private-path.php");')"
headers="$(php -r '$h=get_headers("http://127.0.0.1:8766/private-path.php"); echo implode("\n", $h);')"
printf '%s\n' "$headers" | head -n 1 | grep -q '404' || fail "private-path.php was not 404: $headers"
[[ "$body" != *comments-private* && "$body" != *"$PRIVATE"* ]] || fail "private-path.php revealed its path: $body"
cleanup_server
trap 'rm -rf "$WORK"' EXIT

printf 'PASS: cPanel deploy paths, config.php preservation, and Directory Privacy merge.\n'
