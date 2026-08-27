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

    // Add reverse-proxy addresses you control. Forwarded IP/HTTPS headers
    // are ignored unless REMOTE_ADDR matches one of these IP/CIDR entries.
    // BaoTa nginx 反向代理需要信任本地回环地址，否则 HTTPS 检测和 X-Forwarded-* 头会被忽略。
    'trusted_proxies' => [
        '127.0.0.1/32',
        '::1/128',
    ],

    // "auto" 根据 $_SERVER['HTTPS'] / X-Forwarded-Proto 自动判断：
    // HTTPS 下 Cookie 带 Secure 标记（安全），HTTP 下不带（本地开发可用）。
    // 之前设为 true 会导致 HTTP 环境下浏览器丢弃所有 Secure Cookie，
    // 表现为登录后立刻掉线、管理后台无法进入、图片上传 CSRF 失败。
    'cookie_secure' => 'auto',
    'cookie_domain' => '',
    'session_name' => 'journey_session',
    'session_lifetime' => 7200,

    // Anti-abuse defaults. Both the source IP hash and device-cookie hash are
    // checked independently for registration.
    'registration_account_limit' => 3,
    'daily_post_limit' => 5,
    'timezone' => 'Asia/Shanghai',
];
