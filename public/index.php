<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require __DIR__ . '/private_path.php';
comment_drafter_require_private(__DIR__);
if (!empty($_SERVER['PATH_INFO'])) { http_response_code(404); exit('Page not found.'); }
$requestedSkin = $_GET['skin'] ?? '';
$skin = in_array($requestedSkin, ['classic', 'dark'], true) ? $requestedSkin : 'classic';
$bank = bank();
$token = $_SESSION['csrf'];
session_write_close();
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?><!doctype html>
<html lang="en-AU" data-skin="<?= e($skin) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e($token) ?>">
  <title>Report Comment Builder</title>
  <link rel="stylesheet" href="app.css?v=8">
  <link rel="stylesheet" href="dark.css?v=5">
  <script src="skin.js?v=3" defer></script>
  <script src="app.js?v=6" defer></script>
</head>
<body>
<a class="skip" href="#main">Skip to builder</a>
<header class="masthead"><div class="masthead-bar"><div class="brand"><img class="logo logo-classic" src="assets/logo-classic.webp" width="692" height="168" alt="Better Schools"><img class="logo logo-dark" src="assets/logo-dark.webp" width="692" height="168" alt="Better Schools"></div><div class="masthead-tools"><span class="tag">A little support for report season</span><div class="skin-switch" role="group" aria-label="Appearance"><button type="button" data-set-skin="classic" aria-pressed="<?= $skin === 'classic' ? 'true' : 'false' ?>">Classic</button><button type="button" data-set-skin="dark" aria-pressed="<?= $skin === 'dark' ? 'true' : 'false' ?>">Dark</button></div></div></div></header>
<p id="skin-status" class="sr-only skin-status" role="status" aria-live="polite"></p>
<main id="main">
  <div class="intro"><p class="eyebrow">PROGRESS, PUT INTO WORDS</p><h1>Report Comment Builder</h1><p>Recognise each student’s strengths. Make their next steps feel achievable.</p></div>
  <div class="workspace">
  <form id="builder" autocomplete="off">
    <section class="panel student" aria-labelledby="student-heading">
      <div class="section-heading"><span class="step">01</span><h2 id="student-heading">Start with the student</h2></div>
      <div class="fields">
        <div><label for="name">Full first name <span class="required">*</span></label><input id="name" name="name" required maxlength="100" autocomplete="off" placeholder="e.g. Alex" aria-describedby="name-help"></div>
        <div><label for="pronouns">Pronouns</label><select id="pronouns" name="pronouns" aria-describedby="name-help"><option value="they">They/them</option><option value="she">She/her</option><option value="he">He/him</option><option value="name">Prefer name only</option></select></div>
      </div>
      <p id="name-help" class="help">Use the full first name, never a nickname (e.g. Joseph-Thomas, not "JT"). Use a preferred name or pronouns only where parents/carers know and support it; published reports use the legal name unless a preferred name is approved.</p>
      <label for="subject">Subject or learning area <span class="optional">Optional</span></label><input id="subject" name="subject" maxlength="160" autocomplete="off" placeholder="e.g. English, Mathematics, learning across the curriculum">
    </section>
    <section class="panel selection" aria-labelledby="descriptors-heading">
      <div class="section-heading"><span class="step">02</span><h2 id="descriptors-heading">What to include</h2></div>
      <p class="help">Choose any that apply. Growth items have a blue edge. There’s no fixed limit.</p>
      <?php $kinds = ['strength' => 'strengths', 'next' => 'nextSteps', 'support' => 'supports']; ?>
      <?php foreach ($bank['categories'] as $category): ?>
      <div class="descriptor-group" data-category="<?= e($category['id']) ?>" role="group" aria-labelledby="<?= e($category['id']) ?>-heading">
        <div class="group-heading"><h3 id="<?= e($category['id']) ?>-heading"><?= e($category['title']) ?></h3><span class="count" aria-live="polite">0 selected</span></div>
        <p class="help"><?= e($category['hint']) ?></p>
        <div class="chips">
        <?php foreach ($category['items'] as $id => $item): $bankKey = $kinds[$item['kind']]; $tip = $bankKey . '-' . $id; $tone = $item['kind'] === 'next' ? 'growth' : ($item['kind'] === 'support' ? 'support' : 'positive'); ?>
          <div class="chip-wrap <?= e($tone) ?>">
            <button type="button" class="chip" data-id="<?= e($id) ?>" data-bank="<?= e($bankKey) ?>" aria-pressed="false" aria-describedby="<?= e($tip) ?>"><span class="tick" aria-hidden="true">✓</span><?= e($item['label']) ?></button>
            <button type="button" class="info" aria-label="About <?= e($item['label']) ?>" aria-expanded="false" aria-controls="descriptor-dialog" aria-haspopup="dialog" aria-describedby="<?= e($tip) ?>">i</button>
            <div class="description" id="<?= e($tip) ?>" role="tooltip"><?= e($item['description']) ?></div>
          </div>
        <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </section>
    <section class="panel" aria-labelledby="context-heading">
      <div class="section-heading"><span class="step">03</span><h2 id="context-heading">Make it personal</h2></div>
      <label for="observations">Teacher observations <span class="optional">Optional</span></label>
      <p id="observation-help" class="help">Specific examples help personalise the comment. Avoid sensitive personal or medical information.</p>
      <textarea id="observations" name="observations" rows="4" maxlength="4000" aria-describedby="observation-help" placeholder="A moment of progress, an achievement, or something you’ve noticed…"></textarea>
      <label for="reframe">Negative behaviours to reframe <span class="optional">Optional</span></label>
      <p id="reframe-help" class="help">These are never named in the comment; they are turned into positive next steps.</p>
      <textarea id="reframe" name="reframe" rows="2" maxlength="500" aria-describedby="reframe-help" placeholder="Optional. Leave blank if there is nothing to reframe."></textarea>
      <label for="activities">Clubs, activities or other experiences <span class="optional">Optional</span></label>
      <p id="activities-help" class="help">Name the specific club or activity, describe how the student took part, and the skills they built. Treat personal development activities the same way.</p>
      <textarea id="activities" name="activities" rows="2" maxlength="500" aria-describedby="activities-help" placeholder="Optional. Leave blank if it should not appear."></textarea>
      <label for="length">Comment length</label><select id="length" name="length"><option value="short">Shorter · 5 sentences</option><option value="standard" selected>Balanced · 6 sentences</option><option value="detailed">Fuller · 7 sentences</option></select>
    </section>
  </form>
  <aside class="editor-column" aria-labelledby="editor-heading">
    <section class="panel editor-panel">
      <div class="section-heading"><span class="editor-mark" aria-hidden="true">✦</span><h2 id="editor-heading">Your comment</h2><span id="word-count" class="count">0 words</span></div>
      <p class="help">A starting point in your teacher voice. Edit it to make it your own.</p>
      <div id="contradictions" class="notice" role="status" hidden></div>
      <button id="generate" type="submit" form="builder" class="primary">Generate comment <span aria-hidden="true">↗</span></button>
      <p id="generation-status" role="status" aria-live="polite"></p>
      <p id="stale" class="notice" hidden>Inputs have changed. This comment was generated from earlier details.</p>
      <label class="sr-only" for="comment">Editable report comment</label>
      <textarea id="comment" rows="14" placeholder="Your generated comment will appear here. You can also write or edit directly in this space." spellcheck="true"></textarea>
      <p class="review">Review and personalise before using in a student report.</p>
      <div class="actions"><button id="copy" type="button" disabled>Copy comment</button><button id="save" type="button" disabled>Save text file</button></div>
      <p id="action-status" class="help" role="status" aria-live="polite"></p>
      <div class="editor-footer"><span>Ready for a fresh start?</span><button id="new-student" type="button" class="text-button">New student <span aria-hidden="true">↻</span></button></div>
    </section>
    <p class="privacy"><span aria-hidden="true">◇</span> Student details stay in this page until you clear or close it. When you generate, the supplied details are sent to the configured AI provider. Provider retention policies apply. Use only information your school permits you to share.</p>
  </aside>
  </div>
  <footer>Thoughtful comments start with what you know about your student.</footer>
</main>
<dialog id="descriptor-dialog" class="descriptor-dialog" aria-labelledby="descriptor-title" aria-describedby="descriptor-text">
  <div class="descriptor-heading"><h2 id="descriptor-title">Descriptor information</h2><button id="descriptor-close" class="descriptor-close" type="button" autofocus>Close</button></div>
  <p id="descriptor-text"></p>
</dialog>
<noscript><p class="notice">JavaScript is required for selections and comment generation.</p></noscript>
</body>
</html>
