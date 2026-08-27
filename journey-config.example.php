<?php
/**
 * Copy this file to journey-config.php and keep that file out of Git.
 * Environment variables override the database/app-key values below.
 */

return [
    // Leave null to use .journey-data/journey.sqlite (PDO SQLite).
    'db_dsn' => null,
    'db_user' => null,
    'db_password' => null,

    // MySQL / MariaDB example (create the database and restricted user first):
    // 'db_dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=journey;charset=utf8mb4',
    // 'db_user' => 'journey_app',
    // 'db_password' => 'use-a-long-random-password',

    // Prefer JOURNEY_APP_KEY in the BaoTa/PHP-FPM environment. Use at least
    // 32 random bytes and keep it stable across deploys. If omitted, an
    // installation key is generated in DB.
    'app_key' => null,

    // Only add reverse-proxy addresses you control. Forwarded IP/HTTPS headers
    // are ignored unless REMOTE_ADDR matches one of these IP/CIDR entries.
    'trusted_proxies' => [
        // '127.0.0.1/32',
        // '::1/128',
    ],

    // Production must use HTTPS. true forces Secure cookies; "auto" is useful
    // for local HTTP development and still enables Secure under HTTPS.
    'cookie_secure' => true,
    'cookie_domain' => '',
    'session_name' => 'journey_session',
    'session_lifetime' => 7200,

    // Anti-abuse defaults. Both the source IP hash and device-cookie hash are
    // checked independently for registration.
    'registration_account_limit' => 3,
    'daily_post_limit' => 5,
    'timezone' => 'Asia/Shanghai',
];
