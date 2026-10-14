<?php

// Lifecycle → engine bridge. Room/player wrapper endpoints call
// fire_lifecycle_event() after their DB operation; when the game has a
// published program the engine runs the matching trigger (on_room_created,
// on_player_join, on_player_leave, on_match_start, on_match_end).
//
// M1: the engine lands in M2/M3 — for now this resolves the published
// program and returns early if absent, so the wrapper endpoints are already
// wired at the correct seam.

/**
 * @param string $event   Trigger event name (on_player_join, …)
 * @param array  $context { game, room?, player?, payload? } DB rows/values
 */
function fire_lifecycle_event($event, array $context) {
    $game = $context['game'] ?? null;
    if (!is_array($game) || empty($game['published_program_id'])) {
        return null; // no logic published — lifecycle op already applied
    }

    // M3: hand off to Mlc\Engine\Runtime::fire($event, $context).
    return null;
}
