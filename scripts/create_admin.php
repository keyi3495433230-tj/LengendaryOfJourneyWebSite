<?php
/**
 * Create or promote an administrator without a built-in web password.
 *
 * Examples:
 *   php scripts/create_admin.php --username="站长" --email=admin@example.com
 *   JOURNEY_ADMIN_PASSWORD='strong password' php scripts/create_admin.php --username="站长"
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'security.php';

function journey_admin_cli_usage(): void
{
    $script = basename(__FILE__);
    fwrite(STDOUT, "用法：php scripts/{$script} --username=名称 [--email=邮箱] [--password=强密码] [--user-id=A00001]\n");
    fwrite(STDOUT, "也可用 JOURNEY_ADMIN_PASSWORD 环境变量传入密码。未提供密码时会生成并只显示一次。\n");
}

function journey_generate_admin_password(int $length = 24): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%*-_+=';
    $password = '';
    $last = strlen($alphabet) - 1;
    for ($index = 0; $index < $length; $index++) {
        $password .= $alphabet[random_int(0, $last)];
    }
    return $password;
}

$options = getopt('', ['username:', 'email:', 'password:', 'user-id:', 'help']);
if (isset($options['help'])) {
    journey_admin_cli_usage();
    exit(0);
}

$username = trim((string)($options['username'] ?? ''));
if ($username === '') {
    fwrite(STDERR, "错误：必须提供 --username。\n");
    journey_admin_cli_usage();
    exit(2);
}

$email = isset($options['email']) ? trim((string)$options['email']) : null;
$password = isset($options['password']) ? (string)$options['password'] : (string)(getenv('JOURNEY_ADMIN_PASSWORD') ?: '');
$generatedPassword = false;
if ($password === '') {
    $password = journey_generate_admin_password();
    $generatedPassword = true;
}
$userId = isset($options['user-id']) ? trim((string)$options['user-id']) : null;

try {
    $result = journey_create_admin($username, $password, $email, $userId);
    if (empty($result['ok'])) {
        fwrite(STDERR, '管理员创建失败：' . journey_json_encode($result) . PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT, sprintf(
        "管理员已%s：%s（%s）\n",
        ($result['code'] ?? '') === 'created' ? '创建' : '更新',
        (string)$result['user'],
        (string)$result['userId']
    ));
    if ($generatedPassword) {
        fwrite(STDOUT, "一次性生成的密码：{$password}\n请立即保存到密码管理器，登录后再修改。\n");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, '管理员创建失败：' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

