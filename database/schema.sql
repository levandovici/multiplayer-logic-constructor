-- ============================================================================
-- Multiplayer Logic Constructor — complete schema (single source of truth).
-- Import directly (phpMyAdmin / mysql CLI) or run: php bin/migrate.php init
-- No migration files — this file IS the schema.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- Developer accounts (cabinet users). Passwordless: sign-in is email codes.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NULL COMMENT 'Reserved — sign-in is email codes only',
    is_verified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_codes (
    email VARCHAR(190) PRIMARY KEY,
    code_hash CHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Games: one row per developer project. api_key authenticates game clients;
-- the private key (hashed + encrypted) gates admin/destructive endpoints.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS games (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    api_key VARCHAR(36) UNIQUE NOT NULL,
    api_private_key VARCHAR(36) NULL,
    api_private_key_hash CHAR(64) NULL,
    api_private_key_prefix VARCHAR(8) NULL,
    api_private_key_suffix VARCHAR(8) NULL COMMENT 'Last 4 chars — UI masks as firstN...lastN without storing plaintext',
    api_private_key_enc VARCHAR(255) NULL COMMENT 'Private key AES-256-GCM under TOKEN_PEPPER — owner-retrievable, unreadable in a DB dump',
    published_program_id INT NULL COMMENT 'program_versions.id the runtime executes; NULL = no logic published',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_api_key (api_key),
    INDEX idx_api_private_key_hash (api_private_key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Players: end-users of a game. token is the bearer credential presented as
-- X-Player-Token; token_hash is the HMAC lookup form when TOKEN_PEPPER is set.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS players (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    token VARCHAR(36) UNIQUE NULL,
    token_hash CHAR(64) NULL,
    token_enc VARCHAR(255) NULL COMMENT 'Token AES-256-GCM under TOKEN_PEPPER — lets login return the same credential without storing plaintext',
    player_data JSON DEFAULT (JSON_OBJECT()) COMMENT 'Free-form client/profile data the developer controls',
    is_online BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    last_heartbeat TIMESTAMP NULL,
    last_logout TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    UNIQUE KEY uq_game_name (game_id, name),
    INDEX idx_token (token),
    INDEX idx_token_hash (token_hash),
    INDEX idx_last_heartbeat (last_heartbeat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Rooms: a match/lobby instance. Player-visible game state lives in
-- room_state/player_state (logic tables below), not here.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rooms (
    room_id VARCHAR(36) PRIMARY KEY,
    game_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    host_player_id INT NULL,
    password VARCHAR(255) NULL,
    join_token VARCHAR(32) NULL COMMENT 'Invite token — joining with it bypasses the password',
    max_players INT DEFAULT 6,
    status ENUM('lobby','playing','ended') NOT NULL DEFAULT 'lobby',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_room_host FOREIGN KEY (host_player_id) REFERENCES players(id) ON DELETE SET NULL,
    INDEX idx_join_token (join_token),
    INDEX idx_game_active (game_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS room_players (
    player_id INT NOT NULL PRIMARY KEY COMMENT 'A player is in at most one room',
    room_id VARCHAR(36) NOT NULL,
    game_id INT NOT NULL,
    player_name VARCHAR(100) NOT NULL,
    is_host BOOLEAN DEFAULT FALSE,
    is_online BOOLEAN DEFAULT TRUE,
    seat INT NULL COMMENT 'Optional seat/turn-order index managed by program logic',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_heartbeat TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    player_data JSON DEFAULT (JSON_OBJECT()),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE,
    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    INDEX idx_room_id (room_id),
    INDEX idx_game_id (game_id),
    INDEX idx_last_heartbeat (last_heartbeat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- player_updates: the server→client outbox. Engine `send.*` blocks insert
-- rows; clients poll api/player.php?action=updates (Node WS push = post-MVP).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS player_updates (
    update_id VARCHAR(36) PRIMARY KEY,
    room_id VARCHAR(36) NOT NULL,
    game_id INT NOT NULL,
    from_player_id INT NULL COMMENT 'NULL = system/program origin',
    target_player_id INT NOT NULL,
    type VARCHAR(50) NOT NULL COMMENT 'Program-defined event type, e.g. state_changed, chat, match_ended',
    data JSON NOT NULL,
    status ENUM('pending','delivered','read') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    delivered_at TIMESTAMP NULL,
    INDEX idx_target_updates (target_player_id, status, created_at),
    INDEX idx_room_updates (room_id, created_at),
    FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE,
    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_pu_from FOREIGN KEY (from_player_id) REFERENCES players(id) ON DELETE CASCADE,
    CONSTRAINT fk_pu_target FOREIGN KEY (target_player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fixed-window rate limiting (see rate_limit() in src/bootstrap.php).
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket VARCHAR(64) NOT NULL,
    rate_key VARCHAR(190) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (bucket, rate_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- Logic platform tables
-- ============================================================================

-- Programs: the user's block program. draft_workspace is the Blockly JSON;
-- draft_ast is the compiled canonical program the executor runs.
CREATE TABLE IF NOT EXISTS programs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    draft_workspace MEDIUMTEXT NULL COMMENT 'Blockly workspace JSON (editor state)',
    draft_ast MEDIUMTEXT NULL COMMENT 'Compiled canonical program JSON',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    UNIQUE KEY uq_game_program (game_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable published snapshots. Runtime always executes the version pointed
-- to by games.published_program_id — publish/rollback is one UPDATE on games.
CREATE TABLE IF NOT EXISTS program_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    program_id INT NOT NULL,
    version INT NOT NULL,
    ast MEDIUMTEXT NOT NULL COMMENT 'Frozen canonical program JSON',
    checksum CHAR(64) NOT NULL COMMENT 'sha256 of ast — integrity + dedup',
    published_by INT NULL,
    published_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
    FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_program_version (program_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Engine state scopes. Rows are created lazily on first write;
-- state_version increments on every commit (optimistic concurrency + log).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS room_state (
    room_id VARCHAR(36) PRIMARY KEY,
    state JSON NOT NULL DEFAULT (JSON_OBJECT()),
    state_version BIGINT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_state (
    room_id VARCHAR(36) NOT NULL,
    player_id INT NOT NULL,
    state JSON NOT NULL DEFAULT (JSON_OBJECT()),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (room_id, player_id),
    FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_state (
    game_id INT PRIMARY KEY,
    state JSON NOT NULL DEFAULT (JSON_OBJECT()),
    state_version BIGINT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- scheduled_events: `schedule` blocks write rows here; cron/tick.php claims
-- due rows and re-enters the engine in room context. Cron granularity is
-- ~1 min on Hostinger — document timer accuracy accordingly.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS scheduled_events (
    event_id VARCHAR(36) PRIMARY KEY,
    game_id INT NOT NULL,
    room_id VARCHAR(36) NULL COMMENT 'NULL = game-scope timer (no room context)',
    player_id INT NULL COMMENT 'Player the trigger runs as, if bound to one',
    trigger_name VARCHAR(100) NOT NULL COMMENT 'Timer name matched by on_timer triggers',
    payload JSON NULL,
    run_at TIMESTAMP NOT NULL,
    status ENUM('pending','running','done','failed','cancelled') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    claimed_at TIMESTAMP NULL COMMENT 'When a tick run claimed this event; stale claims get re-queued',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    INDEX idx_due (status, run_at),
    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- action_log: append-only audit of every engine run — replay, dispute
-- resolution, and the "logic runs on our platform" proof. Trim by cron.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS action_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    room_id VARCHAR(36) NULL,
    player_id INT NULL,
    action VARCHAR(100) NOT NULL,
    payload JSON NULL,
    result ENUM('ok','rejected','error') NOT NULL,
    reason VARCHAR(255) NULL,
    steps_used INT NOT NULL DEFAULT 0,
    duration_ms INT NOT NULL DEFAULT 0,
    state_version_before BIGINT NULL,
    state_version_after BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_room_log (game_id, room_id, created_at),
    INDEX idx_player_log (game_id, player_id, created_at),
    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Circular FK resolved last: games.published_program_id → program_versions.
ALTER TABLE games
    ADD CONSTRAINT fk_game_published
    FOREIGN KEY (published_program_id) REFERENCES program_versions(id) ON DELETE SET NULL;
