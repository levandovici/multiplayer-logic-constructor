#!/usr/bin/env php
<?php
// Builds SDK zips into downloads/ (release artifacts — e.g. for attaching
// to a GitHub release). The cabinet download endpoint builds the same zips
// in-memory at request time, so the site always serves current sources.
//   php bin/package-sdks.php            → downloads/mlc-*-sdk-*.zip
//   php bin/package-sdks.php --list     → list SDK slugs

if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
// Packaging is pure file IO — no DB needed (bootstrap connects eagerly).
require_once dirname(__DIR__) . '/src/sdk_package.php';
if (!defined('APP_ROOT')) { define('APP_ROOT', dirname(__DIR__)); }

$out = APP_ROOT . '/downloads';
if (!is_dir($out)) { mkdir($out, 0755, true); }

$args = (array)($argv ?? $_SERVER['argv'] ?? []);
if (($args[1] ?? '') === '--list') {
    foreach (MLC_SDKS as $slug => $title) { echo "$slug  $title\n"; }
    exit(0);
}

foreach (MLC_SDKS as $slug => $title) {
    $zip = sdk_zip($slug);
    if ($zip === null) {
        fwrite(STDERR, "!! $slug: missing sources, skipped\n");
        continue;
    }
    $file = $out . '/' . sdk_zip_name($slug);
    file_put_contents($file, $zip);
    printf("%-8s %-22s %6.1f KB\n", $slug, basename($file), strlen($zip) / 1024);
}
echo "done — zips in downloads/\n";
