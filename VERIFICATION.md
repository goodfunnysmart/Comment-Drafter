# Verification

Development checks performed on 22 October 2026 with PHP 8.4.25:

- PHP syntax checks passed for every PHP file.
- `tests/validate.php`: passed counts (26 strengths, 28 next steps), Unicode/punctuation names, strengths-only and next-steps-only inputs, the full selection bank, and 14 invalid-input cases including blank names, unknown/duplicate IDs, excessive lengths and invalid types.
- `tests/http_check.py`: passed page rendering of 54 chips, no-store headers, HttpOnly/SameSite cookies, POST-only enforcement, CSRF and origin checks, invalid JSON/data, request-size cap, private-path inaccessibility, safe unconfigured error and session throttling.
- `tests/private_path.php`: passed local sibling resolution, the deployed `comments-private` location, rejection of paths inside the front controller or `public_html`, and the deploy hint and environment override.
- `tests/deploy_check.sh`: passed copying site files to `public_html/comments` and private files to `comments-private`, leaving `config.php` unchanged, and keeping a cPanel Directory Privacy block across redeploys.
- `tests/client.mjs`: passed selection toggles, validation, a synthetic successful response appearing in the editor, manual-edit confirmation, failure preserving edited text, copying/saving the latest edited text, readable/safe filenames with a local timestamp, stale-input and contradiction notices, and clearing with a late synthetic response.
- The server-side bank was extracted directly from the supplied request to preserve exact labels and descriptions.
- Public source contains no configured API key; its only configuration metadata is the session CSRF token. Generation traffic from the browser contains student inputs/IDs, not a model, system prompt or API key.

The user reported successful live demo generation. Automated regression tests use synthetic responses and do not establish provider accuracy or availability. Local PHP cURL was enabled and HTTPS connectivity verified with Windows native trusted certificates. No browser was connected for agent visual verification, so visual rendering, device clipboard/download integration and keyboard/touch behaviour still require the checks below.

## Manual checklist before school use

- [ ] Enable PHP cURL, HTTPS and whole-directory hosting authentication. In a signed-out private window, verify the page, assets and direct endpoint requests all require authentication.
- [ ] Without Directory Privacy credentials, `https://greache.com/comments` returns 401. `bank.json`, `instructions.txt`, `config.php` and `README.md` are not reachable, including under `/comments/private/` and `/comments/public/`. `comments-private` is not a URL. Confirm `public_html/comments` contains only the deployed site files.
- [ ] Set the private API key and exact model ID. Generate a comment with synthetic student details first. Confirm a genuine provider response appears in the editor, uses the requested pronouns and is 5, 6 or 7 sentences as selected.
- [ ] Count all 26 strengths and 28 next steps. Toggle selections by mouse, Tab + Enter/Space and touch. Check counts, ticks and `aria-pressed`. Hover/focus descriptions and tap each information button; ensure tapping information does not select the chip. Press Escape to dismiss a description. Check small-screen tooltip positions and wrapping.
- [ ] Leave the full first name blank or choose no descriptors; generation must be prevented. Test names with spaces, apostrophes, hyphens and non-English characters. Confirm strengths-only and next-steps-only generation. Optional activities and reporting arrangements appear only when entered.
- [ ] Confirm a kept draft is 5–7 sentences, opens from the evidence, places any difficulty between strengths, and does not add clubs, supports or goals that were not supplied.
- [ ] Select Consistent Attendance and Attendance Routines; verify the notice appears before generation without blocking it. Add context and review the result for invented timelines or support arrangements.
- [ ] Edit a draft; cancel regeneration confirmation and confirm edits remain. Regenerate and ensure one request occurs; test a provider error/quota/unavailable-model/timeout case and confirm the old edited comment stays intact.
- [ ] Change details while generating or after generation; confirm a returned draft shows the earlier-input notice. Clear the student while a request is running, accept confirmation, enter a new student and confirm the late old response never appears.
- [ ] Type edits, then copy and paste into a blank document; confirm the exact current editor text. Test the fallback when Clipboard API permission is denied. Save and open the UTF-8 text file; verify current text, readable student name and local date/time, including apostrophes and accented names.
- [ ] Clear the editor manually; copy/save must disable. Cancel New student; all values must remain. Accept New student; values, counts and notices clear, pronouns/length reset and the name receives focus.
- [ ] Inspect browser source, JavaScript, request payloads and responses: no API key. Only the PHP server’s outbound request carries the key header. Confirm no student data is saved in browser storage or PHP session records, and hosting debug/body logging is disabled.
- [ ] Check desktop, tablet and phone widths, visible focus, labels, live messages, screen-reader descriptions and adequate contrast. Confirm the editor stays beside the selections on a wide desktop and flows below them on mobile.
- [ ] Have a teacher review generated wording, pronouns, Australian English and factual support before real reporting. Confirm school approval for sending the chosen data to the provider.

## Theme and description checks

- All three theme URLs render the 54 selection controls and shared editor.
- Theme switch checks passed for Studio, Classic and Dark, including `aria-pressed` updates without accessing student fields or storage.
- Description interaction checks passed with a fake DOM: mouse departure, selection click, scroll and outside dismissal; keyboard-only focus; no touch hover; information dialog content; Close, Escape and backdrop dismissal; `aria-expanded` reset.
- Existing comment workflow regressions passed after these interface changes.
- cPanel diagnostic checks passed for invalid config syntax/format and disabled cURL functions, without exposing raw exception messages or credentials.

These programmatic checks do not replace visual or touchscreen browser testing. The HTTP test must run in an isolated unconfigured development copy; do not run it against a configured provider.
