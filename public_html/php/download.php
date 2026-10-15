<?php
// Session-gated SDK downloads — registered/logged-in cabinet users only.
//   GET /php/download.php?sdk=unity → mlc-unity-sdk-<version>.zip
// Zips are built from current sdks/ sources on each request — never stale.
// GET is read-only so no CSRF check is needed.
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
send_security_headers();

if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$slug = get_param('sdk') ?? '';
if (!isset(MLC_SDKS[$slug])) {
    respond_json(['success' => false, 'error' => 'Unknown SDK'], 404);
}

$zip = sdk_zip($slug);
if ($zip === null) {
    respond_json(['success' => false, 'error' => 'SDK not available'], 404);
}

$filename = sdk_zip_name($slug);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($zip));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $zip;
