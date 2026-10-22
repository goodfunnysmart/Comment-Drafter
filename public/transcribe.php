<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require __DIR__ . '/private_path.php';
comment_drafter_require_private(__DIR__);

const COMMENT_DRAFTER_AUDIO_BYTES = 5000000;
const COMMENT_DRAFTER_AUDIO_BODY = 7000000;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    fail(405, 'Use POST to transcribe a recording.');
}
if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') !== 'same-origin') fail(403, 'Use the app from its own page.');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
if ($origin !== '' && $origin !== $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '')) fail(403, 'Use the app from its own page.');
if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail(403, 'Your security token has expired. Copy any draft, then reload the page.');
if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') fail(415, 'Send JSON data.');
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > COMMENT_DRAFTER_AUDIO_BODY) fail(413, 'The recording is too large. Try a shorter clip.');
$raw = file_get_contents('php://input', false, null, 0, COMMENT_DRAFTER_AUDIO_BODY + 1);
if ($raw === false || strlen($raw) > COMMENT_DRAFTER_AUDIO_BODY) fail(413, 'The recording is too large. Try a shorter clip.');

$mime = '';
$audio = '';
try {
    $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('The recording could not be read.');
    $mime = comment_drafter_audio_mime($input['mime'] ?? '');
    $audio = comment_drafter_audio_payload($input['audio'] ?? null);
} catch (JsonException) {
    fail(422, 'The recording could not be read.');
} catch (InvalidArgumentException $e) {
    $status = str_contains($e->getMessage(), 'too large') ? 413 : (str_contains($e->getMessage(), 'format') ? 415 : 422);
    fail($status, $e->getMessage());
}
unset($raw, $input);

$now = time();
$recent = array_values(array_filter($_SESSION['transcriptions'] ?? [], fn($t) => is_int($t) && $t > $now - 600));
if (count($recent) >= 10 || ($recent && $now - end($recent) < 3)) {
    header('Retry-After: 60');
    fail(429, 'Please wait a minute before dictating again. Limit: 10 transcriptions per 10 minutes.');
}
$recent[] = $now;
$_SESSION['transcriptions'] = $recent;
session_write_close();

try {
    $config = is_file(PRIVATE_DIR . '/config.php') ? require PRIVATE_DIR . '/config.php' : [];
    if (!is_array($config)) fail(503, 'Dictation is not configured. Ask the administrator to set the private Gemini API key and model.');
    $key = getenv('GEMINI_API_KEY') ?: ($config['GEMINI_API_KEY'] ?? '');
    $model = getenv('GEMINI_MODEL') ?: ($config['GEMINI_MODEL'] ?? '');
    if (!is_string($key) || !is_string($model) || !$key || $key === 'REPLACE_WITH_YOUR_KEY' || !$model) {
        fail(503, 'Dictation is not configured. Ask the administrator to set the private Gemini API key and model.');
    }
    if (!preg_match('/\A[a-zA-Z0-9._-]+\z/', $model) || !function_exists('curl_init')) {
        fail(503, 'The server configuration needs attention. Contact the administrator.');
    }
    $result = comment_drafter_transcribe($model, $key, $mime, $audio);
    unset($audio, $key);
    $durationMs = $result['duration_ms'];
    error_log('comment-drafter transcribe duration_ms=' . $durationMs . ' status=' . $result['status']);
    if (isset($result['error'])) reply($result['status'], ['ok' => false, 'error' => $result['error'], 'duration_ms' => $durationMs]);
    reply(200, ['ok' => true, 'text' => $result['text'], 'duration_ms' => $durationMs]);
} catch (Throwable) {
    unset($audio);
    error_log('comment-drafter transcribe status=500');
    fail(500, 'Dictation could not be completed. Try again.');
}

function comment_drafter_audio_mime(mixed $mime): string {
    if (!is_string($mime)) throw new InvalidArgumentException('This recording format is not supported.');
    $base = strtolower(trim(explode(';', $mime, 2)[0]));
    $map = [
        'audio/webm' => 'audio/webm',
        'audio/mp4' => 'audio/mp4',
        'audio/ogg' => 'audio/ogg',
        'audio/wav' => 'audio/wav',
        'audio/wave' => 'audio/wav',
        'audio/x-wav' => 'audio/wav',
        'audio/mpeg' => 'audio/mpeg',
        'audio/mp3' => 'audio/mp3',
        'audio/mpga' => 'audio/mpeg',
        'audio/m4a' => 'audio/mp4',
        'audio/x-m4a' => 'audio/mp4',
        'audio/aac' => 'audio/aac',
        'audio/x-aac' => 'audio/aac',
        'audio/flac' => 'audio/flac',
    ];
    if (!isset($map[$base])) throw new InvalidArgumentException('This recording format is not supported.');
    return $map[$base];
}

function comment_drafter_audio_payload(mixed $audio): string {
    if (!is_string($audio)) throw new InvalidArgumentException('The recording could not be read.');
    $audio = preg_replace('/\s+/', '', $audio) ?? '';
    if ($audio === '') throw new InvalidArgumentException('The recording was empty. Try again.');
    if (strlen($audio) > 6800000) throw new InvalidArgumentException('The recording is too large. Try a shorter clip.');
    if (!preg_match('/\A[A-Za-z0-9+\/]+=*\z/', $audio)) throw new InvalidArgumentException('The recording could not be read.');
    $binary = base64_decode($audio, true);
    if ($binary === false) throw new InvalidArgumentException('The recording could not be read.');
    $bytes = strlen($binary);
    if (function_exists('sodium_memzero')) {
        try { sodium_memzero($binary); } catch (Throwable) {}
    }
    unset($binary);
    if ($bytes < 100) throw new InvalidArgumentException('The recording was too short. Try again.');
    if ($bytes > COMMENT_DRAFTER_AUDIO_BYTES) throw new InvalidArgumentException('The recording is too large. Try a shorter clip.');
    return $audio;
}

function comment_drafter_transcribe(string $model, string $key, string $mime, string $audio): array {
    foreach (['curl_init', 'curl_setopt_array', 'curl_setopt', 'curl_exec', 'curl_errno', 'curl_getinfo', 'curl_close'] as $curlFunction) {
        if (!function_exists($curlFunction)) return ['status' => 503, 'duration_ms' => 0, 'error' => 'Dictation is not available on this server. Contact the administrator.'];
    }
    $payload = [
        'contents' => [[
            'role' => 'user',
            'parts' => [
                ['text' => 'Transcribe this audio verbatim in Australian English. Include punctuation. Do not add commentary. If there is no speech, return an empty string.'],
                ['inlineData' => ['mimeType' => $mime, 'data' => $audio]],
            ],
        ]],
        'generationConfig' => ['maxOutputTokens' => 2048],
    ];
    try {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } catch (JsonException) {
        return ['status' => 422, 'duration_ms' => 0, 'error' => 'The recording could not be read.'];
    }
    unset($payload, $audio);
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent');
    if ($ch === false) return ['status' => 503, 'duration_ms' => 0, 'error' => 'Dictation is not available on this server. Contact the administrator.'];
    $started = microtime(true);
    $optionsSet = curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
        CURLOPT_POSTFIELDS => $encoded,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    unset($encoded);
    if (!$optionsSet) {
        curl_close($ch);
        return ['status' => 503, 'duration_ms' => 0, 'error' => 'Dictation is not available on this server. Contact the administrator.'];
    }
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    $body = curl_exec($ch);
    $error = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $durationMs = (int) round((microtime(true) - $started) * 1000);
    if ($error === 28) return ['status' => 504, 'duration_ms' => $durationMs, 'error' => 'Dictation did not finish in time. Try a shorter clip.'];
    if (in_array($error, [60, 77], true)) return ['status' => 503, 'duration_ms' => $durationMs, 'error' => 'The server could not verify the AI provider’s HTTPS certificate. Ask the administrator to check PHP’s trusted certificates.'];
    if ($error) return ['status' => 502, 'duration_ms' => $durationMs, 'error' => 'Dictation could not reach the AI provider. Try again shortly.'];
    if ($status === 400) {
        $providerError = json_decode((string) $body, true);
        foreach ($providerError['error']['details'] ?? [] as $detail) {
            if (($detail['reason'] ?? '') === 'API_KEY_INVALID') {
                unset($body, $providerError);
                return ['status' => 503, 'duration_ms' => $durationMs, 'error' => 'Dictation is not configured. Ask the administrator to check the Gemini API key.'];
            }
        }
        unset($providerError);
    }
    if ($status === 429) {
        unset($body);
        return ['status' => 429, 'duration_ms' => $durationMs, 'error' => 'The AI provider’s quota or rate limit has been reached. Try again later.'];
    }
    if ($status === 404 || in_array($status, [401, 403], true)) {
        unset($body);
        return ['status' => 503, 'duration_ms' => $durationMs, 'error' => 'Dictation is not available with the current server configuration. Contact the administrator.'];
    }
    if ($status < 200 || $status >= 300) {
        unset($body);
        return ['status' => 502, 'duration_ms' => $durationMs, 'error' => 'Dictation failed. Try again.'];
    }
    try {
        $result = json_decode((string) $body, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        unset($body);
        return ['status' => 502, 'duration_ms' => $durationMs, 'error' => 'Dictation failed. Try again.'];
    }
    unset($body);
    if (!is_array($result)) return ['status' => 502, 'duration_ms' => $durationMs, 'error' => 'Dictation failed. Try again.'];
    $candidate = $result['candidates'][0] ?? [];
    if (!empty($result['promptFeedback']['blockReason']) || ($candidate['finishReason'] ?? '') !== 'STOP') {
        $message = ($candidate['finishReason'] ?? '') === 'MAX_TOKENS'
            ? 'The recording was too long to transcribe. Try a shorter clip.'
            : 'The recording could not be transcribed. Try again.';
        return ['status' => 422, 'duration_ms' => $durationMs, 'error' => $message];
    }
    $text = '';
    foreach ($candidate['content']['parts'] ?? [] as $part) {
        if (empty($part['thought']) && isset($part['text']) && is_string($part['text'])) $text .= $part['text'];
    }
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if (strlen($text) > 16000) return ['status' => 422, 'duration_ms' => $durationMs, 'error' => 'The transcription was too long. Try a shorter clip.'];
    return ['status' => 200, 'duration_ms' => $durationMs, 'text' => $text];
}
