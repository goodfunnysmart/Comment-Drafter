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
echo "PASS: bank counts, Unicode names, both single-category cases, all selections, 14 invalid inputs.\n";
