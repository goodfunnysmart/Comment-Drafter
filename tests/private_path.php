<?php
// Run: php tests/private_path.php
declare(strict_types=1);
require dirname(__DIR__) . '/public/private_path.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function make_tree(string $root): void
{
    foreach ([
        $root . '/project/public',
        $root . '/project/private',
        $root . '/home/public_html/comments/private',
        $root . '/home/public_html/private',
        $root . '/home/comments-private',
        $root . '/www/project/public',
        $root . '/www/project/private',
        $root . '/hint-target',
        $root . '/env-target',
        $root . '/lonely/public',
    ] as $dir) {
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create ' . $dir);
        }
    }
    foreach ([
        $root . '/project/private/bootstrap.php',
        $root . '/home/comments-private/bootstrap.php',
        $root . '/home/public_html/private/bootstrap.php',
        $root . '/home/public_html/comments/private/bootstrap.php',
        $root . '/www/project/private/bootstrap.php',
        $root . '/hint-target/bootstrap.php',
        $root . '/env-target/bootstrap.php',
    ] as $file) {
        file_put_contents($file, "<?php\n");
    }
}

function rm_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $path = $item->getPathname();
        $item->isDir() ? rmdir($path) : unlink($path);
    }
    rmdir($dir);
}

$previous = getenv('COMMENT_DRAFTER_PRIVATE');
putenv('COMMENT_DRAFTER_PRIVATE');
$root = sys_get_temp_dir() . '/cd-path-' . bin2hex(random_bytes(4));

try {
    $repoBootstrap = realpath(dirname(__DIR__) . '/private/bootstrap.php');
    $resolvedRepo = comment_drafter_private_bootstrap(dirname(__DIR__) . '/public');
    check($resolvedRepo === comment_drafter_normalise_path($repoBootstrap), 'Repository layout resolves to private/bootstrap.php');
    check(str_contains((string) file_get_contents($resolvedRepo), 'function bank('), 'Resolved file is the application bootstrap');

    check(comment_drafter_path_is_web_exposed(
        '/home/user/public_html/comments/private/bootstrap.php',
        '/home/user/public_html/comments'
    ), 'Bootstrap inside the front-controller directory is exposed');
    check(comment_drafter_path_is_web_exposed(
        '/home/user/public_html/private/bootstrap.php',
        '/home/user/public_html/comments'
    ), 'A private directory under public_html is exposed');
    check(!comment_drafter_path_is_web_exposed(
        '/home/user/comments-private/bootstrap.php',
        '/home/user/public_html/comments'
    ), 'comments-private beside public_html is outside the document root');
    check(!comment_drafter_path_is_web_exposed(
        '/tmp/public_html_backup/private/bootstrap.php',
        '/tmp/public_html_backup/public'
    ), 'public_html must match a whole path component');
    check(comment_drafter_path_is_web_exposed(
        'C:\\home\\public_html\\private\\bootstrap.php',
        'C:\\home\\public_html\\comments'
    ), 'Windows separators still detect public_html');

    make_tree($root);
    $local = comment_drafter_private_bootstrap($root . '/project/public');
    check($local === comment_drafter_normalise_path(realpath($root . '/project/private/bootstrap.php')), 'Local sibling private directory');

    $deployed = comment_drafter_private_bootstrap($root . '/home/public_html/comments');
    check($deployed === comment_drafter_normalise_path(realpath($root . '/home/comments-private/bootstrap.php')), 'Deployed comments-private directory');

    $www = comment_drafter_private_bootstrap($root . '/www/project/public');
    check($www === comment_drafter_normalise_path(realpath($root . '/www/project/private/bootstrap.php')), 'A www directory name is not treated as the document root');

    file_put_contents(
        $root . '/home/public_html/comments/private-path.php',
        "<?php return " . var_export($root . '/hint-target', true) . ";\n"
    );
    $hinted = comment_drafter_private_bootstrap($root . '/home/public_html/comments');
    check($hinted === comment_drafter_normalise_path(realpath($root . '/hint-target/bootstrap.php')), 'Deploy hint is used when it is outside the document root');

    file_put_contents(
        $root . '/project/public/private-path.php',
        "<?php return " . var_export($root . '/home/public_html/comments/private', true) . ";\n"
    );
    $ignoredHint = comment_drafter_private_bootstrap($root . '/project/public');
    check($ignoredHint === $local, 'A hint inside public_html is ignored');

    putenv('COMMENT_DRAFTER_PRIVATE=' . $root . '/env-target');
    $fromEnv = comment_drafter_private_bootstrap($root . '/project/public');
    check($fromEnv === comment_drafter_normalise_path(realpath($root . '/env-target/bootstrap.php')), 'Environment override wins');
    putenv('COMMENT_DRAFTER_PRIVATE=' . $root . '/home/public_html/private');
    $ignoredEnv = comment_drafter_private_bootstrap($root . '/project/public');
    check($ignoredEnv === $local, 'An environment path inside public_html is ignored');
    putenv('COMMENT_DRAFTER_PRIVATE');

    try {
        comment_drafter_private_bootstrap($root . '/lonely/public');
        throw new RuntimeException('Missing private files should fail');
    } catch (RuntimeException $e) {
        check($e->getMessage() === 'Private application files are not available.', 'Missing files use a generic error');
        check(!str_contains($e->getMessage(), $root), 'The error does not reveal the search path');
    }

    foreach (['index.php', 'generate.php'] as $entry) {
        $source = (string) file_get_contents(dirname(__DIR__) . '/public/' . $entry);
        check(str_contains($source, 'comment_drafter_require_private(__DIR__)'), $entry . ' uses the shared resolver');
        check(!str_contains($source, "dirname(__DIR__) . '/private/bootstrap.php'"), $entry . ' does not hard-code the sibling path');
    }

    echo "PASS: private path resolution for local, deployed, hint, environment, and exposed paths.\n";
} finally {
    if ($previous === false) {
        putenv('COMMENT_DRAFTER_PRIVATE');
    } else {
        putenv('COMMENT_DRAFTER_PRIVATE=' . $previous);
    }
    rm_tree($root);
}
