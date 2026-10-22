# Appearance and descriptions

The header offers **Studio**, **Classic** and **Dark**. Studio is the default: cream surfaces, forest-green accents, sage strengths, warm next steps and a serif writing area. Classic retains the original design; Dark uses Studio's layout with a dark palette.

Switching changes appearance in page memory without reloading, calling the API or touching student inputs and drafts. The choice is not stored. Direct links `?skin=studio`, `?skin=classic` and `?skin=dark` select an initial appearance; opening a link reloads the page, so copy any existing draft first.

## Files

- `public/app.css`: original styling, shared controls and mobile description dialog.
- `public/studio.css`: Studio/Dark layout, Studio palette and switch styling.
- `public/dark.css`: dark colours and focus/selection states.
- `public/skin.js`: accessible appearance switch; no student data access or storage.
- `public/app.js`: selection, generation, editing and description interactions.
- `public/index.php`: rendered page and dialog.

All fonts and styling are local; there are no third-party resources or tracking. Upload all public files when deploying. If the hosted `index.php` uses a customised absolute bootstrap `require` path, preserve it when replacing the file. API configuration and the generation endpoint are independent of the appearance switch.

## Description controls

On desktop, explanations appear on mouse hover or keyboard focus. Mouse departure, clicking a selection, scrolling, resizing, leaving the window or Escape dismisses the preview. Pointer clicks do not leave a persistent focus tooltip.

On touch devices, tap the separate information button to open a readable description sheet. It does not select the descriptor. The native dialog supports a Close button, Escape, backdrop dismissal, focus trapping and focus return. Touch information controls have a 44-pixel minimum target. On wider screens, the dialog is centred.

Selection ticks, counts, `aria-pressed`, notices and visible focus remain available in all themes. Reduced-motion preferences are respected. Programmatic interaction checks passed; browser visual and touch verification remains part of the deployment checklist in VERIFICATION.md.
