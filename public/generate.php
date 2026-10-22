<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require __DIR__ . '/private_path.php';
comment_drafter_require_private(__DIR__);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST'); fail(405, 'Use POST to generate a comment.');
}
if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') !== 'same-origin') fail(403, 'Use the app from its own page.');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
if ($origin !== '' && $origin !== $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '')) fail(403, 'Use the app from its own page.');
if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail(403, 'Your security token has expired. Copy any draft, then reload the page.');
if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') fail(415, 'Send JSON data.');
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 24000) fail(413, 'The request is too large. Shorten the observations.');
$raw = file_get_contents('php://input', false, null, 0, 24001);
if (strlen($raw) > 24000) fail(413, 'The request is too large.');
try {
    $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('Invalid request.');
    $data = validateInput($input, bank());
} catch (JsonException | InvalidArgumentException $e) { fail(422, $e instanceof JsonException ? 'Invalid JSON request.' : $e->getMessage()); }
// Security metadata only. The session lock serialises the throttle check.
$now = time();
$recent = array_values(array_filter($_SESSION['requests'] ?? [], fn($t) => $t > $now - 600));
if (count($recent) >= 10 || ($recent && $now - end($recent) < 5)) {
    header('Retry-After: 60'); fail(429, 'Please wait a minute before generating again. Limit: 10 requests per 10 minutes per session.');
}
$recent[] = $now;
$_SESSION['requests'] = $recent;
session_write_close();
$stage = 'CONFIG';
try {
    $config = is_file(PRIVATE_DIR . '/config.php') ? require PRIVATE_DIR . '/config.php' : [];
    if (!is_array($config)) fail(503, 'Private config.php must return a settings array. [CONFIG_FORMAT]');
    $key = getenv('GEMINI_API_KEY') ?: ($config['GEMINI_API_KEY'] ?? '');
    $model = getenv('GEMINI_MODEL') ?: ($config['GEMINI_MODEL'] ?? '');
    if (!is_string($key) || !is_string($model)) fail(503, 'GEMINI_API_KEY and GEMINI_MODEL must be text values. [CONFIG_TYPES]');
    if (!$key || $key === 'REPLACE_WITH_YOUR_KEY' || !$model) fail(503, 'Generation is not configured. Ask the administrator to set the private Gemini API key and model.');
    if (!preg_match('/\A[a-zA-Z0-9._-]+\z/', $model) || !function_exists('curl_init')) fail(503, 'The server configuration needs attention. Contact the administrator.');
    $stage = 'PROMPT';
    $instructionsPath = PRIVATE_DIR . '/instructions.txt';
    if (!is_readable($instructionsPath)) fail(503, 'The private instructions.txt file is missing or unreadable. Check its upload location and permissions. [PROMPT_FILE]');
    $instructions = file_get_contents($instructionsPath);
    if ($instructions === false || trim($instructions) === '') fail(503, 'The private instructions.txt file is empty or unreadable. [PROMPT_FILE]');
    $ranges = ['short' => '5 sentences', 'standard' => '6 sentences', 'detailed' => '7 sentences'];
    $pronouns = ['they' => 'they/them/their; use plural verb agreement', 'she' => 'she/her/her', 'he' => 'he/him/his', 'name' => 'Use the supplied name only; do not use personal pronouns'];
    $data['pronouns'] = $pronouns[$data['pronouns']];
    $data['length'] = $ranges[$data['length']] . '; the whole comment must be 5–7 sentences';
    $payload = [
        'systemInstruction' => ['parts' => [['text' => $instructions]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => commentRequestText($data)]]]],
        'generationConfig' => ['maxOutputTokens' => 2048],
    ];
    $stage = 'CURL_SETUP';
    foreach (['curl_init', 'curl_setopt_array', 'curl_setopt', 'curl_exec', 'curl_errno', 'curl_getinfo', 'curl_close'] as $curlFunction) {
        if (!function_exists($curlFunction)) fail(503, 'PHP cURL functions are unavailable or disabled by the host. Ask the provider to enable PHP cURL. [CURL_DISABLED]');
    }
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent');
    if ($ch === false) fail(503, 'PHP could not initialise the HTTPS connection. Contact the hosting provider. [CURL_INIT]');
    $optionsSet = curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
    if (!$optionsSet) fail(503, 'PHP could not configure the HTTPS connection. Contact the hosting provider. [CURL_OPTIONS]');
    // Windows PHP/OpenSSL may have no bundled CA file. Use the OS trust store.
    // Certificate and hostname verification stay enabled on every platform.
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    $stage = 'CURL_REQUEST';
    $body = curl_exec($ch);
    $error = curl_errno($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $stage = 'CURL_RESULT';
    if ($error === 28) fail(504, 'The AI provider did not respond in time. Your existing comment is unchanged. Try again shortly.');
    if (in_array($error, [60, 77], true)) fail(503, 'The server could not verify the AI provider’s HTTPS certificate. Ask the administrator to check PHP’s trusted certificates. Your existing comment is unchanged.');
    if ($error) fail(502, 'The server could not connect securely to the AI provider. Check the server’s internet connection or proxy settings. Your existing comment is unchanged.');
    if ($status === 400) {
        $providerError = json_decode($body, true);
        foreach ($providerError['error']['details'] ?? [] as $detail) {
            if (($detail['reason'] ?? '') === 'API_KEY_INVALID') {
                fail(503, 'Google rejected the configured API key. Replace GEMINI_API_KEY in the private configuration or server environment, then try again. Your existing comment is unchanged.');
            }
        }
    }
    if ($status === 429) fail(429, 'The AI provider’s quota or rate limit has been reached. Try again later or contact the administrator.');
    if ($status === 404) fail(503, 'The configured Gemini model is unavailable. Ask the administrator to check GEMINI_MODEL and account access.');
    if (in_array($status, [401,403], true)) fail(503, 'The AI provider could not authorise this server. Contact the administrator.');
    if ($status < 200 || $status >= 300) fail(502, 'The AI provider could not generate a comment. Your existing comment is unchanged. Try again later.');
    $stage = 'PROVIDER_JSON';
    $result = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($result)) fail(502, 'The provider returned an invalid response. Contact the hosting provider if this continues. [PROVIDER_FORMAT]');
    $stage = 'PROVIDER_OUTPUT';
    $candidate = $result['candidates'][0] ?? [];
    if (!empty($result['promptFeedback']['blockReason']) || ($candidate['finishReason'] ?? '') !== 'STOP') fail(422, 'The provider blocked or did not finish the response. Review the observations and try again.');
    $text = '';
    foreach ($candidate['content']['parts'] ?? [] as $part) if (empty($part['thought']) && isset($part['text'])) $text .= $part['text'];
    $text = normaliseComment($text);
    if ($text === '' || strlen($text) > 16000) fail(502, 'The AI provider returned no usable comment. Please try again.');
    $sentences = commentSentenceCount($text);
    if ($sentences < 5 || $sentences > 7) fail(422, 'The draft was not 5–7 sentences, so it was not kept. Your existing comment is unchanged. Please try again.');
    reply(200, ['ok' => true, 'comment' => $text]);
} catch (Throwable $e) {
    // Never log upstream bodies, credentials or student data.
    // Only fixed diagnostic codes leave the server; never expose exception messages.
    $kind = $e instanceof ParseError ? 'SYNTAX' : ($e instanceof JsonException ? 'JSON' : ($e instanceof TypeError ? 'TYPE' : ($e instanceof Error ? 'RUNTIME' : 'ERROR')));
    $hint = match ($stage) {
        'CONFIG' => 'Check private/config.php syntax and ensure it returns a settings array.',
        'PROMPT' => 'Check that private/instructions.txt was uploaded and PHP can read it.',
        'CURL_SETUP', 'CURL_REQUEST', 'CURL_RESULT' => 'Ask the host to check PHP cURL, its HTTPS support and disabled functions.',
        'PROVIDER_JSON', 'PROVIDER_OUTPUT' => 'The provider response could not be processed. Ask the host to check outbound HTTPS and proxy filtering.',
        default => 'Contact the administrator.',
    };
    fail(500, 'Generation could not be completed. ' . $hint . ' [' . $stage . '_' . $kind . '] Your existing comment is unchanged.');
}
