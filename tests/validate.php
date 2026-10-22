<?php
// Run: php tests/validate.php
require dirname(__DIR__) . '/private/bootstrap.php';
$bank = bank();
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
check(count($bank['strengths']) === 26 && count($bank['nextSteps']) === 28, 'Bank counts');
$base = ['name' => 'Élodie O’Neil-Smith', 'subject' => '', 'observations' => '', 'pronouns' => 'they', 'length' => 'standard', 'strengths' => ['natural-curiosity'], 'nextSteps' => []];
check(validateInput($base, $bank)['name'] === $base['name'], 'Unicode names');
$nextOnly = array_replace($base, ['strengths' => [], 'nextSteps' => ['regular-engagement']]);
check(count(validateInput($nextOnly, $bank)['nextSteps']) === 1, 'Next steps only');
$all = array_replace($base, ['strengths' => array_keys($bank['strengths']), 'nextSteps' => array_keys($bank['nextSteps'])]);
check(count(validateInput($all, $bank)['strengths']) === 26, 'No artificial selection limits');
$bad = [
    ['name' => '  '], ['name' => []], ['name' => str_repeat('é', 101)],
    ['observations' => str_repeat('a', 4001)], ['subject' => 123],
    ['activities' => 123], ['activities' => str_repeat('a', 501)], ['arrangements' => "note\0"],
    ['pronouns' => 'anything'], ['length' => []],
    ['strengths' => [], 'nextSteps' => []], ['strengths' => ['unknown']],
    ['strengths' => ['natural-curiosity', 'natural-curiosity']],
    ['strengths' => ['regular-engagement']], ['nextSteps' => 'not-array'],
    ['strengths' => array_fill(0, 27, 'natural-curiosity')], ['name' => "Alex\0"],
];
foreach ($bad as $index => $change) {
    try { validateInput(array_replace($base, $change), $bank); }
    catch (InvalidArgumentException $e) { continue; }
    throw new RuntimeException('Invalid case accepted: ' . $index);
}
check(validateInput($base, $bank)['activities'] === '' && validateInput($base, $bank)['arrangements'] === '', 'Optional notes default to empty');
$noted = array_replace($base, ['activities' => ' Lunch club ', 'arrangements' => 'Use the supplied name.']);
check(validateInput($noted, $bank)['activities'] === 'Lunch club', 'Optional activities are trimmed');
$five = 'Alex is curious and kind. Alex listens well. Rushing sometimes hides that understanding. A careful check helps. Alex can finish each task with the same care.';
$six = $five . ' Alex can use this routine next term.';
$seven = $six . ' That practice matters.';
$withAbbreviation = 'Alex checks the work, e.g. the final line, before moving on. Alex then asks a question. Alex keeps trying. Alex works with a classmate. Alex can use this routine next term.';
check(commentSentenceCount($five) === 5 && commentSentenceCount($six) === 6 && commentSentenceCount($seven) === 7, 'Sentence counts of 5, 6 and 7');
check(commentSentenceCount($five . ' One more.') === 6, 'A further sentence is counted');
check(commentSentenceCount('One. Two. Three. Four.') === 4 && commentSentenceCount($seven . ' Extra.') === 8, 'Counts outside 5–7 are detectable');
check(commentSentenceCount($withAbbreviation) === 5, 'Abbreviations are not extra sentences');
check(commentSentenceCount('') === 0 && normaliseComment("  \"Kept as one. Still one.\" ") === 'Kept as one. Still one.', 'Empty text and wrapped comments');
$instructions = file_get_contents(dirname(__DIR__) . '/private/instructions.txt');
check(is_string($instructions) && str_contains($instructions, '5–7 sentences') && str_contains($instructions, 'full first name'), 'Private instructions carry the comment priorities');
check(str_contains($instructions, 'between positive statements') && str_contains($instructions, 'short or thin comment'), 'Private instructions require a sandwiched, substantial comment');
check(str_contains($instructions, 'name it specifically') && str_contains($instructions, 'skills they built') && str_contains($instructions, 'supplied input'), 'Private instructions name supplied clubs and skills only');
check(!str_contains($instructions, '.docx') && !str_contains($instructions, 'uploads/'), 'Private instructions do not point at source documents');
$page = file_get_contents(dirname(__DIR__) . '/public/index.php');
check(str_contains($page, 'id="name-help"') && str_contains($page, 'aria-describedby="name-help"') && str_contains($page, 'never a nickname'), 'Name field has the full-name reminder');
check(str_contains($page, 'id="activities-help"') && str_contains($page, 'aria-describedby="activities-help"') && str_contains($page, 'skills they built') && str_contains($page, 'personal development activities'), 'Activities field has the participation reminder');
check(str_contains($page, "['classic', 'dark']") && str_contains($page, ": 'classic'") && str_contains($page, 'data-set-skin="classic"') && str_contains($page, 'data-set-skin="dark"'), 'Classic is the default appearance');
check(!str_contains($page, 'studio'), 'The page does not offer the Studio appearance');
$skinJs = file_get_contents(dirname(__DIR__) . '/public/skin.js');
check(is_string($skinJs) && str_contains($skinJs, "['classic', 'dark']") && !str_contains($skinJs, 'studio'), 'The appearance switch only offers Classic and Dark');
check(!is_file(dirname(__DIR__) . '/public/studio.css'), 'studio.css is not part of the site');
check(str_contains($page, 'alt="Better Schools"') && str_contains($page, 'assets/logo-classic.webp') && str_contains($page, 'assets/logo-dark.webp'), 'The header includes both Better Schools logos');
foreach (['logo-classic.webp', 'logo-dark.webp'] as $logo) {
    $logoPath = dirname(__DIR__) . '/public/assets/' . $logo;
    check(is_file($logoPath) && filesize($logoPath) < 100000, $logo . ' is an optimised asset');
}
$styles = file_get_contents(dirname(__DIR__) . '/public/app.css');
check(is_string($styles) && str_contains($styles, '#042e5e'), 'Classic accent uses the measured logo navy');
$htaccess = file_get_contents(dirname(__DIR__) . '/public/.htaccess');
$bootstrap = file_get_contents(dirname(__DIR__) . '/private/bootstrap.php');
check(str_contains($htaccess, 'AddType image/webp .webp') && str_contains($htaccess, 'no-store'), 'Static responses keep no-store and a webp type');
check(str_contains($bootstrap, "img-src 'self'"), 'Page policy allows same-origin images');
echo "PASS: bank counts, Unicode names, both single-category cases, all selections, 17 invalid inputs, optional notes, sentence counts.\n";
