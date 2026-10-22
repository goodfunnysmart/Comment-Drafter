<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require __DIR__ . '/private_path.php';
comment_drafter_require_private(__DIR__);
require PRIVATE_DIR . '/sessions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    fail(405, 'Use POST for a session.');
}
if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') !== 'same-origin') fail(403, 'Use the app from its own page.');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
if ($origin !== '' && $origin !== $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '')) fail(403, 'Use the app from its own page.');
if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail(403, 'Your security token has expired. Copy any draft, then reload the page.');
if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') fail(415, 'Send JSON data.');
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 600000) fail(413, 'The request is too large.');
$raw = file_get_contents('php://input', false, null, 0, 600001);
if (!is_string($raw) || strlen($raw) > 600000) fail(413, 'The request is too large.');
session_write_close();

try {
    try { commentSessionCleanup(); } catch (Throwable) {}
    try {
        $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new InvalidArgumentException('Invalid JSON request.');
    }
    if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('Please check the request.');
    $action = $input['action'] ?? '';
    if (!is_string($action) || !in_array($action, ['create', 'resume', 'save', 'clear', 'email', 'download'], true)) {
        throw new InvalidArgumentException('Please check the request.');
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($ip) || $ip === '') $ip = 'unknown';
    if ($action === 'create') {
        if (!commentSessionThrottle('create', $ip, 3600, 30, true)) {
            header('Retry-After: 900');
            fail(429, 'Please wait before starting another session.');
        }
        reply(200, commentSessionCreate());
    }
    $code = $input['code'] ?? '';
    if (!is_string($code)) throw new InvalidArgumentException('Check the session code and try again.');
    if ($action === 'resume') {
        $opened = commentSessionOpen($code);
        commentSessionClearFailures($ip);
        reply(200, $opened);
    }
    if ($action === 'save') {
        if (!isset($input['comments']) || !is_array($input['comments'])) throw new InvalidArgumentException('Check the session comments and try again.');
        $saved = commentSessionStore($code, $input['comments']);
        commentSessionClearFailures($ip);
        reply(200, $saved);
    }
    if ($action === 'clear') {
        commentSessionDelete($code);
        commentSessionClearFailures($ip);
        reply(200, ['ok' => true, 'cleared' => true]);
    }
    if ($action === 'download') {
        $format = $input['format'] ?? '';
        if (!is_string($format)) throw new InvalidArgumentException('Choose a text or Word download.');
        $opened = commentSessionOpen($code);
        commentSessionClearFailures($ip);
        $bytes = commentSessionDocument($opened['comments'], $format);
        header('X-Content-Type-Options: nosniff');
        if ($format === 'docx') {
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            header('Content-Disposition: attachment; filename="session-comments.docx"');
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="session-comments.txt"');
        }
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }
    $email = $input['email'] ?? '';
    if (!is_string($email) || !commentSessionEmailValid($email)) throw new InvalidArgumentException('Enter a valid email address.');
    if (!commentSessionEmailAllowed($ip, false)) throw new CommentSessionThrottled('email');
    $view = commentSessionNoteEmail($code);
    commentSessionClearFailures($ip);
    if (!commentSessionEmailAllowed($ip, true)) throw new CommentSessionThrottled('email');
    $message = commentSessionEmailMessage($view['code'], $view['expiresLabel']);
    $sent = @mail($email, $message['subject'], $message['body'], $message['headers']);
    if ($sent !== true) fail(502, 'The code could not be emailed. Copy it instead.');
    reply(200, ['ok' => true, 'sent' => true]);
} catch (CommentSessionThrottled $e) {
    header('Retry-After: 900');
    fail(429, $e->kind === 'email'
        ? 'That code was emailed recently. Copy it instead, or try again later.'
        : 'Too many attempts. Wait a few minutes, then try the code again.');
} catch (CommentSessionNotFound) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($ip) || $ip === '') $ip = 'unknown';
    commentSessionRecordFailure($ip);
    if (!commentSessionFailureAllowed($ip)) {
        header('Retry-After: 900');
        fail(429, 'Too many attempts. Wait a few minutes, then try the code again.');
    }
    fail(404, 'That session code was not found, or it has expired.');
} catch (InvalidArgumentException $e) {
    fail(422, $e->getMessage());
} catch (Throwable) {
    fail(500, 'The session could not be saved. Ask the administrator to check the private sessions folder.');
}
