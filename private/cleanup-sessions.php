<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}

require __DIR__ . '/sessions.php';

try {
    $removed = commentSessionCleanup(true);
} catch (Throwable) {
    fwrite(STDERR, "Session cleanup failed.\n");
    exit(1);
}

fwrite(STDOUT, 'Removed ' . $removed . " expired session file(s).\n");
