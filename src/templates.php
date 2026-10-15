<?php

// Game templates — cloneable starter programs for "new game from template".
// Each template is a pair of committed artifacts under templates/:
//   <slug>.workspace.json — Blockly serialization → programs.draft_workspace
//   <slug>.ast.json       — canonical AST compiled by bin/build-templates.mjs
//                           → programs.draft_ast AND published v1, so a
//                           template-created game is playable immediately.
// Never hand-edit the .ast.json files — rebuild with:
//   node bin/build-templates.mjs

/** @return array<string,array{name:string,genre:string,desc:string,image:string}> */
function program_templates(): array
{
    return [
        'tictactoe' => ['name' => 'Tic-Tac-Toe', 'genre' => 'Board & turn-based',
            'desc' => 'Turn order, win detection, rejections — the classic starter.',
            'image' => '/assets/marketing/genre-board.png'],
        'clicker' => ['name' => 'Idle Clicker', 'genre' => 'Idle & economy',
            'desc' => 'Claim resources, buy upgrades — the server owns the math.',
            'image' => '/assets/marketing/genre-clicker.png'],
        'quiz' => ['name' => 'Quiz Night', 'genre' => 'Quiz & trivia',
            'desc' => 'Fastest correct answer wins — timing is verified.',
            'image' => '/assets/marketing/genre-quiz.png'],
        'racing' => ['name' => 'Lap Racer', 'genre' => 'Racing & laps',
            'desc' => 'Checkpoints in order, laps and winner verified.',
            'image' => '/assets/marketing/genre-racing.png'],
        'cards' => ['name' => 'Card Duel', 'genre' => 'Card battlers',
            'desc' => 'Hands stay secret; a card must be held to be played.',
            'image' => '/assets/marketing/genre-cards.png'],
        'arena' => ['name' => 'Zone Arena', 'genre' => 'Arena & battle',
            'desc' => 'Shrinking zone on a timer; moves are speed-checked.',
            'image' => '/assets/marketing/genre-arena.png'],
    ];
}

/** @return array{workspace:string,ast:string}|null */
function template_files(string $slug): ?array
{
    if (!preg_match('/^[a-z0-9_]+$/', $slug)) {
        return null;
    }
    $ws = APP_ROOT . '/templates/' . $slug . '.workspace.json';
    $ast = APP_ROOT . '/templates/' . $slug . '.ast.json';
    if (!is_file($ws) || !is_file($ast)) {
        return null;
    }
    $workspace = file_get_contents($ws);
    $astJson = file_get_contents($ast);
    if ($workspace === false || $astJson === false) {
        return null;
    }
    return ['workspace' => $workspace, 'ast' => $astJson];
}
