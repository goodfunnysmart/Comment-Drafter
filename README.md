# Comment Drafter

Report Comment Builder is a complete single-page teacher workspace built with PHP, HTML, CSS and vanilla JavaScript. No build step, framework, database or analytics. The only browser storage is the active session code. The selectable points live in one private JSON bank, grouped into six categories. Positives, challenges and obstacles, and items for development sit in three columns under each category, except Specific Adjustments / Supports, which stays one list. PHP renders the bank and resolves submitted IDs against it before calling Gemini. A tweak sends the current comment and a short change request through the same endpoint.

## Project files

```text
Comment Drafter/
├── .cpanel.yml                   ← cPanel "Deploy HEAD Commit" tasks
├── deploy/
│   └── cpanel-deploy.sh          # Copies site files; keeps config.php and Directory Privacy
├── public/                       ← Deployed to $HOME/betterschools.com.au/comments
│   ├── index.php                 # Page, bank rendering, CSRF token
│   ├── generate.php              # Validated server-side Gemini endpoint
│   ├── transcribe.php            # In-memory dictation; audio is not stored
│   ├── session.php               # Session create, resume, save, email and download
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
│   ├── sessions.php              # Encrypted session files, outside the website
│   ├── cleanup-sessions.php      # Monthly removal of expired sessions
│   └── config.example.php        # Placeholders; the real config.php stays server-only
├── tests/
│   ├── validate.php
│   ├── session.php
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

- PHP 8.2 or newer, with sessions, JSON, OpenSSL and **cURL** enabled.
- A working system CA certificate bundle and outbound HTTPS access to `generativelanguage.googleapis.com:443`. On Windows, the app uses the operating system’s trusted certificate store when supported by cURL. On other hosts, configure PHP’s CA bundle. Never disable certificate verification to fix a CA error.
- HTTPS and hosting-level authentication covering the entire public directory.
- An API key with access and quota for the configured Gemini model.

## Deploy on cPanel

cPanel clones this private repository **outside** the website. **Deploy HEAD Commit** runs `.cpanel.yml`, which runs `deploy/cpanel-deploy.sh`. The script uses `$HOME`, so the account name is not stored in Git. The document root for betterschools.com.au is `$HOME/betterschools.com.au`, not `$HOME/public_html`. The script copies the files in `public/`, including the logo files in `public/assets/`, into `$HOME/betterschools.com.au/comments` and the private application files into `$HOME/comments-private`. `SITE_ROOT` at the top of `deploy/cpanel-deploy.sh` defaults to `$HOME/betterschools.com.au`; export `SITE_ROOT` to use another directory inside `$HOME`. Site files then go to `$SITE_ROOT/comments`. The live address is `https://betterschools.com.au/comments`, with no `/public` on the end.

Do not upload the project into `betterschools.com.au` or `public_html`. Do not point the domain’s document root at the clone or at `comments-private`.

The deploy copies named application files only. It does not create, copy, or delete `config.php`. It does not delete Directory Privacy rules in `betterschools.com.au/comments/.htaccess`. cPanel keeps those rules in a block marked `cp:ppd`. Each deploy writes `public/.htaccess` and then puts that block back unchanged. Lines outside the block are replaced by the copy in Git. If deploy finds authentication directives that are not inside a `cp:ppd` block, it stops and leaves `.htaccess` as it is.

`index.php` and `generate.php` load the private bootstrap only after this search:

1. `COMMENT_DRAFTER_PRIVATE`, when that environment variable names a directory outside the document root.
2. `private-path.php`, which deploy writes next to `index.php`. Opening that address in a browser does not print the path.
3. A sibling `private/` directory. This is what `php -S 127.0.0.1:8765 -t public` uses.
4. `$HOME/comments-private`, when the site files are in `$HOME/betterschools.com.au/comments`.

A result inside the front-controller directory, or anywhere under a `public_html` or `betterschools.com.au` directory, is ignored. The loaded bootstrap reads `bank.json`, `instructions.txt` and `config.php` from its own directory.

### One-time cPanel setup

Use the cPanel account that serves `betterschools.com.au`. File Manager shows the home directory as `/home/<account>/`. Nothing below puts that account name into Git.

**1. Password-protect the live comments directory**

The live document root is `$HOME/betterschools.com.au`. The site directory is `$HOME/betterschools.com.au/comments`. An earlier upload can leave `private/bank.json`, `private/instructions.txt` and `README.md` downloadable there. `private/config.php` runs as PHP. An earlier deploy also created `$HOME/public_html/comments`; that path is not the live site.

1. Open **cPanel → Files → Directory Privacy**.
2. Open `betterschools.com.au`, then `comments`, then **Edit**.
3. Select **Password protect this directory**. Enter a name such as `Comments`. Click **Save**.
4. Create a username and a strong password. cPanel stores the password file under `.htpasswds`, outside the document root.
5. In a private browser window, open `https://betterschools.com.au/comments/private/bank.json`. It must ask for a password or return **401** before you continue.

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
4. Repository Path: `repositories/Comment-Drafter`. cPanel creates `/home/<account>/repositories/Comment-Drafter`. Do not use any path inside `betterschools.com.au` or `public_html`.
5. Repository Name: `Comment Drafter`.
6. Click **Create** and wait until the clone finishes.

**4. First deploy**

1. **Git Version Control → Manage** for Comment Drafter.
2. On **Basic Information**, set **Checked-Out Branch** to `main` and click **Update** if another branch is selected.
3. Open **Pull or Deploy**.
4. Click **Update from Remote**, then **Deploy HEAD Commit**.

Deployment must succeed, and **Last Deployed SHA** must match `main` on GitHub. This creates:

```text
/home/<account>/betterschools.com.au/comments/     site files only
/home/<account>/comments-private/         bootstrap, bank, instructions, example config
/home/<account>/comments-private/sessions/  encrypted session files, mode 0700
```

`comments-private/config.php` is not created yet. In **cPanel → Software → Select PHP Version** (or MultiPHP), use PHP 8.2 or newer and enable cURL for this domain.

**5. Move the existing key**

1. Open **File Manager** and confirm `comments-private/bootstrap.php`, `bank.json` and `instructions.txt` exist, and that this folder is not inside `betterschools.com.au` or `public_html`.
2. **Move** the real `config.php` to `comments-private/config.php`. Check `betterschools.com.au/comments/private/config.php` and, if the earlier deploy left one, `public_html/comments/private/config.php`. Move the one real key. Do not leave a second copy, and do not replace it with `config.example.php`.
3. Confirm those old paths are gone and `comments-private/config.php` is present.
4. **Deploy HEAD Commit** again. That sets private-file permissions and rewrites `.htaccess` while keeping the Directory Privacy block. It does not change `config.php`.

**6. Delete the old upload**

In File Manager, open `betterschools.com.au/comments`. Delete uploaded project files that are not the site. Delete these if they are present:

- `private/`
- `public/`
- `README.md`
- `tests/`
- `SKINS.md`
- `VERIFICATION.md`
- `.git/`
- `.gitignore`, `.gitattributes`, `.editorconfig`

Keep `.htaccess`, `index.php`, `generate.php`, `transcribe.php`, `private_path.php`, `private-path.php`, `app.css`, `app.js`, `dark.css`, `skin.js`, `assets/logo-classic.webp` and `assets/logo-dark.webp`. Deploy removes a leftover `studio.css` file. Do not delete `comments-private` or `repositories/Comment-Drafter`. If a site file is removed by mistake, click **Deploy HEAD Commit** again. Do not copy `.htaccess` from Git over the server file by hand.

`$HOME/public_html/comments` is not the live site. After `config.php` exists only in `comments-private`, delete `$HOME/public_html/comments` if that tree is still there.

**7. Check the lock and the private files**

Run these while signed out. Every `https://betterschools.com.au/comments` URL must print `401`. The two `comments-private` URLs must print `404`. None of them may print `200`.

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://betterschools.com.au/comments
curl -s -o /dev/null -w '%{http_code}\n' https://betterschools.com.au/comments/generate.php
curl -s -o /dev/null -w '%{http_code}\n' https://betterschools.com.au/comments/app.js
curl -s -o /dev/null -w '%{http_code}\n' https://betterschools.com.au/comments/private/bank.json
curl -s -o /dev/null -w '%{http_code}\n' https://betterschools.com.au/comments/private/instructions.txt
curl -s -o /dev/null -w '%{http_code}\n' https://betterschools.com.au/comments/private/config.php
curl -s -o /dev/null -w '%{http_code}\n' https://betterschools.com.au/comments/README.md
curl -s -o /dev/null -w '%{http_code}\n' https://betterschools.com.au/comments/public/
curl -s -o /dev/null -w '%{http_code}\n' https://betterschools.com.au/comments-private/bank.json
curl -s -o /dev/null -w '%{http_code}\n' https://betterschools.com.au/comments-private/config.php
```

Then sign in with the Directory Privacy user, open `https://betterschools.com.au/comments`, and generate a comment with a made-up student. That confirms PHP is reading the moved key.

### Later changes

Merge the change into GitHub `main`. In cPanel, open **Git Version Control → Manage → Pull or Deploy**, click **Update from Remote**, then **Deploy HEAD Commit**. Do not move `config.php` again. In a private window, `https://betterschools.com.au/comments` must still return **401**.

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

The server follows Google’s [generateContent REST reference](https://ai.google.dev/api/generate-content) using an HTTPS POST and `x-goog-api-key` header. [Google’s migration documentation](https://ai.google.dev/gemini-api/docs/generate-content/whats-new-gemini-3.6) says to omit deprecated sampling parameters for this model; the implementation omits them. It sends server-owned `systemInstruction` plus descriptor-resolved source material. Requests have a 10-second connection timeout and 45-second total timeout. A general comment uses the supplied first name exactly three times. The server normalises that name’s capitalisation and replaces any other casing in the draft. The length control targets approximately 60–90, 90–120, 120–150 or 150–200 words when the evidence can support it. Sparse evidence should produce a shorter comment rather than invented detail. The request tells the model to write only from the selected descriptors, observations, activities note and negative behaviours actually supplied. Selected Specific Adjustments / Supports, plus any other adjustment the teacher types, are named by the server in one exact sentence: “Throughout the semester, strategies implemented to support [Name] included …”. The model is told not to write that sentence. Any copy it writes is removed, and the server inserts the exact sentence before the closing sentence. That use of the name counts toward the three. Selected challenges are addressed constructively, as something being worked on, between positive statements. The comment is returned either way. One automatic retry is attempted only when the name count is wrong, and only when it fits inside the same throttle slot and the browser timeout. Sentence count is not a reason to reject a draft. Temperature is not sent: this model ignores it, and later Gemini models reject it. Tweak comment uses the same endpoint, with `mode` set to `tweak`, and asks for the smallest edit that follows the teacher’s request. The same name and supports repair applies to a tweak.

## Required hosting-level authentication

CSRF tokens and same-origin checks **are not authentication**. Do not deploy an anonymously accessible paid-generation endpoint.

For this site, Directory Privacy on `betterschools.com.au/comments` is step 1 of the setup above. It covers the page, `generate.php`, static files, and any subdirectory still left from the old upload. The password file stays in `.htpasswds`. Redeploys keep the `cp:ppd` block. Do not replace that `.htaccess` with a raw copy from Git. If `Options -Indexes` causes a 500 error, ask the provider to turn directory listing off, remove that one line from `public/.htaccess` in Git, and deploy again. Do not remove the `cp:ppd` block.

Without the Directory Privacy password, `https://betterschools.com.au/comments` and `https://betterschools.com.au/comments/generate.php` must return **401**, including a direct POST, before PHP runs. Use HTTPS. HTTP Basic authentication needs TLS. Then complete the manual checks in `VERIFICATION.md`.

The app’s basic per-session throttle allows at most 10 validated attempts per 10 minutes, at least five seconds apart. A new PHP session can reset it; it is not an abuse-proof account/IP quota. Use hosting/WAF rate limits and Google project quotas for stronger cost control. PHP session cookies contain only CSRF and throttle metadata, never student records. Comment sessions are separate encrypted files.

## Session documents

A teacher can keep a set of comments under a random code, without an account. The code looks like four letters, four digits and two more words, for example `BARE-4827-CAME-DEFT`. It avoids easily confused characters. The page asks for a first name only and asks the teacher not to include a school, campus, class or other identifying details.

The code is the only key. Each session is one file in `$HOME/comments-private/sessions/`. That folder is mode `0700`, outside the website. Deploy creates it and does not delete files already there. The file name is the SHA-256 of the code, not the code itself. The comments are encrypted with AES-256-GCM. The key is derived from the code, so a copy of the file cannot be read without it. The created month is stored beside the ciphertext so expired files can be removed without the code.

A session lasts until the end of the month after the month it was created, using Australia/Sydney time (UTC if that timezone is unavailable). A session created in February is removed on 1 April. The app also clears expired files about once an hour when someone uses it.

The optional “Email me this code” field sends one message with PHP `mail()`. The From address is `noreply@betterschools.com.au`. The address the teacher types is used for that message only and is not stored. Copying the code still works if the message cannot be sent. Failed code lookups are limited per IP, and emailing a code is limited as well.

### Monthly cleanup cron

Deploy places `cleanup-sessions.php` in `/home/ACCOUNT/comments-private/`. In **cPanel → Advanced → Cron Jobs**, add one job. If the PHP shown below is not the version selected for the site, use the path from **cPanel → Software → MultiPHP Manager** or from Terminal (`which php`). A common EasyApache path is `/opt/cpanel/ea-php83/root/usr/bin/php`. Replace `ACCOUNT` with the cPanel username. Do not put this command inside the website folder.

```cron
15 3 1 * * /usr/local/bin/php -f /home/ACCOUNT/comments-private/cleanup-sessions.php
```

Minute `15`, hour `3`, day `1`, every month. The script deletes only expired sessions. It prints a count and does not print a session code. Run it once in **cPanel → Terminal** after the first deploy to confirm the PHP path. The hourly cleanup inside the app covers the days between cron runs; the cron is the sweep for a month when nobody opens the page.

## Running locally

From the project directory:

```powershell
php -S 127.0.0.1:8765 -t public
```

Visit `http://127.0.0.1:8765`. The development server uses the sibling `private/` directory and does not need `private-path.php`. It is for local testing only; bind it to loopback. Cookies are HttpOnly and SameSite=Strict, with Secure enabled when PHP receives HTTPS. Do not use the development server as public hosting. Without a configured key/model, generation reports that it is not configured; manual editing, copying and downloading still work.

## Data handling and behaviour

- The current draft exists in the page’s memory. There is no database, autosave, analytics or third-party frontend resource. The active session code can be kept in localStorage on this device; the comments themselves are not. Leave session forgets that code and leaves the stored comments in place. Clear session deletes them after confirmation. The page warns before leaving with entered data and reloads if restored from the back/forward cache.
- When Generate is clicked, the pronouns, selected descriptions, observations, any negative behaviours to reframe, any other specific adjustments, and any optional activities go through the PHP server to Google. The student’s name is replaced with the placeholder `[NAME]` in that request, including inside those notes, and the real name is put back only after the reply. Tweak comment sends those same details plus the current editor text, including manual edits, and the change request, with the name replaced in the same way. Dictation sends that recording to the same provider and does not send the name. This app does not store the audio and does not write it to disk. Google’s policies and your school’s agreement determine retention; this app makes no no-retention claim. Use only school-approved information and follow your school’s AI/data policy.
- Dictation is limited to 10 transcriptions per 10 minutes, at least 3 seconds apart. That limit is separate from comment generation. A clip can run for about 90 seconds and must stay under 5 MB.
- Server code does not log names, observations, drafts, tweak requests, keys or upstream bodies. Keep hosting/PHP debug modes and request-body capture disabled; check any host-level logging or monitoring independently. Normal URL access logs contain no form data because generation uses POST JSON, never query parameters.
- Requests/pages use `Cache-Control: no-store`; shared caches should bypass this application. Copy and download use the editor’s current value without another request. Downloads use a UTF-8 Blob and the teacher’s local time.
- Descriptions appear on hover/focus; separate information buttons support touch without changing selection. Escape dismisses descriptions. Selected buttons expose `aria-pressed`, ticks and visible counts.
- Possible contradictions display a non-blocking notice. The server prompt forbids invented timelines and unsupported factual claims. Teachers must review every AI draft; prompting cannot guarantee factual correctness or resist every malicious instruction.
- New student confirms discarding data, aborts the request, increments a request version and returns focus to the name. Even if the server finishes an aborted request, its response cannot overwrite a new student’s editor. An aborted request may still consume provider quota.
- Duplicate generation is disabled while loading. The editor is temporarily read-only during generation or a tweak to prevent an arriving response replacing newly typed edits; inputs remain adjustable and trigger a stale-input notice if the returned draft used earlier details. Failed generation or a failed tweak preserves the existing draft. Undo restores the wording from immediately before the last tweak, and that copy stays in page memory only. New student clears the tweak box.

## Automated checks

```powershell
php tests/validate.php
php tests/session.php
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

The interface supports Classic and Dark appearances without reloading or discarding the current draft. Classic is the default. Read [SKINS.md](SKINS.md) for styling and description controls. Deploy with the cPanel steps above. Do not upload `private/`, tests, or this README into `betterschools.com.au` or `public_html`.
