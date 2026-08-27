<?php
/**
 * Journey identity and request-security helpers.
 *
 * Include this file from HTTP entry points, then call
 * journey_security_bootstrap() before producing output.
 */

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'database.php';

if (!function_exists('journey_base64url_encode')) {
    function journey_base64url_encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

if (!function_exists('journey_security_secret')) {
    function journey_security_secret(): string
    {
        static $secret = null;
        if ($secret !== null) {
            return $secret;
        }

        $configured = trim((string)journey_config('app_key', ''));
        if (strlen($configured) >= 32) {
            $secret = $configured;
            return $secret;
        }

        $stored = journey_setting_get('security.install_key', '');
        if (!is_string($stored) || strlen($stored) < 43) {
            $stored = journey_base64url_encode(random_bytes(48));
            journey_setting_set('security.install_key', $stored);
        }
        $secret = $stored;
        return $secret;
    }
}

if (!function_exists('journey_is_https')) {
    function journey_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        $remoteAddress = journey_normalize_ip((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remoteAddress !== null && journey_is_trusted_proxy($remoteAddress)) {
            $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            return $forwardedProto === 'https';
        }
        return false;
    }
}

if (!function_exists('journey_cookie_secure')) {
    function journey_cookie_secure(): bool
    {
        $configured = journey_config('cookie_secure', 'auto');
        if (is_bool($configured)) {
            return $configured;
        }
        $value = strtolower(trim((string)$configured));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return journey_is_https();
    }
}

if (!function_exists('journey_cookie_options')) {
    /** @return array<string,mixed> */
    function journey_cookie_options(bool $httpOnly, int $expires = 0): array
    {
        $options = [
            'expires' => $expires,
            'path' => '/',
            'secure' => journey_cookie_secure(),
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ];
        $domain = trim((string)journey_config('cookie_domain', ''));
        if ($domain !== '') {
            $options['domain'] = $domain;
        }
        return $options;
    }
}

if (!function_exists('journey_security_bootstrap')) {
    function journey_security_bootstrap(): void
    {
        static $bootstrapped = false;
        if ($bootstrapped) {
            return;
        }

        journey_db_init();
        if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
            if (headers_sent($file, $line)) {
                throw new RuntimeException('Security session cannot start after output at ' . $file . ':' . $line);
            }
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', 'Lax');
            ini_set('session.cookie_secure', journey_cookie_secure() ? '1' : '0');
            ini_set('session.gc_maxlifetime', (string)max(900, (int)journey_config('session_lifetime', 7200)));
            session_name((string)journey_config('session_name', 'journey_session'));
            $sessionCookieOptions = journey_cookie_options(true, 0);
            unset($sessionCookieOptions['expires']);
            $sessionCookieOptions['lifetime'] = 0;
            session_set_cookie_params($sessionCookieOptions);
            session_start();
        }

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        }

        journey_device_id();
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            journey_csrf_token();
            journey_validate_session_device();
        }
        $bootstrapped = true;
    }
}

if (!function_exists('journey_security_init')) {
    function journey_security_init(): void
    {
        journey_security_bootstrap();
    }
}

if (!function_exists('journey_normalize_ip')) {
    function journey_normalize_ip(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }
        if ($ip[0] === '[' && substr($ip, -1) === ']') {
            $ip = substr($ip, 1, -1);
        }
        $zonePosition = strpos($ip, '%');
        if ($zonePosition !== false) {
            $ip = substr($ip, 0, $zonePosition);
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        $normalized = @inet_ntop($packed);
        return $normalized === false ? null : strtolower($normalized);
    }
}

if (!function_exists('journey_ip_in_cidr')) {
    function journey_ip_in_cidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', trim($cidr), 2);
        $network = journey_normalize_ip($parts[0]);
        $ip = journey_normalize_ip($ip);
        if ($network === null || $ip === null) {
            return false;
        }
        $ipPacked = inet_pton($ip);
        $networkPacked = inet_pton($network);
        if ($ipPacked === false || $networkPacked === false || strlen($ipPacked) !== strlen($networkPacked)) {
            return false;
        }
        $maximumBits = strlen($ipPacked) * 8;
        $bits = isset($parts[1]) ? (int)$parts[1] : $maximumBits;
        if ($bits < 0 || $bits > $maximumBits) {
            return false;
        }
        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;
        if ($wholeBytes > 0 && substr($ipPacked, 0, $wholeBytes) !== substr($networkPacked, 0, $wholeBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($ipPacked[$wholeBytes]) & $mask) === (ord($networkPacked[$wholeBytes]) & $mask);
    }
}

if (!function_exists('journey_is_trusted_proxy')) {
    function journey_is_trusted_proxy(string $ip): bool
    {
        $trusted = journey_config('trusted_proxies', []);
        if (is_string($trusted)) {
            $trusted = array_filter(array_map('trim', explode(',', $trusted)));
        }
        if (!is_array($trusted)) {
            return false;
        }
        foreach ($trusted as $cidr) {
            if (is_string($cidr) && journey_ip_in_cidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('journey_client_ip')) {
    function journey_client_ip(): string
    {
        $remote = journey_normalize_ip((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote === null) {
            return PHP_SAPI === 'cli' ? '127.0.0.1' : '0.0.0.0';
        }
        if (!journey_is_trusted_proxy($remote)) {
            return $remote;
        }

        $chain = [];
        foreach (explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) as $candidate) {
            $normalized = journey_normalize_ip($candidate);
            if ($normalized !== null) {
                $chain[] = $normalized;
            }
        }
        $chain[] = $remote;
        for ($index = count($chain) - 1; $index >= 0; $index--) {
            $candidate = $chain[$index];
            if (!journey_is_trusted_proxy($candidate)) {
                return $candidate;
            }
        }
        return $chain[0] ?? $remote;
    }
}

if (!function_exists('journey_ip_hash')) {
    function journey_ip_hash(?string $ip = null): string
    {
        $ip = journey_normalize_ip($ip ?? journey_client_ip()) ?? '0.0.0.0';
        return hash_hmac('sha256', 'ip:' . $ip, journey_security_secret());
    }
}

if (!function_exists('journey_device_id')) {
    function journey_device_id(): string
    {
        static $deviceId = null;
        if ($deviceId !== null) {
            return $deviceId;
        }

        if (PHP_SAPI === 'cli') {
            $deviceId = 'cli';
            return $deviceId;
        }

        $cookieName = 'journey_device';
        $headerCandidate = trim((string)($_SERVER['HTTP_X_JOURNEY_DEVICE'] ?? ''));
        $candidate = preg_match('/^[A-Za-z0-9_-]{20,80}$/', $headerCandidate)
            ? $headerCandidate
            : (string)($_COOKIE[$cookieName] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{20,80}$/', $candidate)) {
            $candidate = journey_base64url_encode(random_bytes(32));
        }
        if (!isset($_COOKIE[$cookieName]) || !hash_equals((string)$_COOKIE[$cookieName], $candidate)) {
            if (!headers_sent()) {
                setcookie($cookieName, $candidate, journey_cookie_options(true, time() + 31536000));
            }
            $_COOKIE[$cookieName] = $candidate;
        }
        $deviceId = $candidate;
        return $deviceId;
    }
}

if (!function_exists('journey_device_hash')) {
    function journey_device_hash(?string $deviceId = null): string
    {
        return hash_hmac('sha256', 'device:' . ($deviceId ?? journey_device_id()), journey_security_secret());
    }
}

if (!function_exists('journey_csrf_token')) {
    function journey_csrf_token(): string
    {
        if (PHP_SAPI === 'cli') {
            return '';
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            journey_security_bootstrap();
        }
        if (empty($_SESSION['journey_csrf']) || !is_string($_SESSION['journey_csrf'])) {
            $_SESSION['journey_csrf'] = journey_base64url_encode(random_bytes(32));
        }
        $token = $_SESSION['journey_csrf'];
        if (!isset($_COOKIE['journey_csrf']) || !hash_equals($token, (string)$_COOKIE['journey_csrf'])) {
            if (!headers_sent()) {
                setcookie('journey_csrf', $token, journey_cookie_options(false, 0));
            }
            $_COOKIE['journey_csrf'] = $token;
        }
        return $token;
    }
}

if (!function_exists('journey_request_csrf_token')) {
    function journey_request_csrf_token(): string
    {
        $header = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_XSRF_TOKEN'] ?? '');
        if ($header !== '') {
            return trim($header);
        }
        return trim((string)($_POST['_csrf'] ?? $_POST['csrf'] ?? ''));
    }
}

if (!function_exists('journey_verify_csrf')) {
    function journey_verify_csrf(?string $providedToken = null): bool
    {
        if (PHP_SAPI === 'cli') {
            return true;
        }
        journey_security_bootstrap();
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }
        $providedToken = $providedToken ?? journey_request_csrf_token();
        $sessionToken = (string)($_SESSION['journey_csrf'] ?? '');
        $cookieToken = (string)($_COOKIE['journey_csrf'] ?? '');
        return $providedToken !== ''
            && $sessionToken !== ''
            && $cookieToken !== ''
            && hash_equals($sessionToken, $providedToken)
            && hash_equals($sessionToken, $cookieToken);
    }
}

if (!function_exists('journey_csrf_validate')) {
    function journey_csrf_validate(?string $providedToken = null): bool
    {
        return journey_verify_csrf($providedToken);
    }
}

if (!function_exists('journey_validate_session_device')) {
    function journey_validate_session_device(): bool
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['journey_user_id'])) {
            return true;
        }
        $now = time();
        $lifetime = max(900, (int)journey_config('session_lifetime', 7200));
        $lastSeen = (int)($_SESSION['journey_last_seen'] ?? $_SESSION['journey_authenticated_at'] ?? 0);
        if ($lastSeen <= 0 || ($now - $lastSeen) > $lifetime) {
            journey_session_logout(true);
            return false;
        }
        $expected = (string)($_SESSION['journey_device_hash'] ?? '');
        $actual = journey_device_hash();
        if ($expected === '' || !hash_equals($expected, $actual)) {
            journey_session_logout(true);
            return false;
        }
        $_SESSION['journey_last_seen'] = $now;
        return true;
    }
}

if (!function_exists('journey_session_login')) {
    /** @param array<string,mixed> $userRecord */
    function journey_session_login(array $userRecord): void
    {
        journey_security_bootstrap();
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        session_regenerate_id(true);
        $_SESSION['journey_user_id'] = (string)$userRecord['user_id'];
        $_SESSION['journey_role'] = (string)$userRecord['role'];
        $_SESSION['journey_device_hash'] = journey_device_hash();
        $_SESSION['journey_auth_version'] = hash('sha256', (string)$userRecord['password_hash']);
        $_SESSION['journey_authenticated_at'] = time();
        $_SESSION['journey_last_seen'] = time();
        unset($_SESSION['journey_csrf']);
        journey_csrf_token();
    }
}

if (!function_exists('journey_session_logout')) {
    function journey_session_logout(bool $destroyCookie = true): void
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if ($destroyCookie && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool)($params['secure'] ?? false),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }
}

if (!function_exists('journey_logout')) {
    function journey_logout(): void
    {
        $userId = journey_current_user_id();
        if ($userId !== null) {
            journey_audit('auth.logged_out', [], $userId, 'user', $userId);
        }
        journey_session_logout(true);
    }
}

if (!function_exists('journey_current_user_id')) {
    function journey_current_user_id(): ?string
    {
        journey_security_bootstrap();
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE || !journey_validate_session_device()) {
            return null;
        }
        $userId = trim((string)($_SESSION['journey_user_id'] ?? ''));
        return $userId === '' ? null : $userId;
    }
}

if (!function_exists('journey_current_user')) {
    /** @return array<string,mixed>|null */
    function journey_current_user(): ?array
    {
        $userId = journey_current_user_id();
        if ($userId === null) {
            return null;
        }
        $record = journey_find_user_record_by_id($userId);
        if ($record === null || (string)$record['status'] !== 'active') {
            journey_session_logout();
            return null;
        }
        $sessionVersion = (string)($_SESSION['journey_auth_version'] ?? '');
        $currentVersion = hash('sha256', (string)$record['password_hash']);
        if ($sessionVersion === '' || !hash_equals($sessionVersion, $currentVersion)) {
            journey_session_logout();
            return null;
        }
        return journey_user_row_to_legacy($record);
    }
}

if (!function_exists('journey_require_authenticated_user')) {
    /** @return array<string,mixed>|null */
    function journey_require_authenticated_user(?string $claimedUserId = null): ?array
    {
        $user = journey_current_user();
        if ($user === null) {
            return null;
        }
        if ($claimedUserId !== null && $claimedUserId !== '' && !hash_equals((string)$user['userId'], $claimedUserId)) {
            return null;
        }
        return $user;
    }
}

if (!function_exists('journey_is_admin')) {
    function journey_is_admin(?string $userId = null): bool
    {
        $userId = $userId ?? journey_current_user_id();
        if ($userId === null || $userId === '') {
            return false;
        }
        $record = journey_find_user_record_by_id($userId);
        return $record !== null && $record['status'] === 'active' && $record['role'] === 'admin';
    }
}

if (!function_exists('journey_redact_sensitive')) {
    /** @param mixed $value
     *  @return mixed
     */
    function journey_redact_sensitive($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $redacted = [];
        foreach ($value as $key => $item) {
            $normalizedKey = strtolower((string)$key);
            if (preg_match('/password|passwd|pwd|secret|token|authorization|cookie/', $normalizedKey)) {
                $redacted[$key] = '[redacted]';
            } else {
                $redacted[$key] = journey_redact_sensitive($item);
            }
        }
        return $redacted;
    }
}

if (!function_exists('journey_audit')) {
    /** @param array<string,mixed> $details */
    function journey_audit(
        string $eventType,
        array $details = [],
        ?string $actorUserId = null,
        ?string $targetType = null,
        ?string $targetId = null
    ): void {
        $sql = 'INSERT INTO audit_log
                (actor_user_id, event_type, target_type, target_id, ip_hash, device_hash, details_json, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        journey_db()->prepare($sql)->execute([
            $actorUserId,
            substr($eventType, 0, 100),
            $targetType,
            $targetId,
            journey_ip_hash(),
            journey_device_hash(),
            journey_json_encode(journey_redact_sensitive($details)),
            journey_now(),
        ]);
    }
}

if (!function_exists('journey_rate_limit_window')) {
    /** @return array<string,int|bool> */
    function journey_rate_limit_window(
        string $scope,
        string $identifier,
        int $limit,
        int $windowStart,
        int $expiresAt,
        bool $consume = true
    ): array {
        $limit = max(1, $limit);
        $rateKey = substr($scope, 0, 80) . ':' . hash_hmac('sha256', $identifier, journey_security_secret());
        $pdo = journey_db();
        $now = time();

        if ($consume) {
            if (journey_db_driver($pdo) === 'sqlite') {
                $sql = 'INSERT INTO rate_limits (rate_key, window_start, hits, expires_at, updated_at)
                        VALUES (?, ?, 1, ?, ?)
                        ON CONFLICT(rate_key, window_start) DO UPDATE SET
                            hits = rate_limits.hits + 1,
                            expires_at = excluded.expires_at,
                            updated_at = excluded.updated_at';
            } else {
                $sql = 'INSERT INTO rate_limits (rate_key, window_start, hits, expires_at, updated_at)
                        VALUES (?, ?, 1, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            hits = hits + 1,
                            expires_at = VALUES(expires_at),
                            updated_at = VALUES(updated_at)';
            }
            $pdo->prepare($sql)->execute([$rateKey, $windowStart, $expiresAt, journey_now()]);
        }

        $statement = $pdo->prepare('SELECT hits FROM rate_limits WHERE rate_key = ? AND window_start = ?');
        $statement->execute([$rateKey, $windowStart]);
        $hits = (int)($statement->fetchColumn() ?: 0);
        if (random_int(1, 100) === 1) {
            $pdo->prepare('DELETE FROM rate_limits WHERE expires_at < ?')->execute([$now]);
        }
        return [
            'allowed' => $hits <= $limit,
            'limit' => $limit,
            'used' => $hits,
            'remaining' => max(0, $limit - $hits),
            'resetAt' => $expiresAt,
            'retryAfter' => max(0, $expiresAt - $now),
        ];
    }
}

if (!function_exists('journey_rate_limit')) {
    /** @return array<string,int|bool> */
    function journey_rate_limit(
        string $scope,
        string $identifier,
        int $limit,
        int $windowSeconds = 60,
        bool $consume = true
    ): array {
        $windowSeconds = max(1, $windowSeconds);
        $now = time();
        $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
        return journey_rate_limit_window(
            $scope,
            $identifier,
            $limit,
            $windowStart,
            $windowStart + $windowSeconds,
            $consume
        );
    }
}

if (!function_exists('journey_rate_limit_allow')) {
    function journey_rate_limit_allow(
        string $scope,
        string $identifier,
        int $limit,
        int $windowSeconds = 60
    ): bool {
        $result = journey_rate_limit($scope, $identifier, $limit, $windowSeconds, true);
        return (bool)$result['allowed'];
    }
}

if (!function_exists('journey_daily_action_limit')) {
    /** @return array<string,int|bool> */
    function journey_daily_action_limit(
        string $scope,
        string $userId,
        ?int $limit = null,
        bool $consume = true
    ): array {
        $timezone = new DateTimeZone((string)journey_config('timezone', 'Asia/Shanghai'));
        $now = new DateTimeImmutable('now', $timezone);
        $start = $now->setTime(0, 0, 0)->getTimestamp();
        $end = $now->modify('+1 day')->setTime(0, 0, 0)->getTimestamp();
        $limit = $limit ?? (int)journey_config('daily_post_limit', 5);
        return journey_rate_limit_window($scope . '.daily', $userId, $limit, $start, $end, $consume);
    }
}

if (!function_exists('journey_record_registration_event')) {
    function journey_record_registration_event(
        ?string $userId,
        string $outcome,
        ?string $reason,
        ?string $ipHash = null,
        ?string $deviceHash = null
    ): void {
        $sql = 'INSERT INTO registration_events
                (user_id, ip_hash, device_hash, outcome, reason, created_at)
                VALUES (?, ?, ?, ?, ?, ?)';
        journey_db()->prepare($sql)->execute([
            $userId,
            $ipHash ?? journey_ip_hash(),
            $deviceHash ?? journey_device_hash(),
            $outcome,
            $reason,
            journey_now(),
        ]);
    }
}

if (!function_exists('journey_registration_counts')) {
    /** @return array{ip:int,device:int} */
    function journey_registration_counts(?string $ipHash = null, ?string $deviceHash = null): array
    {
        $pdo = journey_db();
        $ipHash = $ipHash ?? journey_ip_hash();
        $deviceHash = $deviceHash ?? journey_device_hash();
        $statement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE registration_ip_hash = ?');
        $statement->execute([$ipHash]);
        $ipCount = (int)$statement->fetchColumn();
        $statement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE registration_device_hash = ?');
        $statement->execute([$deviceHash]);
        return ['ip' => $ipCount, 'device' => (int)$statement->fetchColumn()];
    }
}

if (!function_exists('journey_string_length')) {
    function journey_string_length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}

if (!function_exists('journey_validate_registration')) {
    /** @return array<int,string> */
    function journey_validate_registration(string $username, string $password, ?string $email = null): array
    {
        $errors = [];
        $usernameLength = journey_string_length($username);
        if ($usernameLength < 2 || $usernameLength > 32) {
            $errors[] = 'username_length';
        }
        if (preg_match('/[\x00-\x1F\x7F<>]/u', $username)) {
            $errors[] = 'username_characters';
        }
        $passwordLength = strlen($password);
        if ($passwordLength < 8 || $passwordLength > 128) {
            $errors[] = 'password_length';
        }
        if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'email_invalid';
        }
        return array_values(array_unique($errors));
    }
}

if (!function_exists('journey_begin_write_transaction')) {
    function journey_begin_write_transaction(PDO $pdo): bool
    {
        if ($pdo->inTransaction()) {
            return false;
        }
        $pdo->beginTransaction();
        return true;
    }
}

if (!function_exists('journey_register_user')) {
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    function journey_register_user(string $username, string $password, ?string $email = null, array $options = []): array
    {
        journey_security_bootstrap();
        $username = trim($username);
        $email = $email === null ? null : strtolower(trim($email));
        $errors = journey_validate_registration($username, $password, $email);
        $ipHash = journey_ip_hash();
        $deviceHash = journey_device_hash();

        $attemptLimit = journey_rate_limit('register.ip', $ipHash, 10, 86400, true);
        if (!$attemptLimit['allowed']) {
            journey_record_registration_event(null, 'denied', 'attempt_rate_limit', $ipHash, $deviceHash);
            return ['ok' => false, 'code' => 'rate_limited', 'retryAfter' => $attemptLimit['retryAfter']];
        }
        if ($errors !== []) {
            journey_record_registration_event(null, 'denied', implode(',', $errors), $ipHash, $deviceHash);
            return ['ok' => false, 'code' => 'validation_failed', 'errors' => $errors];
        }

        $pdo = journey_db();
        $started = false;
        try {
            $started = journey_begin_write_transaction($pdo);
            $driver = journey_db_driver($pdo);
            $quotaKeys = ['ip:' . $ipHash, 'device:' . $deviceHash];
            sort($quotaKeys, SORT_STRING);
            foreach ($quotaKeys as $quotaKey) {
                if ($driver === 'sqlite') {
                    $guardSql = 'INSERT OR IGNORE INTO registration_guards (quota_key, account_count, updated_at) VALUES (?, 0, ?)';
                } else {
                    $guardSql = 'INSERT INTO registration_guards (quota_key, account_count, updated_at) VALUES (?, 0, ?)
                                 ON DUPLICATE KEY UPDATE quota_key = VALUES(quota_key)';
                }
                $pdo->prepare($guardSql)->execute([$quotaKey, journey_now()]);
            }
            $guardSql = 'SELECT quota_key, account_count FROM registration_guards WHERE quota_key IN (?, ?) ORDER BY quota_key';
            if ($driver === 'mysql') $guardSql .= ' FOR UPDATE';
            $guardStatement = $pdo->prepare($guardSql);
            $guardStatement->execute($quotaKeys);
            $guardCounts = [];
            foreach ($guardStatement->fetchAll() as $guardRow) {
                $guardCounts[(string)$guardRow['quota_key']] = (int)$guardRow['account_count'];
            }
            $counts = journey_registration_counts($ipHash, $deviceHash);
            $accountLimit = max(1, (int)($options['account_limit'] ?? journey_config('registration_account_limit', 3)));
            $ipAccountLimit = max(1, (int)($options['ip_account_limit'] ?? $accountLimit));
            $deviceAccountLimit = max(1, (int)($options['device_account_limit'] ?? $accountLimit));
            $ipQuotaKey = 'ip:' . $ipHash;
            $deviceQuotaKey = 'device:' . $deviceHash;
            $ipAccountCount = max($counts['ip'], (int)($guardCounts[$ipQuotaKey] ?? 0));
            $deviceAccountCount = max($counts['device'], (int)($guardCounts[$deviceQuotaKey] ?? 0));
            $updateGuard = $pdo->prepare('UPDATE registration_guards SET account_count = ?, updated_at = ? WHERE quota_key = ?');
            $updateGuard->execute([$ipAccountCount, journey_now(), $ipQuotaKey]);
            $updateGuard->execute([$deviceAccountCount, journey_now(), $deviceQuotaKey]);
            if ($ipAccountCount >= $ipAccountLimit || $deviceAccountCount >= $deviceAccountLimit) {
                journey_record_registration_event(null, 'denied', 'account_limit', $ipHash, $deviceHash);
                if ($started) {
                    $pdo->commit();
                }
                return [
                    'ok' => false,
                    'code' => 'account_limit',
                    'limit' => min($ipAccountLimit, $deviceAccountLimit),
                    'ipLimit' => $ipAccountLimit,
                    'deviceLimit' => $deviceAccountLimit,
                    'ipCount' => $ipAccountCount,
                    'deviceCount' => $deviceAccountCount,
                ];
            }

            $duplicate = $pdo->prepare('SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1');
            $duplicate->execute([$username, $email]);
            if ($duplicate->fetchColumn() !== false) {
                journey_record_registration_event(null, 'denied', 'duplicate', $ipHash, $deviceHash);
                if ($started) {
                    $pdo->commit();
                }
                return ['ok' => false, 'code' => 'exists'];
            }

            $userId = journey_next_user_id_internal($pdo);
            $now = journey_now();
            $extra = isset($options['extra']) && is_array($options['extra']) ? $options['extra'] : [];
            $sql = 'INSERT INTO users
                    (user_id, username, email, password_hash, role, status,
                     registration_ip_hash, registration_device_hash, last_ip_hash,
                     last_device_hash, failed_login_count, locked_until, last_login_at,
                     last_active_at, created_at, updated_at, extra_json)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, NULL, ?, ?, ?, ?)';
            $pdo->prepare($sql)->execute([
                $userId,
                $username,
                $email !== '' ? $email : null,
                journey_hash_password($password),
                'user',
                'active',
                $ipHash,
                $deviceHash,
                $ipHash,
                $deviceHash,
                $now,
                $now,
                $now,
                journey_json_encode($extra),
            ]);
            $updateGuard->execute([$ipAccountCount + 1, journey_now(), $ipQuotaKey]);
            $updateGuard->execute([$deviceAccountCount + 1, journey_now(), $deviceQuotaKey]);
            journey_record_registration_event($userId, 'success', null, $ipHash, $deviceHash);
            journey_audit('account.registered', ['username' => $username], $userId, 'user', $userId);
            if ($started) {
                $pdo->commit();
            }
            $record = journey_find_user_record_by_id($userId);
            if ($record !== null && !empty($options['login'])) {
                journey_session_login($record);
            }
            return [
                'ok' => true,
                'code' => 'ok',
                'userId' => $userId,
                'user' => $username,
                'csrfToken' => journey_csrf_token(),
            ];
        } catch (Throwable $exception) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof PDOException && (string)$exception->getCode() === '23000') {
                journey_record_registration_event(null, 'denied', 'duplicate', $ipHash, $deviceHash);
                return ['ok' => false, 'code' => 'exists'];
            }
            throw $exception;
        }
    }
}

if (!function_exists('journey_authenticate_user')) {
    /** @return array<string,mixed> */
    function journey_authenticate_user(string $login, string $password, bool $startSession = true): array
    {
        journey_security_bootstrap();
        $login = trim($login);
        $ipHash = journey_ip_hash();
        $ipLimit = journey_rate_limit('login.ip', $ipHash, 30, 900, true);
        $accountLimit = journey_rate_limit('login.account', strtolower($login), 12, 900, true);
        if (!$ipLimit['allowed'] || !$accountLimit['allowed']) {
            journey_audit('auth.rate_limited', ['login' => $login], null, 'user', null);
            return [
                'ok' => false,
                'code' => 'rate_limited',
                'retryAfter' => max((int)$ipLimit['retryAfter'], (int)$accountLimit['retryAfter']),
            ];
        }

        $record = journey_find_user_record_by_login($login);
        static $dummyHash = null;
        if ($dummyHash === null) {
            $dummyHash = journey_hash_password('not-the-right-password');
        }
        $hash = $record === null ? $dummyHash : (string)$record['password_hash'];
        $valid = password_verify($password, $hash);
        if ($record === null || !$valid) {
            if ($record !== null) {
                $failures = (int)$record['failed_login_count'] + 1;
                $lockedUntil = $failures >= 8 ? journey_time_at(time() + 900) : $record['locked_until'];
                journey_update_user_fields((string)$record['user_id'], [
                    'failed_login_count' => $failures,
                    'locked_until' => $lockedUntil,
                    'last_ip_hash' => $ipHash,
                    'last_device_hash' => journey_device_hash(),
                ]);
            }
            journey_audit('auth.failed', ['login' => $login], null, 'user', $record['user_id'] ?? null);
            return ['ok' => false, 'code' => 'invalid_credentials'];
        }

        $lockedUntilTimestamp = journey_time_to_unix(isset($record['locked_until']) ? (string)$record['locked_until'] : null);
        if ($lockedUntilTimestamp > time()) {
            return [
                'ok' => false,
                'code' => 'locked',
                'retryAfter' => $lockedUntilTimestamp - time(),
            ];
        }
        if ((string)$record['status'] !== 'active') {
            journey_audit('auth.status_denied', ['status' => $record['status']], (string)$record['user_id'], 'user', (string)$record['user_id']);
            return ['ok' => false, 'code' => 'account_' . (string)$record['status']];
        }

        $newHash = password_needs_rehash((string)$record['password_hash'], PASSWORD_DEFAULT)
            ? journey_hash_password($password)
            : (string)$record['password_hash'];
        $now = journey_now();
        journey_update_user_fields((string)$record['user_id'], [
            'password_hash' => $newHash,
            'failed_login_count' => 0,
            'locked_until' => null,
            'last_login_at' => $now,
            'last_active_at' => $now,
            'last_ip_hash' => $ipHash,
            'last_device_hash' => journey_device_hash(),
        ]);
        $record = journey_find_user_record_by_id((string)$record['user_id']);
        if ($record === null) {
            throw new RuntimeException('User disappeared during authentication.');
        }
        if ($startSession) {
            journey_session_login($record);
        }
        journey_audit('auth.succeeded', [], (string)$record['user_id'], 'user', (string)$record['user_id']);
        return [
            'ok' => true,
            'code' => 'ok',
            'userId' => (string)$record['user_id'],
            'user' => (string)$record['username'],
            'role' => (string)$record['role'],
            'csrfToken' => $startSession ? journey_csrf_token() : '',
        ];
    }
}

if (!function_exists('journey_login')) {
    /** @return array<string,mixed> */
    function journey_login(string $login, string $password): array
    {
        return journey_authenticate_user($login, $password, true);
    }
}

if (!function_exists('journey_change_password')) {
    /** @return array<string,mixed> */
    function journey_change_password(string $userId, string $oldPassword, string $newPassword): array
    {
        $current = journey_require_authenticated_user($userId);
        if ($current === null) {
            return ['ok' => false, 'code' => 'unauthorized'];
        }
        if (strlen($newPassword) < 8 || strlen($newPassword) > 128) {
            return ['ok' => false, 'code' => 'password_length'];
        }
        $record = journey_find_user_record_by_id($userId);
        if ($record === null || !password_verify($oldPassword, (string)$record['password_hash'])) {
            return ['ok' => false, 'code' => 'wrong_password'];
        }
        journey_update_user_fields($userId, ['password_hash' => journey_hash_password($newPassword)]);
        journey_audit('account.password_changed', [], $userId, 'user', $userId);
        journey_session_login((array)journey_find_user_record_by_id($userId));
        return ['ok' => true, 'code' => 'ok', 'csrfToken' => journey_csrf_token()];
    }
}

if (!function_exists('journey_create_admin')) {
    /** @return array<string,mixed> */
    function journey_create_admin(string $username, string $password, ?string $email = null, ?string $userId = null): array
    {
        $username = trim($username);
        $email = $email === null ? null : strtolower(trim($email));
        $errors = journey_validate_registration($username, $password, $email);
        if ($errors !== []) {
            return ['ok' => false, 'code' => 'validation_failed', 'errors' => $errors];
        }
        $pdo = journey_db();
        $statement = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $statement->execute([$username]);
        $record = $statement->fetch();
        if ($record !== false) {
            journey_update_user_fields((string)$record['user_id'], [
                'email' => $email !== null && $email !== '' ? $email : $record['email'],
                'password_hash' => journey_hash_password($password),
                'role' => 'admin',
                'status' => 'active',
                'failed_login_count' => 0,
                'locked_until' => null,
            ]);
            journey_audit('admin.account_promoted', ['username' => $username], (string)$record['user_id'], 'user', (string)$record['user_id']);
            return ['ok' => true, 'code' => 'updated', 'userId' => (string)$record['user_id'], 'user' => $username];
        }

        if ($email !== null && $email !== '') {
            $statement = $pdo->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
            $statement->execute([$email]);
            if ($statement->fetchColumn() !== false) {
                return ['ok' => false, 'code' => 'email_exists'];
            }
        }

        $userId = trim((string)$userId);
        if ($userId === '') {
            $userId = journey_next_user_id_internal($pdo);
        }
        $now = journey_now();
        $sql = 'INSERT INTO users
                (user_id, username, email, password_hash, role, status,
                 failed_login_count, created_at, updated_at, extra_json)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?)';
        $pdo->prepare($sql)->execute([
            $userId,
            $username,
            $email !== '' ? $email : null,
            journey_hash_password($password),
            'admin',
            'active',
            $now,
            $now,
            '{}',
        ]);
        journey_audit('admin.account_created', ['username' => $username], $userId, 'user', $userId);
        return ['ok' => true, 'code' => 'created', 'userId' => $userId, 'user' => $username];
    }
}

if (!function_exists('journey_admin_update_user')) {
    /**
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    function journey_admin_update_user(string $actorUserId, string $targetUserId, array $changes): array
    {
        if (PHP_SAPI !== 'cli') {
            $sessionActor = journey_current_user_id();
            if ($sessionActor === null || !hash_equals($sessionActor, $actorUserId)) {
                return ['ok' => false, 'code' => 'forbidden'];
            }
        }
        if (!journey_is_admin($actorUserId)) {
            return ['ok' => false, 'code' => 'forbidden'];
        }
        $record = journey_find_user_record_by_id($targetUserId);
        if ($record === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        $fields = [];
        foreach (['username', 'email', 'role', 'status'] as $field) {
            if (array_key_exists($field, $changes)) {
                $fields[$field] = $changes[$field];
            }
        }
        if (isset($fields['username'])) {
            $fields['username'] = trim((string)$fields['username']);
            $nameErrors = journey_validate_registration($fields['username'], 'temporary-password', null);
            if (in_array('username_length', $nameErrors, true) || in_array('username_characters', $nameErrors, true)) {
                return ['ok' => false, 'code' => 'invalid_username'];
            }
        }
        if (array_key_exists('email', $fields)) {
            $fields['email'] = strtolower(trim((string)$fields['email']));
            if ($fields['email'] === '') {
                $fields['email'] = null;
            } elseif (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'code' => 'invalid_email'];
            }
        }
        if (isset($fields['role']) && !in_array($fields['role'], ['user', 'moderator', 'admin'], true)) {
            return ['ok' => false, 'code' => 'invalid_role'];
        }
        if (isset($fields['status']) && !in_array($fields['status'], ['active', 'pending', 'suspended', 'banned', 'disabled'], true)) {
            return ['ok' => false, 'code' => 'invalid_status'];
        }
        if (!empty($changes['password'])) {
            if (strlen((string)$changes['password']) < 8 || strlen((string)$changes['password']) > 128) {
                return ['ok' => false, 'code' => 'password_length'];
            }
            $fields['password_hash'] = journey_hash_password((string)$changes['password']);
        }
        if (isset($changes['extra']) && is_array($changes['extra'])) {
            $extra = journey_json_decode((string)$record['extra_json'], []);
            $fields['extra_json'] = array_replace(is_array($extra) ? $extra : [], $changes['extra']);
        }
        if ($fields === []) {
            return ['ok' => false, 'code' => 'no_changes'];
        }
        $removesActiveAdmin = (string)$record['role'] === 'admin'
            && (string)$record['status'] === 'active'
            && ((isset($fields['role']) && $fields['role'] !== 'admin')
                || (isset($fields['status']) && $fields['status'] !== 'active'));
        if ($removesActiveAdmin) {
            $activeAdminCount = (int)journey_db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
            if ($activeAdminCount <= 1) {
                return ['ok' => false, 'code' => 'last_admin'];
            }
        }
        journey_update_user_fields($targetUserId, $fields);
        journey_audit('admin.user_updated', ['fields' => array_keys($fields)], $actorUserId, 'user', $targetUserId);
        return ['ok' => true, 'code' => 'ok', 'user' => journey_find_user($targetUserId)];
    }
}
