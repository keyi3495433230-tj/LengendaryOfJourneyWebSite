<?php
/**
 * Journey database foundation.
 *
 * PHP 7.4+ / PDO. The default database is SQLite at
 * .journey-data/journey.sqlite. Set JOURNEY_DB_DSN, JOURNEY_DB_USER and
 * JOURNEY_DB_PASSWORD to use MySQL/MariaDB instead.
 */

declare(strict_types=1);

if (!function_exists('journey_config')) {
    /** @return mixed */
    function journey_config(string $key = '', $default = null)
    {
        static $config = null;

        if ($config === null) {
            $root = dirname(__DIR__);
            $config = [
                'db_dsn' => null,
                'db_user' => null,
                'db_password' => null,
                'data_dir' => $root . DIRECTORY_SEPARATOR . '.journey-data',
                'app_key' => null,
                'trusted_proxies' => [],
                'cookie_secure' => 'auto',
                'cookie_domain' => '',
                'session_name' => 'journey_session',
                'session_lifetime' => 7200,
                'registration_account_limit' => 3,
                'daily_post_limit' => 5,
                'timezone' => 'Asia/Shanghai',
            ];

            $configFile = $root . DIRECTORY_SEPARATOR . 'journey-config.php';
            if (is_file($configFile)) {
                $fileConfig = require $configFile;
                if (is_array($fileConfig)) {
                    $config = array_replace($config, $fileConfig);
                }
            }

            $environmentMap = [
                'JOURNEY_DB_DSN' => 'db_dsn',
                'JOURNEY_DB_USER' => 'db_user',
                'JOURNEY_DB_PASSWORD' => 'db_password',
                'JOURNEY_DATA_DIR' => 'data_dir',
                'JOURNEY_APP_KEY' => 'app_key',
            ];
            foreach ($environmentMap as $environmentName => $configName) {
                $environmentValue = getenv($environmentName);
                if ($environmentValue !== false && $environmentValue !== '') {
                    $config[$configName] = $environmentValue;
                }
            }
        }

        if ($key === '') {
            return $config;
        }
        return array_key_exists($key, $config) ? $config[$key] : $default;
    }
}

if (!function_exists('journey_json_encode')) {
    /** @param mixed $value */
    function journey_json_encode($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('JSON encoding failed: ' . json_last_error_msg());
        }
        return $json;
    }
}

if (!function_exists('journey_json_decode')) {
    /** @param mixed $default
     *  @return mixed
     */
    function journey_json_decode(?string $json, $default = [])
    {
        if ($json === null || trim($json) === '') {
            return $default;
        }
        $decoded = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }
}

if (!function_exists('journey_db')) {
    function journey_db(): PDO
    {
        if (isset($GLOBALS['journey_database_pdo']) && $GLOBALS['journey_database_pdo'] instanceof PDO) {
            return $GLOBALS['journey_database_pdo'];
        }

        if (!class_exists('PDO')) {
            throw new RuntimeException('PDO is required. Enable pdo_sqlite or pdo_mysql in PHP.');
        }

        $dsn = (string)journey_config('db_dsn', '');
        $sqliteFile = null;
        if ($dsn === '') {
            $dataDir = (string)journey_config('data_dir');
            if (!is_dir($dataDir) && !mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
                throw new RuntimeException('Unable to create Journey data directory: ' . $dataDir);
            }
            $sqliteFile = $dataDir . DIRECTORY_SEPARATOR . 'journey.sqlite';
            $dsn = 'sqlite:' . $sqliteFile;
        } elseif (strpos($dsn, 'sqlite:') === 0) {
            $sqlitePath = substr($dsn, 7);
            if ($sqlitePath !== '' && $sqlitePath !== ':memory:') {
                $sqliteFile = $sqlitePath;
                $sqliteDir = dirname($sqlitePath);
                if (!is_dir($sqliteDir) && !mkdir($sqliteDir, 0700, true) && !is_dir($sqliteDir)) {
                    throw new RuntimeException('Unable to create SQLite directory: ' . $sqliteDir);
                }
            }
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        $pdo = new PDO(
            $dsn,
            journey_config('db_user'),
            journey_config('db_password'),
            $options
        );
        $GLOBALS['journey_database_pdo'] = $pdo;
        if ($sqliteFile !== null && is_file($sqliteFile)) {
            @chmod($sqliteFile, 0600);
        }

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
        } elseif ($driver === 'mysql') {
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        } else {
            throw new RuntimeException('Unsupported PDO driver: ' . $driver);
        }

        journey_db_install_schema($pdo);
        journey_migrate_legacy_json($pdo);
        return $pdo;
    }
}

if (!function_exists('journey_db_init')) {
    function journey_db_init(): PDO
    {
        return journey_db();
    }
}

if (!function_exists('journey_db_driver')) {
    function journey_db_driver(?PDO $pdo = null): string
    {
        $pdo = $pdo ?: journey_db();
        return (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}

if (!function_exists('journey_db_install_schema')) {
    function journey_db_install_schema(PDO $pdo): void
    {
        if (!empty($GLOBALS['journey_database_schema_ready'])) {
            return;
        }

        $driver = journey_db_driver($pdo);
        $targetVersion = 2;
        try {
            $settingsExists = $driver === 'sqlite'
                ? (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='settings' LIMIT 1")->fetchColumn()
                : (bool)$pdo->query("SHOW TABLES LIKE 'settings'")->fetchColumn();
            if ($settingsExists) {
                $versionStatement = $pdo->prepare('SELECT value_json FROM settings WHERE setting_key = ? LIMIT 1');
                $versionStatement->execute(['schema.version']);
                $storedVersion = journey_json_decode((string)$versionStatement->fetchColumn(), 0);
                if ((int)$storedVersion >= $targetVersion) {
                    $GLOBALS['journey_database_schema_ready'] = true;
                    return;
                }
            }
        } catch (Throwable $ignored) {
            // First install or restricted metadata access: fall back to idempotent CREATE statements.
        }
        if ($driver === 'sqlite') {
            $statements = journey_sqlite_schema();
        } else {
            $statements = journey_mysql_schema();
        }

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        $GLOBALS['journey_database_schema_ready'] = true;
        journey_setting_set_internal($pdo, 'schema.version', $targetVersion);
    }
}

if (!function_exists('journey_sqlite_schema')) {
    /** @return array<int,string> */
    function journey_sqlite_schema(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL UNIQUE,
                username TEXT NOT NULL COLLATE NOCASE UNIQUE,
                email TEXT COLLATE NOCASE UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user',
                status TEXT NOT NULL DEFAULT 'active',
                registration_ip_hash TEXT,
                registration_device_hash TEXT,
                last_ip_hash TEXT,
                last_device_hash TEXT,
                failed_login_count INTEGER NOT NULL DEFAULT 0,
                locked_until TEXT,
                last_login_at TEXT,
                last_active_at TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                extra_json TEXT NOT NULL DEFAULT '{}'
            )",
            'CREATE INDEX IF NOT EXISTS idx_users_registration_ip ON users(registration_ip_hash)',
            'CREATE INDEX IF NOT EXISTS idx_users_registration_device ON users(registration_device_hash)',
            'CREATE INDEX IF NOT EXISTS idx_users_status_role ON users(status, role)',
            "CREATE TABLE IF NOT EXISTS json_store (
                store_key TEXT PRIMARY KEY,
                data_json TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS registration_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT,
                ip_hash TEXT,
                device_hash TEXT,
                outcome TEXT NOT NULL,
                reason TEXT,
                created_at TEXT NOT NULL
            )",
            'CREATE INDEX IF NOT EXISTS idx_registration_ip_time ON registration_events(ip_hash, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_registration_device_time ON registration_events(device_hash, created_at)',
            "CREATE TABLE IF NOT EXISTS registration_guards (
                quota_key TEXT PRIMARY KEY,
                account_count INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS rate_limits (
                rate_key TEXT NOT NULL,
                window_start INTEGER NOT NULL,
                hits INTEGER NOT NULL DEFAULT 0,
                expires_at INTEGER NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (rate_key, window_start)
            )",
            'CREATE INDEX IF NOT EXISTS idx_rate_limits_expires ON rate_limits(expires_at)',
            "CREATE TABLE IF NOT EXISTS audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_user_id TEXT,
                event_type TEXT NOT NULL,
                target_type TEXT,
                target_id TEXT,
                ip_hash TEXT,
                device_hash TEXT,
                details_json TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL
            )",
            'CREATE INDEX IF NOT EXISTS idx_audit_event_time ON audit_log(event_type, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_audit_actor_time ON audit_log(actor_user_id, created_at)',
            "CREATE TABLE IF NOT EXISTS settings (
                setting_key TEXT PRIMARY KEY,
                value_json TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS media_assets (
                media_id TEXT PRIMARY KEY,
                mime_type TEXT NOT NULL,
                byte_size INTEGER NOT NULL,
                owner_user_id TEXT,
                purpose TEXT NOT NULL,
                content BLOB NOT NULL,
                created_at TEXT NOT NULL
            )",
            'CREATE INDEX IF NOT EXISTS idx_media_owner_time ON media_assets(owner_user_id, created_at)',
        ];
    }
}

if (!function_exists('journey_mysql_schema')) {
    /** @return array<int,string> */
    function journey_mysql_schema(): array
    {
        $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            "CREATE TABLE IF NOT EXISTS users (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id VARCHAR(32) NOT NULL,
                username VARCHAR(80) NOT NULL,
                email VARCHAR(191) NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(32) NOT NULL DEFAULT 'user',
                status VARCHAR(32) NOT NULL DEFAULT 'active',
                registration_ip_hash CHAR(64) NULL,
                registration_device_hash CHAR(64) NULL,
                last_ip_hash CHAR(64) NULL,
                last_device_hash CHAR(64) NULL,
                failed_login_count INT UNSIGNED NOT NULL DEFAULT 0,
                locked_until DATETIME NULL,
                last_login_at DATETIME NULL,
                last_active_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                extra_json LONGTEXT NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_users_user_id (user_id),
                UNIQUE KEY uq_users_username (username),
                UNIQUE KEY uq_users_email (email),
                KEY idx_users_registration_ip (registration_ip_hash),
                KEY idx_users_registration_device (registration_device_hash),
                KEY idx_users_status_role (status, role)
            )" . $suffix,
            "CREATE TABLE IF NOT EXISTS json_store (
                store_key VARCHAR(100) NOT NULL,
                data_json LONGTEXT NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (store_key)
            )" . $suffix,
            "CREATE TABLE IF NOT EXISTS registration_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id VARCHAR(32) NULL,
                ip_hash CHAR(64) NULL,
                device_hash CHAR(64) NULL,
                outcome VARCHAR(32) NOT NULL,
                reason VARCHAR(100) NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_registration_ip_time (ip_hash, created_at),
                KEY idx_registration_device_time (device_hash, created_at)
            )" . $suffix,
            "CREATE TABLE IF NOT EXISTS registration_guards (
                quota_key VARCHAR(80) NOT NULL,
                account_count INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (quota_key)
            )" . $suffix,
            "CREATE TABLE IF NOT EXISTS rate_limits (
                rate_key VARCHAR(191) NOT NULL,
                window_start BIGINT NOT NULL,
                hits INT UNSIGNED NOT NULL DEFAULT 0,
                expires_at BIGINT NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (rate_key, window_start),
                KEY idx_rate_limits_expires (expires_at)
            )" . $suffix,
            "CREATE TABLE IF NOT EXISTS audit_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                actor_user_id VARCHAR(32) NULL,
                event_type VARCHAR(100) NOT NULL,
                target_type VARCHAR(60) NULL,
                target_id VARCHAR(191) NULL,
                ip_hash CHAR(64) NULL,
                device_hash CHAR(64) NULL,
                details_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_audit_event_time (event_type, created_at),
                KEY idx_audit_actor_time (actor_user_id, created_at)
            )" . $suffix,
            "CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(100) NOT NULL,
                value_json LONGTEXT NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (setting_key)
            )" . $suffix,
            "CREATE TABLE IF NOT EXISTS media_assets (
                media_id CHAR(64) NOT NULL,
                mime_type VARCHAR(80) NOT NULL,
                byte_size INT UNSIGNED NOT NULL,
                owner_user_id VARCHAR(32) NULL,
                purpose VARCHAR(32) NOT NULL,
                content LONGBLOB NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (media_id),
                KEY idx_media_owner_time (owner_user_id, created_at)
            )" . $suffix,
        ];
    }
}

if (!function_exists('journey_now')) {
    function journey_now(): string
    {
        return journey_time_at(time());
    }
}

if (!function_exists('journey_time_at')) {
    function journey_time_at(int $timestamp): string
    {
        $timezone = new DateTimeZone((string)journey_config('timezone', 'Asia/Shanghai'));
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->format('Y-m-d H:i:s');
    }
}

if (!function_exists('journey_time_to_unix')) {
    function journey_time_to_unix(?string $value): int
    {
        if ($value === null || trim($value) === '') {
            return 0;
        }
        try {
            $timezone = new DateTimeZone((string)journey_config('timezone', 'Asia/Shanghai'));
            return (new DateTimeImmutable($value, $timezone))->getTimestamp();
        } catch (Throwable $exception) {
            return 0;
        }
    }
}

if (!function_exists('journey_setting_get_internal')) {
    /** @param mixed $default
     *  @return mixed
     */
    function journey_setting_get_internal(PDO $pdo, string $key, $default = null)
    {
        $statement = $pdo->prepare('SELECT value_json FROM settings WHERE setting_key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();
        if ($value === false) {
            return $default;
        }
        return journey_json_decode((string)$value, $default);
    }
}

if (!function_exists('journey_setting_set_internal')) {
    /** @param mixed $value */
    function journey_setting_set_internal(PDO $pdo, string $key, $value): void
    {
        $json = journey_json_encode($value);
        $now = journey_now();
        if (journey_db_driver($pdo) === 'sqlite') {
            $sql = 'INSERT INTO settings (setting_key, value_json, updated_at) VALUES (?, ?, ?)
                    ON CONFLICT(setting_key) DO UPDATE SET value_json = excluded.value_json, updated_at = excluded.updated_at';
        } else {
            $sql = 'INSERT INTO settings (setting_key, value_json, updated_at) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_at = VALUES(updated_at)';
        }
        $pdo->prepare($sql)->execute([$key, $json, $now]);
    }
}

if (!function_exists('journey_setting_get')) {
    /** @param mixed $default
     *  @return mixed
     */
    function journey_setting_get(string $key, $default = null)
    {
        return journey_setting_get_internal(journey_db(), $key, $default);
    }
}

if (!function_exists('journey_setting_set')) {
    /** @param mixed $value */
    function journey_setting_set(string $key, $value): void
    {
        journey_setting_set_internal(journey_db(), $key, $value);
    }
}

if (!function_exists('journey_store_exists_internal')) {
    function journey_store_exists_internal(PDO $pdo, string $key): bool
    {
        $statement = $pdo->prepare('SELECT 1 FROM json_store WHERE store_key = ?');
        $statement->execute([$key]);
        return $statement->fetchColumn() !== false;
    }
}

if (!function_exists('journey_store_get')) {
    /** @param mixed $default
     *  @return mixed
     */
    function journey_store_get(string $key, $default = [])
    {
        $statement = journey_db()->prepare('SELECT data_json FROM json_store WHERE store_key = ?');
        $statement->execute([$key]);
        $json = $statement->fetchColumn();
        return $json === false ? $default : journey_json_decode((string)$json, $default);
    }
}

if (!function_exists('journey_store_set_internal')) {
    /** @param mixed $value */
    function journey_store_set_internal(PDO $pdo, string $key, $value): void
    {
        if (!preg_match('/^[a-z0-9_.-]{1,100}$/i', $key)) {
            throw new InvalidArgumentException('Invalid JSON store key.');
        }
        $json = journey_json_encode($value);
        $now = journey_now();
        if (journey_db_driver($pdo) === 'sqlite') {
            $sql = 'INSERT INTO json_store (store_key, data_json, updated_at) VALUES (?, ?, ?)
                    ON CONFLICT(store_key) DO UPDATE SET data_json = excluded.data_json, updated_at = excluded.updated_at';
        } else {
            $sql = 'INSERT INTO json_store (store_key, data_json, updated_at) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE data_json = VALUES(data_json), updated_at = VALUES(updated_at)';
        }
        $pdo->prepare($sql)->execute([$key, $json, $now]);
    }
}

if (!function_exists('journey_store_set')) {
    /** @param mixed $value */
    function journey_store_set(string $key, $value): void
    {
        journey_store_set_internal(journey_db(), $key, $value);
    }
}

if (!function_exists('journey_store_mutate')) {
    /**
     * Atomically read, transform and save a JSON store value.
     *
     * @param callable $callback function ($currentValue) { return $newValue; }
     * @param mixed $default
     * @return mixed
     */
    function journey_store_mutate(string $key, callable $callback, $default = [])
    {
        $pdo = journey_db();
        $driver = journey_db_driver($pdo);
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $started = false;
            try {
                if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                    $started = true;
                }
                $sql = 'SELECT data_json FROM json_store WHERE store_key = ?' . ($driver === 'mysql' ? ' FOR UPDATE' : '');
                $statement = $pdo->prepare($sql);
                $statement->execute([$key]);
                $json = $statement->fetchColumn();
                $current = $json === false ? $default : journey_json_decode((string)$json, $default);
                $newValue = $callback($current);
                journey_store_set_internal($pdo, $key, $newValue);
                if ($started) {
                    $pdo->commit();
                }
                return $newValue;
            } catch (Throwable $exception) {
                if ($started && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = strtolower($exception->getMessage());
                $sqliteBusy = $started && $driver === 'sqlite'
                    && $exception instanceof PDOException
                    && (strpos($message, 'locked') !== false || strpos($message, 'busy') !== false);
                if (!$sqliteBusy || $attempt === 4) {
                    throw $exception;
                }
                usleep(random_int(20000, 90000) * $attempt);
            }
        }
        throw new RuntimeException('JSON store transaction failed.');
    }
}

if (!function_exists('journey_is_password_hash')) {
    function journey_is_password_hash(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        $info = password_get_info($value);
        return !empty($info['algo']);
    }
}

if (!function_exists('journey_hash_password')) {
    function journey_hash_password(string $password): string
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Password hashing failed.');
        }
        return $hash;
    }
}

if (!function_exists('journey_legacy_user_extra')) {
    /** @param array<string,mixed> $user
     *  @return array<string,mixed>
     */
    function journey_legacy_user_extra(array $user): array
    {
        $structured = [
            'id', 'userId', 'user_id', 'user', 'username', 'email', 'pwd', 'password',
            'password_hash', 'passwordHash', 'role', 'status', 'registration_ip_hash',
            'registration_device_hash', 'last_ip_hash', 'last_device_hash',
            'failed_login_count', 'locked_until', 'last_login_at', 'lastActive',
            'last_active_at', 'createdAt', 'created_at', 'updatedAt', 'updated_at',
            'isAdmin', 'isAdministrator', 'isModerator', 'moderator', 'isBarOwner',
        ];
        foreach ($structured as $field) {
            unset($user[$field]);
        }
        return $user;
    }
}

if (!function_exists('journey_user_row_to_legacy')) {
    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    function journey_user_row_to_legacy(array $row): array
    {
        $user = journey_json_decode(isset($row['extra_json']) ? (string)$row['extra_json'] : '{}', []);
        if (!is_array($user)) {
            $user = [];
        }
        $user['userId'] = (string)$row['user_id'];
        $user['user'] = (string)$row['username'];
        if (!empty($row['email'])) {
            $user['email'] = (string)$row['email'];
        } else {
            unset($user['email']);
        }
        $user['role'] = (string)$row['role'];
        $user['status'] = (string)$row['status'];
        $user['lastActive'] = $row['last_active_at'] ?: ($row['last_login_at'] ?: null);
        $user['createdAt'] = (string)$row['created_at'];
        $user['updatedAt'] = (string)$row['updated_at'];
        return $user;
    }
}

if (!function_exists('journey_get_users')) {
    /** @return array<int,array<string,mixed>> */
    function journey_get_users(): array
    {
        $rows = journey_db()->query('SELECT * FROM users ORDER BY id ASC')->fetchAll();
        return array_map('journey_user_row_to_legacy', $rows ?: []);
    }
}

if (!function_exists('journey_find_user_record_by_id')) {
    /** @return array<string,mixed>|null */
    function journey_find_user_record_by_id(string $userId): ?array
    {
        $statement = journey_db()->prepare('SELECT * FROM users WHERE user_id = ? LIMIT 1');
        $statement->execute([$userId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }
}

if (!function_exists('journey_find_user_record_by_login')) {
    /** @return array<string,mixed>|null */
    function journey_find_user_record_by_login(string $login): ?array
    {
        $statement = journey_db()->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
        $statement->execute([$login, strtolower($login)]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }
}

if (!function_exists('journey_find_user')) {
    /** @return array<string,mixed>|null */
    function journey_find_user(string $userId): ?array
    {
        $row = journey_find_user_record_by_id($userId);
        return $row === null ? null : journey_user_row_to_legacy($row);
    }
}

if (!function_exists('journey_next_user_id_internal')) {
    function journey_next_user_id_internal(PDO $pdo): string
    {
        $maximum = 0;
        $statement = $pdo->query('SELECT user_id FROM users');
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            if (preg_match('/^A(\d+)$/', (string)$userId, $matches)) {
                $maximum = max($maximum, (int)$matches[1]);
            }
        }
        return 'A' . str_pad((string)($maximum + 1), 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('journey_next_user_id')) {
    function journey_next_user_id(): string
    {
        return journey_next_user_id_internal(journey_db());
    }
}

if (!function_exists('journey_save_users')) {
    /**
     * Compatibility upsert for the legacy board user-array shape. Passwords and
     * internal hashes are never returned by journey_get_users(). Missing users
     * are not deleted by this method.
     *
     * @param array<int,array<string,mixed>> $users
     */
    function journey_save_users(array $users): void
    {
        $pdo = journey_db();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            foreach ($users as $user) {
                if (!is_array($user)) {
                    continue;
                }
                journey_upsert_legacy_user_internal($pdo, $user);
            }
            if ($started) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}

if (!function_exists('journey_upsert_legacy_user_internal')) {
    /** @param array<string,mixed> $user */
    function journey_upsert_legacy_user_internal(PDO $pdo, array $user): void
    {
        $userId = trim((string)($user['userId'] ?? $user['user_id'] ?? ''));
        $username = trim((string)($user['user'] ?? $user['username'] ?? ''));
        if ($username === '') {
            return;
        }
        if ($userId === '') {
            $userId = journey_next_user_id_internal($pdo);
        }

        $statement = $pdo->prepare('SELECT * FROM users WHERE user_id = ? LIMIT 1');
        $statement->execute([$userId]);
        $existing = $statement->fetch();
        if ($existing === false) {
            $statement = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
            $statement->execute([$username]);
            $existing = $statement->fetch();
        }

        $role = (string)($user['role'] ?? ($existing['role'] ?? 'user'));
        if (!in_array($role, ['user', 'moderator', 'admin'], true)) {
            $role = 'user';
        }
        $status = (string)($user['status'] ?? ($existing['status'] ?? 'active'));
        if (!in_array($status, ['active', 'pending', 'suspended', 'banned', 'disabled'], true)) {
            $status = 'active';
        }

        $email = trim((string)($user['email'] ?? ($existing['email'] ?? '')));
        $email = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : null;
        $existingExtra = $existing === false ? [] : journey_json_decode((string)$existing['extra_json'], []);
        if (!is_array($existingExtra)) {
            $existingExtra = [];
        }
        $extra = array_replace($existingExtra, journey_legacy_user_extra($user));
        $now = journey_now();

        $candidatePassword = (string)($user['password_hash'] ?? $user['passwordHash'] ?? $user['pwd'] ?? $user['password'] ?? '');
        if ($candidatePassword !== '') {
            $passwordHash = journey_is_password_hash($candidatePassword)
                ? $candidatePassword
                : journey_hash_password($candidatePassword);
        } elseif ($existing !== false) {
            $passwordHash = (string)$existing['password_hash'];
        } else {
            $passwordHash = journey_hash_password(bin2hex(random_bytes(32)));
            $status = 'disabled';
        }

        if ($existing !== false) {
            $sql = 'UPDATE users SET user_id = ?, username = ?, email = ?, password_hash = ?, role = ?, status = ?,
                    last_active_at = ?, updated_at = ?, extra_json = ? WHERE id = ?';
            $pdo->prepare($sql)->execute([
                $userId,
                $username,
                $email,
                $passwordHash,
                $role,
                $status,
                $user['lastActive'] ?? $user['last_active_at'] ?? $existing['last_active_at'],
                $now,
                journey_json_encode($extra),
                $existing['id'],
            ]);
            return;
        }

        $createdAt = (string)($user['createdAt'] ?? $user['created_at'] ?? $now);
        $sql = 'INSERT INTO users
                (user_id, username, email, password_hash, role, status, registration_ip_hash,
                 registration_device_hash, last_ip_hash, last_device_hash, failed_login_count,
                 locked_until, last_login_at, last_active_at, created_at, updated_at, extra_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, NULL, ?, ?, ?, ?)';
        $pdo->prepare($sql)->execute([
            $userId,
            $username,
            $email,
            $passwordHash,
            $role,
            $status,
            $user['registration_ip_hash'] ?? null,
            $user['registration_device_hash'] ?? null,
            $user['last_ip_hash'] ?? null,
            $user['last_device_hash'] ?? null,
            $user['lastActive'] ?? $user['last_active_at'] ?? null,
            $createdAt,
            $now,
            journey_json_encode($extra),
        ]);
    }
}

if (!function_exists('journey_update_user_fields')) {
    /** @param array<string,mixed> $fields */
    function journey_update_user_fields(string $userId, array $fields): bool
    {
        $allowed = [
            'username', 'email', 'password_hash', 'role', 'status',
            'registration_ip_hash', 'registration_device_hash', 'last_ip_hash',
            'last_device_hash', 'failed_login_count', 'locked_until', 'last_login_at',
            'last_active_at', 'extra_json',
        ];
        $assignments = [];
        $parameters = [];
        foreach ($fields as $field => $value) {
            if (!in_array($field, $allowed, true)) {
                continue;
            }
            $assignments[] = $field . ' = ?';
            $parameters[] = $field === 'extra_json' && is_array($value) ? journey_json_encode($value) : $value;
        }
        if ($assignments === []) {
            return false;
        }
        $assignments[] = 'updated_at = ?';
        $parameters[] = journey_now();
        $parameters[] = $userId;
        $statement = journey_db()->prepare('UPDATE users SET ' . implode(', ', $assignments) . ' WHERE user_id = ?');
        $statement->execute($parameters);
        return $statement->rowCount() > 0;
    }
}

if (!function_exists('journey_migrate_legacy_json')) {
    function journey_migrate_legacy_json(?PDO $pdo = null): void
    {
        if (!empty($GLOBALS['journey_database_migration_running'])) {
            return;
        }
        $GLOBALS['journey_database_migration_running'] = true;
        $pdo = $pdo ?: journey_db();
        $root = dirname(__DIR__);

        try {
            $usersPath = $root . DIRECTORY_SEPARATOR . 'users.json';
            if (is_file($usersPath)) {
                journey_migrate_legacy_users_file($pdo, $usersPath);
            }

            $stores = [
                'posts.json' => 'posts',
                'messages.json' => 'messages',
                'worldchat.json' => 'worldchat',
                'market.json' => 'market',
                'redeem_codes.json' => 'redeem_codes',
                'lottery_history.json' => 'lottery_history',
                'normal_lottery_history.json' => 'normal_lottery_history',
            ];
            foreach ($stores as $fileName => $storeKey) {
                $path = $root . DIRECTORY_SEPARATOR . $fileName;
                if (!is_file($path) || journey_store_exists_internal($pdo, $storeKey)) {
                    continue;
                }
                $raw = file_get_contents($path);
                if ($raw === false) {
                    continue;
                }
                $value = journey_json_decode($raw, null);
                if ($value === null && trim($raw) !== 'null') {
                    journey_setting_set_internal($pdo, 'migration.error.' . $storeKey, [
                        'file' => $fileName,
                        'reason' => 'invalid_json',
                        'at' => journey_now(),
                    ]);
                    continue;
                }
                journey_store_set_internal($pdo, $storeKey, $value);
                journey_setting_set_internal($pdo, 'migration.legacy.' . $storeKey, [
                    'file' => $fileName,
                    'sha256' => hash('sha256', $raw),
                    'at' => journey_now(),
                ]);
            }
        } finally {
            $GLOBALS['journey_database_migration_running'] = false;
        }
    }
}

if (!function_exists('journey_migrate_legacy_users_file')) {
    function journey_migrate_legacy_users_file(PDO $pdo, string $path): int
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return 0;
        }
        $fingerprint = hash('sha256', $raw);
        $state = journey_setting_get_internal($pdo, 'migration.legacy.users', []);
        if (is_array($state) && ($state['sha256'] ?? '') === $fingerprint) {
            return 0;
        }
        $users = journey_json_decode($raw, null);
        if (!is_array($users)) {
            journey_setting_set_internal($pdo, 'migration.error.users', [
                'file' => basename($path),
                'reason' => 'invalid_json',
                'at' => journey_now(),
            ]);
            return 0;
        }

        $imported = 0;
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        try {
            foreach ($users as $legacyUser) {
                if (!is_array($legacyUser)) {
                    continue;
                }
                $userId = trim((string)($legacyUser['userId'] ?? ''));
                $username = trim((string)($legacyUser['user'] ?? $legacyUser['username'] ?? ''));
                if ($username === '') {
                    continue;
                }
                $check = $pdo->prepare('SELECT 1 FROM users WHERE user_id = ? OR username = ? LIMIT 1');
                $check->execute([$userId, $username]);
                if ($check->fetchColumn() !== false) {
                    continue;
                }
                journey_upsert_legacy_user_internal($pdo, $legacyUser);
                $imported++;
            }
            journey_setting_set_internal($pdo, 'migration.legacy.users', [
                'file' => basename($path),
                'sha256' => $fingerprint,
                'imported' => $imported,
                'at' => journey_now(),
            ]);
            if ($started) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        return $imported;
    }
}
