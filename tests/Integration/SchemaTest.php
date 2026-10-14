<?php

namespace Mlc\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

// Integration suite — needs MySQL/MariaDB (docker compose up -d mysql).
//   TEST_DB_HOST=127.0.0.1 TEST_DB_NAME=test_mlc TEST_DB_USER=test TEST_DB_PASS=test
//   composer test:integration
class SchemaTest extends TestCase
{
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('TEST_DB_HOST') ?: null;
        if (!$host) {
            self::markTestSkipped('TEST_DB_* env vars not set — needs MySQL (docker compose up -d mysql)');
        }
        self::$pdo = new PDO(
            sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                $host,
                getenv('TEST_DB_NAME') ?: 'test_mlc'
            ),
            getenv('TEST_DB_USER') ?: 'test',
            getenv('TEST_DB_PASS') ?: 'test'
        );
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->exec("SET time_zone = '+00:00'");

        // Fresh schema each run.
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            self::$pdo->exec("DROP TABLE IF EXISTS `$t`");
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        foreach (split_sql_statements(file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql')) as $stmt) {
            self::$pdo->exec($stmt);
        }
    }

    public function testAllTablesExist(): void
    {
        $expected = [
            'users', 'login_codes', 'games', 'players', 'rooms', 'room_players',
            'player_updates', 'rate_limits', 'programs', 'program_versions',
            'room_state', 'player_state', 'game_state', 'scheduled_events', 'action_log',
        ];
        $actual = self::$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($expected as $t) {
            $this->assertContains($t, $actual, "missing table $t");
        }
    }

    public function testGamePlayerRoomFlow(): void
    {
        $pdo = self::$pdo;

        $pdo->exec("INSERT INTO users (email) VALUES ('dev@example.com')");
        $uid = (int)$pdo->lastInsertId();

        $pdo->exec("INSERT INTO games (user_id, name, api_key) VALUES ($uid, 'Test Game', 'k1')");
        $gid = (int)$pdo->lastInsertId();

        $pdo->exec("INSERT INTO players (game_id, name, token) VALUES ($gid, 'alice', 'tok1')");
        $pid = (int)$pdo->lastInsertId();

        $rid = generate_uuid();
        $pdo->exec("INSERT INTO rooms (room_id, game_id, name, host_player_id) VALUES ('$rid', $gid, 'r1', $pid)");
        $pdo->exec("INSERT INTO room_players (player_id, room_id, game_id, player_name, is_host, seat) VALUES ($pid, '$rid', $gid, 'alice', 1, 0)");

        $pdo->exec("INSERT INTO room_state (room_id) VALUES ('$rid')");
        $ver = $pdo->query("SELECT state_version FROM room_state WHERE room_id = '$rid'")->fetchColumn();
        $this->assertSame('0', (string)$ver);

        $pdo->exec("INSERT INTO player_updates (update_id, room_id, game_id, target_player_id, type, data)
                    VALUES ('" . generate_uuid() . "', '$rid', $gid, $pid, 'welcome', '{\"msg\":\"hi\"}')");
        $pending = $pdo->query("SELECT COUNT(*) FROM player_updates WHERE target_player_id = $pid AND status = 'pending'")->fetchColumn();
        $this->assertSame('1', (string)$pending);
    }

    public function testPublishPointerIsUpdatable(): void
    {
        $pdo = self::$pdo;
        $pdo->exec("INSERT INTO users (email) VALUES ('dev2@example.com')");
        $uid = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO games (user_id, name, api_key) VALUES ($uid, 'G2', 'k2')");
        $gid = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO programs (game_id, name) VALUES ($gid, 'main')");
        $progId = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO program_versions (program_id, version, ast, checksum) VALUES ($progId, 1, '{}', 'abc')");
        $verId = (int)$pdo->lastInsertId();
        $pdo->exec("UPDATE games SET published_program_id = $verId WHERE id = $gid");

        $got = $pdo->query("SELECT published_program_id FROM games WHERE id = $gid")->fetchColumn();
        $this->assertSame((string)$verId, (string)$got);
    }
}
