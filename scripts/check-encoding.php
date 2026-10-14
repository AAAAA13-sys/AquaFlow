<?php

/**
 * Fails the build when a source file is saved with a UTF-8 byte-order mark.
 *
 * Why this exists: commit 1676aa6 (the attendance removal) rewrote
 * resources/views/employee/home.blade.php through an editor that appended a BOM.
 * The employee portal then served "\xEF\xBB\xBF\n\n\n\n<!DOCTYPE html>".
 * `vendor/bin/pint --test` reports it via its `encoding` fixer, but Pint is not
 * run in CI - those rules are not enforced - and no test inspected the first
 * bytes of a response, so it reached the default branch unnoticed.
 *
 * This is a deliberate, narrow check rather than a whole-repo Pint run: Pint
 * currently reports style drift in existing files, so gating on it would fail
 * the build for reasons unrelated to this regression.
 *
 * Usage:  php scripts/check-encoding.php [path ...]
 * Exit:   0 = clean, 1 = one or more offenders (each printed to stderr).
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/** Directories scanned when no paths are supplied. */
$defaultTargets = [
    'app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'tests',
];

/** Files whose encoding matters and which we are willing to police. */
$extensions = ['php', 'blade.php', 'js', 'cjs', 'mjs', 'css', 'json', 'md', 'yml', 'yaml'];

$requested = array_slice($argv, 1);
$targets = $requested !== []
    ? array_map(static fn (string $p): string => str_starts_with($p, '/') ? $p : $root.'/'.$p, $requested)
    : array_map(static fn (string $d): string => $root.'/'.$d, $defaultTargets);

$matches = static function (string $name): bool {
    $name = strtolower($name);

    foreach (['.blade.php', '.php', '.js', '.cjs', '.mjs', '.css', '.json', '.md', '.yml', '.yaml'] as $suffix) {
        if (str_ends_with($name, $suffix)) {
            return true;
        }
    }

    return false;
};

$offenders = [];
$scanned = 0;

foreach ($targets as $target) {
    if (is_file($target)) {
        $files = [$target];
    } elseif (is_dir($target)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS)
        );
        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $matches($file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }
    } else {
        fwrite(STDERR, "check-encoding: no such path: {$target}\n");
        exit(2);
    }

    foreach ($files as $file) {
        $scanned++;
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            continue;
        }
        $head = fread($handle, 3);
        fclose($handle);

        if ($head === "\xEF\xBB\xBF") {
            $offenders[] = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, substr($file, strlen($root) + 1));
        }
    }
}

if ($offenders !== []) {
    sort($offenders);
    fwrite(STDERR, 'check-encoding: UTF-8 BOM found in '.count($offenders)." file(s):\n");
    foreach ($offenders as $file) {
        fwrite(STDERR, "  - {$file}\n");
    }
    fwrite(STDERR, "Re-save these as UTF-8 without BOM (VS Code: files.encoding = utf8, files.autoGuessEncoding = false).\n");
    exit(1);
}

fwrite(STDOUT, "check-encoding: no byte-order marks in {$scanned} file(s).\n");
exit(0);
