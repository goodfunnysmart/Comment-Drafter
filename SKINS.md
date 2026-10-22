# Appearance and descriptions

The header shows the Better Schools logo and offers **Classic** and **Dark**. Classic is the default and uses the full-colour logo. Dark uses the same layout with a dark palette and the reversed logo.

Switching changes appearance in page memory without reloading, calling the API or touching student inputs and drafts. The choice is not stored. Direct links `?skin=classic` and `?skin=dark` select an initial appearance. Any other value, including an old `?skin=studio` link, uses Classic. Opening a link reloads the page, so copy any existing draft first.

## Files

- `public/app.css`: Classic styling, the appearance switch, shared controls and the mobile description dialog.
- `public/dark.css`: dark colours and focus/selection states.
- `public/skin.js`: accessible appearance switch; no student data access or storage.
- `public/app.js`: selection, generation, editing and description interactions.
- `public/index.php`: rendered page and dialog.

All fonts and styling are local; there are no third-party resources or tracking. Upload all public files when deploying. If the hosted `index.php` uses a customised absolute bootstrap `require` path, preserve it when replacing the file. API configuration and the generation endpoint are independent of the appearance switch.

## Description controls

On desktop, explanations appear on mouse hover or keyboard focus. Mouse departure, clicking a selection, scrolling, resizing, leaving the window or Escape dismisses the preview. Pointer clicks do not leave a persistent focus tooltip.

On touch devices, tap the separate information button to open a readable description sheet. It does not select the descriptor. The native dialog supports a Close button, Escape, backdrop dismissal, focus trapping and focus return. Touch information controls have a 44-pixel minimum target. On wider screens, the dialog is centred.

Selection ticks, counts, `aria-pressed`, notices and visible focus remain available in both appearances. Reduced-motion preferences are respected. Programmatic interaction checks passed; browser visual and touch verification remains part of the deployment checklist in VERIFICATION.md.
