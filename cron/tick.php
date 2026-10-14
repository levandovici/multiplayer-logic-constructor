#!/usr/bin/php
<?php

// tick.php — scheduled-events worker.
//
// Claims due rows from scheduled_events and re-enters the logic engine in
// the recorded room/player context, firing matching `on_timer` triggers.
//
// Recommended frequency: every 1 minute (Hostinger cron minimum) — timers
// are therefore accurate to roughly a minute; document that to developers.
//
//   CLI:      php cron/tick.php [--verbose]
//   Web cron: /cron/tick.php?cron_token=<CRON_TOKEN>  (404 without token)

require_once dirname(__DIR__) . '/src/bootstrap.php';

if (php_sapi_name() !== 'cli') {
    $cronToken = env_val('CRON_TOKEN', '');
    $providedCron = get_param('cron_token') ?? '';
    if ($cronToken === '' || !hash_equals($cronToken, $providedCron)) {
        http_response_code(404);
        exit;
    }
}

$TICK_VERBOSE = php_sapi_name() === 'cli'
    && (in_array('--verbose', $argv ?? [], true) || in_array('-v', $argv ?? [], true));

function tick_log($msg) {
    global $TICK_VERBOSE;
    error_log('tick: ' . $msg);
    if ($TICK_VERBOSE) {
        echo $msg . "\n";
    }
}

// Claim a bounded batch of due events. UPDATE … status='running' acts as a
// poor-man's queue lock — two overlapping cron runs can't double-fire.
/** @var PDO $pdo */
$claim = $pdo->prepare("
    UPDATE scheduled_events
    SET status = 'running', attempts = attempts + 1
    WHERE status = 'pending' AND run_at <= NOW()
    ORDER BY run_at
    LIMIT 50
");
$claim->execute();
$claimed = $claim->rowCount();

tick_log("claimed $claimed due event(s)");

if ($claimed > 0) {
    $due = $pdo->query("
        SELECT * FROM scheduled_events
        WHERE status = 'running'
        ORDER BY run_at
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);

    $runtime = new Mlc\Engine\Runtime($pdo);
    $gameStmt = $pdo->prepare("SELECT * FROM games WHERE id = ?");
    $playerStmt = $pdo->prepare("SELECT * FROM players WHERE id = ?");
    $doneStmt = $pdo->prepare("UPDATE scheduled_events SET status = ?, processed_at = NOW() WHERE event_id = ?");

    foreach ($due as $event) {
        try {
            $gameStmt->execute([(int)$event['game_id']]);
            $game = $gameStmt->fetch(PDO::FETCH_ASSOC);
            if (!$game) {
                $doneStmt->execute(['failed', $event['event_id']]);
                tick_log("{$event['event_id']}: game {$event['game_id']} gone — failed");
                continue;
            }

            $player = null;
            if ($event['player_id'] !== null) {
                $playerStmt->execute([(int)$event['player_id']]);
                $player = $playerStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            $payload = $event['payload'] !== null
                ? (json_decode((string)$event['payload'], true) ?: [])
                : [];

            // If the room is gone the FK cascade already removed the row —
            // a missing room mid-flight just means the trigger runs nowhere.
            $result = $runtime->fire(
                $game, $event['room_id'], $player,
                'on_timer', $payload, $event['trigger_name']
            );

            // error → failed (retryable via re-claim tooling); ok, rejected
            // and noop (no published program) all count as processed.
            $status = $result['result'] === 'error' ? 'failed' : 'done';
            $doneStmt->execute([$status, $event['event_id']]);
            tick_log("{$event['event_id']} ({$event['trigger_name']}): {$result['result']}" .
                ($result['reason'] ? " — {$result['reason']}" : ''));
        } catch (Throwable $e) {
            $doneStmt->execute(['failed', $event['event_id']]);
            error_log('tick: event ' . $event['event_id'] . ' crashed: ' . redact_log_value($e->getMessage()));
        }
    }
}
