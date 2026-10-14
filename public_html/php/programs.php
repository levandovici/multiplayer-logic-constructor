<?php
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// Cabinet program management — session + CSRF required. JSON responses.
// Drafts save freely (they may be mid-edit); publish validates strictly —
// the canonical AST must survive Program::fromJson before it can run.
header('Content-Type: application/json');

use Mlc\Engine\EngineError;
use Mlc\Engine\Program;

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please log in']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}
require_csrf_json();

/** @var PDO $pdo */
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$userId = (int)$_SESSION['user_id'];

// Resolve the program AND prove the caller owns its game.
function owned_program($programId, $gameId, $userId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT p.*, g.user_id, g.published_program_id
        FROM programs p JOIN games g ON g.id = p.game_id
        WHERE p.id = ? AND p.game_id = ? AND g.user_id = ?
    ");
    $stmt->execute([(int)$programId, (int)$gameId, $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function post_field($name) {
    $v = $_POST[$name] ?? null;
    return is_string($v) ? $v : '';
}

// AST size guard — MEDIUMTEXT is generous but a browser POST shouldn't be.
$astRaw = post_field('ast');
if (strlen($astRaw) > 2 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'Program is too large']);
    exit;
}

// ====================== VALIDATE (no write) ======================
if ($action === 'validate') {
    try {
        $program = Program::fromJson($astRaw);
        $desc = $program->describe();
        echo json_encode([
            'success' => true,
            'summary' => [
                'triggers' => count($desc['triggers']),
                'functions' => count($desc['functions']),
            ],
        ]);
    } catch (EngineError $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ---------- everything below mutates a program ----------
$program = owned_program((int)($_POST['program_id'] ?? 0), (int)($_POST['game_id'] ?? 0), $userId);
if (!$program) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Program not found']);
    exit;
}

// ====================== SAVE DRAFT ======================
if ($action === 'save') {
    $workspace = post_field('workspace');
    // A draft may be mid-edit — save whatever the editor produced, but
    // report validation so the UI can surface it immediately.
    $valid = true;
    $err = null;
    try {
        Program::fromJson($astRaw);
    } catch (EngineError $e) {
        $valid = false;
        $err = $e->getMessage();
    }

    $pdo->prepare("UPDATE programs SET draft_workspace = ?, draft_ast = ? WHERE id = ?")
        ->execute([$workspace, $astRaw, (int)$program['id']]);

    echo json_encode([
        'success' => true,
        'valid' => $valid,
        'error' => $err,
    ]);
    exit;
}

// ====================== PUBLISH ======================
if ($action === 'publish') {
    if (($retry = rate_limit('program_publish', 30, 3600)) > 0) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => "Publish rate limited, try again in {$retry}s"]);
        exit;
    }

    try {
        Program::fromJson($astRaw); // strict — publish is the gate
    } catch (EngineError $e) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Program is invalid: ' . $e->getMessage()]);
        exit;
    }

    $workspace = post_field('workspace');
    $checksum = hash('sha256', $astRaw);

    $pdo->beginTransaction();
    try {
        // Idempotent publish: same checksum as the latest version = no-op.
        $latest = $pdo->prepare("
            SELECT id, version, checksum FROM program_versions
            WHERE program_id = ? ORDER BY version DESC LIMIT 1
        ");
        $latest->execute([(int)$program['id']]);
        $row = $latest->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['checksum'] === $checksum) {
            $versionId = (int)$row['id'];
            $versionNum = (int)$row['version'];
        } else {
            $versionNum = ($row ? (int)$row['version'] : 0) + 1;
            $pdo->prepare("
                INSERT INTO program_versions (program_id, version, ast, checksum, published_by)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([(int)$program['id'], $versionNum, $astRaw, $checksum, $userId]);
            $versionId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare("UPDATE games SET published_program_id = ? WHERE id = ?")
            ->execute([$versionId, (int)$program['game_id']]);
        $pdo->prepare("UPDATE programs SET draft_workspace = ?, draft_ast = ? WHERE id = ?")
            ->execute([$workspace, $astRaw, (int)$program['id']]);

        $pdo->commit();
        echo json_encode(['success' => true, 'version' => $versionNum, 'version_id' => $versionId]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('program publish error: ' . redact_log_value($e->getMessage()));
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// ====================== ROLLBACK ======================
if ($action === 'rollback') {
    $versionId = (int)($_POST['version_id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT v.id, v.version FROM program_versions v
        WHERE v.id = ? AND v.program_id = ?
    ");
    $stmt->execute([$versionId, (int)$program['id']]);
    $ver = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ver) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Version not found']);
        exit;
    }
    $pdo->prepare("UPDATE games SET published_program_id = ? WHERE id = ?")
        ->execute([(int)$ver['id'], (int)$program['game_id']]);
    echo json_encode(['success' => true, 'version' => (int)$ver['version']]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action']);
