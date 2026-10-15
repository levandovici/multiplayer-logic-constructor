#!/usr/bin/env php
<?php
// SMTP/mail() diagnostic — run ON THE SERVER to see exactly which stage
// fails on your host (DNS, TCP connect, TLS, banner, auth, or mail()).
//
//   php ~/bin/test-mail.php you@example.com            probe + send test
//   php ~/bin/test-mail.php you@example.com --mail     mail() only
//   php ~/bin/test-mail.php you@example.com --probe    probe ports, no send
//
// exit 0 when any transport succeeds.

if (php_sapi_name() !== 'cli') {
    exit('CLI only');
}

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once APP_SRC . '/mailer.php';

$args = (array)($argv ?? $_SERVER['argv'] ?? []);
$to = $args[1] ?? '';
$mailOnly = in_array('--mail', $args, true);
$probeOnly = in_array('--probe', $args, true);
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "usage: test-mail.php you@example.com [--mail|--probe]\n");
    exit(1);
}

$host = env_val('SMTP_HOST', '');
$port = (int)env_val('SMTP_PORT', '587');
$user = env_val('SMTP_USER', '');
echo "SMTP_HOST=" . ($host ?: '(empty — mail() path)') . "\n";
echo "SMTP_PORT=$port  SMTP_USER=" . ($user ?: '(empty)') . "\n";
echo "SMTP_FROM=" . env_val('SMTP_FROM', '') . "  EMAIL_FROM=" . env_val('EMAIL_FROM', '') . "\n\n";

function probe_port(string $host, int $port): bool {
    $scheme = $port === 465 ? 'ssl' : 'tcp';
    $errno = 0;
    $errstr = '';
    $t = microtime(true);
    $sock = @fsockopen("$scheme://$host", $port, $errno, $errstr, 10);
    $ms = (int)((microtime(true) - $t) * 1000);
    if (!$sock) {
        echo "  $scheme://$host:$port  CONNECT FAILED after {$ms}ms: $errstr ($errno)\n";
        return false;
    }
    stream_set_timeout($sock, 10);
    $t = microtime(true);
    $banner = fgets($sock, 515);
    $ms = (int)((microtime(true) - $t) * 1000);
    $meta = stream_get_meta_data($sock);
    if ($banner === false) {
        $why = !empty($meta['timed_out']) ? "no banner after {$ms}ms (timeout — blackholed?)" : "closed before banner after {$ms}ms";
        echo "  $scheme://$host:$port  CONNECTED, but $why\n";
        fclose($sock);
        return false;
    }
    echo "  $scheme://$host:$port  banner in {$ms}ms: " . trim($banner) . "\n";
    fwrite($sock, "QUIT\r\n");
    fclose($sock);
    return true;
}

$ok = false;

if (!$mailOnly && $host !== '') {
    echo "-- Probing ports --\n";
    foreach (array_unique([$port, $port === 465 ? 587 : 465]) as $p) {
        probe_port($host, $p);
    }
    echo "\n";
}

if (!$probeOnly) {
    $subject = 'Multiplayer Logic Constructor mail test ' . date('H:i:s');
    $body = "If you see this, outbound mail works from this host.\nSent via test-mail.php";
    echo "-- send_mail() --\n";
    $ok = send_mail($to, $subject, $body);
    echo $ok ? "  ACCEPTED — check $to (and spam)\n" : "  FAILED — see error_log\n";
}

exit($ok || $probeOnly ? 0 : 1);
