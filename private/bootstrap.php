<?php
declare(strict_types=1);
define('PRIVATE_DIR', __DIR__);
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('report_builder');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict', 'path' => '/',
]);
session_start();
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function bank(): array {
    return json_decode(file_get_contents(__DIR__ . '/bank.json'), true, 512, JSON_THROW_ON_ERROR);
}
function reply(int $status, array $data): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function fail(int $status, string $message): never { reply($status, ['ok' => false, 'error' => $message]); }
function validateInput(array $input, array $bank): array {
    $out = [];
    foreach (['name' => 100, 'subject' => 160, 'observations' => 4000] as $key => $limit) {
        if (!isset($input[$key]) || !is_string($input[$key]) || !preg_match('//u', $input[$key])) {
            throw new InvalidArgumentException('Please check the text fields.');
        }
        $out[$key] = trim($input[$key]);
        if (preg_match_all('/./us', $out[$key]) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $input[$key])) {
            throw new InvalidArgumentException('A text field is too long or contains invalid characters.');
        }
    }
    if ($out['name'] === '') throw new InvalidArgumentException('Enter the student’s first name.');
    foreach (['pronouns' => ['they', 'she', 'he', 'name'], 'length' => ['short', 'standard', 'detailed']] as $key => $options) {
        if (!isset($input[$key]) || !is_string($input[$key]) || !in_array($input[$key], $options, true)) {
            throw new InvalidArgumentException('Choose valid pronouns and a comment length.');
        }
        $out[$key] = $input[$key];
    }
    foreach (['strengths', 'nextSteps'] as $kind) {
        $ids = $input[$kind] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > count($bank[$kind])) {
            throw new InvalidArgumentException('Please check the selected descriptors.');
        }
        $out[$kind] = [];
        foreach ($ids as $id) {
            if (!is_string($id) || !isset($bank[$kind][$id]) || isset($out[$kind][$id])) {
                throw new InvalidArgumentException('Unknown or duplicate descriptor selected. Reload the page and try again.');
            }
            $out[$kind][$id] = $bank[$kind][$id];
        }
    }
    if (!$out['strengths'] && !$out['nextSteps']) throw new InvalidArgumentException('Choose at least one strength or next step.');
    return $out;
}
