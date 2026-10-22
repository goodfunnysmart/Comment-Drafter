<?php
declare(strict_types=1);

// Session files live in this directory, outside the document root.
// The file name is a hash of the code. The comments are encrypted with a different key derived from the code.
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME'])) {
    $script = realpath((string) $_SERVER['SCRIPT_FILENAME']);
    $self = realpath(__FILE__);
    if ($script !== false && $self !== false && $script === $self) {
        http_response_code(404);
        header('Cache-Control: no-store');
        exit;
    }
}

const COMMENT_SESSION_LIMIT = 60;
const COMMENT_SESSION_NAME_LIMIT = 100;
const COMMENT_SESSION_TEXT_LIMIT = 8000;
const COMMENT_SESSION_FROM = 'noreply@betterschools.com.au';

class CommentSessionNotFound extends RuntimeException {}
class CommentSessionThrottled extends RuntimeException {
    public function __construct(public string $kind = 'lookup') { parent::__construct($kind); }
}

function commentSessionTimezone(): DateTimeZone {
    static $zone = null;
    if ($zone instanceof DateTimeZone) return $zone;
    try {
        $zone = new DateTimeZone('Australia/Sydney');
    } catch (Exception) {
        $zone = new DateTimeZone('UTC');
    }
    return $zone;
}

function commentSessionWords(): array {
    static $words = null;
    if ($words !== null) return $words;
    $vowels = ['A', 'E', 'U'];
    $consonants = ['B', 'C', 'D', 'F', 'G', 'H', 'J', 'K', 'M', 'N', 'P', 'R', 'S', 'T', 'V', 'W', 'Y', 'Z'];
    $banned = array_flip(['HATE', 'RAPE', 'NUDE', 'PUKE', 'PUBE', 'KAKA', 'TEAT', 'CACA']);
    $words = [];
    foreach ($consonants as $c1) {
        foreach ($vowels as $v1) {
            foreach ($consonants as $c2) {
                foreach ($vowels as $v2) {
                    $word = $c1 . $v1 . $c2 . $v2;
                    if (isset($banned[$word])) continue;
                    $words[] = $word;
                    if (count($words) === 1024) return $words;
                }
            }
        }
    }
    throw new RuntimeException('sessions word list');
}

function commentSessionCode(): string {
    $words = commentSessionWords();
    $pick = static function () use ($words): string {
        return $words[random_int(0, count($words) - 1)];
    };
    $digits = '';
    for ($i = 0; $i < 4; $i++) $digits .= (string) random_int(2, 9);
    return $pick() . '-' . $digits . '-' . $pick() . '-' . $pick();
}

function commentSessionNormalise(string $code): string {
    $code = strtoupper($code);
    $stripped = preg_replace('/[\s\-–—_]+/u', '', $code);
    return is_string($stripped) ? $stripped : '';
}

function commentSessionFormatOk(string $normalised): bool {
    return preg_match('/\A[ABCDEFGHJKMNPQRSTUVWXYZ]{4}[2-9]{4}[ABCDEFGHJKMNPQRSTUVWXYZ]{8}\z/', $normalised) === 1;
}

function commentSessionDisplay(string $normalised): string {
    if (!commentSessionFormatOk($normalised)) return '';
    return substr($normalised, 0, 4) . '-' . substr($normalised, 4, 4) . '-' . substr($normalised, 8, 4) . '-' . substr($normalised, 12, 4);
}

function commentSessionRequireCode(string $code): string {
    $normalised = commentSessionNormalise($code);
    if (!commentSessionFormatOk($normalised)) {
        throw new InvalidArgumentException('Check the session code and try again.');
    }
    return $normalised;
}

function commentSessionFilename(string $normalised): string {
    return hash('sha256', $normalised) . '.json';
}

function commentSessionDirectory(): string {
    $dir = __DIR__ . '/sessions';
    $override = getenv('COMMENT_DRAFTER_SESSIONS');
    if (PHP_SAPI === 'cli' && is_string($override) && $override !== '') {
        if (str_contains($override, '..') || preg_match('#\A/[A-Za-z0-9._/-]+\z#', $override) !== 1) {
            throw new RuntimeException('sessions path');
        }
        $dir = rtrim($override, '/');
    }
    foreach ([$dir, $dir . '/throttle'] as $path) {
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('sessions directory');
        }
        @chmod($path, 0700);
    }
    return $dir;
}

function commentSessionPath(string $normalised): string {
    $name = commentSessionFilename($normalised);
    if (preg_match('/\A[a-f0-9]{64}\.json\z/', $name) !== 1) throw new RuntimeException('sessions name');
    return commentSessionDirectory() . '/' . $name;
}

function commentSessionCreatedMonth(?DateTimeImmutable $now = null): string {
    $now ??= new DateTimeImmutable('now', commentSessionTimezone());
    return $now->setTimezone(commentSessionTimezone())->format('Y-m');
}

function commentSessionWipeDate(string $created): ?DateTimeImmutable {
    if (preg_match('/\A(\d{4})-(\d{2})\z/', $created, $match) !== 1) return null;
    $year = (int) $match[1];
    $month = (int) $match[2];
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) return null;
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', $year, $month), commentSessionTimezone());
    if (!$start instanceof DateTimeImmutable) return null;
    return $start->modify('+2 months');
}

function commentSessionExpired(string $created, ?DateTimeImmutable $now = null): bool {
    $wipe = commentSessionWipeDate($created);
    if ($wipe === null) return false;
    $now ??= new DateTimeImmutable('now', commentSessionTimezone());
    return $now >= $wipe;
}

function commentSessionExpiresLabel(string $created): string {
    $wipe = commentSessionWipeDate($created);
    if ($wipe === null) return '';
    $months = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
    return $wipe->format('j') . ' ' . $months[(int) $wipe->format('n')] . ' ' . $wipe->format('Y');
}

function commentSessionSeal(string $normalised, array $payload, string $created): array {
    $body = json_encode([
        'comments' => array_values($payload['comments'] ?? []),
        'emailSends' => array_values(array_filter($payload['emailSends'] ?? [], 'is_int')),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $salt = random_bytes(16);
    $nonce = random_bytes(12);
    $key = hash_hkdf('sha256', $normalised, 32, 'comment-drafter-session', $salt);
    $tag = '';
    $cipher = openssl_encrypt($body, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if (!is_string($cipher) || strlen($tag) !== 16) throw new RuntimeException('sessions encrypt');
    return [
        'v' => 1,
        'created' => $created,
        'salt' => base64_encode($salt),
        'nonce' => base64_encode($nonce),
        'tag' => base64_encode($tag),
        'ciphertext' => base64_encode($cipher),
    ];
}

function commentSessionOpenPayload(string $normalised, array $wrapper): ?array {
    foreach (['salt', 'nonce', 'tag', 'ciphertext'] as $field) {
        if (!isset($wrapper[$field]) || !is_string($wrapper[$field])) return null;
    }
    $salt = base64_decode($wrapper['salt'], true);
    $nonce = base64_decode($wrapper['nonce'], true);
    $tag = base64_decode($wrapper['tag'], true);
    $cipher = base64_decode($wrapper['ciphertext'], true);
    if (!is_string($salt) || strlen($salt) !== 16 || !is_string($nonce) || strlen($nonce) !== 12 || !is_string($tag) || strlen($tag) !== 16 || !is_string($cipher)) return null;
    $key = hash_hkdf('sha256', $normalised, 32, 'comment-drafter-session', $salt);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if (!is_string($plain)) return null;
    try {
        $decoded = json_decode($plain, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    return is_array($decoded) ? $decoded : null;
}

function commentSessionLength(string $text): int {
    $count = preg_match_all('/./us', $text);
    return $count === false ? strlen($text) : $count;
}

function commentSessionStudentName(string $name): string {
    if (function_exists('commentNormaliseName')) return commentNormaliseName($name);
    $collapsed = preg_replace('/\s+/u', ' ', trim($name));
    return is_string($collapsed) ? $collapsed : '';
}

function commentSessionValidateComments(array $comments): array {
    if (!array_is_list($comments) || count($comments) > COMMENT_SESSION_LIMIT) {
        throw new InvalidArgumentException(count($comments) > COMMENT_SESSION_LIMIT ? 'A session can hold 60 comments.' : 'Check the session comments and try again.');
    }
    $clean = [];
    $seen = [];
    foreach ($comments as $comment) {
        if (!is_array($comment) || !isset($comment['id'], $comment['name'], $comment['text']) || !is_string($comment['id']) || !is_string($comment['name']) || !is_string($comment['text'])) {
            throw new InvalidArgumentException('Check the session comments and try again.');
        }
        if (preg_match('/\A[a-zA-Z0-9]{8,32}\z/', $comment['id']) !== 1 || isset($seen[$comment['id']])) {
            throw new InvalidArgumentException('Check the session comments and try again.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $comment['name']) === 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $comment['text']) === 1) {
            throw new InvalidArgumentException('A comment contains characters that cannot be stored.');
        }
        $name = commentSessionStudentName($comment['name']);
        $text = trim($comment['text']);
        if ($name === '' || commentSessionLength($name) > COMMENT_SESSION_NAME_LIMIT) {
            throw new InvalidArgumentException('Enter the student’s first name.');
        }
        if ($text === '' || commentSessionLength($text) > COMMENT_SESSION_TEXT_LIMIT || preg_match('//u', $text) !== 1) {
            throw new InvalidArgumentException('A comment is empty or too long.');
        }
        $seen[$comment['id']] = true;
        $clean[] = ['id' => $comment['id'], 'name' => $name, 'text' => $text];
    }
    return $clean;
}

function commentSessionWrite(string $normalised, array $payload, string $created): void {
    if (commentSessionWipeDate($created) === null) throw new RuntimeException('sessions created');
    $wrapper = commentSessionSeal($normalised, $payload, $created);
    $json = json_encode($wrapper, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $dir = commentSessionDirectory();
    $path = $dir . '/' . commentSessionFilename($normalised);
    $tmp = $dir . '/.' . hash('sha256', $normalised) . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('sessions write');
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('sessions write');
    }
    @chmod($path, 0600);
}

function commentSessionLocked(string $normalised, callable $fn): mixed {
    $lockPath = commentSessionPath($normalised) . '.lock';
    $handle = fopen($lockPath, 'c');
    if ($handle === false) throw new RuntimeException('sessions lock');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('sessions lock');
        @chmod($lockPath, 0600);
        return $fn();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function commentSessionReadWrapper(string $normalised): array {
    $path = commentSessionPath($normalised);
    if (!is_file($path)) throw new CommentSessionNotFound();
    $raw = file_get_contents($path);
    $wrapper = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($wrapper) || !isset($wrapper['created']) || !is_string($wrapper['created'])) throw new CommentSessionNotFound();
    $wipe = commentSessionWipeDate($wrapper['created']);
    if ($wipe === null) throw new RuntimeException('sessions created');
    if (commentSessionExpired($wrapper['created'])) {
        @unlink($path);
        throw new CommentSessionNotFound();
    }
    $payload = commentSessionOpenPayload($normalised, $wrapper);
    if ($payload === null) throw new CommentSessionNotFound();
    $comments = commentSessionValidateComments(is_array($payload['comments'] ?? null) ? $payload['comments'] : []);
    $sends = [];
    foreach ($payload['emailSends'] ?? [] as $stamp) if (is_int($stamp)) $sends[] = $stamp;
    $created = $wrapper['created'];
    return [
        'code' => commentSessionDisplay($normalised),
        'expires' => $wipe->format('Y-m-d'),
        'expiresLabel' => commentSessionExpiresLabel($created),
        'comments' => $comments,
        'created' => $created,
        'payload' => ['comments' => $comments, 'emailSends' => $sends],
    ];
}

function commentSessionPublicView(array $opened): array {
    return [
        'ok' => true,
        'code' => $opened['code'],
        'expires' => $opened['expires'],
        'expiresLabel' => $opened['expiresLabel'],
        'count' => count($opened['comments']),
        'comments' => $opened['comments'],
    ];
}

function commentSessionCreate(): array {
    $created = commentSessionCreatedMonth();
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $normalised = commentSessionNormalise(commentSessionCode());
        if (is_file(commentSessionPath($normalised))) continue;
        commentSessionWrite($normalised, ['comments' => [], 'emailSends' => []], $created);
        return commentSessionPublicView(commentSessionReadWrapper($normalised));
    }
    throw new RuntimeException('sessions create');
}

function commentSessionOpen(string $code): array {
    $normalised = commentSessionRequireCode($code);
    return commentSessionLocked($normalised, static function () use ($normalised): array {
        return commentSessionPublicView(commentSessionReadWrapper($normalised));
    });
}

function commentSessionStore(string $code, array $comments): array {
    $normalised = commentSessionRequireCode($code);
    $clean = commentSessionValidateComments($comments);
    return commentSessionLocked($normalised, static function () use ($normalised, $clean): array {
        $opened = commentSessionReadWrapper($normalised);
        $opened['payload']['comments'] = $clean;
        commentSessionWrite($normalised, $opened['payload'], $opened['created']);
        return commentSessionPublicView(commentSessionReadWrapper($normalised));
    });
}

function commentSessionDelete(string $code): void {
    $normalised = commentSessionRequireCode($code);
    commentSessionLocked($normalised, static function () use ($normalised): void {
        commentSessionReadWrapper($normalised);
        $path = commentSessionPath($normalised);
        if (is_file($path) && !@unlink($path)) throw new RuntimeException('sessions delete');
    });
}

function commentSessionNoteEmail(string $code): array {
    $normalised = commentSessionRequireCode($code);
    return commentSessionLocked($normalised, static function () use ($normalised): array {
        $opened = commentSessionReadWrapper($normalised);
        $now = time();
        $sends = [];
        foreach ($opened['payload']['emailSends'] as $stamp) {
            if ($stamp > $now - 86400) $sends[] = $stamp;
        }
        if (count($sends) >= 3) throw new CommentSessionThrottled('email');
        $sends[] = $now;
        $opened['payload']['emailSends'] = $sends;
        commentSessionWrite($normalised, $opened['payload'], $opened['created']);
        return commentSessionPublicView(commentSessionReadWrapper($normalised));
    });
}

function commentSessionEmailValid(string $email): bool {
    if (strlen($email) > 254 || preg_match('/[\r\n\x00]/', $email) === 1) return false;
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function commentSessionEmailMessage(string $displayCode, string $expiresLabel): array {
    $body = "Your Comment Drafter session code\n\n" . $displayCode . "\n\n"
        . "Write this down. Without this code the comments cannot be opened.\n\n"
        . 'This session is removed on ' . $expiresLabel . ".\n\n"
        . "This address was used only to send the code. It is not stored.\n";
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nFrom: " . COMMENT_SESSION_FROM;
    return ['subject' => 'Your Comment Drafter session code', 'body' => $body, 'headers' => $headers];
}

function commentSessionThrottle(string $kind, string $ip, int $window, int $limit, bool $record): bool {
    $dir = commentSessionDirectory() . '/throttle';
    $name = hash('sha256', $kind . '|' . $ip) . '.json';
    $path = $dir . '/' . $name;
    $handle = fopen($path, 'c+');
    if ($handle === false) return false;
    try {
        if (!flock($handle, LOCK_EX)) return false;
        rewind($handle);
        $raw = stream_get_contents($handle);
        $now = time();
        $stamps = [];
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($decoded) && isset($decoded['at']) && is_array($decoded['at'])) {
            foreach ($decoded['at'] as $stamp) {
                if (is_int($stamp) && $stamp > $now - $window) $stamps[] = $stamp;
            }
        }
        $allowed = count($stamps) < $limit;
        if ($record && $allowed) {
            $stamps[] = $now;
            $encoded = json_encode(['at' => $stamps], JSON_THROW_ON_ERROR);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $encoded);
            fflush($handle);
        }
        return $allowed;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
        @chmod($path, 0600);
    }
}

function commentSessionFailureAllowed(string $ip): bool {
    return commentSessionThrottle('fail', $ip, 900, 8, false);
}
function commentSessionClearFailures(string $ip): void {
    $path = commentSessionDirectory() . '/throttle/' . hash('sha256', 'fail|' . $ip) . '.json';
    if (is_file($path)) @unlink($path);
}
function commentSessionRecordFailure(string $ip): bool {
    return commentSessionThrottle('fail', $ip, 900, 8, true);
}
function commentSessionEmailAllowed(string $ip, bool $record): bool {
    return commentSessionThrottle('email', $ip, 3600, 5, $record);
}

function commentSessionFileExpired(string $file, DateTimeImmutable $now): bool {
    $raw = @file_get_contents($file);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $created = is_array($decoded) ? ($decoded['created'] ?? null) : null;
    if (is_string($created) && commentSessionWipeDate($created) !== null) return commentSessionExpired($created, $now);
    $mtime = @filemtime($file);
    return $mtime !== false && $mtime < $now->getTimestamp() - 86400 * 100;
}

function commentSessionCleanup(bool $force = false, ?DateTimeImmutable $now = null): int {
    $dir = commentSessionDirectory();
    $now ??= new DateTimeImmutable('now', commentSessionTimezone());
    $handle = fopen($dir . '/.cleanup.lock', 'c');
    if ($handle === false) return 0;
    $blocking = $force ? LOCK_EX : LOCK_EX | LOCK_NB;
    if (!flock($handle, $blocking)) {
        fclose($handle);
        return 0;
    }
    @chmod($dir . '/.cleanup.lock', 0600);
    try {
        $stamp = $dir . '/.cleanup';
        if (!$force && is_file($stamp)) {
            $mtime = filemtime($stamp);
            if ($mtime !== false && ($now->getTimestamp() - $mtime) < 3600) return 0;
        }
        $removed = 0;
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if (is_file($file) && !is_link($file) && commentSessionFileExpired($file, $now) && @unlink($file)) $removed++;
        }
        $cutoff = $now->getTimestamp() - 172800;
        foreach (array_merge(glob($dir . '/throttle/*.json') ?: [], glob($dir . '/*.tmp') ?: [], glob($dir . '/*.lock') ?: []) as $file) {
            if (!is_file($file) || is_link($file)) continue;
            $mtime = filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) @unlink($file);
        }
        @touch($stamp, $now->getTimestamp());
        @chmod($stamp, 0600);
        return $removed;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function commentSessionXml(string $text): string {
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function commentSessionZip(array $files): string {
    $date = getdate();
    $dosTime = (($date['hours'] & 0x1f) << 11) | (($date['minutes'] & 0x3f) << 5) | (intdiv($date['seconds'], 2) & 0x1f);
    $dosDate = (((max(1980, $date['year']) - 1980) & 0x7f) << 9) | (($date['mon'] & 0x0f) << 5) | ($date['mday'] & 0x1f);
    $local = '';
    $central = '';
    $offset = 0;
    foreach ($files as $file) {
        $name = $file['name'];
        $data = $file['data'];
        $crc = hexdec(hash('crc32b', $data));
        $size = strlen($data);
        $nameLen = strlen($name);
        $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLen, 0);
        $local .= $localHeader . $name . $data;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLen, 0, 0, 0, 0, 0, $offset) . $name;
        $offset += strlen($localHeader) + $nameLen + $size;
    }
    $centralLen = strlen($central);
    $count = count($files);
    return $local . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $centralLen, $offset, 0);
}

function commentSessionDocument(array $comments, string $format): string {
    $comments = commentSessionValidateComments($comments);
    if ($format === 'txt') {
        if (!$comments) return "No comments in this session.\n";
        $blocks = [];
        foreach ($comments as $comment) $blocks[] = $comment['name'] . "\n\n" . $comment['text'];
        return implode("\n\n---\n\n", $blocks) . "\n";
    }
    if ($format !== 'docx') throw new InvalidArgumentException('Choose a text or Word download.');
    $paragraphs = '';
    if (!$comments) {
        $paragraphs .= '<w:p><w:r><w:t>No comments in this session.</w:t></w:r></w:p>';
    }
    foreach ($comments as $comment) {
        $paragraphs .= '<w:p><w:pPr><w:pStyle w:val="Heading1"/><w:outlineLvl w:val="0"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="32"/></w:rPr><w:t>' . commentSessionXml($comment['name']) . '</w:t></w:r></w:p>';
        $lines = preg_split("/\r\n|\n|\r/u", $comment['text']) ?: [$comment['text']];
        foreach ($lines as $line) {
            $paragraphs .= '<w:p><w:r><w:t xml:space="preserve">' . commentSessionXml($line) . '</w:t></w:r></w:p>';
        }
    }
    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $paragraphs
        . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr></w:body></w:document>';
    $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';
    return commentSessionZip([
        ['name' => '[Content_Types].xml', 'data' => $types],
        ['name' => '_rels/.rels', 'data' => $rels],
        ['name' => 'word/document.xml', 'data' => $document],
    ]);
}
