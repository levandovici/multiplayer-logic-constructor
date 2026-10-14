<?php

// Lifecycle → engine bridge. Room/player wrapper endpoints call
// fire_lifecycle_event() after their DB operation, INSIDE the same
// transaction, so a `reject` block vetoes the whole thing atomically
// (e.g. on_player_join can refuse a join).
//
// Returns the Runtime::fire() result array when the engine ran
// ({result: ok|rejected|error|noop, ...}), or null when the game has no
// published program. On 'rejected'/'error' the surrounding transaction is
// already rolled back — callers must NOT commit afterwards; respond with
// the reason instead.

use Mlc\Engine\Runtime;

/**
 * @param string $event   Trigger event name (on_player_join, …)
 * @param array  $context { game, room_id?, player?, payload?, key? }
 */
function fire_lifecycle_event($event, array $context) {
    $game = $context['game'] ?? null;
    if (!is_array($game) || empty($game['published_program_id'])) {
        return null; // no logic published — lifecycle op stands
    }

    global $pdo;
    $runtime = new Runtime($pdo);
    return $runtime->fire(
        $game,
        isset($context['room_id']) ? (string)$context['room_id'] : null,
        $context['player'] ?? null,
        $event,
        is_array($context['payload'] ?? null) ? $context['payload'] : [],
        isset($context['key']) ? (string)$context['key'] : null
    );
}

// Respond appropriately when a lifecycle engine run rejected or errored.
// The engine already rolled back the shared transaction. Returns true when
// it answered (caller must stop), false when the result was ok/noop/null.
function lifecycle_vetoed($result) {
    if (!is_array($result)) {
        return false;
    }
    if ($result['result'] === 'rejected') {
        respond_json(['success' => false, 'error' => $result['reason'] ?? 'Rejected by game logic'], 403);
    }
    if ($result['result'] === 'error') {
        respond_json(['success' => false, 'error' => 'Game logic error'], 500);
    }
    return false;
}
