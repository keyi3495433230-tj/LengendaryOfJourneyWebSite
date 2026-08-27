<?php
// 地牢外观图片公开访问端点
// 管理员在 admin.php 上传的背景/地板图片保存在 .journey-data/uploads/dungeon/，
// 该目录被 .htaccess / nginx 规则禁止直访，必须经由此脚本按白名单键读取。
define('JOURNEY_ADMIN_LOADED', true);
require_once __DIR__ . '/board.php';

$allowedKeys = [
    'dungeon_background',
    'floor_spawn', 'floor_chest', 'floor_merchant', 'floor_camp', 'floor_shrine',
    'floor_boss', 'floor_normal', 'floor_elite', 'floor_town', 'floor_bridge',
];
$key = (string)($_GET['key'] ?? '');
if ($key === '' || !in_array($key, $allowedKeys, true)) {
    http_response_code(404);
    exit;
}

$map = journey_setting_get('dungeon_appearance_files', []);
if (!is_array($map)) $map = [];
$filename = (string)($map[$key] ?? '');
// 只允许本系统生成的文件名（键名_16位十六进制.扩展名），杜绝路径穿越
if (!preg_match('/^[a-z0-9_]{1,64}\.(?:jpe?g|png|webp|gif)$/i', $filename)) {
    http_response_code(404);
    exit;
}

$path = rtrim((string)journey_config('data_dir'), '/\\') . DIRECTORY_SEPARATOR
    . 'uploads' . DIRECTORY_SEPARATOR . 'dungeon' . DIRECTORY_SEPARATOR . $filename;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$mimeTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . (string)filesize($path));
// URL 中带 v=版本哈希，管理员换图后 URL 变化即可拿到新图，因此可长缓存
header('Cache-Control: public, max-age=2592000');
readfile($path);
exit;
