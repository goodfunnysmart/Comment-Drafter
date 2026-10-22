# Comment Drafter

Report Comment Builder is a complete single-page teacher workspace built with PHP, HTML, CSS and vanilla JavaScript. No build step, framework, database, analytics or browser storage. The selectable points live in one private JSON bank, grouped into five categories. PHP renders the bank and resolves submitted IDs against it before calling Gemini.

## Project files

```text
Comment Drafter/
├── .cpanel.yml                   ← cPanel "Deploy HEAD Commit" tasks
├── deploy/
│   └── cpanel-deploy.sh          # Copies site files; keeps config.php and Directory Privacy
├── public/                       ← Deployed to $HOME/greache.com/comments
│   ├── index.php                 # Page, bank rendering, CSRF token
│   ├── generate.php              # Validated server-side Gemini endpoint
│   ├── private_path.php          # Finds the private bootstrap outside the document root
│   ├── assets/
│   │   ├── logo-classic.webp     # Better Schools logo for Classic
│   │   └── logo-dark.webp        # Reversed logo for Dark
│   ├── app.css                   # Classic layout, including the appearance switch
│   ├── app.js
│   ├── dark.css                  # Dark palette on the Classic layout
│   ├── skin.js                   # Classic / Dark switch
│   └── .htaccess                 # Merged with cPanel's auth block; never copied over it
├── private/                      ← Deployed to $HOME/comments-private
│   ├── bootstrap.php             # Session, headers, validation
│   ├── bank.json                 # Single source of truth, stable IDs
│   ├── instructions.txt          # Server-owned generation rules
│   └── config.example.php        # Placeholders; the real config.php stays server-only
├── tests/
│   ├── validate.php
│   ├── private_path.php
│   ├── deploy_check.sh
│   ├── http_check.py
│   └── client.mjs
├── .gitignore
├── .gitattributes
├── .editorconfig
├── SKINS.md
├── README.md
└── VERIFICATION.md
```

## Requirements

- PHP 8.2 or newer, with sessions, JSON and **cURL** enabled.
- A working system CA certificate bundle and outbound HTTPS access to `generativelanguage.googleapis.com:443`. On Windows, the app uses the operating system’s trusted certificate store when supported by cURL. On other hosts, configure PHP’s CA bundle. Never disable certificate verification to fix a CA error.
- HTTPS and hosting-level authentication covering the entire public directory.
- An API key with access and quota for the configured Gemini model.

## Deploy on cPanel

cPanel clones this private repository **outside** the website. **Deploy HEAD Commit** runs `.cpanel.yml`, which runs `deploy/cpanel-deploy.sh`. The script uses `$HOME`, so the account name is not stored in Git. The document root for greache.com is `$HOME/greache.com`, not `$HOME/public_html`. The script copies the files in `public/`, including the logo files in `public/assets/`, into `$HOME/greache.com/comments` and the private application files into `$HOME/comments-private`. `SITE_ROOT` at the top of `deploy/cpanel-deploy.sh` defaults to `$HOME/greache.com`; export `SITE_ROOT` to use another directory inside `$HOME`. Site files then go to `$SITE_ROOT/comments`. The live address is `https://greache.com/comments`, with no `/public` on the end.

Do not upload the project into `greache.com` or `public_html`. Do not point the domain’s document root at the clone or at `comments-private`.

The deploy copies named application files only. It does not create, copy, or delete `config.php`. It does not delete Directory Privacy rules in `greache.com/comments/.htaccess`. cPanel keeps those rules in a block marked `cp:ppd`. Each deploy writes `public/.htaccess` and then puts that block back unchanged. Lines outside the block are replaced by the copy in Git. If deploy finds authentication directives that are not inside a `cp:ppd` block, it stops and leaves `.htaccess` as it is.

`index.php` and `generate.php` load the private bootstrap only after this search:

1. `COMMENT_DRAFTER_PRIVATE`, when that environment variable names a directory outside the document root.
2. `private-path.php`, which deploy writes next to `index.php`. Opening that address in a browser does not print the path.
3. A sibling `private/` directory. This is what `php -S 127.0.0.1:8765 -t public` uses.
4. `$HOME/comments-private`, when the site files are in `$HOME/greache.com/comments`.

A result inside the front-controller directory, or anywhere under a `public_html` or `greache.com` directory, is ignored. The loaded bootstrap reads `bank.json`, `instructions.txt` and `config.php` from its own directory.

### One-time cPanel setup

Use the cPanel account that serves `greache.com`. File Manager shows the home directory as `/home/<account>/`. Nothing below puts that account name into Git.

**1. Password-protect the live comments directory**

The live document root is `$HOME/greache.com`. The site directory is `$HOME/greache.com/comments`. An earlier upload can leave `private/bank.json`, `private/instructions.txt` and `README.md` downloadable there. `private/config.php` runs as PHP. An earlier deploy also created `$HOME/public_html/comments`; that path is not the live site.

1. Open **cPanel → Files → Directory Privacy**.
2. Open `greache.com`, then `comments`, then **Edit**.
3. Select **Password protect this directory**. Enter a name such as `Comments`. Click **Save**.
4. Create a username and a strong password. cPanel stores the password file under `.htpasswds`, outside the document root.
5. In a private browser window, open `https://greache.com/comments/private/bank.json`. It must ask for a password or return **401** before you continue.

**2. Give cPanel read access to the private GitHub repository**

Clone URL: `git@github.com:goodfunnysmart/Comment-Drafter.git`

SSH deploy key (read-only) is the method that **Update from Remote** can keep using. **cPanel → Advanced → Terminal** is required.

```bash
mkdir -p ~/.ssh
chmod 700 ~/.ssh
ssh-keygen -t ed25519 -f ~/.ssh/comment-drafter -C "comments-deploy"
```

Press Enter for no passphrase. A passphrase stops unattended pulls. If `ed25519` is rejected, use `ssh-keygen -t rsa -b 4096 -f ~/.ssh/comment-drafter -C "comments-deploy"` instead.

If `~/.ssh/config` does not exist, create it with the block below. If it already has a `Host github.com` entry, add the `IdentityFile` and `IdentitiesOnly` lines to that entry instead of adding a second one.

```text
Host github.com
    HostName github.com
    User git
    IdentityFile ~/.ssh/comment-drafter
    IdentitiesOnly yes
```

```bash
chmod 600 ~/.ssh/config ~/.ssh/comment-drafter
cat ~/.ssh/comment-drafter.pub
```

On GitHub, open the private repository **goodfunnysmart/Comment-Drafter → Settings → Deploy keys → Add deploy key**. Paste the public key. Leave **Allow write access** unchecked.

Test the key:

```bash
ssh -o IdentitiesOnly=yes -i ~/.ssh/comment-drafter -T git@github.com
```

The first connection shows GitHub’s host-key fingerprint. Accept it only when the SHA-256 value is one of these, which GitHub publishes for `github.com`:

- Ed25519 `SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU`
- ECDSA `SHA256:p2QAMXNIC1TJYWeIOttrVc98/R1BUFWu3/LiyKgUfQM`
- RSA `SHA256:uNiVztksCsDhcc0u9e8BujQXVUpKZIDTMczCvj3tD2s`

GitHub then says the key authenticated successfully and does not provide shell access. `Permission denied` means the deploy key or `IdentityFile` is not in use.

HTTPS alternative: on GitHub, create a fine-grained personal access token for only this repository with **Contents: Read-only**. In Terminal:

```bash
mkdir -p "$HOME/repositories"
git clone https://github.com/goodfunnysmart/Comment-Drafter.git "$HOME/repositories/Comment-Drafter"
```

When Git asks, the username is the GitHub user and the password is the token. Do not put the token in the URL, in `.cpanel.yml`, or in the document root. Then in **Git Version Control → Create**, turn **Clone a Repository** off, set Repository Path to `repositories/Comment-Drafter`, name it `Comment Drafter`, and click **Create**. Skip step 3.

**3. Clone outside the website**

1. Open **cPanel → Files → Git Version Control → Create**.
2. Leave **Clone a Repository** on.
3. Clone URL: `git@github.com:goodfunnysmart/Comment-Drafter.git`. If cPanel asks to save GitHub’s host key, accept it only when the SHA-256 fingerprint matches one of the values above.
4. Repository Path: `repositories/Comment-Drafter`. cPanel creates `/home/<account>/repositories/Comment-Drafter`. Do not use any path inside `greache.com` or `public_html`.
5. Repository Name: `Comment Drafter`.
6. Click **Create** and wait until the clone finishes.

**4. First deploy**

1. **Git Version Control → Manage** for Comment Drafter.
2. On **Basic Information**, set **Checked-Out Branch** to `main` and click **Update** if another branch is selected.
3. Open **Pull or Deploy**.
4. Click **Update from Remote**, then **Deploy HEAD Commit**.

Deployment must succeed, and **Last Deployed SHA** must match `main` on GitHub. This creates:

```text
/home/<account>/greache.com/comments/     site files only
/home/<account>/comments-private/         bootstrap, bank, instructions, example config
```

`comments-private/config.php` is not created yet. In **cPanel → Software → Select PHP Version** (or MultiPHP), use PHP 8.2 or newer and enable cURL for this domain.

**5. Move the existing key**

1. Open **File Manager** and confirm `comments-private/bootstrap.php`, `bank.json` and `instructions.txt` exist, and that this folder is not inside `greache.com` or `public_html`.
2. **Move** the real `config.php` to `comments-private/config.php`. Check `greache.com/comments/private/config.php` and, if the earlier deploy left one, `public_html/comments/private/config.php`. Move the one real key. Do not leave a second copy, and do not replace it with `config.example.php`.
3. Confirm those old paths are gone and `comments-private/config.php` is present.
4. **Deploy HEAD Commit** again. That sets private-file permissions and rewrites `.htaccess` while keeping the Directory Privacy block. It does not change `config.php`.

**6. Delete the old upload**

In File Manager, open `greache.com/comments`. Delete uploaded project files that are not the site. Delete these if they are present:

- `private/`
- `public/`
- `README.md`
- `tests/`
- `SKINS.md`
- `VERIFICATION.md`
- `.git/`
- `.gitignore`, `.gitattributes`, `.editorconfig`

Keep `.htaccess`, `index.php`, `generate.php`, `private_path.php`, `private-path.php`, `app.css`, `app.js`, `dark.css`, `skin.js`, `assets/logo-classic.webp` and `assets/logo-dark.webp`. Deploy removes a leftover `studio.css` file. Do not delete `comments-private` or `repositories/Comment-Drafter`. If a site file is removed by mistake, click **Deploy HEAD Commit** again. Do not copy `.htaccess` from Git over the server file by hand.

`$HOME/public_html/comments` is not the live site. After `config.php` exists only in `comments-private`, delete `$HOME/public_html/comments` if that tree is still there.

**7. Check the lock and the private files**

Run these while signed out. Every `https://greache.com/comments` URL must print `401`. The two `comments-private` URLs must print `404`. None of them may print `200`.

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://greache.com/comments
curl -s -o /dev/null -w '%{http_code}\n' https://greache.com/comments/generate.php
curl -s -o /dev/null -w '%{http_code}\n' https://greache.com/comments/app.js
curl -s -o /dev/null -w '%{http_code}\n' https://greache.com/comments/private/bank.json
curl -s -o /dev/null -w '%{http_code}\n' https://greache.com/comments/private/instructions.txt
curl -s -o /dev/null -w '%{http_code}\n' https://greache.com/comments/private/config.php
curl -s -o /dev/null -w '%{http_code}\n' https://greache.com/comments/README.md
curl -s -o /dev/null -w '%{http_code}\n' https://greache.com/comments/public/
curl -s -o /dev/null -w '%{http_code}\n' https://greache.com/comments-private/bank.json
curl -s -o /dev/null -w '%{http_code}\n' https://greache.com/comments-private/config.php
```

Then sign in with the Directory Privacy user, open `https://greache.com/comments`, and generate a comment with a made-up student. That confirms PHP is reading the moved key.

### Later changes

Merge the change into GitHub `main`. In cPanel, open **Git Version Control → Manage → Pull or Deploy**, click **Update from Remote**, then **Deploy HEAD Commit**. Do not move `config.php` again. In a private window, `https://greache.com/comments` must still return **401**.

## Private Gemini configuration

Preferred: have the host configure **server environment variables** for the app’s PHP process:

```text
GEMINI_API_KEY=your-private-key
GEMINI_MODEL=gemini-3.5-flash-lite
```

The application never sends these settings to the browser. Environment variables take precedence over private configuration.

If the host cannot supply environment variables, copy `private/config.example.php` to `config.php` in the same private directory as `bootstrap.php`, outside the document root, and edit that private copy. On the server that directory is `$HOME/comments-private/`. Deploy never creates, copies, or deletes `config.php`. Locally it is `private/config.php`, which Git ignores.

```php
<?php
return [
    'GEMINI_API_KEY' => 'YOUR_PRIVATE_KEY',
    'GEMINI_MODEL' => 'gemini-3.5-flash-lite',
];
```

The example in this project contains placeholders only. `config.php` is excluded from Git. Do not put real keys into the example file or paste them into this chat. If configuration is missing, the app returns a clear configuration error and retains any draft.

The exact ID **`gemini-3.5-flash-lite`** was verified against [Google’s model documentation](https://ai.google.dev/gemini-api/docs/models/gemini-3.5-flash-lite) on 22 October 2026. Account access and quota must still be confirmed with your key. No substitute model is selected automatically. Change only `GEMINI_MODEL` to change models.

The server follows Google’s [generateContent REST reference](https://ai.google.dev/api/generate-content) using an HTTPS POST and `x-goog-api-key` header. [Google’s migration documentation](https://ai.google.dev/gemini-api/docs/generate-content/whats-new-gemini-3.6) says to omit deprecated sampling parameters for this model; the implementation omits them. It sends server-owned `systemInstruction` plus descriptor-resolved source material. Requests have a 10-second connection timeout and 45-second total timeout. A general comment is 5–7 sentences. The length control chooses 5, 6 or 7. A draft outside that range is not kept. Evidence that is too thin is not padded with invented detail.

## Required hosting-level authentication

CSRF tokens and same-origin checks **are not authentication**. Do not deploy an anonymously accessible paid-generation endpoint.

For this site, Directory Privacy on `greache.com/comments` is step 1 of the setup above. It covers the page, `generate.php`, static files, and any subdirectory still left from the old upload. The password file stays in `.htpasswds`. Redeploys keep the `cp:ppd` block. Do not replace that `.htaccess` with a raw copy from Git. If `Options -Indexes` causes a 500 error, ask the provider to turn directory listing off, remove that one line from `public/.htaccess` in Git, and deploy again. Do not remove the `cp:ppd` block.

Without the Directory Privacy password, `https://greache.com/comments` and `https://greache.com/comments/generate.php` must return **401**, including a direct POST, before PHP runs. Use HTTPS. HTTP Basic authentication needs TLS. Then complete the manual checks in `VERIFICATION.md`.

The app’s basic per-session throttle allows at most 10 validated attempts per 10 minutes, at least five seconds apart. A new session can reset it; it is not an abuse-proof account/IP quota. Use hosting/WAF rate limits and Google project quotas for stronger cost control. PHP sessions contain only CSRF and throttle metadata, never student records.

## Running locally

From the project directory:

```powershell
php -S 127.0.0.1:8765 -t public
```

Visit `http://127.0.0.1:8765`. The development server uses the sibling `private/` directory and does not need `private-path.php`. It is for local testing only; bind it to loopback. Cookies are HttpOnly and SameSite=Strict, with Secure enabled when PHP receives HTTPS. Do not use the development server as public hosting. Without a configured key/model, generation reports that it is not configured; manual editing, copying and downloading still work.

## Data handling and behaviour

- Student details and drafts exist in the page’s memory only. There is no database, localStorage, sessionStorage, autosave, analytics or third-party frontend resource. The page warns before leaving with entered data and reloads if restored from the back/forward cache.
- When Generate is clicked, the name, pronouns, subject, selected descriptions, observations, any negative behaviours to reframe, and any optional activities go through the PHP server to Google. Google’s policies and your school’s agreement determine retention; this app makes no no-retention claim. Use only school-approved information and follow your school’s AI/data policy.
- Server code does not log names, observations, drafts, keys or upstream bodies. Keep hosting/PHP debug modes and request-body capture disabled; check any host-level logging or monitoring independently. Normal URL access logs contain no form data because generation uses POST JSON, never query parameters.
- Requests/pages use `Cache-Control: no-store`; shared caches should bypass this application. Copy and download use the editor’s current value without another request. Downloads use a UTF-8 Blob and the teacher’s local time.
- Descriptions appear on hover/focus; separate information buttons support touch without changing selection. Escape dismisses descriptions. Selected buttons expose `aria-pressed`, ticks and visible counts.
- Possible contradictions display a non-blocking notice. The server prompt forbids invented timelines and unsupported factual claims. Teachers must review every AI draft; prompting cannot guarantee factual correctness or resist every malicious instruction.
- New student confirms discarding data, aborts the request, increments a request version and returns focus to the name. Even if the server finishes an aborted request, its response cannot overwrite a new student’s editor. An aborted request may still consume provider quota.
- Duplicate generation is disabled while loading. The editor is temporarily read-only during generation to prevent an arriving response replacing newly typed edits; inputs remain adjustable and trigger a stale-input notice if the returned draft used earlier details. Failed generation preserves the existing draft.

## Automated checks

```powershell
php tests/validate.php
php tests/private_path.php
bash tests/deploy_check.sh
# Start the local PHP server in another terminal first. No key/config should be set for this test:
python tests/http_check.py http://127.0.0.1:8765
# Optional Node.js runtime for client logic regression checks:
node tests/client.mjs
```

The HTTP test expects the development setup to be unconfigured and deliberately sends invalid requests; do not run it against a live configured provider. Client tests use a fake DOM and synthetic API responses, not live generation. See `VERIFICATION.md` for completed checks and remaining browser/live-provider verification.

## Repository and local files

The repository is named **Comment Drafter** and is intended to be private. GitHub will usually use `Comment-Drafter` as its URL name. No license or public release is implied.

`private/` contains server-only source files that belong in Git, including the placeholder `config.example.php`. Your real **`private/config.php` is ignored** and must never be committed, even to a private repository. Other `private/config*.php` copies are ignored too, with the example as the only exception. Keep production credentials outside the hosting document root.

Old distribution ZIPs and pre-change backups are preserved under `.local/archive/` and excluded from Git. New ZIPs, logs, editor state, OS metadata and Python caches are also excluded. There is no build step or dependency installation.

Use `git status` to review source changes and `git check-ignore private/config.php` to confirm the key file is excluded. Avoid force-adding ignored configuration files. Before any push, review the staged file list. Create the GitHub repository with **Private** visibility; local Git initialisation does not create a GitHub repository or set remote visibility.

The interface supports Classic and Dark appearances without reloading or discarding the current draft. Classic is the default. Read [SKINS.md](SKINS.md) for styling and description controls. Deploy with the cPanel steps above. Do not upload `private/`, tests, or this README into `greache.com` or `public_html`.
