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
function chipControl(string $id, array $item): void {
    $kinds = ['strength' => 'strengths', 'next' => 'nextSteps', 'support' => 'supports', 'challenge' => 'challenges'];
    $bankKey = $kinds[$item['kind']];
    $tip = $bankKey . '-' . $id;
    $tone = match ($item['kind']) {
        'next' => 'growth',
        'challenge' => 'challenge',
        'support' => 'support',
        default => 'positive',
    };
    ?>
          <div class="chip-wrap <?= e($tone) ?>">
            <button type="button" class="chip" data-id="<?= e($id) ?>" data-bank="<?= e($bankKey) ?>" aria-pressed="false"><span class="tick" aria-hidden="true">✓</span><?= e($item['label']) ?></button>
            <button type="button" class="info" aria-label="About <?= e($item['label']) ?>" aria-expanded="false" aria-controls="descriptor-dialog" aria-haspopup="dialog" aria-describedby="<?= e($tip) ?>">i</button>
            <div class="description" id="<?= e($tip) ?>" role="tooltip"><?= e($item['description']) ?></div>
          </div>
    <?php
}
function dictateControl(string $id, string $label): void {
    $status = $id . '-dictate-status';
    ?>
          <button type="button" class="dictate-mic" hidden data-dictate="<?= e($id) ?>" data-label="<?= e($label) ?>" aria-label="<?= e($label) ?>" aria-describedby="<?= e($status) ?>"><svg class="dictate-mic-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M12 14a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v5a3 3 0 0 0 3 3z" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M6.5 11a5.5 5.5 0 0 0 11 0M12 16.5v3M9 19.5h6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg><svg class="dictate-stop-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" hidden><rect x="7" y="7" width="10" height="10" rx="1.5" fill="currentColor"/></svg><span class="dictate-pulse" hidden></span><span class="dictate-spinner" hidden></span><span class="dictate-timer" aria-hidden="true"></span></button>
    <?php
}
?><!doctype html>
<html lang="en-AU" data-skin="<?= e($skin) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e($token) ?>">
  <title>Report Comment Builder</title>
  <link rel="stylesheet" href="app.css?v=15">
  <link rel="stylesheet" href="dark.css?v=9">
  <script src="skin.js?v=3" defer></script>
  <script src="app.js?v=14" defer></script>
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
    </section>
    <section class="panel selection" aria-labelledby="descriptors-heading">
      <div class="section-heading"><span class="step">02</span><h2 id="descriptors-heading">What to include</h2></div>
      <p class="help">Choose any that apply. Strengths are on the left, areas for growth in the middle, and challenges on the right. Supports stay in one list. There’s no fixed limit.</p>
      <?php foreach ($bank['categories'] as $category):
        $grouped = ['strength' => [], 'next' => [], 'challenge' => [], 'support' => []];
        foreach ($category['items'] as $id => $item) if (isset($grouped[$item['kind']])) $grouped[$item['kind']][$id] = $item;
      ?>
      <div class="descriptor-group" data-category="<?= e($category['id']) ?>" role="group" aria-labelledby="<?= e($category['id']) ?>-heading">
        <div class="group-heading"><h3 id="<?= e($category['id']) ?>-heading"><?= e($category['title']) ?></h3><span class="count" aria-live="polite">0 selected</span></div>
        <p class="help"><?= e($category['hint']) ?></p>
        <?php if ($category['id'] === 'supports'): ?>
        <div class="chips">
        <?php foreach ($category['items'] as $id => $item) chipControl($id, $item); ?>
        </div>
        <div class="other-adjustments">
          <label for="adjustments">Other specific adjustments <span class="optional">Optional</span></label>
          <p id="adjustments-help" class="help">Type any other adjustment already in place. It is included with the selected items, in your words.</p>
          <div class="dictate"><textarea id="adjustments" name="adjustments" rows="2" maxlength="200" aria-describedby="adjustments-help adjustments-dictate-status" placeholder="Optional. For example, a quiet space for independent work."></textarea><?php dictateControl('adjustments', 'Dictate other specific adjustments'); ?></div>
          <p id="adjustments-dictate-status" class="dictate-error help" role="status"></p>
        </div>
        <?php else: ?>
        <div class="traffic-columns">
          <?php foreach (['strength' => ['Strengths', 'strengths-column'], 'next' => ['Areas for Growth', 'growth-column'], 'challenge' => ['Challenges and Obstacles', 'challenge-column']] as $kind => [$columnLabel, $columnClass]): $labelId = $category['id'] . '-' . $kind . '-label'; ?>
          <div class="traffic-column <?= e($columnClass) ?>" role="group" aria-labelledby="<?= e($labelId) ?>">
            <p class="column-label" id="<?= e($labelId) ?>"><?= e($columnLabel) ?></p>
            <div class="chips">
            <?php foreach ($grouped[$kind] as $id => $item) chipControl($id, $item); ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </section>
    <section class="panel" aria-labelledby="context-heading">
      <div class="section-heading"><span class="step">03</span><h2 id="context-heading">Make it personal</h2></div>
      <label for="observations">Teacher observations <span class="optional">Optional</span></label>
      <p id="observation-help" class="help">Specific examples help personalise the comment. Avoid sensitive personal or medical information.</p>
      <div class="dictate"><textarea id="observations" name="observations" rows="4" maxlength="4000" aria-describedby="observation-help observations-dictate-status" placeholder="A moment of progress, an achievement, or something you’ve noticed…"></textarea><?php dictateControl('observations', 'Dictate teacher observations'); ?></div>
      <p id="observations-dictate-status" class="dictate-error help" role="status"></p>
      <label for="reframe">Negative behaviours to reframe <span class="optional">Optional</span></label>
      <p id="reframe-help" class="help">These are never named in the comment; they are turned into positive next steps.</p>
      <div class="dictate"><textarea id="reframe" name="reframe" rows="2" maxlength="500" aria-describedby="reframe-help reframe-dictate-status" placeholder="Optional. Leave blank if there is nothing to reframe."></textarea><?php dictateControl('reframe', 'Dictate negative behaviours to reframe'); ?></div>
      <p id="reframe-dictate-status" class="dictate-error help" role="status"></p>
      <label for="activities">Clubs, activities or other experiences <span class="optional">Optional</span></label>
      <p id="activities-help" class="help">Name the specific club or activity, describe how the student took part, and the skills they built. Treat personal development activities the same way.</p>
      <div class="dictate"><textarea id="activities" name="activities" rows="2" maxlength="500" aria-describedby="activities-help activities-dictate-status" placeholder="Optional. Leave blank if it should not appear."></textarea><?php dictateControl('activities', 'Dictate clubs or activities'); ?></div>
      <p id="activities-dictate-status" class="dictate-error help" role="status"></p>
      <label for="length">Comment length</label><select id="length" name="length"><option value="short">Short · approximately 60–90 words</option><option value="standard" selected>Standard · approximately 90–120 words</option><option value="detailed">Detailed · approximately 120–150 words</option><option value="long">Long · approximately 150–200 words</option></select>
    </section>
  </form>
  <aside class="editor-column" aria-labelledby="editor-heading">
    <section class="panel editor-panel">
      <div class="section-heading"><span class="editor-mark" aria-hidden="true">✦</span><h2 id="editor-heading">Your comment</h2><span id="word-count" class="count">0 words</span></div>
      <p class="help">A starting point in your teacher voice. Edit it to make it your own.</p>
      <div id="contradictions" class="notice" role="status" hidden></div>
      <button id="generate" type="submit" form="builder" class="primary">Generate comment <span aria-hidden="true">↗</span></button>
      <p class="help name-privacy">Student names are never sent to the AI. Please avoid typing other identifying details.</p>
      <p id="generation-status" role="status" aria-live="polite"></p>
      <p id="stale" class="notice" hidden>Inputs have changed. This comment was generated from earlier details.</p>
      <label class="sr-only" for="comment">Editable report comment</label>
      <textarea id="comment" rows="14" placeholder="Your generated comment will appear here. You can also write or edit directly in this space." spellcheck="true"></textarea>
      <div id="tweak-panel" class="tweak-panel" hidden>
        <label for="tweak">What would you like changed?</label>
        <p id="tweak-help" class="help">Changes only what you ask for. The comment still uses the first name three times and follows the selected length.</p>
        <div class="dictate"><textarea id="tweak" rows="2" maxlength="500" aria-describedby="tweak-help tweak-dictate-status" placeholder="Make it a little friendlier, lower the reading level, add their effort in science..."></textarea><?php dictateControl('tweak', 'Dictate the change you want'); ?></div>
        <p id="tweak-dictate-status" class="dictate-error help" role="status"></p>
        <div class="tweak-actions">
          <button id="tweak-comment" type="button">Tweak comment</button>
          <button id="undo-tweak" type="button" hidden>Undo</button>
        </div>
      </div>
      <p class="review">Review and personalise before using in a student report.</p>
      <div class="actions"><button id="copy" type="button" disabled>Copy comment</button><button id="commit-session" type="button" disabled>Commit to session</button><button id="save" type="button" disabled>Save text file</button></div>
      <p id="action-status" class="help" role="status" aria-live="polite"></p>
      <div class="editor-footer"><span>Ready for a fresh start?</span><button id="new-student" type="button" class="text-button">New student <span aria-hidden="true">↻</span></button></div>
    </section>
    <p class="privacy"><span aria-hidden="true">◇</span> Student details stay in this page until you clear or close it. When you generate or tweak, the supplied details are sent to the configured AI provider. The student’s name is replaced before that request and is not sent. A tweak also sends the current comment and the change you asked for, with the name replaced in the same way. Dictation sends that recording to the same provider and does not include the name. This app does not store the audio. Provider retention policies apply. Use only information your school permits you to share.</p>
  </aside>
  </div>
  <footer>Thoughtful comments start with what you know about your student.</footer>
</main>
<dialog id="descriptor-dialog" class="descriptor-dialog" aria-labelledby="descriptor-title" aria-describedby="descriptor-text">
  <div class="descriptor-heading"><h2 id="descriptor-title">Descriptor information</h2><button id="descriptor-close" class="descriptor-close" type="button" autofocus>Close</button></div>
  <p id="descriptor-text"></p>
</dialog>
<div class="session-dock">
  <section id="session-panel" class="session-panel" hidden aria-labelledby="session-heading">
    <div class="session-heading"><h2 id="session-heading">Session</h2><button id="session-close" type="button">Close</button></div>
    <p class="session-privacy">Use a first name only. Do not include a school, campus, class, or other identifying details.</p>
    <div id="session-start">
      <button id="session-new" type="button" class="session-primary">Start new session</button>
      <label for="session-code">Resume with a code</label>
      <input id="session-code" autocomplete="off" spellcheck="false" maxlength="64" autocapitalize="characters" placeholder="Paste the code">
      <button id="session-resume" type="button">Resume</button>
    </div>
    <div id="session-active" hidden>
      <p class="session-write">Write this down. Without this code the comments cannot be opened.</p>
      <label class="sr-only" for="session-code-display">Session code</label>
      <input id="session-code-display" class="session-code" readonly spellcheck="false">
      <button id="session-copy-code" type="button">Copy code</button>
      <p id="session-expiry"></p>
      <label for="session-email">Email me this code <span class="optional">Optional</span></label>
      <p id="session-email-help" class="help">Used only to send the code. It is not stored.</p>
      <input id="session-email" type="email" maxlength="254" autocomplete="email" aria-describedby="session-email-help">
      <button id="session-send" type="button">Email me this code</button>
      <p id="session-list-count">0 comments</p>
      <ul id="session-list"></ul>
      <div class="session-downloads">
        <button id="session-txt" type="button">Download .txt</button>
        <button id="session-docx" type="button">Download .docx</button>
      </div>
      <div class="session-manage">
        <button id="session-leave" type="button">Leave session</button>
        <button id="session-clear" type="button">Clear session</button>
      </div>
    </div>
    <p id="session-message" class="help" role="status"></p>
  </section>
  <button id="session-toggle" class="session-toggle" type="button" aria-expanded="false" aria-controls="session-panel">Session <span id="session-count">0</span></button>
</div>
<noscript><p class="notice">JavaScript is required for selections and comment generation.</p></noscript>
</body>
</html>
