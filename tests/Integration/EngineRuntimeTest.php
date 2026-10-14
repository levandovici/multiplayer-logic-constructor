<?php

namespace Mlc\Tests\Integration;

use Mlc\Engine\Runtime;
use PDO;
use PHPUnit\Framework\TestCase;

// End-to-end engine test against real MySQL: seed a game with the
// tic-tac-toe fixture published, create a room + two members, fire real
// actions through Runtime and assert transactional semantics.
//   TEST_DB_HOST=127.0.0.1 TEST_DB_NAME=test_mlc … composer test:integration
class EngineRuntimeTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static int $gameId;
    private static string $roomId;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('TEST_DB_HOST') ?: null;
        if (!$host) {
            self::markTestSkipped('TEST_DB_* env vars not set — needs MySQL');
        }
        self::$pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, getenv('TEST_DB_NAME') ?: 'test_mlc'),
            getenv('TEST_DB_USER') ?: 'test',
            getenv('TEST_DB_PASS') ?: 'test'
        );
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->exec("SET time_zone = '+00:00'");

        // Fresh schema.
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            self::$pdo->exec("DROP TABLE IF EXISTS `$t`");
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        foreach (split_sql_statements(file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql')) as $stmt) {
            self::$pdo->exec($stmt);
        }

        $pdo = self::$pdo;
        $pdo->exec("INSERT INTO users (email) VALUES ('rt@example.com')");
        $uid = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO games (user_id, name, api_key) VALUES ($uid, 'RT', 'rtkey')");
        self::$gameId = (int)$pdo->lastInsertId();

        $ast = file_get_contents(dirname(__DIR__) . '/Fixtures/programs/tictactoe.json');
        $pdo->exec("INSERT INTO programs (game_id, name) VALUES (" . self::$gameId . ", 'main')");
        $progId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO program_versions (program_id, version, ast, checksum) VALUES (?, 1, ?, ?)");
        $stmt->execute([$progId, $ast, hash('sha256', (string)$ast)]);
        $pdo->exec("UPDATE games SET published_program_id = " . (int)$pdo->lastInsertId() . " WHERE id = " . self::$gameId);

        // two players + a room both seated in
        $pdo->exec("INSERT INTO players (game_id, name, token) VALUES (" . self::$gameId . ", 'alice', 'ta')");
        $alice = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO players (game_id, name, token) VALUES (" . self::$gameId . ", 'bob', 'tb')");
        $bob = (int)$pdo->lastInsertId();
        self::$roomId = generate_uuid();
        $pdo->exec("INSERT INTO rooms (room_id, game_id, name, host_player_id, status) VALUES ('" . self::$roomId . "', " . self::$gameId . ", 'r', $alice, 'playing')");
        $pdo->exec("INSERT INTO room_players (player_id, room_id, game_id, player_name, is_host, seat) VALUES
                    ($alice, '" . self::$roomId . "', " . self::$gameId . ", 'alice', 1, 0),
                    ($bob, '" . self::$roomId . "', " . self::$gameId . ", 'bob', 0, 1)");

        // Fire the join lifecycle — same as the room.php join wrapper — so
        // on_player_join assigns each player their symbol (X / O).
        $rt = new Runtime($pdo);
        $game = $pdo->query("SELECT * FROM games WHERE id = " . self::$gameId)->fetch(PDO::FETCH_ASSOC);
        foreach (['alice', 'bob'] as $name) {
            $stmt = $pdo->prepare("SELECT * FROM players WHERE game_id = ? AND name = ?");
            $stmt->execute([self::$gameId, $name]);
            $rt->fire($game, self::$roomId, $stmt->fetch(PDO::FETCH_ASSOC), 'on_player_join', [], null);
        }
    }

    private function game(): array
    {
        return self::$pdo->query("SELECT * FROM games WHERE id = " . self::$gameId)->fetch(PDO::FETCH_ASSOC);
    }

    private function player(string $name): array
    {
        $stmt = self::$pdo->prepare("SELECT * FROM players WHERE game_id = ? AND name = ?");
        $stmt->execute([self::$gameId, $name]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function roomState(): array
    {
        $json = self::$pdo->query("SELECT state FROM room_state WHERE room_id = '" . self::$roomId . "'")->fetchColumn();
        return $json === false ? [] : (json_decode((string)$json, true) ?: []);
    }

    public function testMoveWritesStateAndUpdatesAtomically(): void
    {
        $rt = new Runtime(self::$pdo);
        $res = $rt->fire($this->game(), self::$roomId, $this->player('alice'), 'on_player_action', ['x' => 0, 'y' => 0], 'move');

        $this->assertSame('ok', $result = $res['result']);
        $this->assertSame(1, $res['version']);

        $state = $this->roomState();
        $this->assertSame('X', $state['board'][0]);
        $this->assertSame(1, $state['moves']);

        $updates = self::$pdo->query("SELECT COUNT(*) FROM player_updates WHERE room_id = '" . self::$roomId . "' AND type = 'board'")->fetchColumn();
        $this->assertSame('2', (string)$updates); // broadcast to both members

        $log = self::$pdo->query("SELECT result FROM action_log WHERE room_id = '" . self::$roomId . "' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->assertSame('ok', $log);
    }

    public function testWrongTurnRejectedAndNothingPersisted(): void
    {
        // Alice just moved (test above) — turn flipped to seat 1, so a second
        // Alice move is the wrong-turn case.
        $before = self::$pdo->query("SELECT COUNT(*) FROM player_updates")->fetchColumn();
        $rt = new Runtime(self::$pdo);
        $res = $rt->fire($this->game(), self::$roomId, $this->player('alice'), 'on_player_action', ['x' => 1, 'y' => 0], 'move');

        $this->assertSame('rejected', $res['result']);
        $this->assertSame('not your turn', $res['reason']);

        // rollback: no state row update, no updates queued
        $this->assertSame($before, self::$pdo->query("SELECT COUNT(*) FROM player_updates")->fetchColumn());
        $state = $this->roomState();
        $this->assertSame(1, $state['moves']); // still just alice's move

        $log = self::$pdo->query("SELECT result FROM action_log WHERE room_id = '" . self::$roomId . "' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->assertSame('rejected', $log);
    }

    public function testPlayerScopePersistsAcrossRuns(): void
    {
        $rt = new Runtime(self::$pdo);
        $rt->fire($this->game(), self::$roomId, $this->player('alice'), 'on_player_join', [], null);
        $symbol = self::$pdo->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(state, '$.symbol')) FROM player_state WHERE player_id = " . (int)$this->player('alice')['id'])->fetchColumn();
        $this->assertSame('X', $symbol);
    }
}
