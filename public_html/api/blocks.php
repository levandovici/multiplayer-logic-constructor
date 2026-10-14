<?php
// GET api/blocks.php — the block/op catalogue as server-side truth.
// Editors, SDK generators, and docs all read this; an op absent here is
// unexecutable by construction (the engine validates against the same
// whitelist). Game-token auth — it's developer-facing metadata.
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
api_headers();

use Mlc\Engine\Ops;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_json(['success' => false, 'error' => 'GET only'], 405);
}
api_auth_game();

respond_json([
    'success' => true,
    'catalogue' => [
        'version' => 1,
        'statements' => Ops::STATEMENTS,
        'expressions' => Ops::EXPRESSIONS,
        'events' => Ops::EVENTS,
        'keyed_events' => Ops::KEYED_EVENTS,
        'scopes' => ['local', 'player', 'room', 'game'],
        'limits' => [
            'step_budget' => 10000,
            'loop_budget' => 10000,
            'max_program_nodes' => 5000,
            'max_depth' => 64,
        ],
    ],
]);
