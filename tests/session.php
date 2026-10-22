<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/bootstrap.php';
require dirname(__DIR__) . '/private/sessions.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$dir = sys_get_temp_dir() . '/comment-drafter-sessions-' . bin2hex(random_bytes(4));
putenv('COMMENT_DRAFTER_SESSIONS=' . $dir);
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $path . '/' . $name;
        if (is_dir($child) && !is_link($child)) $removeTree($child);
        else @unlink($child);
    }
    @rmdir($path);
};

try {
    $words = commentSessionWords();
    check(count($words) === 1024 && count(array_unique($words)) === 1024, 'Word list has 1024 distinct entries');
    foreach ($words as $word) {
        check(preg_match('/\A[ABCDEFGHJKMNPQRSTUVWXYZ]{4}\z/', $word) === 1, 'Word uses the unambiguous alphabet');
        check(preg_match('/[ILO]/', $word) !== 1, 'Word contains an ambiguous letter');
    }
    foreach (['HATE', 'RAPE', 'NUDE', 'PUKE', 'PUBE', 'KAKA', 'TEAT', 'CACA'] as $banned) {
        check(!in_array($banned, $words, true), 'Banned word ' . $banned);
    }
    $bits = log((1024 ** 3) * (8 ** 4), 2);
    check($bits >= 40, 'Code entropy is at least 40 bits');
    for ($i = 0; $i < 20; $i++) {
        $code = commentSessionCode();
        check(preg_match('/\A[A-Z]{4}-[2-9]{4}-[A-Z]{4}-[A-Z]{4}\z/', $code) === 1, 'Generated code shape');
        check(preg_match('/[ILO01]/', $code) !== 1, 'Generated code has an ambiguous character');
    }

    $sample = commentSessionCode();
    $normalised = commentSessionNormalise($sample);
    $spaced = strtolower(str_replace('-', ' – ', $sample));
    check(commentSessionNormalise($spaced) === $normalised, 'Spaces, case and dashes normalise together');
    check(commentSessionDisplay($normalised) === $sample, 'Display restores the dashed code');
    check(commentSessionFilename($normalised) === hash('sha256', $normalised) . '.json', 'File name is the SHA-256 of the code');
    check(!str_contains(commentSessionFilename($normalised), substr($sample, 0, 4)), 'File name does not contain the code');

    $zone = commentSessionTimezone();
    check(commentSessionWipeDate('2026-02')->format('Y-m-d') === '2026-04-01', 'February session is wiped on 1 April');
    check(commentSessionWipeDate('2026-12')->format('Y-m-d') === '2027-02-01', 'December session is wiped on 1 February');
    check(commentSessionExpiresLabel('2026-02') === '1 April 2026', 'Expiry label');
    $before = new DateTimeImmutable('2026-03-31 23:59:59', $zone);
    $on = new DateTimeImmutable('2026-04-01 00:00:00', $zone);
    check(!commentSessionExpired('2026-02', $before), 'Still kept through March');
    check(commentSessionExpired('2026-02', $on), 'Removed at the start of April');

    $created = commentSessionCreate();
    check($created['ok'] === true && $created['count'] === 0 && $created['comments'] === [], 'Create starts empty');
    check(preg_match('/\A\d{4}-\d{2}-01\z/', $created['expires']) === 1, 'Expiry is the first of a month');
    $path = $dir . '/' . commentSessionFilename(commentSessionNormalise($created['code']));
    check(is_file($path), 'Session file exists');
    check((fileperms($dir) & 0777) === 0700, 'Session directory is mode 0700');
    check((fileperms($path) & 0777) === 0600, 'Session file is mode 0600');
    $raw = (string) file_get_contents($path);
    $wrapper = json_decode($raw, true);
    check(is_array($wrapper) && ($wrapper['created'] ?? '') === commentSessionCreatedMonth(), 'Created month is stored outside the ciphertext');
    check(commentSessionOpenPayload(hash('sha256', commentSessionNormalise($created['code'])), $wrapper) === null, 'The file hash is not the encryption key');
    $resumed = commentSessionOpen(strtolower(str_replace('-', ' ', $created['code'])));
    check($resumed['code'] === $created['code'], 'Resume ignores case and spaces');

    $stored = commentSessionStore($created['code'], [[
        'id' => 'abcd1234',
        'name' => '  mckenzie ',
        'text' => "Kept a steady pace.\nSESSIONTEXTTOKEN",
    ], [
        'id' => 'efgh5678',
        'name' => 'Sam & Jo',
        'text' => 'Asked for the next step.',
    ]]);
    check($stored['count'] === 2 && $stored['comments'][0]['name'] === 'Mckenzie', 'Stored name is normalised');
    check($stored['comments'][1]['name'] === 'Sam & Jo', 'Punctuation in a name is kept');
    $raw = (string) file_get_contents($path);
    check(!str_contains($raw, 'SESSIONTEXTTOKEN'), 'Comment text is not stored in plaintext');
    check(!str_contains($raw, 'Mckenzie'), 'Student name is not stored in plaintext');
    check(!str_contains($raw, $created['code']), 'Session code is not stored in the file');
    check(!str_contains($raw, '@'), 'No email address is stored');
    $reordered = commentSessionStore($created['code'], array_reverse($stored['comments']));
    check($reordered['comments'][0]['name'] === 'Sam & Jo', 'Order follows the saved list');

    $tooMany = array_map(static fn(int $i): array => ['id' => sprintf('id%06d', $i), 'name' => 'Alex', 'text' => 'A comment.'], range(1, 61));
    $rejected = false;
    try { commentSessionStore($created['code'], $tooMany); } catch (InvalidArgumentException) { $rejected = true; }
    check($rejected, '61 comments are rejected');
    $still = commentSessionOpen($created['code']);
    check($still['count'] === 2, 'A rejected save does not replace the session');
    $long = false;
    try {
        commentSessionStore($created['code'], [['id' => 'abcd1234', 'name' => 'Alex', 'text' => str_repeat('a', 8001)]]);
    } catch (InvalidArgumentException) { $long = true; }
    check($long, 'An over-long comment is rejected');

    $message = commentSessionEmailMessage($created['code'], $created['expiresLabel']);
    check($message['subject'] === 'Your Comment Drafter session code', 'Email subject');
    check(str_contains($message['body'], $created['code']) && str_contains($message['body'], 'not stored') && str_contains($message['body'], $created['expiresLabel']), 'Email body has the code and expiry');
    check(str_contains($message['headers'], 'From: ' . COMMENT_SESSION_FROM) && COMMENT_SESSION_FROM === 'noreply@betterschools.com.au', 'From address is on the site domain');
    check(!preg_match('/[\r\n]/', COMMENT_SESSION_FROM), 'From address is a single line');
    check(commentSessionEmailValid('teacher@example.com') && !commentSessionEmailValid("a@b.com\nBcc: x") && !commentSessionEmailValid('not-an-email'), 'Email validation');
    $noted = commentSessionNoteEmail($created['code']);
    check($noted['count'] === 2, 'Noting an email keeps the comments');
    check(!str_contains((string) file_get_contents($path), '@'), 'Email note does not write an address');

    $txt = commentSessionDocument($stored['comments'], 'txt');
    check(str_starts_with($txt, "Mckenzie\n\n") && str_contains($txt, "SESSIONTEXTTOKEN") && str_contains($txt, "\n\n---\n\nSam & Jo\n\n"), 'Text download is one block per student');
    check(!str_contains($txt, $created['code']), 'Text download does not include the code');
    $docx = commentSessionDocument($stored['comments'], 'docx');
    check(str_starts_with($docx, "PK\x03\x04"), 'Word download is a zip');
    check(substr($docx, -22, 4) === pack('V', 0x06054b50), 'Word download has a zip end record');
    $document = zipEntry($docx, 'word/document.xml');
    check(str_contains($document, '<w:pStyle w:val="Heading1"/>') && str_contains($document, '<w:t>Mckenzie</w:t>'), 'Student name is a heading');
    check(str_contains($document, 'Sam &amp; Jo') && !str_contains($document, 'Sam & Jo'), 'Names are XML-escaped');
    check(str_contains($document, 'SESSIONTEXTTOKEN') && !str_contains($document, $created['code']), 'Word download has the comment and not the code');
    check(zipEntry($docx, '[Content_Types].xml') !== '' && zipEntry($docx, '_rels/.rels') !== '', 'Word package has the required parts');

    $ip = '203.0.113.50';
    for ($i = 0; $i < 8; $i++) check(commentSessionRecordFailure($ip) === true, 'Failure ' . $i . ' is allowed');
    check(commentSessionFailureAllowed($ip) === false, 'The ninth lookup is throttled');
    check(commentSessionFailureAllowed('203.0.113.51') === true, 'Another address is not throttled');
    $throttleName = hash('sha256', 'fail|' . $ip) . '.json';
    $throttleRaw = (string) file_get_contents($dir . '/throttle/' . $throttleName);
    check(!str_contains($throttleRaw, $ip), 'Throttle file does not store the address');
    for ($i = 0; $i < 5; $i++) check(commentSessionEmailAllowed($ip, true) === true, 'Email send ' . $i);
    check(commentSessionEmailAllowed($ip, false) === false, 'Email is limited per address');

    $expiredCode = commentSessionNormalise(commentSessionCode());
    $currentCode = commentSessionNormalise(commentSessionCode());
    commentSessionWrite($expiredCode, ['comments' => [], 'emailSends' => []], '2026-02');
    commentSessionWrite($currentCode, ['comments' => [], 'emailSends' => []], commentSessionCreatedMonth());
    $missing = false;
    try { commentSessionOpen(commentSessionDisplay($expiredCode)); } catch (CommentSessionNotFound) { $missing = true; }
    check($missing && !is_file($dir . '/' . commentSessionFilename($expiredCode)), 'Opening an expired session removes it');
    commentSessionWrite($expiredCode, ['comments' => [], 'emailSends' => []], '2026-02');
    $april = new DateTimeImmutable('2026-04-01 12:00:00', $zone);
    check(commentSessionCleanup(true, $april) === 1, 'Forced cleanup removes the February file');
    check(is_file($dir . '/' . commentSessionFilename($currentCode)), 'Cleanup keeps the current session');
    commentSessionWrite($expiredCode, ['comments' => [], 'emailSends' => []], '2026-02');
    check(commentSessionCleanup(false, $april) === 0 && is_file($dir . '/' . commentSessionFilename($expiredCode)), 'Lazy cleanup waits for the throttle window');
    check(commentSessionCleanup(false, $april->modify('+2 hours')) === 1, 'Lazy cleanup runs again after an hour');

    $output = [];
    $code = 0;
    exec(PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__) . '/private/cleanup-sessions.php'), $output, $code);
    $printed = implode("\n", $output);
    check($code === 0 && str_contains($printed, 'Removed ') && str_contains($printed, 'expired session file(s).'), 'CLI cleanup prints a count');
    check(!str_contains($printed, commentSessionDisplay($currentCode)), 'CLI cleanup does not print a code');

    echo "PASS: session codes, encryption, expiry, limits, downloads, throttle, cleanup\n";
} finally {
    $removeTree($dir);
    putenv('COMMENT_DRAFTER_SESSIONS');
}

function zipEntry(string $zip, string $wanted): string {
    $offset = 0;
    $length = strlen($zip);
    while ($offset + 30 <= $length) {
        $header = unpack('Vsig/vversion/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen', substr($zip, $offset, 30));
        if (!is_array($header) || ($header['sig'] ?? 0) !== 0x04034b50) break;
        $name = substr($zip, $offset + 30, (int) $header['namelen']);
        $data = substr($zip, $offset + 30 + (int) $header['namelen'] + (int) $header['extralen'], (int) $header['csize']);
        if ($name === $wanted) {
            check((int) $header['method'] === 0, 'Zip entry is stored uncompressed');
            check(hexdec(hash('crc32b', $data)) === (int) $header['crc'], 'Zip entry crc');
            return $data;
        }
        $offset += 30 + (int) $header['namelen'] + (int) $header['extralen'] + (int) $header['csize'];
    }
    throw new RuntimeException('Missing zip entry ' . $wanted);
}
