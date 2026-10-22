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
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 24000) fail(413, 'The request is too large. Shorten the text and try again.');
$raw = file_get_contents('php://input', false, null, 0, 24001);
if (strlen($raw) > 24000) fail(413, 'The request is too large. Shorten the text and try again.');
$mode = 'generate';
$tweak = null;
try {
    $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('Invalid request.');
    $bank = bank();
    $data = validateInput($input, $bank);
    $mode = $input['mode'] ?? 'generate';
    if (!is_string($mode) || !in_array($mode, ['generate', 'tweak'], true)) throw new InvalidArgumentException('Please check the request.');
    if ($mode === 'tweak') $tweak = validateTweak($input);
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
    $pronounLabels = ['they' => 'they/them/their; use plural verb agreement', 'she' => 'she/her/her', 'he' => 'he/him/his', 'name' => 'Use the supplied name only; do not use personal pronouns'];
    $pronounChoice = $data['pronouns'];
    $data['pronouns'] = $pronounLabels[$pronounChoice];
    $data['length'] = commentLengthTarget($data['length']);
    $supportLabels = commentSelectedSupportLabels($data['supports'], $bank);
    $adjustments = (string) ($data['adjustments'] ?? '');
    $userText = commentNameRule($data['name'], $pronounChoice) . "\n\n" . commentSupportRule($supportLabels, $data['name'], $pronounChoice, $adjustments) . "\n\n" . ($mode === 'tweak' ? commentTweakText($data, $tweak['comment'], $tweak['instruction']) : commentRequestText($data));
    // One provider call is already recorded in the throttle. A single repair retry stays inside that same slot and must finish before the browser aborts at 55 seconds.
    $stage = 'CURL_REQUEST';
    $started = microtime(true);
    $draft = comment_drafter_fetch_draft($model, $key, $instructions, $userText, 45);
    if (isset($draft['error'])) fail($draft['status'], $draft['error']);
    $text = $draft['text'];
    $elapsed = microtime(true) - $started;
    if ((commentNameCount($text, $data['name']) !== 3 || !commentSupportsCovered($text, $supportLabels, $adjustments)) && $elapsed < 20) {
        $retryTimeout = (int) max(8, min(25, floor(50 - $elapsed)));
        $retry = comment_drafter_fetch_draft($model, $key, $instructions, $userText . "\n\nThe previous draft missed the name rule or the supports sentence. Write the comment again. Stay within the selected word range. " . commentNameRule($data['name'], $pronounChoice) . ' ' . commentSupportRule($supportLabels, $data['name'], $pronounChoice, $adjustments), $retryTimeout);
        if (isset($retry['text']) && $retry['text'] !== '') $text = $retry['text'];
    }
    $text = commentRepairSupports($text, $supportLabels, $data['name'], $pronounChoice, $adjustments);
    $text = commentRepairName($text, $data['name'], $pronounChoice);
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

function comment_drafter_fetch_draft(string $model, string $key, string $instructions, string $userText, int $timeout): array {
    foreach (['curl_init', 'curl_setopt_array', 'curl_setopt', 'curl_exec', 'curl_errno', 'curl_getinfo', 'curl_close'] as $curlFunction) {
        if (!function_exists($curlFunction)) return ['status' => 503, 'error' => 'PHP cURL functions are unavailable or disabled by the host. Ask the provider to enable PHP cURL. [CURL_DISABLED]'];
    }
    $payload = [
        'systemInstruction' => ['parts' => [['text' => $instructions]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $userText]]]],
        'generationConfig' => ['maxOutputTokens' => 2048],
    ];
    try {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['status' => 500, 'error' => 'Generation could not be completed. The provider response could not be processed. Ask the host to check outbound HTTPS and proxy filtering. [PROMPT_JSON] Your existing comment is unchanged.'];
    }
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent');
    if ($ch === false) return ['status' => 503, 'error' => 'PHP could not initialise the HTTPS connection. Contact the hosting provider. [CURL_INIT]'];
    $optionsSet = curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
        CURLOPT_POSTFIELDS => $encoded,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout), CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
    if (!$optionsSet) { curl_close($ch); return ['status' => 503, 'error' => 'PHP could not configure the HTTPS connection. Contact the hosting provider. [CURL_OPTIONS]']; }
    // Windows PHP/OpenSSL may have no bundled CA file. Use the OS trust store.
    // Certificate and hostname verification stay enabled on every platform.
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    $body = curl_exec($ch);
    $error = curl_errno($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($error === 28) return ['status' => 504, 'error' => 'The AI provider did not respond in time. Your existing comment is unchanged. Try again shortly.'];
    if (in_array($error, [60, 77], true)) return ['status' => 503, 'error' => 'The server could not verify the AI provider’s HTTPS certificate. Ask the administrator to check PHP’s trusted certificates. Your existing comment is unchanged.'];
    if ($error) return ['status' => 502, 'error' => 'The server could not connect securely to the AI provider. Check the server’s internet connection or proxy settings. Your existing comment is unchanged.'];
    if ($status === 400) {
        $providerError = json_decode((string) $body, true);
        foreach ($providerError['error']['details'] ?? [] as $detail) {
            if (($detail['reason'] ?? '') === 'API_KEY_INVALID') {
                return ['status' => 503, 'error' => 'Google rejected the configured API key. Replace GEMINI_API_KEY in the private configuration or server environment, then try again. Your existing comment is unchanged.'];
            }
        }
    }
    if ($status === 429) return ['status' => 429, 'error' => 'The AI provider’s quota or rate limit has been reached. Try again later or contact the administrator.'];
    if ($status === 404) return ['status' => 503, 'error' => 'The configured Gemini model is unavailable. Ask the administrator to check GEMINI_MODEL and account access.'];
    if (in_array($status, [401, 403], true)) return ['status' => 503, 'error' => 'The AI provider could not authorise this server. Contact the administrator.'];
    if ($status < 200 || $status >= 300) return ['status' => 502, 'error' => 'The AI provider could not generate a comment. Your existing comment is unchanged. Try again later.'];
    try {
        $result = json_decode((string) $body, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['status' => 502, 'error' => 'The provider returned an invalid response. Contact the hosting provider if this continues. [PROVIDER_FORMAT]'];
    }
    if (!is_array($result)) return ['status' => 502, 'error' => 'The provider returned an invalid response. Contact the hosting provider if this continues. [PROVIDER_FORMAT]'];
    $candidate = $result['candidates'][0] ?? [];
    if (!empty($result['promptFeedback']['blockReason']) || ($candidate['finishReason'] ?? '') !== 'STOP') return ['status' => 422, 'error' => 'The provider blocked or did not finish the response. Review the observations and try again.'];
    $text = '';
    foreach ($candidate['content']['parts'] ?? [] as $part) if (empty($part['thought']) && isset($part['text'])) $text .= $part['text'];
    $text = normaliseComment($text);
    if ($text === '' || strlen($text) > 16000) return ['status' => 502, 'error' => 'The AI provider returned no usable comment. Please try again.'];
    return ['text' => $text];
}
