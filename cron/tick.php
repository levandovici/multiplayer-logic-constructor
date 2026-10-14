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

    foreach ($due as $event) {
        // M4: Mlc\Engine\Runtime::fireTimer($event) — load published program,
        // lock room, run matching on_timer triggers, mark done/failed.
        // Until the engine lands, events are parked back to 'pending' so a
        // deployed cron never silently drops them.
        $pdo->prepare("UPDATE scheduled_events SET status = 'pending' WHERE event_id = ?")
            ->execute([$event['event_id']]);
        tick_log("parked {$event['event_id']} ({$event['trigger_name']}) — engine pending M4");
    }
}
