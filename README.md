# Comment Drafter

Report Comment Builder is a complete single-page teacher workspace built with PHP, HTML, CSS and vanilla JavaScript. No build step, framework, database, analytics or browser storage. All 26 strengths and 28 next steps live in one private JSON bank. PHP renders the bank and resolves submitted IDs against it before calling Gemini.

## Project files

```text
Comment Drafter/
├── public/                       ← Only this directory is web-accessible
│   ├── index.php                 # Page, bank rendering, CSRF token
│   ├── generate.php              # Validated server-side Gemini endpoint
│   ├── app.css
│   ├── app.js
│   ├── studio.css                # Studio layout and styling
│   ├── dark.css                  # Dark palette
│   ├── skin.js                   # Studio / Classic / Dark switch
│   └── .htaccess
├── private/                      ← Keep outside ALL document roots
│   ├── bootstrap.php             # Session, headers, validation
│   ├── bank.json                 # Single source of truth, stable IDs
│   ├── instructions.txt          # Server-owned generation rules
│   └── config.example.php        # Placeholders; copy privately to config.php
├── tests/
│   ├── validate.php
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

## Installation on ordinary PHP / cPanel hosting

### Recommended: a dedicated subdomain

1. Create `/home/YOURACCOUNT/report-builder/` outside `public_html` and other document roots.
2. Upload the `public` and `private` folders into it, preserving this structure:

   ```text
   /home/YOURACCOUNT/report-builder/private/bootstrap.php
   /home/YOURACCOUNT/report-builder/private/bank.json
   /home/YOURACCOUNT/report-builder/private/instructions.txt
   /home/YOURACCOUNT/report-builder/private/config.php
   /home/YOURACCOUNT/report-builder/public/index.php
   /home/YOURACCOUNT/report-builder/public/generate.php
   /home/YOURACCOUNT/report-builder/public/app.js
   /home/YOURACCOUNT/report-builder/public/app.css
   /home/YOURACCOUNT/report-builder/public/.htaccess
   ```

3. In cPanel Domains, create a dedicated subdomain with document root **`/home/YOURACCOUNT/report-builder/public`**. If your provider restricts subdomain roots, use the alternative below. Never point a document root at the project parent or private directory.
4. Enable HTTPS for that domain and redirect HTTP to HTTPS through the host’s controls. PHP should receive `HTTPS=on`; if the host terminates TLS at a proxy, have it set the correct HTTPS server variable. The app does not trust arbitrary forwarded headers.
5. Use cPanel’s PHP selector / extensions page to enable cURL. Confirm JSON and sessions work. Use restrictive permissions: private directory `700`, private files `600` where PHP runs as your account. Some hosts need `750` / `640` for the PHP group; ask your provider. Public files usually use `644`, directories `755`. Never use `777`.
6. Configure the key and model as described below, then protect the directory **before making generation available**.

### Alternative: existing public_html directory

For an application at the domain root, upload **only the contents** of `public/` into `/home/YOURACCOUNT/public_html/`, and upload `private/` as `/home/YOURACCOUNT/private/`. The existing PHP includes work unchanged because these are sibling directories. Keep other existing site files intact.

For a subfolder such as `/home/YOURACCOUNT/public_html/reports/`, put private files in `/home/YOURACCOUNT/report-builder-private/`. In **both** `index.php` and `generate.php`, replace:

```php
require dirname(__DIR__) . '/private/bootstrap.php';
```

with your actual absolute filesystem path:

```php
require '/home/YOURACCOUNT/report-builder-private/bootstrap.php';
```

The loaded bootstrap uses its own absolute directory for the bank, instructions and configuration. Do not create a private folder inside `public_html`, even with a dot prefix or access-deny rule. Do not upload the project parent, tests or README into a public directory. Do not store secrets in a public `.env`, JavaScript, HTML, `.htaccess` or version control.

## Private Gemini configuration

Preferred: have the host configure **server environment variables** for the app’s PHP process:

```text
GEMINI_API_KEY=your-private-key
GEMINI_MODEL=gemini-3.5-flash-lite
```

The application never sends these settings to the browser. Environment variables take precedence over private configuration.

If the host cannot supply environment variables, copy `private/config.example.php` to `private/config.php` **outside the document root**, and edit that private copy:

```php
<?php
return [
    'GEMINI_API_KEY' => 'YOUR_PRIVATE_KEY',
    'GEMINI_MODEL' => 'gemini-3.5-flash-lite',
];
```

The example in this project contains placeholders only. `config.php` is excluded from Git. Do not put real keys into the example file or paste them into this chat. If configuration is missing, the app returns a clear configuration error and retains any draft.

The exact ID **`gemini-3.5-flash-lite`** was verified against [Google’s model documentation](https://ai.google.dev/gemini-api/docs/models/gemini-3.5-flash-lite) on 22 October 2026. Account access and quota must still be confirmed with your key. No substitute model is selected automatically. Change only `GEMINI_MODEL` to change models.

The server follows Google’s [generateContent REST reference](https://ai.google.dev/api/generate-content) using an HTTPS POST and `x-goog-api-key` header. [Google’s migration documentation](https://ai.google.dev/gemini-api/docs/generate-content/whats-new-gemini-3.6) says to omit deprecated sampling parameters for this model; the implementation omits them. It sends server-owned `systemInstruction` plus descriptor-resolved source material. Requests have a 10-second connection timeout and 45-second total timeout. Output length is guided by the prompt; evidence may justify fewer words.

## Required hosting-level authentication

CSRF tokens and same-origin checks **are not authentication**. Do not deploy an anonymously accessible paid-generation endpoint.

1. Open **cPanel → Files → Directory Privacy** (sometimes called Password Protect Directories).
2. Select the actual application document root (the `public` directory, `public_html` for a dedicated domain root, or the `reports` subdirectory). Choose **Password protect this directory**, name the protected area, and save.
3. Create a username and strong password using the host’s interface. The host should keep its password file outside the public directory.
4. If uploading `.htaccess`, upload it before enabling Directory Privacy, or carefully merge the host’s authentication directives. **Do not overwrite the provider’s generated authentication rules.** If `Options -Indexes` causes a 500 error on your host, ask the provider to disable directory listing in its controls and remove that one directive.
5. In a private/incognito window, visit both the page and `https://YOURDOMAIN/generate.php` without credentials. **Both must prompt for credentials or return 401.** A direct unauthenticated POST must also return 401 before PHP runs. Check static assets too.
6. Log in and complete the manual checks in `VERIFICATION.md`. Use HTTPS exclusively: HTTP Basic authentication needs TLS.

For non-cPanel hosting, use the provider’s equivalent whole-directory authentication. The app’s basic per-session throttle allows at most 10 validated attempts per 10 minutes, at least five seconds apart. A new session can reset it; it is not an abuse-proof account/IP quota. Use hosting/WAF rate limits and Google project quotas for stronger cost control. PHP sessions contain only CSRF and throttle metadata, never student records.

## Running locally

From the project directory:

```powershell
php -S 127.0.0.1:8765 -t public
```

Visit `http://127.0.0.1:8765`. The PHP development server is for local testing only; bind it to loopback. Cookies are HttpOnly and SameSite=Strict, with Secure enabled when PHP receives HTTPS. Do not use the development server as public hosting. Without a configured key/model, generation reports that it is not configured; manual editing, copying and downloading still work.

## Data handling and behaviour

- Student details and drafts exist in the page’s memory only. There is no database, localStorage, sessionStorage, autosave, analytics or third-party frontend resource. The page warns before leaving with entered data and reloads if restored from the back/forward cache.
- When Generate is clicked, the name, pronouns, subject, selected descriptions and observations go through the PHP server to Google. Google’s policies and your school’s agreement determine retention; this app makes no no-retention claim. Use only school-approved information and follow your school’s AI/data policy.
- Server code does not log names, observations, drafts, keys or upstream bodies. Keep hosting/PHP debug modes and request-body capture disabled; check any host-level logging or monitoring independently. Normal URL access logs contain no form data because generation uses POST JSON, never query parameters.
- Requests/pages use `Cache-Control: no-store`; shared caches should bypass this application. Copy and download use the editor’s current value without another request. Downloads use a UTF-8 Blob and the teacher’s local time.
- Descriptions appear on hover/focus; separate information buttons support touch without changing selection. Escape dismisses descriptions. Selected buttons expose `aria-pressed`, ticks and visible counts.
- Possible contradictions display a non-blocking notice. The server prompt forbids invented timelines and unsupported factual claims. Teachers must review every AI draft; prompting cannot guarantee factual correctness or resist every malicious instruction.
- New student confirms discarding data, aborts the request, increments a request version and returns focus to the name. Even if the server finishes an aborted request, its response cannot overwrite a new student’s editor. An aborted request may still consume provider quota.
- Duplicate generation is disabled while loading. The editor is temporarily read-only during generation to prevent an arriving response replacing newly typed edits; inputs remain adjustable and trigger a stale-input notice if the returned draft used earlier details. Failed generation preserves the existing draft.

## Automated checks

```powershell
php tests/validate.php
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

The interface supports Studio, Classic and Dark appearances without reloading or discarding the current draft. Read [SKINS.md](SKINS.md) for styling and description controls. For deployment, upload all files under `public/`, including the theme assets; keep the private files outside the document root.
