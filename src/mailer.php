<?php

// Outbound mail. Prefers authenticated SMTP (SMTP_* env) when configured —
// tries the configured port, then the alternate (465 ↔ 587), since hosts
// often silently drop one of them. Falls back to mail() when SMTP_HOST is
// unset OR every SMTP attempt fails (Hostinger shared hosting blocks
// outbound SMTP — the socket connects, then the 220 banner never arrives).
//
//   SMTP_HOST=smtp.hostinger.com
//   SMTP_PORT=465        SSL — or 587 for STARTTLS
//   SMTP_USER=mailbox@yourdomain.com
//   SMTP_PASS=…
//   SMTP_FROM=mailbox@yourdomain.com   (must match the SMTP account)
//   EMAIL_FROM=…                       fallback envelope for mail()

// Returns true when the message was accepted by a relay/mail().
function send_mail(string $to, string $subject, string $body): bool {
    if (env_val('SMTP_HOST', '') !== '') {
        foreach (smtp_ports_to_try() as $port) {
            if (smtp_send($to, $subject, $body, $port)) {
                return true;
            }
        }
        error_log('smtp: all attempts failed, falling back to mail()');
    }
    $from = env_val('SMTP_FROM', env_val('EMAIL_FROM', ''));
    $headers = "From: $from\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    return @mail($to, $subject, $body, $headers);
}

/** Configured port first, then the alternate standard port. */
function smtp_ports_to_try(): array {
    $port = (int)env_val('SMTP_PORT', '587');
    return $port === 465 ? [465, 587] : [$port, $port === 587 ? 465 : 587];
}

function smtp_send(string $to, string $subject, string $body, int $port): bool {
    $host = env_val('SMTP_HOST');
    $user = env_val('SMTP_USER', '');
    $pass = env_val('SMTP_PASS', '');
    $from = env_val('SMTP_FROM', env_val('EMAIL_FROM', $user));

    $scheme = $port === 465 ? 'ssl' : 'tcp';
    $errno = 0;
    $errstr = '';
    $sock = @fsockopen("$scheme://$host", $port, $errno, $errstr, 15);
    if (!$sock) {
        error_log("smtp: connect $host:$port failed: $errstr ($errno)");
        return false;
    }
    stream_set_timeout($sock, 15);

    try {
        smtp_expect($sock, [220], "greeting on $host:$port");

        $ehlo = smtp_cmd($sock, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);

        if ($port !== 465) {
            if (strpos($ehlo, 'STARTTLS') === false) {
                throw new RuntimeException('server does not advertise STARTTLS');
            }
            smtp_cmd($sock, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed');
            }
            smtp_cmd($sock, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);
        }

        smtp_cmd($sock, 'AUTH LOGIN', [334]);
        smtp_cmd($sock, base64_encode($user), [334]);
        smtp_cmd($sock, base64_encode($pass), [235]);

        smtp_cmd($sock, "MAIL FROM:<$from>", [250]);
        smtp_cmd($sock, "RCPT TO:<$to>", [250, 251]);
        smtp_cmd($sock, 'DATA', [354]);

        // Dot-stuffing + CRLF termination per RFC 5321.
        $data = "From: $from\r\nTo: $to\r\nSubject: $subject\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
            . preg_replace('/\r?\n/', "\r\n", $body) . "\r\n.";
        $data = str_replace("\r\n.", "\r\n..", $data); // escape lone dots
        smtp_cmd($sock, $data, [250]);

        smtp_cmd($sock, 'QUIT', [221]);
        return true;
    } catch (Throwable $e) {
        error_log("smtp: $host:$port — " . $e->getMessage());
        return false;
    } finally {
        fclose($sock);
    }
}

/** Send a command (or raw data) and assert the reply code. */
function smtp_cmd($sock, string $line, array $expect): string {
    fwrite($sock, $line . "\r\n");
    return smtp_expect($sock, $expect);
}

/** Read a (possibly multiline) reply; assert its code is in $expect. */
function smtp_expect($sock, array $expect, string $what = 'reply'): string {
    $reply = '';
    while (($line = fgets($sock, 515)) !== false) {
        $reply .= $line;
        // "250-..." continues; "250 ..." terminates the reply.
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }
    $code = (int)substr($reply, 0, 3);
    if (!in_array($code, $expect, true)) {
        if ($reply === '') {
            $meta = stream_get_meta_data($sock);
            $why = !empty($meta['timed_out'])
                ? 'no ' . $what . ' within timeout — connection blackholed (outbound SMTP likely blocked by the host)'
                : 'connection closed before ' . $what;
            throw new RuntimeException($why);
        }
        throw new RuntimeException('unexpected ' . $what . ': ' . trim($reply));
    }
    return $reply;
}
