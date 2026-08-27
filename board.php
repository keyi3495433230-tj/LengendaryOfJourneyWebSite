<?php
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/dungeon.php';
journey_security_bootstrap();

// 如果被其他文件include，只加载函数不执行action处理
if (defined('JOURNEY_ADMIN_LOADED')) {
    return;
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store, private');
date_default_timezone_set('Asia/Shanghai');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

$userFile = __DIR__ . "/users.json"; // migration source only
$postFile = __DIR__ . "/posts.json"; // migration source only
$messageFile = __DIR__ . "/messages.json";
$worldChatFile = __DIR__ . "/worldchat.json";
$marketFile = __DIR__ . "/market.json";
$redeemCodeFile = __DIR__ . "/redeem_codes.json";
$lotteryHistoryFile = __DIR__ . "/lottery_history.json";
$normalLotteryHistoryFile = __DIR__ . "/normal_lottery_history.json";
$uploadDir = rtrim((string)journey_config('data_dir'), '/\\') . DIRECTORY_SEPARATOR . 'uploads';
$paidSectionPassword = (string)journey_setting_get('forum.paid_password', '');

if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

function outputCachedJson($payload, $maxAge = 0, $visibility = 'public') {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    $json = json_encode($payload, $flags);
    if ($json === false) {
        http_response_code(500);
        echo '{"code":"encode_error"}';
        exit;
    }
    $etag = '"' . hash('sha256', $json) . '"';
    header('Cache-Control: ' . $visibility . ', max-age=' . max(0, (int)$maxAge) . ', must-revalidate');
    header('ETag: ' . $etag);
    $clientTag = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($clientTag !== '' && strpos($clientTag, $etag) !== false) {
        http_response_code(304);
        exit;
    }
    echo $json;
    exit;
}

function genUserId() {
    return journey_next_user_id();
}

function getUsers() {
    $users = journey_get_users();
    $snapshot = [];
    foreach ($users as $user) {
        if (!empty($user['userId'])) $snapshot[(string)$user['userId']] = $user;
    }
    $GLOBALS['journey_users_snapshot'] = $snapshot;
    return $users;
}

function saveUsers($users) {
    $snapshot = isset($GLOBALS['journey_users_snapshot']) && is_array($GLOBALS['journey_users_snapshot'])
        ? $GLOBALS['journey_users_snapshot'] : [];
    $changesByUser = [];
    foreach ($users as $user) {
        if (!is_array($user) || empty($user['userId'])) continue;
        $userId = (string)$user['userId'];
        $before = $snapshot[$userId] ?? [];
        $fieldChanges = [];
        foreach ($user as $field => $value) {
            if (!array_key_exists($field, $before) || serialize($before[$field]) !== serialize($value)) {
                $fieldChanges[$field] = $value;
            }
        }
        foreach ($before as $field => $value) {
            if (!array_key_exists($field, $user)) $fieldChanges[$field] = null;
        }
        if ($fieldChanges) $changesByUser[$userId] = ['before' => $before, 'changes' => $fieldChanges];
    }
    if (!$changesByUser) return;

    $pdo = journey_db();
    $started = false;
    $userIds = array_keys($changesByUser);
    sort($userIds, SORT_STRING);
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        if (journey_db_driver($pdo) === 'mysql') {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $lock = $pdo->prepare('SELECT user_id FROM users WHERE user_id IN (' . $placeholders . ') ORDER BY user_id FOR UPDATE');
            $lock->execute($userIds);
            $lock->fetchAll();
        } else {
            $lock = $pdo->prepare('UPDATE users SET updated_at = updated_at WHERE user_id = ?');
            foreach ($userIds as $lockedUserId) $lock->execute([$lockedUserId]);
        }
        foreach ($userIds as $changedUserId) {
            $current = journey_find_user($changedUserId);
            if ($current === null) continue;
            $before = $changesByUser[$changedUserId]['before'];
            foreach ($changesByUser[$changedUserId]['changes'] as $field => $value) {
                if (($field === 'xp' || $field === 'gold') && isset($before[$field]) && is_numeric($before[$field]) && is_numeric($value)) {
                    $current[$field] = max(0, (int)($current[$field] ?? 0) + ((int)$value - (int)$before[$field]));
                } elseif ($value === null && array_key_exists($field, $before)) {
                    unset($current[$field]);
                } else {
                    $current[$field] = $value;
                }
            }
            journey_upsert_legacy_user_internal($pdo, $current);
            $snapshot[$changedUserId] = $current;
        }
        if ($started) $pdo->commit();
        $GLOBALS['journey_users_snapshot'] = $snapshot;
    } catch (Throwable $exception) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function isAdministratorUser($userId) {
    return $userId !== '' && journey_is_admin($userId);
}

function userHasFriend($user, $friendId) {
    foreach (($user['friends'] ?? []) as $friend) {
        $storedFriendId = is_array($friend) ? ($friend['friendId'] ?? '') : $friend;
        if ($storedFriendId === $friendId) {
            return true;
        }
    }
    return false;
}

function getMessages() {
    $messages = journey_store_get('messages', []);
    return is_array($messages) ? $messages : [];
}

function saveMessages($messages) {
    journey_store_set('messages', array_values($messages));
}

function appendWorldSystemMessage($content, $type = 'system') {
    journey_store_mutate('worldchat', function($worldChat) use ($content, $type) {
        if (!is_array($worldChat)) $worldChat = [];
        $worldChat[] = [
            'from' => 'SYSTEM',
            'user' => '系统广播',
            'content' => (string)$content,
            'time' => date('Y-m-d H:i:s'),
            'system' => true,
            'systemType' => $type
        ];
        return array_slice($worldChat, -200);
    }, []);
}

function appendLegendaryLotteryHistory($user, $item) {
    journey_store_mutate('lottery_history', function($history) use ($user, $item) {
        if (!is_array($history)) $history = [];
        $history[] = [
            'userId' => $user['userId'] ?? '',
            'user' => $user['user'] ?? '玩家',
            'itemId' => $item['id'] ?? '',
            'itemName' => $item['name'] ?? '传说物品',
            'quality' => 'legendary',
            'time' => date('Y-m-d H:i:s')
        ];
        return array_slice($history, -30);
    }, []);
}

function appendNormalLotteryHistory($user, $item) {
    journey_store_mutate('normal_lottery_history', function($history) use ($user, $item) {
        if (!is_array($history)) $history = [];
        $history[] = [
            'userId' => $user['userId'] ?? '',
            'user' => $user['user'] ?? '玩家',
            'itemId' => $item['id'] ?? '',
            'itemName' => $item['name'] ?? '物品',
            'quality' => $item['quality'] ?? 'common',
            'time' => date('Y-m-d H:i:s')
        ];
        return array_slice($history, -30);
    }, []);
}

function getMarketItems() {
    $items = journey_store_get('market', []);
    return is_array($items) ? $items : [];
}

function saveMarketItems($items) {
    journey_store_set('market', array_values($items));
}

function getRedeemCodes() {
    $codes = journey_store_get('redeem_codes', []);
    return is_array($codes) ? $codes : [];
}

function saveRedeemCodes($codes) {
    journey_store_set('redeem_codes', array_values($codes));
}

function generateRedeemCodeValue() {
    return 'JT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 4)) . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 4));
}

function savePosts($posts) {
    try {
        journey_store_set('posts', array_values($posts));
        if (!empty($GLOBALS['journey_posts_write_lock'])) {
            $pdo = journey_db();
            if ($pdo->inTransaction()) $pdo->commit();
            $GLOBALS['journey_posts_write_lock'] = false;
        }
    } catch (Throwable $exception) {
        $pdo = journey_db();
        if ($pdo->inTransaction()) $pdo->rollBack();
        $GLOBALS['journey_posts_write_lock'] = false;
        throw $exception;
    }
}

function getPosts() {
    $posts = journey_store_get('posts', []);
    return is_array($posts) ? array_values($posts) : [];
}

function beginPostsWriteLock() {
    if (!empty($GLOBALS['journey_posts_write_lock'])) return;
    $pdo = journey_db();
    if ($pdo->inTransaction()) throw new RuntimeException('Unexpected active transaction before forum write.');
    $now = journey_now();
    if (journey_db_driver($pdo) === 'sqlite') {
        $pdo->prepare("INSERT OR IGNORE INTO json_store (store_key, data_json, updated_at) VALUES ('posts', '[]', ?)")->execute([$now]);
    } else {
        $pdo->prepare("INSERT INTO json_store (store_key, data_json, updated_at) VALUES ('posts', '[]', ?)
                       ON DUPLICATE KEY UPDATE store_key = VALUES(store_key)")->execute([$now]);
    }
    $pdo->beginTransaction();
    if (journey_db_driver($pdo) === 'mysql') {
        $pdo->query("SELECT data_json FROM json_store WHERE store_key = 'posts' FOR UPDATE")->fetchColumn();
    } else {
        $pdo->exec("UPDATE json_store SET updated_at = updated_at WHERE store_key = 'posts'");
    }
    $GLOBALS['journey_posts_write_lock'] = true;
}

function normalizeCategory($category) {
    $allowed = ['daily', 'mc', 'journey', 'paid'];
    return in_array($category, $allowed, true) ? $category : 'daily';
}

function canAccessCategory($category, $password) {
    global $paidSectionPassword;
    return $category !== 'paid' || ($paidSectionPassword !== '' && hash_equals($paidSectionPassword, (string)$password));
}

function forumTitleTiers() {
    $tiers = [
        ['min' => 1, 'title' => '初来乍到'],
        ['min' => 11, 'title' => '小镇旅人'],
        ['min' => 21, 'title' => '林间行者'],
        ['min' => 31, 'title' => '篝火伙伴'],
        ['min' => 41, 'title' => '遗迹探索者'],
        ['min' => 51, 'title' => '星火守望'],
        ['min' => 61, 'title' => '深境开拓者'],
        ['min' => 71, 'title' => '传说记录者'],
        ['min' => 81, 'title' => '旅途先知'],
        ['min' => 91, 'title' => '旅途传说']
    ];
    for ($level = 100; $level <= 1000; $level += 100) {
        $tiers[] = ['min' => $level, 'title' => (int)($level / 100) . '※'];
    }
    return $tiers;
}

function defaultAvatar($name) {
    $name = trim((string)$name);
    $seed = '旅';
    if ($name !== '') {
        $seed = function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1);
    }
    return [
        'type' => 'initial',
        'text' => $seed,
        'color' => '#8f2730'
    ];
}

function levelFromXp($xp) {
    return max(1, min(1000, (int)floor(max(0, (int)$xp) / 20) + 1));
}

function calculateUserStats($userOrId, $posts) {
    $userId = is_array($userOrId) ? ($userOrId['userId'] ?? '') : (string)$userOrId;
    $xp = is_array($userOrId) ? (int)($userOrId['xp'] ?? 0) : 0;
    $postCount = 0;
    $replyCount = 0;
    $receivedLikes = 0;

    foreach ($posts as $post) {
        if (($post['userId'] ?? '') === $userId) {
            $postCount++;
            $receivedLikes += (int)($post['likeNum'] ?? 0);
        }
        $replies = isset($post['reply']) && is_array($post['reply']) ? $post['reply'] : [];
        foreach ($replies as $reply) {
            if (($reply['userId'] ?? '') === $userId) {
                $replyCount++;
                $receivedLikes += (int)($reply['replyLikeNum'] ?? 0);
            }
        }
    }

    $level = levelFromXp($xp);
    $nextLevelXp = $level >= 1000 ? $xp : $level * 20;

    return [
        'xp' => $xp,
        'level' => $level,
        'postCount' => $postCount,
        'replyCount' => $replyCount,
        'receivedLikes' => $receivedLikes,
        'nextLevelXp' => $nextLevelXp
    ];
}

function grantUserXp(&$user, $min, $max) {
    $gain = random_int($min, $max);
    $user['xp'] = (int)($user['xp'] ?? 0) + $gain;
    return $gain;
}

function unlockedTitles($level) {
    $titles = [];
    foreach (forumTitleTiers() as $tier) {
        if ($level >= $tier['min']) $titles[] = $tier['title'];
    }
    return $titles ?: ['初来乍到'];
}

function selectedTitleForUser($user, $level) {
    $titles = unlockedTitles($level);
    $selected = $user['selectedTitle'] ?? '';
    return in_array($selected, $titles, true) ? $selected : $titles[count($titles) - 1];
}

function buildUserMetaMap($users, $posts) {
    $map = [];
    foreach ($users as $u) {
        $stats = calculateUserStats($u, $posts);
        $map[$u['userId']] = [
            'level' => $stats['level'],
            'title' => selectedTitleForUser($u, $stats['level']),
            'avatar' => $u['avatar'] ?? defaultAvatar($u['user'] ?? ''),
            'role' => $u['role'] ?? 'user'
        ];
    }
    return $map;
}

function publicUserProfile($user, $posts) {
    $stats = calculateUserStats($user, $posts);
    $titles = unlockedTitles($stats['level']);
    $user['level'] = $stats['level'];
    $user['xp'] = $stats['xp'];
    $user['nextLevelXp'] = $stats['nextLevelXp'];
    $user['postCount'] = $stats['postCount'];
    $user['replyCount'] = $stats['replyCount'];
    $user['receivedLikes'] = $stats['receivedLikes'];
    $user['unlockedTitles'] = $titles;
    $user['selectedTitle'] = selectedTitleForUser($user, $stats['level']);
    $user['avatar'] = $user['avatar'] ?? defaultAvatar($user['user'] ?? '');
    unset($user['pwd'], $user['password'], $user['password_hash'], $user['registration_ip_hash'], $user['registration_device_hash'], $user['last_ip_hash'], $user['last_device_hash']);
    return $user;
}

function publicVisibleProfile($user, $posts) {
    $profile = publicUserProfile($user, $posts);
    $inventory = normalizeInventorySlots($profile['inventory'] ?? [], false);
    $profile['publicInventory'] = [];
    foreach ($inventory as $slotIndex => $inventoryItem) {
        if (!$inventoryItem) {
            continue;
        }
        $publicItem = itemDefinition($inventoryItem['id'] ?? '');
        if (!empty($inventoryItem['customName'])) {
            $publicItem['originalName'] = $publicItem['name'] ?? '';
            $publicItem['name'] = $inventoryItem['customName'];
            $publicItem['customName'] = $inventoryItem['customName'];
        }
        $profile['publicInventory'][] = [
            'slotIndex' => $slotIndex,
            'count' => max(1, (int)($inventoryItem['count'] ?? 1)),
            'item' => $publicItem
        ];
    }
    unset($profile['email'], $profile['friends'], $profile['friendRequests'], $profile['inventory'], $profile['gameHotbar']);
    $profile['bio'] = $profile['bio'] ?? '';
    $profile['gold'] = (int)($profile['gold'] ?? 0);
    return $profile;
}

function adminSettingsPayload() {
    $rates = lotteryRateSettings();
    return [
        'registration_limit_ip' => max(1, min(3, (int)journey_setting_get('security.registration_limit_ip', 3))),
        'registration_limit_device' => max(1, min(3, (int)journey_setting_get('security.registration_limit_device', 3))),
        'daily_post_limit' => max(1, min(5, (int)journey_setting_get('security.daily_post_limit', 5))),
        'lottery_common' => $rates['common'],
        'lottery_uncommon' => $rates['uncommon'],
        'lottery_rare' => $rates['rare'],
        'lottery_epic' => $rates['epic'],
        'lottery_legendary' => $rates['legendary'],
        'keyi_contact' => keyiContactProfile(),
        'hotdog_contact' => hotdogContactProfile(),
        'jack_contact' => jackContactProfile(),
        'george_contact' => georgeContactProfile()
    ];
}

function adminUserPayload($user, $postCount = 0) {
    $wingCoins = 0;
    try { journey_dungeon_ensure_player(journey_db(), (string)($user['userId'] ?? '')); $stmt=journey_db()->prepare('SELECT wing_coins FROM dungeon_player_state WHERE user_id=?');$stmt->execute([(string)($user['userId']??'')]);$wingCoins=(int)$stmt->fetchColumn(); } catch(Throwable $ignored) {}
    return [
        'userId' => (string)($user['userId'] ?? ''),
        'user' => (string)($user['user'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'role' => (string)($user['role'] ?? 'user'),
        'status' => (string)($user['status'] ?? 'active'),
        'xp' => max(0, (int)($user['xp'] ?? 0)),
        'gold' => max(0, (int)($user['gold'] ?? 0)),
        'wingCoins' => max(0, $wingCoins),
        'bio' => (string)($user['bio'] ?? ''),
        'createdAt' => (string)($user['createdAt'] ?? ''),
        'lastActive' => (string)($user['lastActive'] ?? ''),
        'postCount' => max(0, (int)$postCount)
    ];
}

function defaultInventory() {
    return [
        ['id' => 'welcome_journey', 'count' => 1, 'createdAt' => date('Y-m-d H:i:s')]
    ];
}

function defaultGameHotbar() {
    return array_fill(0, 7, null);
}

function normalizeGameHotbarSlots($hotbar, $ensureDefault = false) {
    $items = is_array($hotbar) ? $hotbar : defaultGameHotbar();
    $slots = [];
    foreach ($items as $item) {
        if ($item === null) {
            $slots[] = null;
            continue;
        }
        if (!is_array($item) || empty($item['id'])) {
            $slots[] = null;
            continue;
        }
        $normalizedItem = [
            'id' => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$item['id']),
            'count' => max(1, (int)($item['count'] ?? 1)),
            'createdAt' => $item['createdAt'] ?? date('Y-m-d H:i:s')
        ];
        if (!empty($item['customName'])) {
            $customName = trim(strip_tags((string)$item['customName']));
            $normalizedItem['customName'] = function_exists('mb_substr') ? mb_substr($customName, 0, 20, 'UTF-8') : substr($customName, 0, 60);
        }
        $slots[] = $normalizedItem;
    }
    $slots = array_slice($slots, 0, 7);
    while (count($slots) < 7) $slots[] = null;
    if ($ensureDefault && !array_filter($slots)) {
        $slots[0] = null;
    }
    return $slots;
}

function formatGameHotbar($hotbar) {
    $qualityColors = [
        'common'    => 0xaaaaaa,
        'uncommon'  => 0x4ecdc4,
        'rare'      => 0x64b5f6,
        'epic'      => 0xba68c8,
        'legendary' => 0xffd93d
    ];
    $hotbar = normalizeGameHotbarSlots($hotbar, false);
    $gameSlots = [];
    for ($i = 0; $i < 7; $i++) {
        $slotItem = $hotbar[$i] ?? null;
        if (!is_array($slotItem)) {
            $gameSlots[] = null;
            continue;
        }
        $def = itemDefinition((string)($slotItem['id'] ?? ''));
        $quality = (string)($def['quality'] ?? 'common');
        $gameSlots[] = [
            'id'          => (string)($slotItem['id'] ?? ''),
            'name'        => (string)($slotItem['customName'] ?? $def['name'] ?? $slotItem['id']),
            'icon'        => (string)($def['icon'] ?? '?'),
            'color'       => $qualityColors[$quality] ?? 0xaaaaaa,
            'quantity'    => max(1, (int)($slotItem['count'] ?? 1)),
            'description' => (string)($def['desc'] ?? ''),
            'quality'     => $quality,
            'createdAt'   => (string)($slotItem['createdAt'] ?? '')
        ];
    }
    return $gameSlots;
}

function combinedInventoryFingerprint($inventory, $hotbar = null) {
    $combined = array_merge(
        normalizeInventorySlots($inventory, false),
        normalizeGameHotbarSlots($hotbar !== null ? $hotbar : defaultGameHotbar(), false)
    );
    return inventoryContentFingerprint($combined);
}

function itemQualityConfig($quality) {
    $configs = [
        'common' => ['label' => '普通', 'icon' => '◇', 'power' => [1, 18]],
        'uncommon' => ['label' => '精良', 'icon' => '◆', 'power' => [20, 42]],
        'rare' => ['label' => '稀有', 'icon' => '✦', 'power' => [45, 70]],
        'epic' => ['label' => '史诗', 'icon' => '✧', 'power' => [75, 110]],
        'legendary' => ['label' => '传说', 'icon' => '✹', 'power' => [120, 180]]
    ];
    return $configs[$quality] ?? $configs['common'];
}

function spreadsheetItemNames() {
    static $names = null;
    if ($names !== null) return $names;
    $names = [
        'C000001' => '手枪#1', 'C000002' => '手枪#2', 'C000003' => '冲锋枪#1', 'C000004' => '冲锋枪#2',
        'C000005' => '步枪#01AR', 'C000006' => '步枪#02AK', 'C000007' => '步枪#03灵魂',
        'C000008' => '匕首#01', 'C000009' => '匕首#02 “被污染的匕首”',
        'C000010' => '指虎#01仁慈', 'C000011' => '指虎#01暴力',
        'C000012' => '长鞭#01普通', 'C000013' => '长鞭#02荆棘',
        'C000014' => '长鞭#03血管（吸血效果+30%）', 'C000015' => '长鞭#04灵魂（每次攻击回复玩家SAN值0.1）',
        'C000016' => '自制弓箭', 'C000017' => '自制手枪#1', 'C000018' => '巨型棒棒糖',
        'C000019' => '达尔拉里的手#支配者',
        'C000020' => '布甲#01', 'C000021' => '布甲#02', 'C000022' => '布甲#03',
        'C000023' => '铁甲#01', 'C000024' => '铁甲#02', 'C000025' => '铁甲#03',
        'C000026' => '复合装甲#01', 'C000027' => '复合装甲#02', 'C000028' => '复合装甲#03',
        'C000029' => '节日限定甲#01',
        'D000001' => '弩箭', 'D000002' => '手枪子弹', 'D000003' => '冲锋前子弹', 'D000004' => '狙击枪子弹',
        'D000005' => '手枪子弹#破甲弹', 'D000006' => '冲锋枪子弹#破甲弹', 'D000007' => '狙击枪子弹#破甲弹',
        'D000008' => '玩具熊的眼睛', 'D000009' => '达尔拉里的蝴蝶结', 'D000010' => '达尔拉里的眼泪',
        'D000011' => '达尔拉里的玩具熊', 'D000012' => '达尔拉里的灵魂', 'D000013' => '高级灵魂核心',
        'D000014' => '达尔拉里的灵魂粒子', 'D000015' => '饼干核心', 'D000016' => '饼干灵魂',
        'D000017' => '糖果护甲碎片', 'D000018' => '糖果核心', 'D000019' => '矮人灵魂',
        'D000020' => '兽人灵魂', 'D000021' => '兽人皮肤（防御类）', 'D000022' => '精灵灵魂',
        'D000023' => '精灵皮肤（防御类）', 'D000024' => '石像鬼的毛发', 'D000025' => '吸血鬼的血液',
        'D000026' => '武器核心（可叠加5个）', 'D000027' => '傀儡心', 'D000028' => '隐身巧克力',
        'D000029' => '血瓶#普通', 'D000030' => '血瓶#珍贵',
        'D000031' => '糖果炸弹#Ⅰ级', 'D000032' => '糖果炸弹#Ⅱ级',
        'D000033' => '傀儡娃娃#高兴', 'D000034' => '傀儡娃娃#恶毒',
        'D000035' => '傀儡娃娃#保护', 'D000036' => '傀儡娃娃#现实', 'D000037' => '存档胶囊'
    ];
    return $names;
}

function spreadsheetItemDefinition($itemId) {
    $names = spreadsheetItemNames();
    if (!isset($names[$itemId])) return null;
    $number = (int)substr($itemId, 1);
    $quality = 'common';
    if ($itemId[0] === 'C') {
        if (in_array($number, [7, 9, 11, 13, 16, 17, 26, 27, 28], true)) $quality = 'rare';
        if (in_array($number, [14, 15, 18, 29], true)) $quality = 'epic';
        if ($number === 19) $quality = 'legendary';
        if (in_array($number, [2, 4, 6, 10, 23, 24, 25], true)) $quality = 'uncommon';
        $type = $number >= 20 ? '护甲' : '武器';
    } else {
        if (in_array($number, [5, 6, 7, 8, 15, 16, 17, 18, 19, 20, 22, 24, 26, 29, 31, 33], true)) $quality = 'uncommon';
        if (in_array($number, [9, 10, 14, 21, 23, 25, 27, 28, 30, 32, 34, 35], true)) $quality = 'rare';
        if (in_array($number, [11, 13, 36, 37], true)) $quality = 'epic';
        if ($number === 12) $quality = 'legendary';
        if ($number <= 7) $type = '弹药';
        elseif (in_array($number, [28, 29, 30, 31, 32, 37], true)) $type = '消耗品';
        elseif ($number >= 33 && $number <= 36) $type = '傀儡道具';
        else $type = '材料';
    }
    $config = itemQualityConfig($quality);
    return [
        'id' => $itemId,
        'name' => $names[$itemId],
        'quality' => $quality,
        'qualityLabel' => $config['label'],
        'icon' => $config['icon'],
        'type' => $type,
        'desc' => '《旅途传说》策划案中的' . $type . '，可用于网页收藏、交易和后续游戏系统。',
        'props' => [
            '品质' => $config['label'],
            '编号' => $itemId,
            '分类' => $type,
            '来源' => '策划案物品表'
        ]
    ];
}

function baseItemDefinition($itemId) {
    $dungeonItem = journey_dungeon_item_definition((string)$itemId);
    if ($dungeonItem) return $dungeonItem;
    if ($itemId === 'welcome_journey') {
        return [
            'id' => 'welcome_journey',
            'name' => '欢迎来到旅途传说',
            'quality' => 'legendary',
            'qualityLabel' => '传说',
            'icon' => '✦',
            'type' => '网页道具',
            'desc' => '每个注册账号默认拥有的纪念道具，可用于网页身份展示和后续活动。',
            'props' => ['来源' => '账号创建', '用途' => '网页系统道具', '状态' => '永久保留']
        ];
    }
    if ($itemId === 'nut_cola') {
        return [
            'id' => 'nut_cola',
            'name' => '坚果的可乐',
            'quality' => 'legendary',
            'qualityLabel' => '传说',
            'icon' => '🥤',
            'type' => '传说彩蛋收藏品',
            'desc' => '“如果坚果直接把他的可乐给你，那要小心了，他可能已经摇了至少30分钟。”',
            'special' => true,
            'marketTradable' => true,
            'recyclable' => true,
            'props' => [
                '品质' => '传说',
                '持有者' => '坚果',
                '摇晃时间' => '至少30分钟',
                '危险等级' => '开罐即爆发',
                '收藏编号' => 'NUT-COLA-001'
            ]
        ];
    }

    $spreadsheetItem = spreadsheetItemDefinition($itemId);
    if ($spreadsheetItem) return $spreadsheetItem;

    if (!preg_match('/^(common|uncommon|rare|epic|legendary)_(\d{3})$/', $itemId, $m)) {
        return [
            'id' => $itemId,
            'name' => $itemId,
            'quality' => 'common',
            'qualityLabel' => '普通',
            'icon' => '?',
            'type' => '未知道具',
            'desc' => '这个道具暂时还没有配置说明。',
            'props' => ['编号' => $itemId]
        ];
    }

    $quality = $m[1];
    $num = (int)$m[2];
    $config = itemQualityConfig($quality);
    $commonNames = ['旧布片', '磨损纽扣', '裂纹石子', '空玻璃瓶', '褪色纸条', '木质齿轮', '断裂铅笔', '生锈小钉', '糖纸碎片', '暗淡贝壳', '干枯花瓣', '旧钥匙胚', '破损绷带', '细麻绳', '灰尘羽毛'];
    $highNames = ['星火徽记', '月影晶片', '梦境核心', '绯红吊坠', '雾银指环', '秘纹齿轮', '远古书签', '琥珀棋子', '烛光宝珠', '镜界残片'];
    $nameBase = $quality === 'common' ? $commonNames[($num - 1) % count($commonNames)] : $highNames[($num - 1) % count($highNames)];
    $prefixes = ['旅途', '旧日', '糖果镇', '玩具墓园', '梦树', '星末', '红门', '回声', '村庄', '迷雾'];
    $powerMin = $config['power'][0];
    $powerMax = $config['power'][1];
    $power = $powerMin + (($num * 7) % max(1, $powerMax - $powerMin + 1));
    return [
        'id' => $itemId,
        'name' => $prefixes[($num - 1) % count($prefixes)] . '·' . $nameBase,
        'quality' => $quality,
        'qualityLabel' => $config['label'],
        'icon' => $config['icon'],
        'type' => $quality === 'common' ? '杂物' : '高品质杂物',
        'desc' => $quality === 'common' ? '旅途中随手收集的小杂物，也许以后会有用途。' : '带有特殊气息的网页道具，可用于市场交易和后续活动。',
        'props' => [
            '品质' => $config['label'],
            '编号' => strtoupper($quality) . '-' . str_pad((string)$num, 3, '0', STR_PAD_LEFT),
            '网页价值' => (string)$power,
            '分类' => $quality === 'common' ? '普通杂物' : '高品质收藏'
        ]
    ];
}

function managedItemMap($refresh = false) {
    static $cached = null;
    if (!$refresh && is_array($cached)) return $cached;
    $items = journey_store_get('managed_items', []);
    if (!is_array($items)) return [];
    $map = [];
    foreach ($items as $item) {
        if (is_array($item) && !empty($item['id'])) $map[(string)$item['id']] = $item;
    }
    $cached = $map;
    return $cached;
}

function itemDefinition($itemId) {
    $itemId = (string)$itemId;
    $managed = managedItemMap();
    $override = $managed[$itemId] ?? null;
    if (is_array($override) && !empty($override['custom'])) {
        $quality = in_array(($override['quality'] ?? ''), ['common','uncommon','rare','epic','legendary'], true) ? $override['quality'] : 'common';
        $config = itemQualityConfig($quality);
        return [
            'id' => $itemId,
            'name' => (string)($override['name'] ?? $itemId),
            'quality' => $quality,
            'qualityLabel' => $config['label'],
            'icon' => (string)($override['icon'] ?? $config['icon']),
            'type' => (string)($override['type'] ?? '自定义物品'),
            'desc' => (string)($override['desc'] ?? ''),
            'props' => ['品质' => $config['label'], '编号' => $itemId, '分类' => (string)($override['type'] ?? '自定义物品')]
        ];
    }
    $base = baseItemDefinition($itemId);
    if (is_array($override)) {
        foreach (['name','icon','type','desc'] as $field) {
            if (isset($override[$field]) && $override[$field] !== '') $base[$field] = (string)$override[$field];
        }
        if (isset($override['quality']) && in_array($override['quality'], ['common','uncommon','rare','epic','legendary'], true)) {
            $base['quality'] = $override['quality'];
            $config = itemQualityConfig($override['quality']);
            $base['qualityLabel'] = $config['label'];
            if (empty($override['icon'])) $base['icon'] = $config['icon'];
        }
    }
    return $base;
}

function lotteryItemPool() {
    $pool = [];
    for ($i = 1; $i <= 150; $i++) $pool[] = 'common_' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
    for ($i = 1; $i <= 20; $i++) $pool[] = 'uncommon_' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
    for ($i = 1; $i <= 15; $i++) $pool[] = 'rare_' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
    for ($i = 1; $i <= 10; $i++) $pool[] = 'epic_' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
    for ($i = 1; $i <= 5; $i++) $pool[] = 'legendary_' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
    $pool = array_merge($pool, array_keys(spreadsheetItemNames()));
    $managed = managedItemMap();
    foreach ($managed as $itemId => $item) {
        if (!empty($item['custom']) && empty($item['deleted'])) $pool[] = $itemId;
    }
    $pool = array_values(array_unique($pool));
    return array_values(array_filter($pool, function($itemId) use ($managed) { return empty($managed[$itemId]['deleted']); }));
}

function lotteryPoolForQuality($quality) {
    return array_values(array_filter(lotteryItemPool(), function($itemId) use ($quality) {
        return (itemDefinition($itemId)['quality'] ?? 'common') === $quality;
    }));
}

function lotteryQualityWeights() {
    $defaults = [
        'common' => 85600,
        'uncommon' => 6900,
        'rare' => 4600,
        'epic' => 2800,
        'legendary' => 100
    ];
    $stored = journey_setting_get('lottery.weights', $defaults);
    if (!is_array($stored)) return $defaults;
    $weights = [];
    foreach ($defaults as $quality => $fallback) {
        $weights[$quality] = max(0, (int)($stored[$quality] ?? $fallback));
    }
    return array_sum($weights) > 0 ? $weights : $defaults;
}

function lotteryRateSettings() {
    $weights = lotteryQualityWeights();
    $total = max(1, array_sum($weights));
    $rates = [];
    foreach ($weights as $quality => $weight) {
        $rates[$quality] = round(((int)$weight / $total) * 100, 3);
    }
    return $rates;
}

function lotteryRateRows() {
    $labels = [
        'common' => '普通',
        'uncommon' => '精良',
        'rare' => '稀有',
        'epic' => '史诗',
        'legendary' => '传说'
    ];
    $rows = [];
    foreach (lotteryRateSettings() as $quality => $percentage) {
        $display = rtrim(rtrim(number_format($percentage, 3, '.', ''), '0'), '.');
        $rows[] = [
            'quality' => $quality,
            'label' => $labels[$quality],
            'rate' => $display . '%',
            'probability' => $percentage
        ];
    }
    return $rows;
}

function randomLotteryItemId() {
    $weights = lotteryQualityWeights();
    $quality = randomLotteryQuality($weights);
    return randomLotteryItemIdForQuality($quality);
}

function randomLotteryQuality($weights) {
    $weights = is_array($weights) ? $weights : [];
    foreach ($weights as $quality => $weight) $weights[$quality] = max(0, (int)$weight);
    $total = array_sum($weights);
    if ($total < 1) return 'common';
    $roll = random_int(1, array_sum($weights));
    $quality = 'common';
    $cursor = 0;
    foreach ($weights as $candidate => $weight) {
        $cursor += $weight;
        if ($roll <= $cursor) {
            $quality = $candidate;
            break;
        }
    }
    return $quality;
}

function randomLotteryItemIdForQuality($quality) {
    $pool = lotteryPoolForQuality($quality);
    if (!$pool) $pool = lotteryPoolForQuality('common');
    return $pool[random_int(0, count($pool) - 1)];
}

function lotteryPityConfig() {
    return [
        'uncommonHard' => 10,
        'epicHard' => 40,
        'legendarySoftStart' => 350,
        'legendaryHard' => 600,
        'legendarySoftMaxRate' => 0.02
    ];
}

function lotteryPityPayload($user) {
    $config = lotteryPityConfig();
    $uncommon = max(0, min($config['uncommonHard'] - 1, (int)($user['lotteryUncommonPity'] ?? 0)));
    $epic = max(0, min($config['epicHard'] - 1, (int)($user['lotteryEpicPity'] ?? 0)));
    $legendary = max(0, min($config['legendaryHard'] - 1, (int)($user['lotteryLegendaryPity'] ?? 0)));
    return [
        'uncommon' => $uncommon,
        'uncommonHard' => $config['uncommonHard'],
        'epic' => $epic,
        'epicHard' => $config['epicHard'],
        'legendary' => $legendary,
        'legendarySoftStart' => $config['legendarySoftStart'],
        'legendaryHard' => $config['legendaryHard'],
        'softActive' => ($legendary + 1) >= $config['legendarySoftStart']
    ];
}

function drawLotteryItemWithPity(&$user) {
    $config = lotteryPityConfig();
    $uncommonMisses = max(0, (int)($user['lotteryUncommonPity'] ?? 0));
    $epicMisses = max(0, (int)($user['lotteryEpicPity'] ?? 0));
    $legendaryMisses = max(0, (int)($user['lotteryLegendaryPity'] ?? 0));
    $uncommonAttempt = $uncommonMisses + 1;
    $epicAttempt = $epicMisses + 1;
    $legendaryAttempt = $legendaryMisses + 1;
    $weights = lotteryQualityWeights();
    $triggered = '';

    if ($legendaryAttempt >= $config['legendaryHard']) {
        $quality = 'legendary';
        $triggered = 'legendary';
    } else {
        if ($legendaryAttempt >= $config['legendarySoftStart']) {
            $total = max(1, array_sum($weights));
            $baseLegendaryWeight = max(0, (int)($weights['legendary'] ?? 0));
            $baseRate = $baseLegendaryWeight / $total;
            $span = max(1, $config['legendaryHard'] - $config['legendarySoftStart']);
            $progress = min(1, max(0, ($legendaryAttempt - $config['legendarySoftStart'] + 1) / $span));
            $targetRate = $baseRate + (max($baseRate, $config['legendarySoftMaxRate']) - $baseRate) * $progress;
            $targetWeight = max($baseLegendaryWeight, (int)round($total * $targetRate));
            $addedWeight = max(0, $targetWeight - $baseLegendaryWeight);
            $weights['legendary'] = $targetWeight;
            $weights['common'] = max(0, (int)($weights['common'] ?? 0) - $addedWeight);
        }
        if ($epicAttempt >= $config['epicHard']) {
            $weights['common'] = 0;
            $weights['uncommon'] = 0;
            $weights['rare'] = 0;
            $weights['epic'] = max(1, (int)($weights['epic'] ?? 0));
            $triggered = 'epic';
        } elseif ($uncommonAttempt >= $config['uncommonHard']) {
            $weights['common'] = 0;
            $triggered = 'uncommon';
        }
        $quality = randomLotteryQuality($weights);
        if ($quality === 'legendary' && $triggered === 'epic') $triggered = '';
    }

    if ($quality === 'legendary') {
        $user['lotteryUncommonPity'] = 0;
        $user['lotteryEpicPity'] = 0;
        $user['lotteryLegendaryPity'] = 0;
    } else {
        $user['lotteryUncommonPity'] = $quality === 'common'
            ? min($config['uncommonHard'] - 1, $uncommonMisses + 1)
            : 0;
        $user['lotteryLegendaryPity'] = min($config['legendaryHard'] - 1, $legendaryMisses + 1);
        $user['lotteryEpicPity'] = $quality === 'epic'
            ? 0
            : min($config['epicHard'] - 1, $epicMisses + 1);
    }

    return [
        'itemId' => randomLotteryItemIdForQuality($quality),
        'quality' => $quality,
        'triggered' => $triggered
    ];
}

function driftBottleStore() {
    $store = journey_store_get('drift_bottles', []);
    if (!is_array($store)) $store = [];
    return [
        'bottles' => is_array($store['bottles'] ?? null) ? array_values($store['bottles']) : [],
        'daily' => is_array($store['daily'] ?? null) ? $store['daily'] : [],
        'pendingItems' => is_array($store['pendingItems'] ?? null) ? $store['pendingItems'] : []
    ];
}

function normalizeDriftText($value, $limit) {
    $text = trim(strip_tags((string)$value));
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit * 3);
}

function driftDailyRecord($store, $userId, $date = null) {
    $date = $date ?: date('Y-m-d');
    $record = is_array($store['daily'][$userId] ?? null) ? $store['daily'][$userId] : [];
    if (($record['date'] ?? '') !== $date) {
        $record = ['date' => $date, 'throws' => 0, 'picks' => 0, 'seen' => []];
    }
    $record['throws'] = max(0, (int)($record['throws'] ?? 0));
    $record['picks'] = max(0, (int)($record['picks'] ?? 0));
    $record['seen'] = is_array($record['seen'] ?? null) ? array_values(array_unique(array_map('strval', $record['seen']))) : [];
    return $record;
}

function publicDriftBottle($bottle, $viewerId = '', $admin = false) {
    $anonymous = !empty($bottle['anonymous']);
    $payload = [
        'id' => (string)($bottle['id'] ?? ''),
        'content' => (string)($bottle['content'] ?? ''),
        'anonymous' => $anonymous,
        'authorName' => $anonymous && !$admin ? '匿名旅人' : (string)($bottle['authorName'] ?? '未知旅人'),
        'createdAt' => (string)($bottle['createdAt'] ?? ''),
        'commentCount' => count(is_array($bottle['comments'] ?? null) ? $bottle['comments'] : []),
        'comments' => []
    ];
    if (!$anonymous || $admin) $payload['authorId'] = (string)($bottle['authorId'] ?? '');
    foreach ((is_array($bottle['comments'] ?? null) ? $bottle['comments'] : []) as $comment) {
        $commentAnonymous = !empty($comment['anonymous']);
        $row = [
            'id' => (string)($comment['id'] ?? ''),
            'content' => (string)($comment['content'] ?? ''),
            'anonymous' => $commentAnonymous,
            'authorName' => $commentAnonymous && !$admin ? '匿名旅人' : (string)($comment['authorName'] ?? '未知旅人'),
            'createdAt' => (string)($comment['createdAt'] ?? ''),
            'isMine' => $viewerId !== '' && hash_equals((string)($comment['authorId'] ?? ''), $viewerId)
        ];
        if (!$commentAnonymous || $admin) $row['authorId'] = (string)($comment['authorId'] ?? '');
        $payload['comments'][] = $row;
    }
    return $payload;
}

function driftBottleStatusPayload($store, $userId) {
    $daily = driftDailyRecord($store, $userId);
    $todayThrowers = 0;
    foreach (($store['daily'] ?? []) as $record) {
        if (is_array($record) && ($record['date'] ?? '') === $daily['date'] && (int)($record['throws'] ?? 0) > 0) $todayThrowers++;
    }
    $pending = is_array($store['pendingItems'][$userId] ?? null) ? $store['pendingItems'][$userId] : null;
    if ($pending && (int)($pending['expiresAt'] ?? 0) < time()) $pending = null;
    return [
        'date' => $daily['date'],
        'throwsUsed' => min(3, $daily['throws']),
        'throwsLeft' => max(0, 3 - $daily['throws']),
        'picksUsed' => min(20, $daily['picks']),
        'picksLeft' => max(0, 20 - $daily['picks']),
        'goldPerThrow' => 5,
        'todayThrowers' => $todayThrowers,
        'pendingItem' => $pending ? [
            'token' => (string)($pending['token'] ?? ''),
            'item' => itemDefinition((string)($pending['itemId'] ?? '')),
            'expiresAt' => date('Y-m-d H:i:s', (int)$pending['expiresAt'])
        ] : null
    ];
}

function itemCatalog() {
    $items = [];
    $managed = managedItemMap();
    $builtInItems = [];
    if (empty($managed['welcome_journey']['deleted'])) $builtInItems[] = 'welcome_journey';
    if (empty($managed['nut_cola']['deleted'])) $builtInItems[] = 'nut_cola';
    $catalogIds = array_merge($builtInItems, lotteryItemPool(), array_map(function($id) { return 'd_' . $id; }, array_keys(journey_dungeon_items())));
    foreach ($catalogIds as $itemId) {
        $item = itemDefinition($itemId);
        $item['systemPrice'] = itemSystemPrice($itemId);
        $items[] = $item;
    }
    return $items;
}

function lotteryEconomyConfig() {
    return [
        'drawCost' => 10,
        // 0 表示不限制每日次数；100 抽仅作为经济平衡的测算单位。
        'dailyLimit' => 0,
        'ranges' => [
            'common' => [3, 6],
            'uncommon' => [7, 14],
            'rare' => [17, 34],
            'epic' => [80, 160],
            'legendary' => [900, 1800]
        ],
        'volatility' => [
            'common' => 0.04,
            'uncommon' => 0.08,
            'rare' => 0.12,
            'epic' => 0.17,
            'legendary' => 0.24
        ]
    ];
}

function qualityDailyMarketMultiplier($quality, $date = null) {
    $config = lotteryEconomyConfig();
    $quality = array_key_exists($quality, $config['volatility']) ? $quality : 'common';
    $date = $date ?: date('Y-m-d');
    $seed = hexdec(substr(hash('sha256', 'journey-market|' . $quality . '|' . $date), 0, 8));
    $unit = ($seed / 4294967295) * 2 - 1;
    return 1 + $unit * (float)$config['volatility'][$quality];
}

function itemSystemPrice($itemId, $date = null) {
    if (journey_dungeon_item_definition((string)$itemId)) return 0;
    if ($itemId === 'welcome_journey') {
        return 50;
    }
    if ($itemId === 'nut_cola') {
        return max(1, (int)round(1500 * qualityDailyMarketMultiplier('legendary', $date)));
    }
    $managed = managedItemMap();
    $definition = itemDefinition($itemId);
    $quality = $definition['quality'] ?? 'common';
    $ranges = lotteryEconomyConfig()['ranges'];
    $range = $ranges[$quality] ?? $ranges['common'];
    $isLotteryItem = in_array((string)$itemId, lotteryItemPool(), true);
    if (!$isLotteryItem && isset($managed[$itemId]['price'])) {
        $basePrice = max($range[0], min($range[1], (int)$managed[$itemId]['price']));
    } else {
        $seed = hexdec(substr(hash('sha256', 'journey-item-price|' . $itemId), 0, 8));
        if ($quality === 'common') {
            // 约一成普通物品取区间上限，其余保持极低回收价。
            $basePrice = ($seed % 10 === 0) ? $range[1] : $range[0];
        } else {
            $span = $range[1] - $range[0] + 1;
            $basePrice = $range[0] + ($seed % $span);
        }
    }
    return max(1, (int)round($basePrice * qualityDailyMarketMultiplier($quality, $date)));
}

function keyiContactProfile() {
    $avatar = journey_setting_get('contacts.keyi_avatar', null);
    if (!is_array($avatar) || empty($avatar['type'])) {
        $avatar = ['type' => 'initial', 'text' => '翼', 'color' => '#8f2730'];
    }
    return [
        'id' => 'keyi',
        'name' => (string)journey_setting_get('contacts.keyi_name', '可翼'),
        'title' => (string)journey_setting_get('contacts.keyi_title', '良心商人'),
        'description' => (string)journey_setting_get('contacts.keyi_description', '不问来处，只谈今天的价格。货单每天零点刷新。'),
        'avatar' => $avatar
    ];
}

function hotdogContactProfile() {
    $avatar = journey_setting_get('contacts.hotdog_avatar', null);
    if (!is_array($avatar) || empty($avatar['type'])) $avatar = ['type' => 'initial', 'text' => '热', 'color' => '#b65c35'];
    return [
        'id' => 'hotdog',
        'name' => (string)journey_setting_get('contacts.hotdog_name', '阿尔法'),
        'title' => (string)journey_setting_get('contacts.hotdog_title', '地牢物资联络人'),
        'description' => (string)journey_setting_get('contacts.hotdog_description', '只接受官网物品以物易物，也可以回收符合条件的 [D] 地牢物品。'),
        'avatar' => $avatar
    ];
}

function jackContactProfile() {
    $avatar = journey_setting_get('contacts.jack_avatar', null);
    return ['id'=>'jack', 'name'=>(string)journey_setting_get('contacts.jack_name','杰克'), 'title'=>(string)journey_setting_get('contacts.jack_title','军火商'), 'description'=>(string)journey_setting_get('contacts.jack_description','枪支、弹药和可靠的火力。弹药 10 翼币 1 发。'), 'avatar'=>is_array($avatar)?$avatar:['type'=>'initial','text'=>'杰','color'=>'#b66a55']];
}

function jackMarketPayload($user): array {
    $items = [
        ['offerId'=>'jack_scatter_gun','itemId'=>'d_scatter_gun','price'=>500,'quality'=>'epic','fixed'=>true],
        ['offerId'=>'jack_laser_gun','itemId'=>'d_laser_gun','price'=>800,'quality'=>'epic','fixed'=>true],
        ['offerId'=>'jack_ammo','itemId'=>'d_modern_ammo','price'=>10,'quality'=>'common','fixed'=>true,'count'=>1]
    ];
    $gunPrices = [180,240,320,380,460,560,680,800,950,1150,1350,1550,1800,2200,2800,3400,4200,5200,6500,8000];
    $gunQualities = ['common','common','uncommon','uncommon','uncommon','rare','rare','rare','rare','epic','epic','epic','epic','epic','legendary','legendary','legendary','legendary','legendary','legendary'];
    foreach ($gunPrices as $index => $price) {
        $slot = str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT);
        $items[] = ['offerId'=>'jack_gun_'.$slot,'itemId'=>'d_gun_'.$slot,'price'=>$price,'quality'=>$gunQualities[$index],'fixed'=>true];
    }
    $offers=[];
    foreach($items as $slot=>$offer){
        $item=itemDefinition($offer['itemId']);
        if (!$item) $item=['id'=>$offer['itemId'],'name'=>$offer['itemId']==='d_modern_ammo'?'现代弹药':($offer['itemId']==='d_laser_gun'?'脉冲激光枪':'碎岩霰铳'),'icon'=>$offer['itemId']==='d_modern_ammo'?'▪':($offer['itemId']==='d_laser_gun'?'⚡':'≋'),'desc'=>'黑暗地牢专用装备'];
        $offers[]=['offerId'=>$offer['offerId'],'slot'=>$slot,'itemId'=>$offer['itemId'],'item'=>$item,'quality'=>$offer['quality'],'price'=>$offer['price'],'count'=>$offer['count']??1,'purchased'=>false];
    }
    return ['date'=>date('Y-m-d'),'contact'=>jackContactProfile(),'offers'=>$offers,'purchasedCount'=>0,'transactionType'=>'jack','wingCoins'=>0];
}

function georgeContactProfile() {
    $avatar = journey_setting_get('contacts.george_avatar', null);
    if (!is_array($avatar) || empty($avatar['type'])) $avatar = ['type' => 'initial', 'text' => '乔', 'color' => '#9a6138'];
    return ['id'=>'george','name'=>(string)journey_setting_get('contacts.george_name','乔治'),'title'=>(string)journey_setting_get('contacts.george_title','特效收藏家'),'description'=>(string)journey_setting_get('contacts.george_description','我不卖力量，只卖让你的旅程更有辨识度的光。'),'avatar'=>$avatar];
}

function qualityDailyAveragePrice($quality, $date = null) {
    $date = $date ?: date('Y-m-d');
    $prices = [];
    foreach (lotteryPoolForQuality($quality) as $itemId) $prices[] = itemSystemPrice($itemId, $date);
    return $prices ? max(1, (int)round(array_sum($prices) / count($prices))) : 1;
}

function hotdogDailyMarket() {
    $today = date('Y-m-d');
    $store = journey_store_get('contact_hotdog_market', []);
    if (is_array($store) && ($store['date'] ?? '') === $today && (int)($store['version'] ?? 0) >= 1 && count($store['offers'] ?? []) === 20) {
        return $store;
    }
    $weights = ['common' => 4500, 'uncommon' => 3000, 'rare' => 1500, 'epic' => 800, 'legendary' => 200];
    $dungeonByQuality = [];
    foreach (journey_dungeon_items() as $item) $dungeonByQuality[$item['quality']][] = $item['id'];
    $offers = [];
    for ($slot = 0; $slot < 20; $slot++) {
        $quality = randomLotteryQuality($weights);
        if (empty($dungeonByQuality[$quality])) $quality = 'common';
        $rewardPool = $dungeonByQuality[$quality];
        $materialPool = lotteryPoolForQuality($quality);
        if (!$materialPool) $materialPool = lotteryPoolForQuality('common');
        $rewardId = $rewardPool[random_int(0, count($rewardPool) - 1)];
        $materialId = $materialPool[random_int(0, count($materialPool) - 1)];
        $offers[] = [
            'offerId' => $today . '_' . str_pad((string)$slot, 2, '0', STR_PAD_LEFT),
            'slot' => $slot,
            'itemId' => $rewardId,
            'quality' => $quality,
            'materialItemId' => $materialId,
            'materialCount' => 1,
            'referenceValue' => qualityDailyAveragePrice($quality, $today)
        ];
    }
    $store = ['version' => 1, 'date' => $today, 'offers' => $offers, 'generatedAt' => date('Y-m-d H:i:s')];
    journey_store_set('contact_hotdog_market', $store);
    return $store;
}

function userItemCount($user, $itemId) {
    $count = 0;
    foreach (normalizeInventorySlots($user['inventory'] ?? [], false) as $item) {
        if (is_array($item) && ($item['id'] ?? '') === $itemId) $count += max(1, (int)($item['count'] ?? 1));
    }
    foreach (normalizeWarehouseSlots($user['warehouse'] ?? [], max(0, (int)($user['warehouseLevel'] ?? 0)) * 21) as $item) {
        if (is_array($item) && ($item['id'] ?? '') === $itemId) $count += max(1, (int)($item['count'] ?? 1));
    }
    return $count;
}

function removeUserItemById(&$user, $itemId, $count) {
    $remaining = max(1, (int)$count);
    $inventory = normalizeInventorySlots($user['inventory'] ?? [], false);
    foreach ($inventory as $index => $item) {
        if ($remaining < 1) break;
        if (!is_array($item) || ($item['id'] ?? '') !== $itemId) continue;
        $take = min($remaining, max(1, (int)($item['count'] ?? 1)));
        $inventory[$index]['count'] -= $take;
        if ($inventory[$index]['count'] <= 0) $inventory[$index] = null;
        $remaining -= $take;
    }
    $user['inventory'] = normalizeInventorySlots($inventory, false);
    $capacity = max(0, (int)($user['warehouseLevel'] ?? 0)) * 21;
    $warehouse = normalizeWarehouseSlots($user['warehouse'] ?? [], $capacity);
    foreach ($warehouse as $index => $item) {
        if ($remaining < 1) break;
        if (!is_array($item) || ($item['id'] ?? '') !== $itemId) continue;
        $take = min($remaining, max(1, (int)($item['count'] ?? 1)));
        $warehouse[$index]['count'] -= $take;
        if ($warehouse[$index]['count'] <= 0) $warehouse[$index] = null;
        $remaining -= $take;
    }
    $user['warehouse'] = normalizeWarehouseSlots($warehouse, $capacity);
    return $remaining === 0;
}

function hotdogMarketPayload($user) {
    $market = hotdogDailyMarket();
    $purchased = (($user['hotdogPurchaseDate'] ?? '') === $market['date'] && is_array($user['hotdogPurchasedOffers'] ?? null))
        ? array_values(array_unique(array_map('strval', $user['hotdogPurchasedOffers']))) : [];
    $offers = [];
    foreach ($market['offers'] as $offer) {
        $material = itemDefinition($offer['materialItemId']);
        $offers[] = array_merge($offer, [
            'item' => itemDefinition($offer['itemId']),
            'material' => $material,
            'ownedMaterialCount' => userItemCount($user, $offer['materialItemId']),
            'purchased' => in_array((string)$offer['offerId'], $purchased, true)
        ]);
    }
    return [
        'date' => $market['date'], 'contact' => hotdogContactProfile(), 'offers' => $offers,
        'purchasedCount' => count($purchased), 'refreshAt' => date('Y-m-d 00:00:00', strtotime('+1 day')),
        'transactionType' => 'barter'
    ];
}

function alphaSellDefinition(string $itemId): ?array {
    $definition = journey_dungeon_item_definition($itemId);
    if (!is_array($definition)) return null;
    $type = (string)($definition['type'] ?? '');
    $localId = substr($itemId, 2);
    // 药水、弹药、钥匙和材料可大量获得，不进入阿尔法的回收报价。
    $blockedIds = ['bandage','smoke_bomb','holy_water','ammo_bundle','arrow_bundle','bolt_bundle','bullet_bundle','mana_charge','relic_shard','brass_key'];
    if (in_array($localId, $blockedIds, true) || str_contains($type, '药水') || str_contains($type, '弹药') || str_contains($type, '材料')) return null;
    return $definition;
}

function alphaSellPrice(string $quality, string $itemId): int {
    $ranges = ['common'=>[1,10], 'uncommon'=>[10,15], 'rare'=>[15,20], 'epic'=>[20,50], 'legendary'=>[400,500]];
    $range = $ranges[$quality] ?? $ranges['common'];
    $seed = crc32(date('Y-m-d') . ':' . $itemId);
    return $range[0] + ($seed % ($range[1] - $range[0] + 1));
}

function alphaSellMarketPayload($user): array {
    $offers = [];
    foreach (normalizeInventorySlots($user['inventory'] ?? [], false) as $slot => $entry) {
        if (!is_array($entry) || empty($entry['id'])) continue;
        $definition = alphaSellDefinition((string)$entry['id']);
        if (!$definition) continue;
        $quality = (string)($definition['quality'] ?? 'common');
        $offers[] = ['offerId'=>'alpha-sell-'.$slot, 'slot'=>(int)$slot, 'itemId'=>(string)$entry['id'], 'item'=>itemDefinition((string)$entry['id']), 'quality'=>$quality, 'price'=>alphaSellPrice($quality, (string)$entry['id']), 'count'=>max(1, (int)($entry['count'] ?? 1))];
    }
    for ($slot = 0; $slot < 21; $slot++) {
        $exists = false; foreach ($offers as $offer) if ((int)$offer['slot'] === $slot) { $exists = true; break; }
        if (!$exists) $offers[] = ['offerId'=>'alpha-sell-'.$slot, 'slot'=>$slot, 'itemId'=>'', 'item'=>['id'=>'','name'=>'空格','icon'=>'◇','desc'=>'暂无可出售的 [D] 物品'], 'quality'=>'common', 'price'=>0, 'count'=>0, 'empty'=>true];
    }
    usort($offers, static fn($a,$b)=>(int)$a['slot'] <=> (int)$b['slot']);
    return ['date'=>date('Y-m-d'), 'contact'=>hotdogContactProfile(), 'offers'=>$offers, 'transactionType'=>'sell', 'wingCoins'=>(int)($user['dungeonWingCoins'] ?? 0)];
}

function keyiDailyMarket() {
    $today = date('Y-m-d');
    $store = journey_store_get('contact_keyi_market', []);
    if (is_array($store) && ($store['date'] ?? '') === $today && (int)($store['version'] ?? 0) >= 2 && count($store['offers'] ?? []) === 30) {
        return $store;
    }

    // 可翼的高品质权重明显高于普通抽奖，但仍以普通物品为主。
    $weights = ['common' => 6000, 'uncommon' => 1800, 'rare' => 1200, 'epic' => 800, 'legendary' => 200];
    $dungeonByQuality = [];
    foreach (journey_dungeon_items() as $dungeonItem) $dungeonByQuality[$dungeonItem['quality']][] = $dungeonItem['id'];
    $hotdogMaterials = array_values(array_unique(array_column(hotdogDailyMarket()['offers'], 'materialItemId')));
    $offers = [];
    for ($slot = 0; $slot < 30; $slot++) {
        $quality = randomLotteryQuality($weights);
        $materialCandidates = array_values(array_filter($hotdogMaterials, function($itemId) use ($quality) {
            return (itemDefinition($itemId)['quality'] ?? 'common') === $quality;
        }));
        $useHotdogMaterial = !empty($materialCandidates) && random_int(1, 100) <= 35;
        $useDungeonItem = !$useHotdogMaterial && ($slot < 10 || random_int(1, 100) <= 30);
        $dungeonPool = $dungeonByQuality[$quality] ?? [];
        $itemId = $useHotdogMaterial ? $materialCandidates[array_rand($materialCandidates)]
            : ($useDungeonItem && $dungeonPool ? $dungeonPool[array_rand($dungeonPool)] : randomLotteryItemIdForQuality($quality));
        $averagePrice = qualityDailyAveragePrice($quality, $today);
        $discount = random_int(10, 50);
        $price = max(1, (int)floor($averagePrice * (100 - $discount) / 100));
        if ($averagePrice > 1) $price = min($price, $averagePrice - 1);
        $offers[] = [
            'offerId' => $today . '_' . str_pad((string)$slot, 2, '0', STR_PAD_LEFT),
            'slot' => $slot,
            'itemId' => $itemId,
            'quality' => $quality,
            'price' => $price,
            'averagePrice' => $averagePrice,
            'discount' => $discount
            , 'hotdogMaterial' => in_array($itemId, $hotdogMaterials, true)
        ];
    }
    $store = ['version' => 2, 'date' => $today, 'offers' => $offers, 'generatedAt' => date('Y-m-d H:i:s')];
    journey_store_set('contact_keyi_market', $store);
    return $store;
}

function keyiMarketPayload($user) {
    $market = keyiDailyMarket();
    $purchased = (($user['keyiPurchaseDate'] ?? '') === $market['date'] && is_array($user['keyiPurchasedOffers'] ?? null))
        ? array_values(array_unique(array_map('strval', $user['keyiPurchasedOffers']))) : [];
    $offers = [];
    foreach ($market['offers'] as $offer) {
        $item = itemDefinition($offer['itemId']);
        $item['systemPrice'] = itemSystemPrice($offer['itemId'], $market['date']);
        $item['recyclable'] = !journey_dungeon_item_definition((string)$offer['itemId']);
        $offers[] = array_merge($offer, [
            'item' => $item,
            'purchased' => in_array((string)$offer['offerId'], $purchased, true)
        ]);
    }
    return [
        'date' => $market['date'],
        'contact' => keyiContactProfile(),
        'offers' => $offers,
        'purchasedCount' => count($purchased),
        'refreshAt' => date('Y-m-d 00:00:00', strtotime('+1 day')),
        'refreshLeft' => max(0, 3 - (($user['keyiRefreshDate'] ?? '') === date('Y-m-d') ? (int)($user['keyiRefreshCount'] ?? 0) : 0))
    ];
}

function wishingWellInventoryPayload($user) {
    $slots = normalizeInventorySlots($user['inventory'] ?? [], false);
    $items = [];
    foreach ($slots as $slotIndex => $slot) {
        if (!is_array($slot) || empty($slot['id'])) continue;
        $item = itemDefinition($slot['id']);
        if (!empty($slot['customName'])) $item['name'] = $slot['customName'];
        $items[] = [
            'slotIndex' => $slotIndex,
            'count' => max(1, (int)($slot['count'] ?? 1)),
            'customName' => (string)($slot['customName'] ?? ''),
            'item' => $item
        ];
    }
    return $items;
}

function wishingWellHistoryPayload() {
    $history = journey_store_get('wishing_well_history', []);
    if (!is_array($history)) return [];
    return array_slice(array_values(array_filter($history, 'is_array')), 0, 10);
}

function recordWishingWellHistory($user, $offering, $doubled) {
    $entry = [
        'id' => bin2hex(random_bytes(8)),
        'user' => (string)($user['user'] ?? '未知旅人'),
        'createdAt' => date('Y-m-d H:i:s'),
        'success' => (bool)$doubled,
        'offering' => ($offering['kind'] ?? '') === 'gold'
            ? ('金币 × ' . max(1, (int)($offering['amount'] ?? 1)))
            : (string)($offering['item']['name'] ?? '一件物品')
    ];
    journey_store_mutate('wishing_well_history', function($history) use ($entry) {
        if (!is_array($history)) $history = [];
        array_unshift($history, $entry);
        return array_slice($history, 0, 10);
    });
    return wishingWellHistoryPayload();
}

function rpsArenaState() {
    $state = journey_store_get('rps_arena', []);
    if (!is_array($state)) $state = [];
    if (!is_array($state['rooms'] ?? null)) $state['rooms'] = [];
    if (!is_array($state['results'] ?? null)) $state['results'] = [];
    return $state;
}

function rpsUserName($user) {
    $name = trim((string)($user['displayName'] ?? $user['user'] ?? $user['username'] ?? ''));
    return $name !== '' ? $name : '玩家' . (string)($user['userId'] ?? '');
}

function rpsItemOptions($user) {
    $rows = [];
    foreach (['inventory', 'warehouse'] as $storage) {
        $slots = $storage === 'inventory'
            ? normalizeInventorySlots($user[$storage] ?? [], false)
            : normalizeWarehouseSlots($user[$storage] ?? [], (int)($user['warehouseLevel'] ?? 0) * 21);
        foreach ($slots as $slotIndex => $slot) {
            if (!is_array($slot) || empty($slot['id'])) continue;
            $item = itemDefinition((string)$slot['id']);
            if (!empty($slot['customName'])) $item['name'] = (string)$slot['customName'];
            $rows[] = [
                'storage' => $storage, 'slotIndex' => (int)$slotIndex,
                'count' => max(1, (int)($slot['count'] ?? 1)), 'item' => $item
            ];
        }
    }
    return $rows;
}

function rpsTakeItem(&$user, $storage, $slotIndex) {
    $storage = $storage === 'warehouse' ? 'warehouse' : 'inventory';
    ensureEconomyFields($user);
    $slotIndex = (int)$slotIndex;
    if (!is_array($user[$storage][$slotIndex] ?? null)) return null;
    $item = $user[$storage][$slotIndex];
    $item['count'] = 1;
    $user[$storage][$slotIndex]['count'] = max(1, (int)($user[$storage][$slotIndex]['count'] ?? 1)) - 1;
    if ($user[$storage][$slotIndex]['count'] <= 0) $user[$storage][$slotIndex] = null;
    return [
        'itemId' => (string)$item['id'],
        'customName' => (string)($item['customName'] ?? ''),
        'item' => array_merge(itemDefinition((string)$item['id']), !empty($item['customName']) ? ['name' => (string)$item['customName']] : [])
    ];
}

function rpsFindRoomIdForUser($state, $userId) {
    foreach (($state['rooms'] ?? []) as $roomId => $room) {
        foreach (['host', 'guest'] as $side) {
            if ((string)($room[$side]['userId'] ?? '') === (string)$userId) return (string)$roomId;
        }
    }
    return '';
}

function rpsParticipantSide($room, $userId) {
    if ((string)($room['host']['userId'] ?? '') === (string)$userId) return 'host';
    if ((string)($room['guest']['userId'] ?? '') === (string)$userId) return 'guest';
    return '';
}

function rpsReturnItem(&$user, $stake, $subject) {
    if (!is_array($stake) || empty($stake['itemId'])) return '';
    return deliverOverflowItem($user, (string)$stake['itemId'], 1, $subject, (string)($stake['customName'] ?? ''));
}

function rpsSettleRoom(&$state, $roomId, $reason, $winnerId = '') {
    if (!is_array($state['rooms'][$roomId] ?? null)) return;
    $room = $state['rooms'][$roomId];
    $participants = [];
    foreach (['host', 'guest'] as $side) {
        if (!empty($room[$side]['userId'])) $participants[(string)$room[$side]['userId']] = $room[$side];
    }
    $draw = $winnerId === '';
    $now = time();
    $users = [];
    $pdo = journey_db();
    $participantIds = array_keys($participants);
    sort($participantIds, SORT_STRING);
    foreach ($participantIds as $participantId) game_lock_user_for_update($pdo, $participantId);
    foreach ($participants as $participantId => $participant) {
        $player = journey_find_user($participantId);
        if (!is_array($player)) continue;
        ensureEconomyFields($player);
        $users[$participantId] = $player;
    }

    $prizeItem = null;
    $winnerGold = 0;
    if ($draw) {
        foreach ($participants as $participantId => $participant) {
            if (!isset($users[$participantId])) continue;
            $refund = max(0, (int)($participant['ticket'] ?? 0)) + max(0, (int)($participant['stake']['extraGold'] ?? 0));
            $users[$participantId]['gold'] = min(2147483647, (int)$users[$participantId]['gold'] + $refund);
            rpsReturnItem($users[$participantId], $participant['stake'] ?? null, '石头剪刀布平局返还');
        }
    } else {
        foreach ($participants as $participantId => $participant) {
            $winnerGold += max(0, (int)($participant['ticket'] ?? 0)) + max(0, (int)($participant['stake']['extraGold'] ?? 0));
        }
        if (isset($users[$winnerId])) {
            $users[$winnerId]['gold'] = min(2147483647, (int)$users[$winnerId]['gold'] + $winnerGold);
            foreach ($participants as $participantId => $participant) {
                if (is_array($participant['stake'] ?? null) && !empty($participant['stake']['itemId'])) {
                    rpsReturnItem($users[$winnerId], $participant['stake'], $participantId === $winnerId ? '石头剪刀布押注返还' : '石头剪刀布胜利奖品');
                    if ($participantId !== $winnerId) $prizeItem = $participant['stake']['item'] ?? itemDefinition((string)$participant['stake']['itemId']);
                }
            }
        }
    }

    foreach ($users as $player) journey_upsert_legacy_user_internal($pdo, $player);
    foreach ($participants as $participantId => $participant) {
        $state['results'][$participantId] = [
            'roomId' => (string)$roomId, 'reason' => (string)$reason,
            'outcome' => $draw ? 'draw' : ($participantId === $winnerId ? 'win' : 'loss'),
            'winnerId' => (string)$winnerId,
            'winnerName' => $winnerId !== '' ? (string)($participants[$winnerId]['name'] ?? $winnerId) : '',
            'score' => $room['wins'] ?? [], 'lastRound' => $room['lastRound'] ?? null,
            'goldAwarded' => (!$draw && $participantId === $winnerId) ? $winnerGold : 0,
            'prizeItem' => (!$draw && $participantId === $winnerId) ? $prizeItem : null,
            'createdAt' => $now, 'expiresAt' => $now + 600
        ];
    }
    unset($state['rooms'][$roomId]);
}

function rpsMoveWinner($firstMove, $secondMove) {
    if ($firstMove === $secondMove) return 0;
    $wins = ['rock' => 'scissors', 'scissors' => 'paper', 'paper' => 'rock'];
    return (($wins[$firstMove] ?? '') === $secondMove) ? 1 : 2;
}

function rpsAdvanceState(&$state) {
    $now = time();
    foreach ($state['results'] as $userId => $result) {
        if ((int)($result['expiresAt'] ?? 0) <= $now) unset($state['results'][$userId]);
    }
    foreach (array_keys($state['rooms']) as $roomId) {
        if (!is_array($state['rooms'][$roomId] ?? null)) continue;
        $room = &$state['rooms'][$roomId];
        if ($now - (int)($room['createdAt'] ?? $now) >= 1800) {
            unset($room);
            rpsSettleRoom($state, $roomId, 'room_timeout');
            continue;
        }
        if (($room['status'] ?? '') === 'staking' && $now >= (int)($room['stakeDeadline'] ?? 0)) {
            $hostReady = is_array($room['host']['stake'] ?? null);
            $guestReady = is_array($room['guest']['stake'] ?? null);
            $winnerId = ($hostReady xor $guestReady) ? (string)($hostReady ? $room['host']['userId'] : $room['guest']['userId']) : '';
            unset($room);
            rpsSettleRoom($state, $roomId, $winnerId !== '' ? 'stake_timeout_forfeit' : 'stake_timeout_both', $winnerId);
            continue;
        }
        if (($room['status'] ?? '') === 'playing') {
            $hostFresh = $now - (int)($room['host']['lastSeen'] ?? 0) <= 20;
            $guestFresh = $now - (int)($room['guest']['lastSeen'] ?? 0) <= 20;
            if ($hostFresh xor $guestFresh) {
                $winnerId = (string)($hostFresh ? $room['host']['userId'] : $room['guest']['userId']);
                unset($room);
                rpsSettleRoom($state, $roomId, 'disconnect', $winnerId);
                continue;
            }
        }
        if (($room['status'] ?? '') !== 'playing' || $now < (int)($room['roundDeadline'] ?? 0)) {
            unset($room);
            continue;
        }
        $hostId = (string)$room['host']['userId'];
        $guestId = (string)$room['guest']['userId'];
        $moves = ['rock', 'scissors', 'paper'];
        $hostMove = (string)($room['choices'][$hostId] ?? $moves[random_int(0, 2)]);
        $guestMove = (string)($room['choices'][$guestId] ?? $moves[random_int(0, 2)]);
        $roundWinner = rpsMoveWinner($hostMove, $guestMove);
        $roundWinnerId = $roundWinner === 1 ? $hostId : ($roundWinner === 2 ? $guestId : '');
        if ($roundWinnerId !== '') $room['wins'][$roundWinnerId] = (int)($room['wins'][$roundWinnerId] ?? 0) + 1;
        $room['lastRound'] = [
            'round' => (int)($room['round'] ?? 1), 'hostMove' => $hostMove, 'guestMove' => $guestMove,
            'winnerId' => $roundWinnerId, 'resolvedAt' => $now
        ];
        if ($roundWinnerId !== '' && (int)$room['wins'][$roundWinnerId] >= 2) {
            unset($room);
            rpsSettleRoom($state, $roomId, 'victory', $roundWinnerId);
            continue;
        }
        $room['round'] = (int)($room['round'] ?? 1) + 1;
        $room['choices'] = [];
        $room['roundDeadline'] = $now + 10;
        unset($room);
    }
}

function rpsPublicRoom($room, $viewerId = '') {
    $viewerSide = rpsParticipantSide($room, $viewerId);
    $payload = [
        'roomId' => (string)($room['roomId'] ?? ''), 'status' => (string)($room['status'] ?? 'waiting'),
        'createdAt' => (int)($room['createdAt'] ?? 0), 'stakeDeadline' => (int)($room['stakeDeadline'] ?? 0),
        'roundDeadline' => (int)($room['roundDeadline'] ?? 0), 'expiresAt' => (int)($room['createdAt'] ?? 0) + 1800,
        'round' => (int)($room['round'] ?? 0), 'wins' => $room['wins'] ?? [], 'lastRound' => $room['lastRound'] ?? null,
        'mySide' => $viewerSide, 'myChoice' => $viewerSide !== '' ? (string)($room['choices'][$viewerId] ?? '') : ''
    ];
    foreach (['host', 'guest'] as $side) {
        $participant = $room[$side] ?? null;
        if (!is_array($participant)) { $payload[$side] = null; continue; }
        $showStake = ($room['status'] ?? '') === 'playing' || $viewerSide === $side;
        $payload[$side] = [
            'userId' => (string)$participant['userId'], 'name' => (string)$participant['name'],
            'selected' => is_array($participant['stake'] ?? null),
            'stake' => $showStake ? ($participant['stake'] ?? null) : null
        ];
    }
    return $payload;
}

function rpsTransaction($callback) {
    $pdo = journey_db();
    $started = false;
    try {
        if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $started = true; }
        game_lock_store_for_update($pdo, 'rps_arena');
        $state = rpsArenaState();
        rpsAdvanceState($state);
        $result = $callback($state, $pdo);
        journey_store_set_internal($pdo, 'rps_arena', $state);
        if ($started) $pdo->commit();
        return $result;
    } catch (Throwable $exception) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function rpsLobbyPayload($state, $userId) {
    $rooms = [];
    foreach ($state['rooms'] as $room) $rooms[] = rpsPublicRoom($room, $userId);
    usort($rooms, function($a, $b) { return (int)$a['createdAt'] - (int)$b['createdAt']; });
    $myRoomId = rpsFindRoomIdForUser($state, $userId);
    $user = journey_find_user($userId);
    if (is_array($user)) ensureEconomyFields($user);
    return [
        'code' => 'ok', 'rooms' => $rooms, 'roomCount' => count($rooms), 'maxRooms' => 10,
        'myRoom' => $myRoomId !== '' ? rpsPublicRoom($state['rooms'][$myRoomId], $userId) : null,
        'result' => $state['results'][$userId] ?? null,
        'gold' => is_array($user) ? (int)$user['gold'] : 0,
        'unlimitedGold' => is_array($user) ? hasUnlimitedGold($user) : false,
        'items' => is_array($user) ? rpsItemOptions($user) : [], 'serverTime' => time()
    ];
}

function dailyTaskDefinitions() {
    return [
        'post' => ['title' => '发布旅途见闻', 'description' => '在玩家论坛成功发布 1 个主题。', 'target' => 1, 'unit' => '篇'],
        'like' => ['title' => '为同行者点赞', 'description' => '给不同的帖子成功点赞 3 次。', 'target' => 3, 'unit' => '次'],
        'reply' => ['title' => '参与旅途讨论', 'description' => '在玩家论坛成功回复 2 次。', 'target' => 2, 'unit' => '条'],
        'dungeon_kill_10' => ['title'=>'地牢清剿','description'=>'在黑暗地牢击杀 10 只怪物。','target'=>10,'unit'=>'只','event'=>'dungeon_kill','rewardQuality'=>'rare'],
        'dungeon_kill_30' => ['title'=>'深层猎手','description'=>'在黑暗地牢击杀 30 只怪物。','target'=>30,'unit'=>'只','event'=>'dungeon_kill','rewardQuality'=>'epic'],
        'dungeon_kill_60' => ['title'=>'看守者克星','description'=>'在一天内击杀 60 只地牢怪物。','target'=>60,'unit'=>'只','event'=>'dungeon_kill','rewardQuality'=>'legendary'],
        'dungeon_floor_5' => ['title'=>'五层勘探','description'=>'今日累计探索 5 层黑暗地牢。','target'=>5,'unit'=>'层','event'=>'dungeon_floor','rewardQuality'=>'rare'],
        'dungeon_floor_10' => ['title'=>'深入黑暗','description'=>'今日累计探索 10 层黑暗地牢。','target'=>10,'unit'=>'层','event'=>'dungeon_floor','rewardQuality'=>'epic'],
        'dungeon_entry' => ['title'=>'整装出发','description'=>'今日进入黑暗地牢 2 次。','target'=>2,'unit'=>'次','event'=>'dungeon_entry','rewardQuality'=>'rare']
    ];
}

function ensureDailyTask(&$user, $date = null) {
    $date = $date ?: date('Y-m-d');
    $current = is_array($user['dailyTask'] ?? null) ? $user['dailyTask'] : [];
    if (($current['date'] ?? '') === $date && isset(dailyTaskDefinitions()[$current['type'] ?? ''])) {
        $definition = dailyTaskDefinitions()[$current['type']];
        $current['target'] = (int)$definition['target'];
        $current['progress'] = max(0, min((int)$definition['target'], (int)($current['progress'] ?? 0)));
        $current['claimed'] = !empty($current['claimed']);
        $current['seen'] = is_array($current['seen'] ?? null) ? array_values(array_unique(array_map('strval', $current['seen']))) : [];
        $user['dailyTask'] = $current;
        return $current;
    }

    $definitions = dailyTaskDefinitions();
    $types = array_keys($definitions);
    $seed = hexdec(substr(hash('sha256', 'journey-daily-task|' . ($user['userId'] ?? '') . '|' . $date), 0, 8));
    $type = $types[$seed % count($types)];
    $greenPool = lotteryPoolForQuality('uncommon');
    $rewardSeed = hexdec(substr(hash('sha256', 'journey-daily-reward|' . ($user['userId'] ?? '') . '|' . $date), 0, 8));
    $definition = $definitions[$type];
    $rewardItemId = $greenPool ? $greenPool[$rewardSeed % count($greenPool)] : 'uncommon_001';
    $rewardKind = 'gold';
    if (!empty($definition['rewardQuality'])) {
        $pool=[];foreach(journey_dungeon_items() as $item){if(($item['quality']??'')===$definition['rewardQuality'])$pool[]=$item['id'];}
        if($pool){$rewardItemId=$pool[$rewardSeed%count($pool)];$rewardKind='item';}
    }
    $user['dailyTask'] = [
        'date' => $date,
        'type' => $type,
        'target' => (int)$definition['target'],
        'progress' => 0,
        'rewardGold' => $rewardKind==='gold'?itemSystemPrice($rewardItemId, $date):0,
        'rewardItemId' => $rewardItemId,
        'rewardKind' => $rewardKind,
        'claimed' => false,
        'seen' => []
    ];
    return $user['dailyTask'];
}

function recordDailyTaskAction($userId, $type, $evidenceKey, $amount = 1) {
    $definitions = dailyTaskDefinitions();
    if (!isset($definitions[$type]) || $userId === '') return;
    $users = getUsers();
    foreach ($users as &$candidate) {
        if (($candidate['userId'] ?? '') !== $userId) continue;
        ensureEconomyFields($candidate);
        $task = ensureDailyTask($candidate);
        $definition=$definitions[$task['type']??'']??[];
        if ((($task['type'] ?? '') !== $type && ($definition['event']??'') !== $type) || !empty($task['claimed']) || (int)$task['progress'] >= (int)$task['target']) break;
        $evidenceKey = (string)$evidenceKey;
        if ($evidenceKey !== '' && in_array($evidenceKey, $task['seen'], true)) break;
        if ($evidenceKey !== '') $task['seen'][] = $evidenceKey;
        $task['progress'] = min((int)$task['target'], (int)$task['progress'] + max(1,(int)$amount));
        $candidate['dailyTask'] = $task;
        saveUsers($users);
        break;
    }
    unset($candidate);
}

function dailyTaskPayload($user, $posts = []) {
    $task = ensureDailyTask($user);
    $definition = dailyTaskDefinitions()[$task['type']];
    $today = date('Y-m-d');
    $todayPosts = 0;
    $todayReplies = 0;
    foreach ((array)$posts as $post) {
        if (($post['userId'] ?? '') === ($user['userId'] ?? '') && substr((string)($post['time'] ?? ''), 0, 10) === $today) $todayPosts++;
        foreach ((array)($post['reply'] ?? []) as $reply) {
            if (($reply['userId'] ?? '') === ($user['userId'] ?? '') && substr((string)($reply['time'] ?? ''), 0, 10) === $today) $todayReplies++;
        }
    }
    $rewardDefinition = itemDefinition((string)($task['rewardItemId'] ?? 'uncommon_001'));
    $firstPostCompleted = (($user['lastDailyPostReward'] ?? '') === $today) || $todayPosts > 0;
    return [
        'date' => $task['date'],
        'type' => $task['type'],
        'title' => $definition['title'],
        'description' => $definition['description'],
        'target' => (int)$task['target'],
        'progress' => (int)$task['progress'],
        'unit' => $definition['unit'],
        'completed' => (int)$task['progress'] >= (int)$task['target'],
        'claimed' => !empty($task['claimed']),
        'rewardGold' => (int)$task['rewardGold'],
        'rewardKind' => (string)($task['rewardKind'] ?? 'gold'),
        'rewardItemId' => (string)($task['rewardItemId'] ?? ''),
        'rewardItem' => $rewardDefinition,
        'rewardReference' => (string)($rewardDefinition['name'] ?? '绿色品质物品'),
        'xpSources' => [
            ['title' => '每日首次发帖', 'description' => '每天首次发布主题可获得 10 经验。', 'reward' => '10 经验', 'progress' => $firstPostCompleted ? 1 : 0, 'target' => 1, 'completed' => $firstPostCompleted],
            ['title' => '参与帖子回复', 'description' => '每次有效回复随机获得 1-10 经验。', 'reward' => '1-10 经验/次', 'progress' => $todayReplies, 'target' => 1, 'completed' => $todayReplies > 0],
            ['title' => '开启补给箱', 'description' => '每次完成抽奖获得 5 经验。', 'reward' => '5 经验/次', 'progress' => (($user['lotteryDrawDate'] ?? '') === $today) ? (int)($user['lotteryDrawCount'] ?? 0) : 0, 'target' => 1, 'completed' => (($user['lotteryDrawDate'] ?? '') === $today) && (int)($user['lotteryDrawCount'] ?? 0) > 0]
        ]
    ];
}

function qualityMarketHistory($days = 30) {
    $days = max(7, min(60, (int)$days));
    $qualities = ['common', 'uncommon', 'rare', 'epic', 'legendary'];
    $rows = [];
    for ($offset = $days - 1; $offset >= 0; $offset--) {
        $date = date('Y-m-d', strtotime('-' . $offset . ' days'));
        $row = ['date' => $date, 'qualities' => []];
        foreach ($qualities as $quality) {
            $prices = [];
            foreach (lotteryPoolForQuality($quality) as $itemId) {
                $prices[] = itemSystemPrice($itemId, $date);
            }
            if (!$prices) $prices = [1];
            $row['qualities'][$quality] = [
                'min' => min($prices),
                'max' => max($prices),
                'avg' => round(array_sum($prices) / count($prices), 2),
                'multiplier' => round(qualityDailyMarketMultiplier($quality, $date), 4)
            ];
        }
        $rows[] = $row;
    }
    return $rows;
}

function nextItemQuality($quality) {
    $qualities = ['common', 'uncommon', 'rare', 'epic', 'legendary'];
    $index = array_search((string)$quality, $qualities, true);
    return $index === false || $index >= count($qualities) - 1 ? null : $qualities[$index + 1];
}

function getGoldTransfers() {
    $transfers = journey_store_get('gold_transfers', []);
    return is_array($transfers) ? array_values($transfers) : [];
}

function saveGoldTransfers($transfers) {
    journey_store_set('gold_transfers', array_values($transfers));
}

function addUserNotification(&$user, $type, $text, $data = []) {
    if (!isset($user['notifications']) || !is_array($user['notifications'])) $user['notifications'] = [];
    $user['notifications'][] = [
        'id' => date('YmdHis') . '_' . bin2hex(random_bytes(4)),
        'type' => (string)$type,
        'text' => (string)$text,
        'data' => is_array($data) ? $data : [],
        'time' => date('Y-m-d H:i:s'),
        'processed' => false
    ];
    $user['notifications'] = array_slice($user['notifications'], -100);
}

function storeDatabaseImage($dataUrl, $ownerUserId, $purpose, $maxBytes = 900000) {
    if (!preg_match('/^data:image\/(webp|jpe?g|png);base64,([A-Za-z0-9+\/=\r\n]+)$/i', (string)$dataUrl, $matches)) return null;
    $bytes = base64_decode(preg_replace('/\s+/', '', $matches[2]), true);
    if ($bytes === false || strlen($bytes) < 32 || strlen($bytes) > $maxBytes) return null;
    $info = function_exists('getimagesizefromstring') ? @getimagesizefromstring($bytes) : false;
    $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
    if (!in_array($mime, ['image/webp','image/jpeg','image/png'], true)) return null;
    $width = is_array($info) ? (int)($info[0] ?? 0) : 0;
    $height = is_array($info) ? (int)($info[1] ?? 0) : 0;
    if ($width < 1 || $height < 1 || $width > 2400 || $height > 2400 || $width * $height > 6000000) return null;
    $mediaId = hash('sha256', $bytes);
    $pdo = journey_db();
    if (journey_db_driver($pdo) === 'sqlite') {
        $sql = 'INSERT OR IGNORE INTO media_assets (media_id,mime_type,byte_size,owner_user_id,purpose,content,created_at) VALUES (?,?,?,?,?,?,?)';
    } else {
        $sql = 'INSERT IGNORE INTO media_assets (media_id,mime_type,byte_size,owner_user_id,purpose,content,created_at) VALUES (?,?,?,?,?,?,?)';
    }
    $statement = $pdo->prepare($sql);
    $statement->bindValue(1, $mediaId);
    $statement->bindValue(2, $mime);
    $statement->bindValue(3, strlen($bytes), PDO::PARAM_INT);
    $statement->bindValue(4, (string)$ownerUserId);
    $statement->bindValue(5, (string)$purpose);
    $statement->bindValue(6, $bytes, PDO::PARAM_LOB);
    $statement->bindValue(7, date('Y-m-d H:i:s'));
    $statement->execute();
    return ['type' => 'image', 'src' => 'board.php?action=media&id=' . $mediaId, 'mediaId' => $mediaId, 'mime' => $mime];
}

function addWarehouseItem(&$user, $itemId, $count = 1, $customName = '') {
    ensureEconomyFields($user);
    $count = max(1, (int)$count);
    foreach ($user['warehouse'] as &$slot) {
        if (is_array($slot) && ($slot['id'] ?? '') === $itemId && (string)($slot['customName'] ?? '') === (string)$customName) {
            $slot['count'] += $count;
            return true;
        }
    }
    unset($slot);
    $index = array_search(null, $user['warehouse'], true);
    if ($index === false) return false;
    $user['warehouse'][$index] = ['id' => $itemId, 'count' => $count, 'createdAt' => date('Y-m-d H:i:s')];
    if ($customName !== '') $user['warehouse'][$index]['customName'] = $customName;
    return true;
}

function getItemMails() {
    $mails = journey_store_get('item_mail', []);
    return is_array($mails) ? array_values($mails) : [];
}

function createItemMailRecord($recipientId, $itemId, $count, $subject, $customName = '', $senderId = 'SYSTEM', $xp = 0, $gold = 0, $body = '') {
    $itemId = (string)$itemId;
    return [
        'mailId' => date('YmdHis') . '_' . bin2hex(random_bytes(6)),
        'recipientId' => (string)$recipientId,
        'senderId' => (string)$senderId,
        'subject' => (string)$subject,
        'itemId' => $itemId,
        'customName' => (string)$customName,
        'count' => $itemId === '' ? 0 : max(1, (int)$count),
        'xp' => max(0, (int)$xp),
        'gold' => max(0, (int)$gold),
        'body' => (string)$body,
        'status' => 'pending',
        'createdAt' => date('Y-m-d H:i:s'),
        'expiresAt' => date('Y-m-d H:i:s', time() + 10 * 86400)
    ];
}

function sendRewardMail($recipientId, $subject, $body = '', $itemId = '', $count = 0, $xp = 0, $gold = 0, $senderId = 'SYSTEM') {
    $record = createItemMailRecord($recipientId, $itemId, $count, $subject, '', $senderId, $xp, $gold, $body);
    journey_store_mutate('item_mail', function($mails) use ($record) {
        if (!is_array($mails)) $mails = [];
        $mails[] = $record;
        return array_slice($mails, -1000);
    }, []);
    return $record;
}

function sendItemMail($recipientId, $itemId, $count, $subject, $customName = '', $senderId = 'SYSTEM') {
    $record = createItemMailRecord($recipientId, $itemId, $count, $subject, $customName, $senderId);
    journey_store_mutate('item_mail', function($mails) use ($record) {
        if (!is_array($mails)) $mails = [];
        $mails[] = $record;
        return array_slice($mails, -1000);
    }, []);
    return $record;
}

function deliverOverflowItem(&$user, $itemId, $count = 1, $subject = '系统物品附件', $customName = '') {
    if (addInventoryItem($user, $itemId, $count, $customName)) return 'inventory';
    if (addWarehouseItem($user, $itemId, $count, $customName)) return 'warehouse';
    sendItemMail($user['userId'] ?? '', $itemId, $count, $subject, $customName);
    return 'mail';
}

function lotteryTenPayload($user) {
    $batch = $user['lotteryTenPending'] ?? null;
    if (!is_array($batch) || empty($batch['batchId']) || empty($batch['items']) || !is_array($batch['items'])) return null;
    $items = [];
    foreach ($batch['items'] as $entry) {
        if (!is_array($entry) || empty($entry['entryId']) || empty($entry['itemId'])) continue;
        $definition = itemDefinition((string)$entry['itemId']);
        $definition['systemPrice'] = itemSystemPrice((string)$entry['itemId']);
        $items[] = ['entryId' => (string)$entry['entryId'], 'item' => $definition];
    }
    if (!$items) return null;
    return ['batchId' => (string)$batch['batchId'], 'createdAt' => (string)($batch['createdAt'] ?? ''), 'items' => $items];
}

function deliverLotteryTenItem(&$user, $itemId) {
    if (addInventoryItem($user, $itemId, 1)) return 'inventory';
    sendItemMail($user['userId'] ?? '', $itemId, 1, '十连抽物品：背包已满，物品已自动寄送');
    return 'mail';
}

function finalizeLotteryTenBatch(&$user) {
    $batch = $user['lotteryTenPending'] ?? null;
    $result = ['inventory' => 0, 'mail' => 0];
    if (!is_array($batch) || !is_array($batch['items'] ?? null)) {
        unset($user['lotteryTenPending']);
        return $result;
    }
    foreach ($batch['items'] as $entry) {
        $itemId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($entry['itemId'] ?? ''));
        if ($itemId === '') continue;
        $delivery = deliverLotteryTenItem($user, $itemId);
        $result[$delivery]++;
    }
    unset($user['lotteryTenPending']);
    return $result;
}

function normalizeInventory($inventory) {
    if (!is_array($inventory) || count($inventory) === 0) {
        return defaultInventory();
    }
    $normalized = [];
    foreach ($inventory as $item) {
        if (!is_array($item) || empty($item['id'])) continue;
        $normalizedItem = [
            'id' => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$item['id']),
            'count' => max(1, (int)($item['count'] ?? 1)),
            'createdAt' => $item['createdAt'] ?? date('Y-m-d H:i:s')
        ];
        if (!empty($item['customName'])) {
            $customName = trim(strip_tags((string)$item['customName']));
            $normalizedItem['customName'] = function_exists('mb_substr') ? mb_substr($customName, 0, 20, 'UTF-8') : substr($customName, 0, 60);
        }
        $normalized[] = $normalizedItem;
    }
    return $normalized ?: defaultInventory();
}

function formatGameInventory($inventory) {
    $qualityColors = [
        'common'    => 0xaaaaaa,
        'uncommon'  => 0x4ecdc4,
        'rare'      => 0x64b5f6,
        'epic'      => 0xba68c8,
        'legendary' => 0xffd93d
    ];
    $inventory = normalizeInventorySlots($inventory, false);
    $gameSlots = [];
    for ($i = 0; $i < 21; $i++) {
        $slotItem = $inventory[$i] ?? null;
        if (!is_array($slotItem)) {
            $gameSlots[] = null;
            continue;
        }
        $def = itemDefinition((string)($slotItem['id'] ?? ''));
        $quality = (string)($def['quality'] ?? 'common');
        $gameSlots[] = [
            'id'          => (string)($slotItem['id'] ?? ''),
            'name'        => (string)($slotItem['customName'] ?? $def['name'] ?? $slotItem['id']),
            'icon'        => (string)($def['icon'] ?? '?'),
            'color'       => $qualityColors[$quality] ?? 0xaaaaaa,
            'quantity'    => max(1, (int)($slotItem['count'] ?? 1)),
            'description' => (string)($def['desc'] ?? ''),
            'quality'     => $quality,
            'createdAt'   => (string)($slotItem['createdAt'] ?? '')
        ];
    }
    return $gameSlots;
}

function normalizeInventorySlots($inventory, $ensureDefault = false) {
    $items = is_array($inventory) ? $inventory : defaultInventory();
    $slots = [];
    foreach ($items as $item) {
        if ($item === null) {
            $slots[] = null;
            continue;
        }
        if (!is_array($item) || empty($item['id'])) {
            $slots[] = null;
            continue;
        }
        $normalizedItem = [
            'id' => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$item['id']),
            'count' => max(1, (int)($item['count'] ?? 1)),
            'createdAt' => $item['createdAt'] ?? date('Y-m-d H:i:s')
        ];
        if (!empty($item['customName'])) {
            $customName = trim(strip_tags((string)$item['customName']));
            $normalizedItem['customName'] = function_exists('mb_substr') ? mb_substr($customName, 0, 20, 'UTF-8') : substr($customName, 0, 60);
        }
        $slots[] = $normalizedItem;
    }
    $slots = array_slice($slots, 0, 21);
    while (count($slots) < 21) $slots[] = null;
    if ($ensureDefault && !array_filter($slots)) {
        $slots[0] = defaultInventory()[0];
    }
    return $slots;
}

function inventoryContentFingerprint($inventory) {
    $counts = [];
    foreach (normalizeInventorySlots($inventory, false) as $item) {
        if (!is_array($item)) continue;
        $key = (string)($item['id'] ?? '') . "\n" . (string)($item['customName'] ?? '');
        $counts[$key] = ($counts[$key] ?? 0) + max(1, (int)($item['count'] ?? 1));
    }
    ksort($counts, SORT_STRING);
    return hash('sha256', json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function normalizeWarehouseSlots($warehouse, $capacity) {
    $capacity = max(0, min(105, (int)$capacity));
    $items = is_array($warehouse) ? $warehouse : [];
    $slots = [];
    foreach ($items as $item) {
        if ($item === null) {
            $slots[] = null;
            continue;
        }
        if (!is_array($item) || empty($item['id'])) {
            $slots[] = null;
            continue;
        }
        $normalizedItem = [
            'id' => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$item['id']),
            'count' => max(1, (int)($item['count'] ?? 1)),
            'createdAt' => $item['createdAt'] ?? date('Y-m-d H:i:s')
        ];
        if (!empty($item['customName'])) {
            $customName = trim(strip_tags((string)$item['customName']));
            $normalizedItem['customName'] = function_exists('mb_substr') ? mb_substr($customName, 0, 20, 'UTF-8') : substr($customName, 0, 60);
        }
        $slots[] = $normalizedItem;
    }
    $slots = array_slice($slots, 0, $capacity);
    while (count($slots) < $capacity) $slots[] = null;
    return $slots;
}

function addInventoryItem(&$user, $itemId, $count = 1, $customName = '') {
    $itemId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$itemId);
    $customName = trim((string)$customName);
    if ($itemId === '') return false;
    $inventory = normalizeInventorySlots($user['inventory'] ?? [], false);
    foreach ($inventory as &$item) {
        if (is_array($item) && $item['id'] === $itemId && (string)($item['customName'] ?? '') === $customName) {
            $item['count'] += max(1, (int)$count);
            $user['inventory'] = $inventory;
            return true;
        }
    }
    $emptyIndex = array_search(null, $inventory, true);
    if ($emptyIndex === false) return false;
    $inventory[$emptyIndex] = ['id' => $itemId, 'count' => max(1, (int)$count), 'createdAt' => date('Y-m-d H:i:s')];
    if ($customName !== '') $inventory[$emptyIndex]['customName'] = $customName;
    $user['inventory'] = $inventory;
    return true;
}

function removeInventoryItem(&$user, $slotIndex, $count = 1) {
    $inventory = normalizeInventorySlots($user['inventory'] ?? []);
    $slotIndex = (int)$slotIndex;
    if (!isset($inventory[$slotIndex]) || !is_array($inventory[$slotIndex])) return null;
    $count = max(1, (int)$count);
    $item = $inventory[$slotIndex];
    $removeCount = min($count, (int)($item['count'] ?? 1));
    $item['count'] = $removeCount;
    $inventory[$slotIndex]['count'] -= $removeCount;
    if ($inventory[$slotIndex]['count'] <= 0) {
        $inventory[$slotIndex] = null;
    }
    $user['inventory'] = normalizeInventorySlots($inventory, false);
    return $item;
}

function ensureEconomyFields(&$user) {
    if (!isset($user['gold'])) $user['gold'] = 5;
    if ((int)($user['economyVersion'] ?? 0) < 2) {
        $user['gold'] = max(5, (int)$user['gold']);
        $user['economyVersion'] = 2;
    }
    if (!array_key_exists('inventory', $user)) {
        $user['inventory'] = normalizeInventorySlots(defaultInventory(), true);
    } else {
        $user['inventory'] = normalizeInventorySlots($user['inventory'], false);
    }
    if (!array_key_exists('gameHotbar', $user)) {
        $user['gameHotbar'] = defaultGameHotbar();
    } else {
        $user['gameHotbar'] = normalizeGameHotbarSlots($user['gameHotbar'], false);
    }
    $user['warehouseLevel'] = max(0, min(5, (int)($user['warehouseLevel'] ?? 0)));
    $user['warehouse'] = normalizeWarehouseSlots($user['warehouse'] ?? [], $user['warehouseLevel'] * 21);
    $user['inventoryVersion'] = max(1, (int)($user['inventoryVersion'] ?? 1));
    $pityConfig = lotteryPityConfig();
    $user['lotteryUncommonPity'] = max(0, min($pityConfig['uncommonHard'] - 1, (int)($user['lotteryUncommonPity'] ?? 0)));
    $user['lotteryEpicPity'] = max(0, min($pityConfig['epicHard'] - 1, (int)($user['lotteryEpicPity'] ?? 0)));
    $user['lotteryLegendaryPity'] = max(0, min($pityConfig['legendaryHard'] - 1, (int)($user['lotteryLegendaryPity'] ?? 0)));
}

function touchInventoryVersion(&$user) {
    $user['inventoryVersion'] = max(1, (int)($user['inventoryVersion'] ?? 1)) + 1;
}

function hasUnlimitedGold($user) {
    return !empty($user['unlimitedGold']);
}

function handleUploadFile() {
    global $uploadDir;
    if (empty($_FILES['uploadFile']) || !is_uploaded_file($_FILES['uploadFile']['tmp_name'])) {
        return null;
    }

    $file = $_FILES['uploadFile'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'upload_error'];
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        return ['error' => 'file_too_large'];
    }

    $originalName = preg_replace('/[\x00-\x1F\x7F]+/u', '', basename((string)$file['name']));
    $originalName = function_exists('mb_substr') ? mb_substr($originalName, 0, 120, 'UTF-8') : substr($originalName, 0, 240);
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) finfo_close($finfo);
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt'
    ];
    if (!isset($allowedTypes[$mime])) {
        return ['error' => 'file_type_blocked'];
    }

    $storedName = bin2hex(random_bytes(24)) . '.' . $allowedTypes[$mime];
    $target = $uploadDir . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['error' => 'upload_error'];
    }

    return [
        'name' => $originalName,
        'stored' => $storedName,
        'size' => (int)$file['size'],
        'type' => $mime
    ];
}

function storeAvatarImage($dataUrl, $userId) {
    $stored = storeDatabaseImage($dataUrl, $userId, 'avatar', 320000);
    if ($stored !== null) return $stored;
    return null;
    /* Legacy file-storage fallback retained below for old deployments. */
    global $uploadDir;
    if (strlen((string)$dataUrl) > 180 * 1024 || !preg_match('/^data:image\/(png|jpe?g|webp|gif);base64,([A-Za-z0-9+\/=\r\n]+)$/i', (string)$dataUrl, $matches)) {
        return null;
    }
    $bytes = base64_decode(preg_replace('/\s+/', '', $matches[2]), true);
    if ($bytes === false || strlen($bytes) < 32 || strlen($bytes) > 128 * 1024) {
        return null;
    }
    $info = function_exists('getimagesizefromstring') ? @getimagesizefromstring($bytes) : false;
    $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    ];
    $width = is_array($info) ? (int)($info[0] ?? 0) : 0;
    $height = is_array($info) ? (int)($info[1] ?? 0) : 0;
    if (!isset($allowed[$mime]) || $width < 1 || $height < 1 || $width > 1600 || $height > 1600 || ($width * $height) > 2500000) {
        return null;
    }
    $avatarDir = $uploadDir . DIRECTORY_SEPARATOR . 'avatars';
    if (!is_dir($avatarDir) && !mkdir($avatarDir, 0700, true) && !is_dir($avatarDir)) {
        return null;
    }
    $version = bin2hex(random_bytes(12));
    $key = $version . '.' . $allowed[$mime];
    $target = $avatarDir . DIRECTORY_SEPARATOR . $key;
    if (file_put_contents($target, $bytes, LOCK_EX) !== strlen($bytes)) {
        return null;
    }
    @chmod($target, 0600);
    return [
        'type' => 'image',
        'src' => 'board.php?action=avatar&userId=' . rawurlencode((string)$userId) . '&v=' . $version,
        'key' => $key,
        'mime' => $mime
    ];
}

function outputPosts($posts, $userId, $admin_uid) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = (int)($_GET['limit'] ?? 25);
    $category = normalizeCategory($_GET['category'] ?? 'daily');
    $sectionPass = $_GET['sectionPass'] ?? '';
    if ($limit < 1) $limit = 25;
    if ($limit > 50) $limit = 50;
    if (!canAccessCategory($category, $sectionPass)) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $userMetaMap = buildUserMetaMap(getUsers(), $posts);

    foreach ($posts as $i => &$p) {
        if (!isset($p['reply']) || !is_array($p['reply'])) $p['reply'] = [];
        if (!isset($p['likeUsers']) || !is_array($p['likeUsers'])) $p['likeUsers'] = [];
        $p['category'] = normalizeCategory($p['category'] ?? 'daily');
        $p['isMine'] = !empty($userId) && ($p['userId'] === $userId || $userId === $admin_uid);
        $p['liked'] = !empty($userId) && in_array($userId, $p['likeUsers']);
        $p['pinned'] = isset($p['pinned']) ? (bool)$p['pinned'] : false;
        $p['idx'] = $i;
        $p['likeNum'] = isset($p['likeNum']) ? (int)$p['likeNum'] : 0;
        $p['authorMeta'] = $userMetaMap[$p['userId'] ?? ''] ?? [
            'level' => 1,
            'title' => '初来乍到',
            'avatar' => defaultAvatar($p['user'] ?? ''),
            'role' => 'user'
        ];

        foreach ($p['reply'] as &$r) {
            if (!isset($r['likeUsers']) || !is_array($r['likeUsers'])) $r['likeUsers'] = [];
            $r['isReplyMine'] = !empty($userId) && ($r['userId'] === $userId || $p['userId'] === $userId || $userId === $admin_uid);
            $r['replyLiked'] = !empty($userId) && in_array($userId, $r['likeUsers']);
            $r['replyLikeNum'] = isset($r['replyLikeNum']) ? (int)$r['replyLikeNum'] : 0;
            $r['authorMeta'] = $userMetaMap[$r['userId'] ?? ''] ?? [
                'level' => 1,
                'title' => '初来乍到',
                'avatar' => defaultAvatar($r['user'] ?? ''),
                'role' => 'user'
            ];
        }
        if (isset($p['poll']) && is_array($p['poll'])) {
            $voters = is_array($p['poll']['voters'] ?? null) ? $p['poll']['voters'] : [];
            $p['poll']['hasVoted'] = !empty($userId) && isset($voters[$userId]);
            $p['poll']['mySelections'] = $p['poll']['hasVoted'] ? array_values($voters[$userId]) : [];
            $p['poll']['voterCount'] = count($voters);
            if (!isset($p['poll']['options']) || !is_array($p['poll']['options'])) {
                $p['poll']['options'] = [];
            }
            foreach ($p['poll']['options'] as &$pollOption) {
                $pollOption['voteCount'] = count(is_array($pollOption['votes'] ?? null) ? $pollOption['votes'] : []);
                unset($pollOption['votes']);
            }
            unset($pollOption, $p['poll']['voters']);
        }
    }
    unset($p, $r);

    $posts = array_values(array_filter($posts, function($p) use ($category) {
        return normalizeCategory($p['category'] ?? 'daily') === $category;
    }));

    usort($posts, function($a, $b) {
        if (!empty($a['pinned']) && empty($b['pinned'])) return -1;
        if (empty($a['pinned']) && !empty($b['pinned'])) return 1;
        return strtotime($b['time'] ?? '') - strtotime($a['time'] ?? '');
    });

    $total = count($posts);
    $offset = ($page - 1) * $limit;
    $slice = array_slice($posts, $offset, $limit);
    echo json_encode([
        'posts' => $slice,
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'hasMore' => ($offset + $limit) < $total
    ], JSON_UNESCAPED_UNICODE);
}

$action = $_REQUEST['action'] ?? '';
$name = trim($_REQUEST['name'] ?? '');
$pwd = trim($_REQUEST['pwd'] ?? '');
$newName = $_REQUEST['newName'] ?? ($_REQUEST['name'] ?? '');
$content = $_REQUEST['content'] ?? '';
$color = $_REQUEST['color'] ?? '#333';
$bold = ($_REQUEST['bold'] ?? '') === '1';
$italic = ($_REQUEST['italic'] ?? '') === '1';
$image = $_REQUEST['image'] ?? '';
$idx = isset($_REQUEST['idx']) ? (int)$_REQUEST['idx'] : -1;
$pid = isset($_REQUEST['pid']) ? (int)$_REQUEST['pid'] : -1;
$userId = trim($_REQUEST['userId'] ?? '');
$friendId = trim($_REQUEST['friendId'] ?? '');
$messageContent = trim($_REQUEST['messageContent'] ?? '');
$otherUserId = trim($_REQUEST['otherUserId'] ?? '');
$friendRemark = trim(strip_tags((string)($_REQUEST['friendRemark'] ?? '')));
$friendRemark = function_exists('mb_substr') ? mb_substr($friendRemark, 0, 24, 'UTF-8') : substr($friendRemark, 0, 72);
$category = normalizeCategory($_REQUEST['category'] ?? 'daily');
$sectionPass = $_REQUEST['sectionPass'] ?? '';

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$claimedUserId = $userId;
$sessionUser = journey_current_user();
$sessionUserId = $sessionUser['userId'] ?? '';

// 旧拾取接口可绕过掉落物归属校验，永久废弃，重新开放游戏时也不能恢复。
if ($action === 'gameAddItem') {
    journey_audit('security.deprecated_game_grant_blocked', ['action' => $action], $sessionUserId, 'user', $sessionUserId);
    http_response_code(410);
    echo json_encode(['code' => 'disabled', 'message' => '旧游戏加物品接口已永久关闭'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 游戏世界暂时关闭：服务端封锁所有启动、场景和游戏专用背包操作。
$gameWorldDisabled = true;
$disabledGameActions = ['getGameProfile', 'moveGameInventorySlot', 'saveInventory'];
$isDisabledGameAction = in_array((string)$action, $disabledGameActions, true)
    || (strpos((string)$action, 'game_') === 0 && $action !== 'game_leave');
if ($gameWorldDisabled && $isDisabledGameAction) {
    http_response_code(503);
    echo json_encode(['code' => 'game_disabled', 'message' => '游戏世界暂时关闭'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'me') {
    if (!$sessionUser) {
        echo json_encode(['authenticated' => false, 'csrfToken' => journey_csrf_token()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'authenticated' => true,
        'user' => [
            'userId' => $sessionUser['userId'],
            'user' => $sessionUser['user'],
            'role' => $sessionUser['role'] ?? 'user',
            'status' => $sessionUser['status'] ?? 'active'
        ],
        'csrfToken' => journey_csrf_token()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'getGameProfile') {
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if ($origin) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
    // 游戏客户端专用：一次性返回登录用户的背包、等级、称号
    if (!$sessionUser) {
        echo json_encode([
            'authenticated' => false,
            'code' => 'not_logged_in',
            'csrfToken' => journey_csrf_token()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $gameUserId = (string)$sessionUser['userId'];
    $users = getUsers();
    $targetUser = null;
    foreach ($users as $u) {
        if ((string)($u['userId'] ?? '') === $gameUserId) {
            $targetUser = $u;
            break;
        }
    }
    if ($targetUser === null) {
        echo json_encode(['authenticated' => false, 'code' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ensureEconomyFields($targetUser);
    $posts = isset($posts) ? $posts : [];
    $stats = calculateUserStats($targetUser, $posts);
    $level = (int)$stats['level'];
    $title = selectedTitleForUser($targetUser, $level);

    // 品质→游戏颜色映射
    $qualityColors = [
        'common'    => 0xaaaaaa,
        'uncommon'  => 0x4ecdc4,
        'rare'      => 0x64b5f6,
        'epic'      => 0xba68c8,
        'legendary' => 0xffd93d
    ];

    // 构建游戏背包槽位（主背包21格 + 快捷栏7格）
    $gameSlots = formatGameInventory($targetUser['inventory'] ?? []);
    $gameHotbar = formatGameHotbar($targetUser['gameHotbar'] ?? []);

    echo json_encode([
        'authenticated' => true,
        'code'          => 'ok',
        'csrfToken'     => journey_csrf_token(),
        'user'          => [
            'userId'      => $gameUserId,
            'username'    => (string)($sessionUser['username'] ?? $sessionUser['user'] ?? ''),
            'displayName' => (string)($sessionUser['displayName'] ?? $sessionUser['username'] ?? $sessionUser['user'] ?? '玩家'),
            'level'       => $level,
            'title'       => $title,
            'gold'        => (int)($targetUser['gold'] ?? 0),
            'xp'          => (int)($stats['xp'] ?? 0)
        ],
        'inventory'     => $gameSlots,
        'hotbar'        => $gameHotbar
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'logout') {
    if ($requestMethod !== 'POST' || !$sessionUser || !journey_verify_csrf()) {
        http_response_code(403);
        echo json_encode(['code' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    journey_audit('auth.logout', [], $sessionUserId, 'user', $sessionUserId);
    journey_session_logout(true);
    echo json_encode(['code' => 'ok'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'avatar') {
    $avatarUser = $userId !== '' ? journey_find_user($userId) : null;
    $avatar = is_array($avatarUser) && isset($avatarUser['avatar']) && is_array($avatarUser['avatar']) ? $avatarUser['avatar'] : null;
    $avatarKey = is_array($avatar) ? basename((string)($avatar['key'] ?? '')) : '';
    $avatarPath = $avatarKey !== '' ? $uploadDir . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR . $avatarKey : '';
    $allowedAvatarMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $avatarMime = is_array($avatar) ? (string)($avatar['mime'] ?? '') : '';
    if ($avatarPath === '' || !is_file($avatarPath) || !in_array($avatarMime, $allowedAvatarMimes, true)) {
        http_response_code(404);
        echo json_encode(['code' => 'not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: ' . $avatarMime);
    header('Content-Length: ' . filesize($avatarPath));
    header('Content-Disposition: inline; filename="avatar.' . pathinfo($avatarKey, PATHINFO_EXTENSION) . '"');
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($avatarPath);
    exit;
}

if ($action === 'media') {
    $mediaId = strtolower(trim((string)($_GET['id'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/', $mediaId)) { http_response_code(404); exit; }
    $statement = journey_db()->prepare('SELECT mime_type, byte_size, content FROM media_assets WHERE media_id = ? LIMIT 1');
    $statement->execute([$mediaId]);
    $asset = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$asset) { http_response_code(404); exit; }
    header('Content-Type: ' . $asset['mime_type']);
    header('Content-Length: ' . (int)$asset['byte_size']);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: "' . $mediaId . '"');
    echo is_resource($asset['content']) ? stream_get_contents($asset['content']) : $asset['content'];
    exit;
}

$protectedActions = [
    'getDungeonState', 'getDungeonOnline', 'saveDungeonState', 'equipDungeonArmor', 'unequipDungeonArmor', 'repairDungeonArmor', 'exchangeDungeonCurrency', 'buyDungeonWarehouse', 'moveDungeonStorage', 'grantDungeonItem', 'discardDungeonItem', 'clearDungeonCarriedItems', 'consumeDungeonItem', 'synthesizeDungeonItems', 'recordDungeonKill', 'getGeorgeEffects', 'buyGeorgeEffect', 'equipGeorgeEffect', 'unequipGeorgeEffect',
    'generateRedeemCode', 'getRedeemCodes', 'redeemCode', 'updatePassword', 'updateActive',
    'checkOnline', 'updateName', 'add', 'like', 'votePoll', 'reply', 'replyLike', 'del', 'delReply',
    'pin', 'getUser', 'getInventory', 'buyWarehouseExpansion', 'transferStorageItem',
    'renameStorageItem', 'moveWarehouseItem', 'discardWarehouseItem', 'drawLottery',
    'discardLotteryItem', 'sellLotteryItemToSystem', 'grantInventoryItem', 'saveInventory',
    'discardInventoryItem', 'moveInventoryItem', 'sellInventoryToSystem', 'batchSellInventoryToSystem',
    'dailyCheckin', 'getDailyTasks', 'claimDailyTask', 'createGoldTransfer', 'getMyMessages', 'respondGoldTransfer', 'dismissNotification', 'deleteNotification',
        'getWishingWellStatus', 'useWishingWell', 'getContactMarket', 'refreshKeyiMarket', 'buyContactOffer', 'sellContactItem',
        'getRpsLobby', 'createRpsRoom', 'joinRpsRoom', 'cancelRpsRoom', 'lockRpsStake', 'chooseRpsMove', 'surrenderRpsRoom', 'dismissRpsResult',
    'getDriftBottleStatus', 'throwDriftBottle', 'pickDriftBottle', 'commentDriftBottle', 'resolveDriftItem',
    'createItemGift', 'respondItemGift', 'claimItemMail', 'synthesizeItems',
    'listMarketItem', 'delistMarketItem', 'buyMarketItem', 'updateProfile',
    'addFriend', 'getFriendRequests', 'respondFriendRequest', 'getFriends',
    'updateFriendRemark', 'sendMessage', 'getMessages', 'sendWorldMessage', 'getWorldMessages',
    'sendDungeonChat', 'getDungeonChat',
    'adminDashboard', 'adminListUsers', 'adminUpdateUser', 'adminResetPassword',
    'adminListPosts', 'adminDeletePost', 'adminDeleteUserPosts', 'adminUpdateSettings',
    'adminAuditLogs', 'adminListItems', 'adminSaveItem', 'adminDeleteItem', 'adminSendMail',
    'adminListDriftBottles', 'adminDeleteDriftBottle', 'adminDeleteDriftComment', 'adminSaveContact',
    'adminGetDungeonConfig', 'adminSaveDungeonConfig', 'adminUploadDungeonImage'
];
$writeActions = [
    'saveDungeonState', 'equipDungeonArmor', 'unequipDungeonArmor', 'repairDungeonArmor', 'exchangeDungeonCurrency', 'buyDungeonWarehouse', 'moveDungeonStorage', 'grantDungeonItem', 'discardDungeonItem', 'clearDungeonCarriedItems', 'consumeDungeonItem', 'synthesizeDungeonItems', 'recordDungeonKill', 'buyGeorgeEffect', 'equipGeorgeEffect', 'unequipGeorgeEffect',
    'generateRedeemCode', 'redeemCode', 'updatePassword', 'updateActive', 'updateName',
    'add', 'like', 'votePoll', 'reply', 'replyLike', 'del', 'delReply', 'pin', 'buyWarehouseExpansion',
    'transferStorageItem', 'renameStorageItem', 'moveWarehouseItem', 'discardWarehouseItem',
    'drawLottery', 'discardLotteryItem', 'sellLotteryItemToSystem', 'grantInventoryItem',
    'saveInventory', 'discardInventoryItem', 'moveInventoryItem', 'sellInventoryToSystem',
    'batchSellInventoryToSystem', 'dailyCheckin', 'claimDailyTask', 'listMarketItem', 'delistMarketItem',
    'throwDriftBottle', 'pickDriftBottle', 'commentDriftBottle', 'resolveDriftItem',
        'buyMarketItem', 'refreshKeyiMarket', 'sellContactItem', 'createGoldTransfer', 'respondGoldTransfer', 'dismissNotification', 'deleteNotification',
        'useWishingWell', 'buyContactOffer',
        'createRpsRoom', 'joinRpsRoom', 'cancelRpsRoom', 'lockRpsStake', 'chooseRpsMove', 'surrenderRpsRoom', 'dismissRpsResult',
    'createItemGift', 'respondItemGift', 'claimItemMail', 'synthesizeItems',
    'updateProfile', 'addFriend', 'respondFriendRequest',
    'updateFriendRemark', 'sendMessage', 'sendWorldMessage', 'sendDungeonChat', 'adminUpdateUser',
    'adminResetPassword', 'adminDeletePost', 'adminDeleteUserPosts', 'adminUpdateSettings',
    'adminSaveItem', 'adminDeleteItem', 'adminSendMail', 'adminDeleteDriftBottle', 'adminDeleteDriftComment', 'adminSaveContact',
    'adminSaveDungeonConfig', 'adminUploadDungeonImage'
];
$adminActions = [
    'generateRedeemCode', 'getRedeemCodes', 'adminDashboard', 'adminListUsers',
    'adminUpdateUser', 'adminResetPassword', 'adminListPosts', 'adminDeletePost',
    'adminDeleteUserPosts', 'adminUpdateSettings', 'adminAuditLogs', 'adminListItems', 'adminSaveItem', 'adminDeleteItem', 'adminSendMail',
    'adminListDriftBottles', 'adminDeleteDriftBottle', 'adminDeleteDriftComment', 'adminSaveContact',
    'adminGetDungeonConfig', 'adminSaveDungeonConfig', 'adminUploadDungeonImage'
];

if (in_array($action, $protectedActions, true)) {
    if (!$sessionUser) {
        http_response_code(401);
        echo json_encode(['code' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($claimedUserId !== '' && !in_array($action, $adminActions, true) && !hash_equals($sessionUserId, $claimedUserId)) {
        journey_audit('security.identity_mismatch', ['claimedUserId' => $claimedUserId, 'action' => $action], $sessionUserId, 'user', $claimedUserId);
        http_response_code(403);
        echo json_encode(['code' => 'identity_mismatch'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $userId = $sessionUserId;
    $dungeonActions = ['getDungeonState','getDungeonOnline','saveDungeonState','equipDungeonArmor','unequipDungeonArmor','repairDungeonArmor','exchangeDungeonCurrency','buyDungeonWarehouse','moveDungeonStorage','grantDungeonItem','discardDungeonItem','clearDungeonCarriedItems','consumeDungeonItem','synthesizeDungeonItems','recordDungeonKill','getGeorgeEffects','buyGeorgeEffect','equipGeorgeEffect','unequipGeorgeEffect','sendDungeonChat','getDungeonChat'];
    $generalLimit = journey_rate_limit('api.user', $sessionUserId, in_array($action, $dungeonActions, true) ? 3000 : 300, 300, true);
    if (!$generalLimit['allowed']) {
        http_response_code(429);
        header('Retry-After: ' . (int)$generalLimit['retryAfter']);
        echo json_encode(['code' => 'rate_limited', 'retryAfter' => $generalLimit['retryAfter']], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($action === 'get') {
    $userId = $sessionUserId;
}

if (in_array($action, $adminActions, true) && !journey_is_admin($sessionUserId)) {
    http_response_code(403);
    echo json_encode(['code' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (in_array($action, $writeActions, true)) {
    if ($requestMethod !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['code' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $providedCsrf = journey_request_csrf_token();
    $expectedCsrf = journey_csrf_token();
    $cookieCsrf = (string)($_COOKIE['journey_csrf'] ?? '');
    if ($providedCsrf === '' || $cookieCsrf === '' || !hash_equals($expectedCsrf, $providedCsrf) || !hash_equals($expectedCsrf, $cookieCsrf)) {
        http_response_code(403);
        echo json_encode(['code' => 'csrf_failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$admin_uid = journey_is_admin($sessionUserId) ? $sessionUserId : '';

$postWriteActions = ['updateName', 'add', 'like', 'votePoll', 'reply', 'replyLike', 'del', 'delReply', 'pin', 'adminDeletePost', 'adminDeleteUserPosts'];
if (in_array($action, $postWriteActions, true)) beginPostsWriteLock();
$needsPosts = in_array($action, ['get', 'download', 'getUser', 'getPublicUser', 'getLeaderboard', 'getDailyTasks', 'claimDailyTask', 'updateProfile', 'updateName', 'add', 'like', 'votePoll', 'reply', 'replyLike', 'del', 'delReply', 'pin'], true);
$posts = [];
if ($needsPosts) {
    $posts = getPosts();
}

if (journey_handle_dungeon_action((string)$action, (string)$userId)) exit;

if ($action === 'getDriftBottleStatus') {
    $store = driftBottleStore();
    echo json_encode(['code' => 'ok', 'status' => driftBottleStatusPayload($store, $userId)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'throwDriftBottle') {
    $bottleContent = normalizeDriftText($_POST['content'] ?? '', 500);
    $anonymous = ($_POST['anonymous'] ?? '') === '1';
    if (journey_string_length($bottleContent) < 2) {
        http_response_code(422);
        echo json_encode(['code' => 'content_too_short'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $users = getUsers();
    $userIndex = null;
    foreach ($users as $index => $candidate) {
        if ((string)($candidate['userId'] ?? '') === $userId) { $userIndex = $index; break; }
    }
    if ($userIndex === null) {
        http_response_code(404); echo json_encode(['code' => 'user_not_found'], JSON_UNESCAPED_UNICODE); exit;
    }
    $createdBottle = null;
    $limitReached = false;
    $updatedStore = journey_store_mutate('drift_bottles', function($store) use ($userId, $users, $userIndex, $bottleContent, $anonymous, &$createdBottle, &$limitReached) {
        if (!is_array($store)) $store = [];
        $store['bottles'] = is_array($store['bottles'] ?? null) ? array_values($store['bottles']) : [];
        $store['daily'] = is_array($store['daily'] ?? null) ? $store['daily'] : [];
        $store['pendingItems'] = is_array($store['pendingItems'] ?? null) ? $store['pendingItems'] : [];
        $daily = driftDailyRecord($store, $userId);
        if ($daily['throws'] >= 3) { $limitReached = true; return $store; }
        $createdBottle = [
            'id' => 'btl_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)),
            'authorId' => $userId,
            'authorName' => (string)($users[$userIndex]['user'] ?? '未知旅人'),
            'anonymous' => $anonymous,
            'content' => $bottleContent,
            'createdAt' => date('Y-m-d H:i:s'),
            'comments' => []
        ];
        $store['bottles'][] = $createdBottle;
        $store['bottles'] = array_slice($store['bottles'], -3000);
        $daily['throws']++;
        $store['daily'][$userId] = $daily;
        foreach ($store['daily'] as $dailyUserId => $record) {
            if (($record['date'] ?? '') < date('Y-m-d', strtotime('-7 days'))) unset($store['daily'][$dailyUserId]);
        }
        return $store;
    }, ['bottles' => [], 'daily' => [], 'pendingItems' => []]);
    if ($limitReached || !$createdBottle) {
        http_response_code(429);
        echo json_encode(['code' => 'daily_throw_limit', 'status' => driftBottleStatusPayload($updatedStore, $userId)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ensureEconomyFields($users[$userIndex]);
    $users[$userIndex]['gold'] = (int)$users[$userIndex]['gold'] + 5;
    saveUsers($users);
    echo json_encode([
        'code' => 'ok', 'rewardGold' => 5, 'gold' => (int)$users[$userIndex]['gold'],
        'bottle' => publicDriftBottle($createdBottle, $userId),
        'status' => driftBottleStatusPayload($updatedStore, $userId)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'pickDriftBottle') {
    $result = null;
    $limitReached = false;
    $updatedStore = journey_store_mutate('drift_bottles', function($store) use ($userId, &$result, &$limitReached) {
        if (!is_array($store)) $store = [];
        $store['bottles'] = is_array($store['bottles'] ?? null) ? array_values($store['bottles']) : [];
        $store['daily'] = is_array($store['daily'] ?? null) ? $store['daily'] : [];
        $store['pendingItems'] = is_array($store['pendingItems'] ?? null) ? $store['pendingItems'] : [];
        $daily = driftDailyRecord($store, $userId);
        $existingPending = is_array($store['pendingItems'][$userId] ?? null) ? $store['pendingItems'][$userId] : null;
        if ($existingPending && ($existingPending['state'] ?? 'pending') === 'resolving' && (int)($existingPending['reservationAt'] ?? 0) < time() - 30) {
            $existingPending['state'] = 'pending';
            unset($existingPending['reservationId'], $existingPending['reservationAt']);
            $store['pendingItems'][$userId] = $existingPending;
        }
        if ($existingPending && (int)($existingPending['expiresAt'] ?? 0) >= time()) {
            $result = ['type' => 'item', 'pending' => $existingPending, 'existing' => true];
            return $store;
        }
        unset($store['pendingItems'][$userId]);
        if ($daily['picks'] >= 20) { $limitReached = true; return $store; }
        $daily['picks']++;
        $roll = random_int(1, 100);
        if ($roll <= 40) {
            $result = ['type' => 'empty'];
        } elseif ($roll <= 50) {
            $pending = [
                'token' => bin2hex(random_bytes(16)),
                'itemId' => randomLotteryItemId(),
                'createdAt' => time(),
                'expiresAt' => time() + 86400,
                'state' => 'pending'
            ];
            $store['pendingItems'][$userId] = $pending;
            $result = ['type' => 'item', 'pending' => $pending, 'existing' => false];
        } else {
            $eligible = array_values(array_filter($store['bottles'], function($bottle) use ($userId) {
                return (string)($bottle['authorId'] ?? '') !== $userId && !empty($bottle['id']);
            }));
            $unseen = array_values(array_filter($eligible, function($bottle) use ($daily) {
                return !in_array((string)$bottle['id'], $daily['seen'], true);
            }));
            $pool = $unseen ?: $eligible;
            if (!$pool) {
                $result = ['type' => 'empty'];
            } else {
                $bottle = $pool[random_int(0, count($pool) - 1)];
                $daily['seen'][] = (string)$bottle['id'];
                $daily['seen'] = array_slice(array_values(array_unique($daily['seen'])), -200);
                $result = ['type' => 'bottle', 'bottle' => $bottle];
            }
        }
        $store['daily'][$userId] = $daily;
        return $store;
    }, ['bottles' => [], 'daily' => [], 'pendingItems' => []]);
    if ($limitReached) {
        http_response_code(429);
        echo json_encode(['code' => 'daily_pick_limit', 'status' => driftBottleStatusPayload($updatedStore, $userId)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $payload = ['code' => 'ok', 'result' => ['type' => $result['type'] ?? 'empty'], 'status' => driftBottleStatusPayload($updatedStore, $userId)];
    if (($result['type'] ?? '') === 'bottle') $payload['result']['bottle'] = publicDriftBottle($result['bottle'], $userId);
    if (($result['type'] ?? '') === 'item') {
        $item = itemDefinition((string)$result['pending']['itemId']);
        $payload['result']['item'] = $item;
        $payload['result']['token'] = (string)$result['pending']['token'];
        $payload['result']['existing'] = !empty($result['existing']);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'commentDriftBottle') {
    $bottleId = trim((string)($_POST['bottleId'] ?? ''));
    $commentContent = normalizeDriftText($_POST['content'] ?? '', 240);
    $anonymous = ($_POST['anonymous'] ?? '') === '1';
    if ($bottleId === '' || journey_string_length($commentContent) < 1) {
        http_response_code(422); echo json_encode(['code' => 'invalid_comment'], JSON_UNESCAPED_UNICODE); exit;
    }
    $authorName = (string)($sessionUser['user'] ?? '未知旅人');
    $savedBottle = null;
    $notFound = false;
    journey_store_mutate('drift_bottles', function($store) use ($bottleId, $userId, $authorName, $commentContent, $anonymous, &$savedBottle, &$notFound) {
        if (!is_array($store)) $store = [];
        $store['bottles'] = is_array($store['bottles'] ?? null) ? array_values($store['bottles']) : [];
        foreach ($store['bottles'] as &$bottle) {
            if ((string)($bottle['id'] ?? '') !== $bottleId) continue;
            $bottle['comments'] = is_array($bottle['comments'] ?? null) ? $bottle['comments'] : [];
            if (count($bottle['comments']) >= 100) array_shift($bottle['comments']);
            $bottle['comments'][] = [
                'id' => 'cmt_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)),
                'authorId' => $userId, 'authorName' => $authorName, 'anonymous' => $anonymous,
                'content' => $commentContent, 'createdAt' => date('Y-m-d H:i:s')
            ];
            $savedBottle = $bottle;
            break;
        }
        unset($bottle);
        if (!$savedBottle) $notFound = true;
        return $store;
    }, ['bottles' => [], 'daily' => [], 'pendingItems' => []]);
    if ($notFound) { http_response_code(404); echo json_encode(['code' => 'bottle_not_found'], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(['code' => 'ok', 'bottle' => publicDriftBottle($savedBottle, $userId)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'resolveDriftItem') {
    $token = trim((string)($_POST['token'] ?? ''));
    $resolution = (string)($_POST['resolution'] ?? '');
    if ($token === '' || !in_array($resolution, ['keep', 'discard'], true)) {
        http_response_code(422); echo json_encode(['code' => 'invalid_resolution'], JSON_UNESCAPED_UNICODE); exit;
    }
    $reserved = null;
    $reservationId = bin2hex(random_bytes(10));
    journey_store_mutate('drift_bottles', function($store) use ($userId, $token, $resolution, $reservationId, &$reserved) {
        if (!is_array($store)) $store = [];
        $store['pendingItems'] = is_array($store['pendingItems'] ?? null) ? $store['pendingItems'] : [];
        $pending = is_array($store['pendingItems'][$userId] ?? null) ? $store['pendingItems'][$userId] : null;
        if (!$pending || !hash_equals((string)($pending['token'] ?? ''), $token) || (int)($pending['expiresAt'] ?? 0) < time() || ($pending['state'] ?? 'pending') !== 'pending') return $store;
        $reserved = $pending;
        if ($resolution === 'discard') unset($store['pendingItems'][$userId]);
        else {
            $store['pendingItems'][$userId]['state'] = 'resolving';
            $store['pendingItems'][$userId]['reservationId'] = $reservationId;
            $store['pendingItems'][$userId]['reservationAt'] = time();
        }
        return $store;
    }, ['bottles' => [], 'daily' => [], 'pendingItems' => []]);
    if (!$reserved) { http_response_code(409); echo json_encode(['code' => 'item_already_resolved'], JSON_UNESCAPED_UNICODE); exit; }
    if ($resolution === 'discard') {
        echo json_encode(['code' => 'ok', 'resolution' => 'discarded'], JSON_UNESCAPED_UNICODE); exit;
    }
    $users = getUsers();
    $userIndex = null;
    foreach ($users as $index => $candidate) if ((string)($candidate['userId'] ?? '') === $userId) { $userIndex = $index; break; }
    $added = $userIndex !== null && addInventoryItem($users[$userIndex], (string)$reserved['itemId'], 1);
    if (!$added) {
        journey_store_mutate('drift_bottles', function($store) use ($userId, $reservationId) {
            if (isset($store['pendingItems'][$userId]) && ($store['pendingItems'][$userId]['reservationId'] ?? '') === $reservationId) {
                $store['pendingItems'][$userId]['state'] = 'pending'; unset($store['pendingItems'][$userId]['reservationId'], $store['pendingItems'][$userId]['reservationAt']);
            }
            return $store;
        }, []);
        http_response_code(409); echo json_encode(['code' => 'inventory_full'], JSON_UNESCAPED_UNICODE); exit;
    }
    try {
        saveUsers($users);
    } catch (Throwable $exception) {
        journey_store_mutate('drift_bottles', function($store) use ($userId, $reservationId) {
            if (isset($store['pendingItems'][$userId]) && ($store['pendingItems'][$userId]['reservationId'] ?? '') === $reservationId) {
                $store['pendingItems'][$userId]['state'] = 'pending';
                unset($store['pendingItems'][$userId]['reservationId'], $store['pendingItems'][$userId]['reservationAt']);
            }
            return $store;
        }, []);
        throw $exception;
    }
    journey_store_mutate('drift_bottles', function($store) use ($userId, $reservationId) {
        if (isset($store['pendingItems'][$userId]) && ($store['pendingItems'][$userId]['reservationId'] ?? '') === $reservationId) unset($store['pendingItems'][$userId]);
        return $store;
    }, []);
    echo json_encode(['code' => 'ok', 'resolution' => 'kept', 'item' => itemDefinition((string)$reserved['itemId'])], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'adminListDriftBottles') {
    $store = driftBottleStore();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $query = trim((string)($_GET['q'] ?? ''));
    $rows = [];
    foreach (array_reverse($store['bottles']) as $bottle) {
        $haystack = implode(' ', [$bottle['id'] ?? '', $bottle['authorId'] ?? '', $bottle['authorName'] ?? '', $bottle['content'] ?? '']);
        if ($query !== '' && (function_exists('mb_stripos') ? mb_stripos($haystack, $query, 0, 'UTF-8') === false : stripos($haystack, $query) === false)) continue;
        $rows[] = publicDriftBottle($bottle, $sessionUserId, true);
    }
    $total = count($rows);
    echo json_encode(['code' => 'ok', 'bottles' => array_slice($rows, ($page - 1) * $limit, $limit), 'page' => $page, 'limit' => $limit, 'total' => $total], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'adminDeleteDriftBottle') {
    $bottleId = trim((string)($_POST['bottleId'] ?? ''));
    $deleted = null;
    journey_store_mutate('drift_bottles', function($store) use ($bottleId, &$deleted) {
        $bottles = is_array($store['bottles'] ?? null) ? $store['bottles'] : [];
        foreach ($bottles as $index => $bottle) {
            if ((string)($bottle['id'] ?? '') === $bottleId) { $deleted = $bottle; array_splice($bottles, $index, 1); break; }
        }
        $store['bottles'] = array_values($bottles); return $store;
    }, []);
    if (!$deleted) { http_response_code(404); echo json_encode(['code' => 'not_found'], JSON_UNESCAPED_UNICODE); exit; }
    journey_audit('admin_delete_drift_bottle', ['preview' => normalizeDriftText($deleted['content'] ?? '', 60)], $sessionUserId, 'drift_bottle', $bottleId);
    echo json_encode(['code' => 'ok'], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'adminDeleteDriftComment') {
    $bottleId = trim((string)($_POST['bottleId'] ?? ''));
    $commentId = trim((string)($_POST['commentId'] ?? ''));
    $deleted = false;
    journey_store_mutate('drift_bottles', function($store) use ($bottleId, $commentId, &$deleted) {
        foreach (($store['bottles'] ?? []) as &$bottle) {
            if ((string)($bottle['id'] ?? '') !== $bottleId) continue;
            $comments = is_array($bottle['comments'] ?? null) ? $bottle['comments'] : [];
            foreach ($comments as $index => $comment) {
                if ((string)($comment['id'] ?? '') === $commentId) { array_splice($comments, $index, 1); $deleted = true; break; }
            }
            $bottle['comments'] = array_values($comments); break;
        }
        unset($bottle); return $store;
    }, []);
    if (!$deleted) { http_response_code(404); echo json_encode(['code' => 'not_found'], JSON_UNESCAPED_UNICODE); exit; }
    journey_audit('admin_delete_drift_comment', [], $sessionUserId, 'drift_comment', $commentId);
    echo json_encode(['code' => 'ok'], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'generateRedeemCode') {
    if (!isAdministratorUser($userId)) {
        http_response_code(403);
        echo json_encode(['code' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rewardType = in_array(($_REQUEST['rewardType'] ?? ''), ['item', 'gold', 'xp'], true) ? $_REQUEST['rewardType'] : 'item';
    $itemId = trim((string)($_REQUEST['itemId'] ?? ''));
    $rewardAmount = max(1, (int)($_REQUEST['rewardAmount'] ?? 1));
    $codeType = ($_REQUEST['codeType'] ?? '') === 'single' ? 'single' : 'unlimited';
    if ($rewardType === 'item') {
        $validItemIds = array_column(itemCatalog(), 'id');
        if (!in_array($itemId, $validItemIds, true)) {
            echo json_encode(['code' => 'invalid_item'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $rewardAmount = 1;
    } elseif ($rewardType === 'gold') {
        $rewardAmount = min(10000, $rewardAmount);
        $itemId = '';
    } else {
        $rewardAmount = min(2000, $rewardAmount);
        $itemId = '';
    }
    $codes = getRedeemCodes();
    do {
        $codeValue = generateRedeemCodeValue();
        $duplicate = false;
        foreach ($codes as $existingCode) {
            if (($existingCode['code'] ?? '') === $codeValue) {
                $duplicate = true;
                break;
            }
        }
    } while ($duplicate);
    $record = [
        'code' => $codeValue,
        'type' => $codeType,
        'rewardType' => $rewardType,
        'rewardAmount' => $rewardAmount,
        'itemId' => $itemId,
        'createdBy' => $userId,
        'createdAt' => date('Y-m-d H:i:s'),
        'usages' => []
    ];
    $codes[] = $record;
    saveRedeemCodes($codes);
    $record['item'] = $rewardType === 'item' ? itemDefinition($itemId) : null;
    $record['rewardLabel'] = $rewardType === 'item'
        ? ($record['item']['name'] ?? '物品')
        : ($rewardType === 'gold' ? $rewardAmount . ' 金币' : $rewardAmount . ' 经验');
    $record['usageCount'] = 0;
    echo json_encode(['code' => 'ok', 'redeemCode' => $record], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'getRedeemCodes') {
    if (!isAdministratorUser($userId)) {
        http_response_code(403);
        echo json_encode(['code' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $codes = getRedeemCodes();
    foreach ($codes as &$codeRecord) {
        $rewardType = $codeRecord['rewardType'] ?? 'item';
        $rewardAmount = max(1, (int)($codeRecord['rewardAmount'] ?? 1));
        $codeRecord['item'] = $rewardType === 'item' ? itemDefinition($codeRecord['itemId'] ?? '') : null;
        $codeRecord['rewardLabel'] = $rewardType === 'item'
            ? ($codeRecord['item']['name'] ?? '物品')
            : ($rewardType === 'gold' ? $rewardAmount . ' 金币' : $rewardAmount . ' 经验');
        $codeRecord['usageCount'] = count($codeRecord['usages'] ?? []);
    }
    unset($codeRecord);
    echo json_encode(['code' => 'ok', 'codes' => array_reverse($codes)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'redeemCode' && !empty($userId)) {
    $codeValue = strtoupper(trim((string)($_REQUEST['redeemCode'] ?? '')));
    $codes = getRedeemCodes();
    foreach ($codes as &$codeRecord) {
        if (strtoupper((string)($codeRecord['code'] ?? '')) !== $codeValue) {
            continue;
        }
        $usages = is_array($codeRecord['usages'] ?? null) ? $codeRecord['usages'] : [];
        if (($codeRecord['type'] ?? 'single') === 'single' && count($usages) >= 1) {
            echo json_encode(['code' => 'used'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        foreach ($usages as $usage) {
            if (($usage['userId'] ?? '') === $userId) {
                echo json_encode(['code' => 'already'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        $users = getUsers();
        foreach ($users as &$redeemUser) {
            if (($redeemUser['userId'] ?? '') !== $userId) {
                continue;
            }
            ensureEconomyFields($redeemUser);
            $rewardType = $codeRecord['rewardType'] ?? 'item';
            $rewardAmount = max(1, (int)($codeRecord['rewardAmount'] ?? 1));
            $itemId = $codeRecord['itemId'] ?? '';
            $reward = ['type' => $rewardType];
            if ($rewardType === 'item') {
                $reward['delivery'] = deliverOverflowItem($redeemUser, $itemId, 1, '兑换码奖励');
                $reward['item'] = itemDefinition($itemId);
                $reward['label'] = $reward['item']['name'] ?? '物品';
            } elseif ($rewardType === 'gold') {
                $redeemUser['gold'] = (int)($redeemUser['gold'] ?? 0) + $rewardAmount;
                $reward['amount'] = $rewardAmount;
                $reward['label'] = $rewardAmount . ' 金币';
            } else {
                $redeemUser['xp'] = (int)($redeemUser['xp'] ?? 0) + $rewardAmount;
                $reward['amount'] = $rewardAmount;
                $reward['label'] = $rewardAmount . ' 经验';
            }
            $codeRecord['usages'][] = [
                'userId' => $userId,
                'user' => $redeemUser['user'] ?? '玩家',
                'time' => date('Y-m-d H:i:s')
            ];
            saveUsers($users);
            saveRedeemCodes($codes);
            echo json_encode([
                'code' => 'ok',
                'reward' => $reward,
                'item' => $rewardType === 'item' ? itemDefinition($itemId) : null,
                'gold' => (int)($redeemUser['gold'] ?? 0),
                'xp' => (int)($redeemUser['xp'] ?? 0),
                'inventory' => normalizeInventorySlots($redeemUser['inventory'] ?? [], false)
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        unset($redeemUser);
        echo json_encode(['code' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    unset($codeRecord);
    echo json_encode(['code' => 'invalid'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'reg') {
    if ($requestMethod !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['code' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        http_response_code(400);
        echo json_encode(['code' => 'invalid_request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $nameLength = journey_string_length($name);
    if ($nameLength < 2 || $nameLength > 24 || !preg_match('/^[\p{L}\p{N}_.·-]+$/u', $name)) {
        http_response_code(400);
        echo json_encode(['code' => 'invalid_name'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (strlen($pwd) < 8 || strlen($pwd) > 128 || !preg_match('/[A-Za-z]/', $pwd) || !preg_match('/\d/', $pwd)) {
        http_response_code(400);
        echo json_encode(['code' => 'weak_password'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ipLimit = max(1, min(3, (int)journey_setting_get('security.registration_limit_ip', 3)));
    $deviceLimit = max(1, min(3, (int)journey_setting_get('security.registration_limit_device', 3)));
    $result = journey_register_user($name, $pwd, null, [
        'ip_account_limit' => $ipLimit,
        'device_account_limit' => $deviceLimit,
        'login' => false,
        'extra' => [
            'friends' => [],
            'friendRequests' => [],
            'xp' => 0,
            'gold' => 5,
            'economyVersion' => 2,
            'selectedTitle' => '初来乍到',
            'avatar' => defaultAvatar($name),
            'inventory' => defaultInventory()
        ]
    ]);
    $code = (string)($result['code'] ?? 'fail');
    if ($code === 'exists') $code = 'exist';
    if ($code === 'account_limit') $code = 'registration_limit';
    if ($code === 'validation_failed') {
        $errors = $result['errors'] ?? [];
        $code = in_array('password_length', $errors, true) ? 'weak_password' : 'invalid_name';
    }
    if (empty($result['ok'])) http_response_code($code === 'rate_limited' ? 429 : 400);
    echo json_encode([
        'code' => !empty($result['ok']) ? 'created' : $code,
        'userId' => $result['userId'] ?? null,
        'user' => $result['user'] ?? null,
        'limit' => $result['limit'] ?? min($ipLimit, $deviceLimit),
        'ipLimit' => $result['ipLimit'] ?? $ipLimit,
        'deviceLimit' => $result['deviceLimit'] ?? $deviceLimit,
        'retryAfter' => $result['retryAfter'] ?? null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'login') {
    if ($requestMethod !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['code' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = journey_authenticate_user($name, $pwd, true);
    if (empty($result['ok'])) {
        http_response_code(($result['code'] ?? '') === 'rate_limited' ? 429 : 401);
        echo json_encode([
            'code' => $result['code'] ?? 'invalid_credentials',
            'legacyCode' => 1,
            'retryAfter' => $result['retryAfter'] ?? null
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'code' => 0,
        'userId' => $result['userId'],
        'user' => $result['user'],
        'role' => $result['role'] ?? 'user',
        'csrfToken' => $result['csrfToken'] ?? journey_csrf_token()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'updatePassword' && !empty($userId)) {
    if ($requestMethod !== 'POST') {
        http_response_code(405);
        echo 'method_not_allowed';
        exit;
    }
    $oldPwd = trim($_REQUEST['oldPwd'] ?? '');
    $newPwdValue = trim($_REQUEST['newPwd'] ?? '');
    if ($oldPwd === '' || $newPwdValue === '') {
        echo 'fail';
        exit;
    }
    if (strlen($newPwdValue) < 8 || strlen($newPwdValue) > 128 || !preg_match('/[A-Za-z]/', $newPwdValue) || !preg_match('/\d/', $newPwdValue)) {
        echo 'weak_password';
        exit;
    }
    $result = journey_change_password($userId, $oldPwd, $newPwdValue);
    if (!empty($result['ok'])) echo 'ok';
    elseif (($result['code'] ?? '') === 'wrong_password') echo 'wrong';
    else echo (string)($result['code'] ?? 'fail');
    exit;
}

if ($action === 'adminDashboard') {
    $users = getUsers();
    $adminPosts = getPosts();
    $today = date('Y-m-d');
    $activeUsers = 0;
    $restrictedUsers = 0;
    $registrationsToday = 0;
    foreach ($users as $adminUser) {
        if (($adminUser['status'] ?? 'active') === 'active') $activeUsers++;
        else $restrictedUsers++;
        if (substr((string)($adminUser['createdAt'] ?? ''), 0, 10) === $today) $registrationsToday++;
    }
    $postsToday = 0;
    foreach ($adminPosts as $adminPost) {
        if (substr((string)($adminPost['time'] ?? ''), 0, 10) === $today) $postsToday++;
    }
    echo json_encode([
        'code' => 'ok',
        'stats' => [
            'totalUsers' => count($users), 'activeUsers' => $activeUsers,
            'suspendedUsers' => $restrictedUsers, 'totalPosts' => count($adminPosts),
            'postsToday' => $postsToday, 'registrationsToday' => $registrationsToday
        ],
        'settings' => adminSettingsPayload(),
        'currentUser' => ['userId' => $sessionUserId, 'user' => $sessionUser['user'] ?? '管理员', 'role' => 'admin']
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'adminListUsers') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $query = trim((string)($_GET['q'] ?? ''));
    $adminPosts = getPosts();
    $postCounts = [];
    foreach ($adminPosts as $adminPost) {
        $authorId = (string)($adminPost['userId'] ?? '');
        if ($authorId !== '') $postCounts[$authorId] = ($postCounts[$authorId] ?? 0) + 1;
    }
    $rows = [];
    foreach (array_reverse(getUsers()) as $adminUser) {
        $haystack = implode(' ', [$adminUser['userId'] ?? '', $adminUser['user'] ?? '', $adminUser['email'] ?? '']);
        $matches = $query === '' || (function_exists('mb_stripos') ? mb_stripos($haystack, $query, 0, 'UTF-8') !== false : stripos($haystack, $query) !== false);
        if ($matches) $rows[] = adminUserPayload($adminUser, $postCounts[$adminUser['userId'] ?? ''] ?? 0);
    }
    $total = count($rows);
    echo json_encode(['code' => 'ok', 'users' => array_slice($rows, ($page - 1) * $limit, $limit), 'page' => $page, 'limit' => $limit, 'total' => $total], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'adminUpdateUser') {
    $targetUserId = trim((string)($_POST['userId'] ?? ''));
    $username = trim((string)($_POST['user'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $role = (string)($_POST['role'] ?? 'user');
    $status = (string)($_POST['status'] ?? 'active');
    $bio = trim(strip_tags((string)($_POST['bio'] ?? '')));
    if (journey_string_length($username) < 2 || journey_string_length($username) > 24 || !preg_match('/^[\p{L}\p{N}_.·-]+$/u', $username)) {
        http_response_code(422); echo json_encode(['code' => 'invalid_username'], JSON_UNESCAPED_UNICODE); exit;
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422); echo json_encode(['code' => 'invalid_email'], JSON_UNESCAPED_UNICODE); exit;
    }
    $changes = [
        'username' => $username, 'email' => $email, 'role' => $role, 'status' => $status,
        'extra' => [
            'xp' => max(0, min(1000000000, (int)($_POST['xp'] ?? 0))),
            'gold' => max(0, min(1000000000, (int)($_POST['gold'] ?? 0))),
            'bio' => function_exists('mb_substr') ? mb_substr($bio, 0, 180, 'UTF-8') : substr($bio, 0, 540)
        ]
    ];
    try {
        $result = journey_admin_update_user($sessionUserId, $targetUserId, $changes);
        if(!empty($result['ok'])){journey_dungeon_ensure_player(journey_db(),$targetUserId);$wingCoins=max(0,min(100000000,(int)($_POST['wingCoins']??0)));journey_db()->prepare('UPDATE dungeon_player_state SET wing_coins=?,updated_at=? WHERE user_id=?')->execute([$wingCoins,date('Y-m-d H:i:s'),$targetUserId]);}
    } catch (PDOException $exception) {
        http_response_code(409); echo json_encode(['code' => 'conflict', 'message' => '用户名或邮箱已被占用。'], JSON_UNESCAPED_UNICODE); exit;
    }
    if (empty($result['ok'])) http_response_code(($result['code'] ?? '') === 'not_found' ? 404 : 422);
    if (!empty($result['user'])) $result['user'] = adminUserPayload($result['user']);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'adminResetPassword') {
    $targetUserId = trim((string)($_POST['userId'] ?? ''));
    $newPassword = (string)($_POST['newPassword'] ?? '');
    if (strlen($newPassword) < 10 || strlen($newPassword) > 128 || !preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
        http_response_code(422); echo json_encode(['code' => 'password_length'], JSON_UNESCAPED_UNICODE); exit;
    }
    $result = journey_admin_update_user($sessionUserId, $targetUserId, ['password' => $newPassword]);
    if (empty($result['ok'])) http_response_code(($result['code'] ?? '') === 'not_found' ? 404 : 422);
    echo json_encode(['code' => $result['code'] ?? 'fail'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'adminListPosts') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $rows = [];
    foreach (getPosts() as $postIndex => $adminPost) {
        $rows[] = [
            'idx' => $postIndex, 'title' => (string)($adminPost['title'] ?? '无标题'),
            'content' => (string)($adminPost['content'] ?? ''), 'user' => (string)($adminPost['user'] ?? ''),
            'userId' => (string)($adminPost['userId'] ?? ''), 'category' => normalizeCategory($adminPost['category'] ?? 'daily'),
            'time' => (string)($adminPost['time'] ?? ''), 'replyCount' => count(is_array($adminPost['reply'] ?? null) ? $adminPost['reply'] : [])
        ];
    }
    $rows = array_reverse($rows);
    $total = count($rows);
    echo json_encode(['code' => 'ok', 'posts' => array_slice($rows, ($page - 1) * $limit, $limit), 'page' => $page, 'limit' => $limit, 'total' => $total], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'adminDeletePost') {
    $deleteIndex = (int)($_POST['idx'] ?? -1);
    $adminPosts = getPosts();
    if (!isset($adminPosts[$deleteIndex])) { http_response_code(404); echo json_encode(['code' => 'not_found']); exit; }
    $deletedPost = $adminPosts[$deleteIndex];
    array_splice($adminPosts, $deleteIndex, 1);
    savePosts($adminPosts);
    journey_audit('admin_delete_post', ['title' => $deletedPost['title'] ?? ''], $sessionUserId, 'post', (string)$deleteIndex);
    echo json_encode(['code' => 'ok'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'adminDeleteUserPosts') {
    $targetUserId = trim((string)($_POST['userId'] ?? ''));
    $adminPosts = getPosts();
    $topicCount = 0; $replyCount = 0; $kept = [];
    foreach ($adminPosts as $adminPost) {
        if (($adminPost['userId'] ?? '') === $targetUserId) { $topicCount++; continue; }
        $replies = is_array($adminPost['reply'] ?? null) ? $adminPost['reply'] : [];
        $filteredReplies = [];
        foreach ($replies as $reply) {
            if (($reply['userId'] ?? '') === $targetUserId) $replyCount++; else $filteredReplies[] = $reply;
        }
        $adminPost['reply'] = $filteredReplies;
        $kept[] = $adminPost;
    }
    savePosts($kept);
    journey_audit('admin_delete_user_posts', ['topics' => $topicCount, 'replies' => $replyCount], $sessionUserId, 'user', $targetUserId);
    echo json_encode(['code' => 'ok', 'topics' => $topicCount, 'replies' => $replyCount], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'adminSendMail') {
    $targetUserId = trim((string)($_POST['userId'] ?? ''));
    $subject = trim(strip_tags((string)($_POST['subject'] ?? '')));
    $body = trim(strip_tags((string)($_POST['body'] ?? '')));
    $itemId = trim((string)($_POST['itemId'] ?? ''));
    $itemCount = $itemId === '' ? 0 : max(1, min(999, (int)($_POST['itemCount'] ?? 1)));
    $xp = max(0, min(1000000000, (int)($_POST['xp'] ?? 0)));
    $gold = max(0, min(1000000000, (int)($_POST['gold'] ?? 0)));
    $subject = function_exists('mb_substr') ? mb_substr($subject, 0, 80, 'UTF-8') : substr($subject, 0, 240);
    $body = function_exists('mb_substr') ? mb_substr($body, 0, 500, 'UTF-8') : substr($body, 0, 1500);
    if ($targetUserId === '' || $subject === '') {
        http_response_code(422); echo json_encode(['code' => 'validation_failed'], JSON_UNESCAPED_UNICODE); exit;
    }
    $recipient = null;
    foreach (getUsers() as $candidate) {
        if (strcasecmp((string)($candidate['userId'] ?? ''), $targetUserId) === 0) { $recipient = $candidate; break; }
    }
    if (!$recipient) { http_response_code(404); echo json_encode(['code' => 'user_not_found'], JSON_UNESCAPED_UNICODE); exit; }
    if ($itemId !== '') {
        $availableItems = [];
        foreach (itemCatalog() as $catalogItem) $availableItems[(string)$catalogItem['id']] = true;
        if (!isset($availableItems[$itemId])) {
            http_response_code(422); echo json_encode(['code' => 'invalid_item'], JSON_UNESCAPED_UNICODE); exit;
        }
    }
    $mail = sendRewardMail((string)$recipient['userId'], $subject, $body, $itemId, $itemCount, $xp, $gold, $sessionUserId);
    journey_audit('admin_send_mail', ['subject' => $subject, 'itemId' => $itemId, 'itemCount' => $itemCount, 'xp' => $xp, 'gold' => $gold], $sessionUserId, 'user', (string)$recipient['userId']);
    echo json_encode(['code' => 'ok', 'mail' => $mail], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'adminUpdateSettings') {
    $ipLimit = max(1, min(3, (int)($_POST['registration_limit_ip'] ?? 3)));
    $deviceLimit = max(1, min(3, (int)($_POST['registration_limit_device'] ?? 3)));
    $postLimit = max(1, min(5, (int)($_POST['daily_post_limit'] ?? 5)));
    $keys = ['common', 'uncommon', 'rare', 'epic', 'legendary'];
    $rates = []; $sum = 0.0;
    foreach ($keys as $quality) {
        $raw = $_POST['lottery_' . $quality] ?? null;
        if (!is_numeric($raw)) { http_response_code(422); echo json_encode(['code' => 'invalid_rates']); exit; }
        $rates[$quality] = max(0, min(100, (float)$raw)); $sum += $rates[$quality];
    }
    if (abs($sum - 100) > 0.001) { http_response_code(422); echo json_encode(['code' => 'invalid_rates']); exit; }
    $weights = [];
    foreach ($rates as $quality => $rate) $weights[$quality] = (int)round($rate * 1000);
    $weights['common'] += 100000 - array_sum($weights);
    journey_setting_set('security.registration_limit_ip', $ipLimit);
    journey_setting_set('security.registration_limit_device', $deviceLimit);
    journey_setting_set('security.daily_post_limit', $postLimit);
    journey_setting_set('lottery.weights', $weights);
    journey_audit('admin_update_settings', [], $sessionUserId, 'settings', 'security');
    echo json_encode(['code' => 'ok', 'settings' => adminSettingsPayload()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'adminSaveContact') {
    $contactId = trim((string)($_POST['contactId'] ?? 'keyi'));
    $profiles = ['keyi'=>'keyiContactProfile','hotdog'=>'hotdogContactProfile','jack'=>'jackContactProfile','george'=>'georgeContactProfile'];
    $defaults = ['keyi'=>['type'=>'initial','text'=>'翼','color'=>'#8f2730'],'hotdog'=>['type'=>'initial','text'=>'阿','color'=>'#b65c35'],'jack'=>['type'=>'initial','text'=>'杰','color'=>'#b66a55'],'george'=>['type'=>'initial','text'=>'乔','color'=>'#9a6138']];
    if (!isset($profiles[$contactId])) {
        http_response_code(422);
        echo json_encode(['code' => 'invalid_contact'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $reset = ($_POST['avatarReset'] ?? '') === '1';
    $avatarData = trim((string)($_POST['avatar'] ?? ''));
    if ($reset) {
        $avatar = $defaults[$contactId];
    } elseif ($avatarData !== '') {
        $avatar = storeDatabaseImage($avatarData, $sessionUserId, 'contact_' . $contactId, 320000);
        if ($avatar === null) {
            http_response_code(422);
            echo json_encode(['code' => 'avatar_invalid'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        $avatar = $profiles[$contactId]()['avatar'];
    }
    journey_setting_set('contacts.' . $contactId . '_avatar', $avatar);
    foreach (['name','title','description'] as $profileField) {
        if (array_key_exists($profileField, $_POST)) {
            $value = trim((string)$_POST[$profileField]);
            if ($value !== '') journey_setting_set('contacts.' . $contactId . '_' . $profileField, mb_substr($value, 0, $profileField === 'description' ? 240 : 60));
        }
    }
    journey_audit('admin_contact_updated', ['contactId' => $contactId, 'avatarReset' => $reset], $sessionUserId, 'contact', $contactId);
    echo json_encode(['code' => 'ok', 'contact' => $profiles[$contactId]()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'adminGetDungeonConfig') {
    $bg = journey_setting_get('dungeon_background', '');
    $floorTex = journey_setting_get('dungeon_floor_textures', []);
    if (!is_array($floorTex)) $floorTex = [];
    $floorColors = journey_setting_get('dungeon_floor_colors', []);
    if (!is_array($floorColors)) $floorColors = [];
    $monsterConfig=journey_setting_get('dungeon_monsters',[]);if(!is_array($monsterConfig))$monsterConfig=[];
    echo json_encode(['code'=>'ok', 'dungeonBackground'=>(string)$bg, 'floorTextures'=>$floorTex,'floorColors'=>$floorColors,'monsterConfig'=>$monsterConfig], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'adminSaveDungeonConfig') {
    // 接受完整外部 URL 或本地上传后的 image.php 相对路径
    $isValidAppearanceUrl = function(string $url): bool {
        if ($url === '') return true;
        if (filter_var($url, FILTER_VALIDATE_URL)) return true;
        return (bool)preg_match('#^(?:\./|/)?image\.php\?key=[a-z_]+&v=[a-f0-9]{16}$#', $url);
    };
    $bgUrl = trim((string)($_POST['dungeon_background'] ?? ''));
    if (!$isValidAppearanceUrl($bgUrl)) {
        echo json_encode(['code'=>'invalid_url','field'=>'dungeon_background'], JSON_UNESCAPED_UNICODE); exit;
    }
    $floorTypes = ['spawn','chest','merchant','camp','shrine','boss','normal','elite','town','bridge'];
    $textures = [];
    foreach ($floorTypes as $ft) {
        $url = trim((string)($_POST['floor_'.$ft] ?? ''));
        if ($url !== '') {
            if (!$isValidAppearanceUrl($url)) {
                echo json_encode(['code'=>'invalid_url','field'=>'floor_'.$ft], JSON_UNESCAPED_UNICODE); exit;
            }
            $textures[$ft] = $url;
        }
    }
    journey_setting_set('dungeon_background', $bgUrl);
    journey_setting_set('dungeon_floor_textures', $textures);
    $existingFloorColors=journey_setting_get('dungeon_floor_colors',[]);if(!is_array($existingFloorColors))$existingFloorColors=[];
    $floorColors=[];foreach($floorTypes as $ft){$color=strtolower(trim((string)($_POST['color_'.$ft]??($existingFloorColors[$ft]??''))));if(preg_match('/^#[0-9a-f]{6}$/',$color))$floorColors[$ft]=$color;}
    journey_setting_set('dungeon_floor_colors',$floorColors);
    $monsterRaw=json_decode((string)($_POST['monster_config']??'{}'),true);$monsterConfig=[];$cutText=static function(string $value,int $limit):string{$value=trim(strip_tags($value));return function_exists('mb_substr')?mb_substr($value,0,$limit,'UTF-8'):substr($value,0,$limit*3);};
    if(is_array($monsterRaw)){foreach(['crawler','archer','shotgunner','brute','bomber','juggernaut','boss'] as $kind){$row=$monsterRaw[$kind]??null;if(!is_array($row))continue;$monsterConfig[$kind]=['name'=>$cutText((string)($row['name']??''),30),'description'=>$cutText((string)($row['description']??''),160),'image'=>trim((string)($row['image']??'')),'hp'=>max(1,min(100000,(float)($row['hp']??0))),'damage'=>max(1,min(10000,(float)($row['damage']??0))),'speed'=>max(1,min(500,(float)($row['speed']??0))),'attackSpeed'=>max(.1,min(5,(float)($row['attackSpeed']??1))),'attackInterval'=>max(.1,min(20,(float)($row['attackInterval']??1))),'skillDamage'=>max(.1,min(10,(float)($row['skillDamage']??1)))];}}
    journey_setting_set('dungeon_monsters',$monsterConfig);
    journey_audit('admin_dungeon_config_updated', [], $sessionUserId, 'dungeon', 'appearance');
    echo json_encode(['code'=>'ok','dungeonBackground'=>$bgUrl,'floorTextures'=>$textures,'floorColors'=>$floorColors,'monsterConfig'=>$monsterConfig], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'adminUploadDungeonImage') {
    // 管理员直接上传地牢外观图片：保存到 uploads/dungeon/，通过 image.php 公开访问
    // 前端已用 Canvas 压缩，这里再用 GD 做一次服务端兜底压缩
    $allowedKeys = ['dungeon_background','floor_spawn','floor_chest','floor_merchant','floor_camp','floor_shrine','floor_boss','floor_normal','floor_elite','floor_town','floor_bridge','monster_crawler','monster_archer','monster_shotgunner','monster_brute','monster_bomber','monster_juggernaut','monster_boss'];
    $key = trim((string)($_POST['key'] ?? ''));
    if (!in_array($key, $allowedKeys, true)) {
        echo json_encode(['code'=>'invalid_key'], JSON_UNESCAPED_UNICODE); exit;
    }
    // 检测服务器是否因体积过大直接拒绝了整个 POST（post_max_size / client_max_body_size）
    if (empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        echo json_encode(['code'=>'server_size_limit'], JSON_UNESCAPED_UNICODE); exit;
    }
    if (empty($_FILES['file']) || !is_array($_FILES['file']) || (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        echo json_encode(['code'=>'no_file'], JSON_UNESCAPED_UNICODE); exit;
    }
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $code = $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE ? 'file_too_large' : 'upload_error';
        echo json_encode(['code'=>$code,'detail'=>(int)$file['error']], JSON_UNESCAPED_UNICODE); exit;
    }
    if ($file['size'] > 8 * 1024 * 1024) {
        echo json_encode(['code'=>'file_too_large'], JSON_UNESCAPED_UNICODE); exit;
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        echo json_encode(['code'=>'invalid_upload'], JSON_UNESCAPED_UNICODE); exit;
    }
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime = $finfo ? (string)finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) finfo_close($finfo);
    if ($mime === '' && function_exists('mime_content_type')) $mime = (string)@mime_content_type($file['tmp_name']);
    if ($mime === '' && function_exists('getimagesize')) {
        $imageInfo = @getimagesize($file['tmp_name']);
        $mime = is_array($imageInfo) ? (string)($imageInfo['mime'] ?? '') : '';
    }
    $allowedMimes = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    if (!isset($allowedMimes[$mime]) || @getimagesize($file['tmp_name']) === false) {
        echo json_encode(['code'=>'bad_type'], JSON_UNESCAPED_UNICODE); exit;
    }

    // GD 服务端压缩兜底：背景最大1920×1080，地板纹理最大256×256
    $isBg = $key === 'dungeon_background';
    $maxW = $isBg ? 1920 : 256;
    $maxH = $isBg ? 1080 : 256;
    $jpegQuality = $isBg ? 80 : 85;
    $outExt = $allowedMimes[$mime];
    $gdSuccess = false;
    $storedFromUpload = true;
    $sourcePath = $file['tmp_name'];
    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        $srcImg = @imagecreatefromstring(file_get_contents($file['tmp_name']));
        if ($srcImg !== false) {
            $srcW = imagesx($srcImg); $srcH = imagesy($srcImg);
            $scale = min(1, $maxW / $srcW, $maxH / $srcH);
            $dstW = max(1, (int)round($srcW * $scale));
            $dstH = max(1, (int)round($srcH * $scale));
            $dstImg = imagecreatetruecolor($dstW, $dstH);
            // PNG 保留透明通道
            if ($outExt === 'png' && function_exists('imagealphablending')) {
                imagealphablending($dstImg, false);
                imagesavealpha($dstImg, true);
                $transparent = imagecolorallocatealpha($dstImg, 0, 0, 0, 127);
                imagefilledrectangle($dstImg, 0, 0, $dstW, $dstH, $transparent);
            }
            imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
            $tmpCompressed = $file['tmp_name'] . '.compressed';
            if ($outExt === 'png' && function_exists('imagepng')) {
                $gdSuccess = imagepng($dstImg, $tmpCompressed, 9);
            } else {
                // 非透明图统一转 JPEG 以减小体积
                $outExt = 'jpg';
                $gdSuccess = imagejpeg($dstImg, $tmpCompressed, $jpegQuality);
            }
            imagedestroy($srcImg); imagedestroy($dstImg);
            if ($gdSuccess && is_file($tmpCompressed)) {
                // 压缩后的文件已不再是 PHP 标记的“上传文件”，后面不能再调用 move_uploaded_file。
                // 直接记录新的临时路径，最终用 rename 保存，避免始终返回 save_failed。
                $sourcePath = $tmpCompressed;
                $storedFromUpload = false;
            }
        }
    }

    $dungeonDir = rtrim((string)journey_config('data_dir'), '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'dungeon';
    if (!is_dir($dungeonDir)) mkdir($dungeonDir, 0755, true);
    $hash = bin2hex(random_bytes(8));
    $storedName = $key . '_' . $hash . '.' . $outExt;
    $targetPath = $dungeonDir . DIRECTORY_SEPARATOR . $storedName;
    $saved = $storedFromUpload
        ? @move_uploaded_file($file['tmp_name'], $targetPath)
        : @rename($sourcePath, $targetPath);
    if (!$saved) {
        echo json_encode(['code'=>'save_failed'], JSON_UNESCAPED_UNICODE); exit;
    }
    $fileMap = journey_setting_get('dungeon_appearance_files', []);
    if (!is_array($fileMap)) $fileMap = [];
    $old = (string)($fileMap[$key] ?? '');
    if ($old !== '' && preg_match('/^[a-z0-9_]{1,80}\.(?:jpe?g|png|webp|gif)$/i', $old)) {
        @unlink($dungeonDir . DIRECTORY_SEPARATOR . $old);
    }
    $fileMap[$key] = $storedName;
    journey_setting_set('dungeon_appearance_files', $fileMap);
    $url = 'image.php?key=' . $key . '&v=' . $hash;
    journey_audit('admin_dungeon_image_uploaded', ['key'=>$key], $sessionUserId, 'dungeon', 'appearance');
    echo json_encode(['code'=>'ok','url'=>$url,'key'=>$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'adminListItems') {
    $managed = managedItemMap();
    $rows = itemCatalog();
    $known = [];
    foreach ($rows as &$row) {
        $known[$row['id']] = true;
        $row['price'] = itemSystemPrice($row['id']);
        $row['deleted'] = !empty($managed[$row['id']]['deleted']);
        $row['custom'] = !empty($managed[$row['id']]['custom']);
    }
    unset($row);
    foreach ($managed as $itemId => $record) {
        if (isset($known[$itemId])) continue;
        $row = itemDefinition($itemId);
        $row['price'] = max(1, (int)($record['price'] ?? itemSystemPrice($itemId)));
        $row['deleted'] = !empty($record['deleted']);
        $row['custom'] = !empty($record['custom']);
        $rows[] = $row;
    }
    usort($rows, function($a, $b) { return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? '')); });
    echo json_encode(['code' => 'ok', 'items' => $rows], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'adminSaveItem') {
    $itemId = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim((string)($_POST['itemId'] ?? '')));
    if ($itemId === '') $itemId = 'custom_' . bin2hex(random_bytes(6));
    $name = trim(strip_tags((string)($_POST['name'] ?? '')));
    $quality = (string)($_POST['quality'] ?? 'common');
    $price = max(1, min(100000000, (int)($_POST['price'] ?? 1)));
    if ($name === '' || !in_array($quality, ['common','uncommon','rare','epic','legendary'], true)) {
        http_response_code(422); echo json_encode(['code' => 'validation_failed'], JSON_UNESCAPED_UNICODE); exit;
    }
    $managed = managedItemMap();
    $existing = $managed[$itemId] ?? [];
    $spreadsheetNames = spreadsheetItemNames();
    $isBuiltIn = in_array($itemId, ['welcome_journey','nut_cola'], true) || isset($spreadsheetNames[$itemId]) || preg_match('/^(common|uncommon|rare|epic|legendary)_\d{3}$/', $itemId);
    $record = [
        'id' => $itemId, 'name' => function_exists('mb_substr') ? mb_substr($name,0,80,'UTF-8') : substr($name,0,240),
        'quality' => $quality, 'price' => $price,
        'icon' => trim(strip_tags((string)($_POST['icon'] ?? ''))),
        'type' => trim(strip_tags((string)($_POST['type'] ?? ''))),
        'desc' => trim(strip_tags((string)($_POST['desc'] ?? ''))),
        'custom' => !$isBuiltIn || !empty($existing['custom']), 'deleted' => false,
        'updatedAt' => date('Y-m-d H:i:s'), 'updatedBy' => $sessionUserId
    ];
    $managed[$itemId] = $record;
    journey_store_set('managed_items', array_values($managed));
    managedItemMap(true);
    journey_audit('admin_save_item', ['name' => $record['name'], 'price' => $price], $sessionUserId, 'item', $itemId);
    $item = itemDefinition($itemId); $item['price'] = $price; $item['custom'] = $record['custom'];
    echo json_encode(['code' => 'ok', 'item' => $item], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'adminDeleteItem') {
    $itemId = trim((string)($_POST['itemId'] ?? ''));
    if ($itemId === '') { http_response_code(422); echo json_encode(['code' => 'validation_failed']); exit; }
    $managed = managedItemMap();
    $current = $managed[$itemId] ?? itemDefinition($itemId);
    $current['id'] = $itemId; $current['deleted'] = true; $current['updatedAt'] = date('Y-m-d H:i:s'); $current['updatedBy'] = $sessionUserId;
    $managed[$itemId] = $current;
    journey_store_set('managed_items', array_values($managed));
    managedItemMap(true);
    journey_audit('admin_delete_item', [], $sessionUserId, 'item', $itemId);
    echo json_encode(['code' => 'ok'], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'adminAuditLogs') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
    $offset = ($page - 1) * $limit;
    $pdo = journey_db();
    $total = (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    $auditRows = $pdo->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset)->fetchAll();
    $logs = [];
    foreach ($auditRows as $auditRow) {
        $logs[] = [
            'eventType' => $auditRow['event_type'], 'actorUserId' => $auditRow['actor_user_id'],
            'targetType' => $auditRow['target_type'], 'targetId' => $auditRow['target_id'],
            'ipFingerprint' => substr((string)$auditRow['ip_hash'], 0, 12),
            'deviceFingerprint' => substr((string)$auditRow['device_hash'], 0, 12),
            'details' => journey_json_decode((string)$auditRow['details_json'], []), 'createdAt' => $auditRow['created_at']
        ];
    }
    echo json_encode(['code' => 'ok', 'logs' => $logs, 'page' => $page, 'limit' => $limit, 'total' => $total], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'updateActive' && !empty($userId)) {
    $users = getUsers();
    foreach ($users as &$u) {
        if ($u['userId'] === $userId) {
            $u['lastActive'] = date('Y-m-d H:i:s');
            saveUsers($users);
            echo 'ok';
            exit;
        }
    }
    echo 'fail';
    exit;
}

if ($action === 'checkOnline' && !empty($friendId)) {
    $users = getUsers();
    foreach ($users as $u) {
        if ($u['userId'] === $friendId) {
            $lastActive = isset($u['lastActive']) ? $u['lastActive'] : '';
            $now = time();
            $last = strtotime($lastActive);
            $isOnline = ($now - $last) < 300;
            echo json_encode(['online' => $isOnline, 'lastActive' => $lastActive], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    echo json_encode(['online' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'checkName') {
    $users = getUsers();
    foreach ($users as $u) {
        if ($u['user'] === $newName) {
            echo 'exist';
            exit;
        }
    }
    echo 'ok';
    exit;
}

if ($action === 'updateName' && !empty($newName) && !empty($userId)) {
    $newName = trim((string)$newName);
    $newNameLength = journey_string_length($newName);
    if ($newNameLength < 2 || $newNameLength > 24 || !preg_match('/^[\p{L}\p{N}_.·-]+$/u', $newName)) {
        echo 'invalid_name';
        exit;
    }
    $users = getUsers();
    foreach ($users as $u) {
        if ($u['user'] === $newName && $u['userId'] !== $userId) {
            echo 'exist';
            exit;
        }
    }
    foreach ($users as &$u) {
        if ($u['userId'] === $userId) {
            $u['user'] = $newName;
            foreach ($posts as &$p) {
                if ($p['userId'] === $userId) $p['user'] = $newName;
                foreach ($p['reply'] as &$r) {
                    if ($r['userId'] === $userId) $r['user'] = $newName;
                }
            }
            saveUsers($users);
            savePosts($posts);
            echo 'ok';
            exit;
        }
    }
    echo 'fail';
    exit;
}

if ($action === 'get') {
    outputPosts($posts, $userId, $admin_uid);
    exit;
}

if ($action === 'download') {
    $fileId = basename($_GET['file'] ?? '');
    if ($fileId === '') {
        http_response_code(404);
        echo 'not found';
        exit;
    }
    $found = null;
    $foundCategory = 'daily';
    foreach ($posts as $p) {
        if (!empty($p['file']['stored']) && hash_equals($p['file']['stored'], $fileId)) {
            $found = $p['file'];
            $foundCategory = normalizeCategory($p['category'] ?? 'daily');
            break;
        }
    }
    if (!$found || !canAccessCategory($foundCategory, $sectionPass)) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
    $path = $uploadDir . DIRECTORY_SEPARATOR . $fileId;
    if (!is_file($path)) {
        http_response_code(404);
        echo 'not found';
        exit;
    }
    header('Content-Type: ' . ($found['type'] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . rawurlencode($found['name'] ?? $fileId) . '"');
    readfile($path);
    exit;
}

if ($action === 'add' && (!empty($content) || !empty($image) || !empty($_FILES['uploadFile']) || !empty($_REQUEST['pollOptions'])) && !empty($userId)) {
    if (!canAccessCategory($category, $sectionPass)) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
    $content = trim((string)$content);
    $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $content);
    $title = trim((string)($_REQUEST['title'] ?? ''));
    $poll = null;
    $pollOptions = json_decode((string)($_REQUEST['pollOptions'] ?? '[]'), true);
    if (is_array($pollOptions) && count($pollOptions) >= 2) {
        if (!journey_is_admin($userId)) {
            http_response_code(403); echo json_encode(['code' => 'poll_admin_only'], JSON_UNESCAPED_UNICODE); exit;
        }
        $cleanOptions = [];
        foreach (array_slice($pollOptions, 0, 10) as $optionText) {
            $optionText = trim(strip_tags((string)$optionText));
            if ($optionText === '') continue;
            $optionText = function_exists('mb_substr') ? mb_substr($optionText, 0, 80, 'UTF-8') : substr($optionText, 0, 240);
            $cleanOptions[] = ['id' => 'option_' . (count($cleanOptions) + 1), 'text' => $optionText, 'votes' => []];
        }
        if (count($cleanOptions) >= 2) $poll = ['multiple' => ($_REQUEST['pollMultiple'] ?? '') === '1', 'options' => $cleanOptions, 'voters' => []];
    }
    if (journey_string_length($content) > 2000) {
        http_response_code(422);
        echo json_encode(['code' => 'content_too_long'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (journey_string_length($title) > 60) {
        http_response_code(422);
        echo json_encode(['code' => 'title_too_long'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$color)) $color = '#ddd4c8';
    if ($image !== '' && (strlen((string)$image) > 1200 * 1024 || !preg_match('/^data:image\/(png|jpe?g|webp);base64,[A-Za-z0-9+\/=\r\n]+$/i', (string)$image))) {
        http_response_code(422);
        echo json_encode(['code' => 'image_invalid'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $burstLimit = journey_rate_limit('forum.post.burst', $userId, 3, 60, true);
    if (!$burstLimit['allowed']) {
        http_response_code(429);
        echo json_encode(['code' => 'rate_limited', 'retryAfter' => $burstLimit['retryAfter']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $users = getUsers();
    $username = '';
    $xpGain = 0;
    foreach ($users as &$u) {
        if ($u['userId'] === $userId) {
            $username = $u['user'];
            $u['lastActive'] = date('Y-m-d H:i:s');
        }
    }
    if ($username === '') {
        echo 'fail';
        exit;
    }
    $dailyLimitValue = max(1, min(5, (int)journey_setting_get('security.daily_post_limit', 5)));
    $dailyLimit = journey_daily_action_limit('forum.post', $userId, $dailyLimitValue, true);
    if (!$dailyLimit['allowed']) {
        http_response_code(429);
        echo json_encode(['code' => 'daily_post_limit', 'limit' => $dailyLimitValue, 'retryAfter' => $dailyLimit['retryAfter']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $uploadedFile = handleUploadFile();
    if (isset($uploadedFile['error'])) {
        echo $uploadedFile['error'];
        exit;
    }
    if ($image !== '') {
        $storedPostImage = storeDatabaseImage($image, $userId, 'forum', 900000);
        if ($storedPostImage === null) {
            http_response_code(422);
            echo json_encode(['code' => 'image_invalid'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $image = $storedPostImage['src'];
    }
    $dailyGoldGain = 0;
    foreach ($users as &$u) {
        if ($u['userId'] === $userId) {
            $today = date('Y-m-d');
            if (($u['lastDailyPostReward'] ?? '') !== $today) {
                $xpGain = 10;
                $dailyGoldGain = random_int(30, 50);
                $u['xp'] = max(0, (int)($u['xp'] ?? 0)) + $xpGain;
                $u['gold'] = max(0, (int)($u['gold'] ?? 0)) + $dailyGoldGain;
                $u['lastDailyPostReward'] = $today;
                addUserNotification($u, 'daily_post_reward', "每日首次发帖奖励：+{$xpGain} 经验，+{$dailyGoldGain} 金币");
            }
            break;
        }
    }
    unset($u);
    saveUsers($users);
    $posts[] = [
        'user' => $username,
        'userId' => $userId,
        'category' => $category,
        'content' => $content,
        'color' => $color,
        'bold' => $bold,
        'italic' => $italic,
        'time' => date('Y-m-d H:i:s'),
        'likeNum' => 0,
        'likeUsers' => [],
        'rewardedLikeUsers' => [],
        'reply' => [],
        'image' => $image,
        'file' => $uploadedFile,
        'pinned' => false,
        'title' => $title
    ];
    if ($poll !== null) $posts[count($posts) - 1]['poll'] = $poll;
    savePosts($posts);
    recordDailyTaskAction($userId, 'post', 'post:' . (count($posts) - 1));
    echo json_encode(['code' => 'ok', 'xpGain' => $xpGain, 'goldGain' => $dailyGoldGain], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'like' && $idx >= 0 && !empty($userId)) {
    if (!isset($posts[$idx])) {
        echo 'fail';
        exit;
    }
    if (!canAccessCategory(normalizeCategory($posts[$idx]['category'] ?? 'daily'), $sectionPass)) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
    $p = &$posts[$idx];
    if (!isset($p['rewardedLikeUsers']) || !is_array($p['rewardedLikeUsers'])) {
        $p['rewardedLikeUsers'] = array_values(is_array($p['likeUsers'] ?? null) ? $p['likeUsers'] : []);
    }
    $key = array_search($userId, $p['likeUsers'] ?? []);
    $goldReward = 0;
    if ($key === false) {
        $p['likeNum'] = ($p['likeNum'] ?? 0) + 1;
        $p['likeUsers'][] = $userId;
        if (!isset($p['rewardedLikeUsers']) || !is_array($p['rewardedLikeUsers'])) $p['rewardedLikeUsers'] = [];
        $authorId = (string)($p['userId'] ?? '');
        if ($authorId !== '' && $authorId !== $userId && !in_array($userId, $p['rewardedLikeUsers'], true)) {
            $p['rewardedLikeUsers'][] = $userId;
            $users = getUsers();
            foreach ($users as &$likeAuthor) {
                if (($likeAuthor['userId'] ?? '') !== $authorId) continue;
                ensureEconomyFields($likeAuthor);
                $likeAuthor['gold'] += 5;
                addUserNotification($likeAuthor, 'like_reward', '你的帖子首次收到一位玩家的点赞，获得 5 金币。', ['postIndex' => $idx]);
                $goldReward = 5;
                break;
            }
            unset($likeAuthor);
            saveUsers($users);
        }
    } else {
        $p['likeNum'] = ($p['likeNum'] ?? 1) - 1;
        array_splice($p['likeUsers'], $key, 1);
    }
    savePosts($posts);
    if ($key === false) recordDailyTaskAction($userId, 'like', 'post:' . $idx);
    echo json_encode(['likeNum' => $p['likeNum'], 'liked' => $key === false, 'authorGoldReward' => $goldReward], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'votePoll' && $idx >= 0 && !empty($userId)) {
    if (!isset($posts[$idx]['poll']) || !is_array($posts[$idx]['poll'])) {
        http_response_code(404); echo json_encode(['code' => 'not_found'], JSON_UNESCAPED_UNICODE); exit;
    }
    $poll = &$posts[$idx]['poll'];
    if (!isset($poll['voters']) || !is_array($poll['voters'])) $poll['voters'] = [];
    if (isset($poll['voters'][$userId])) {
        echo json_encode(['code' => 'already_voted'], JSON_UNESCAPED_UNICODE); exit;
    }
    $selections = json_decode((string)($_REQUEST['selections'] ?? '[]'), true);
    if (!is_array($selections)) $selections = [];
    $selections = array_values(array_unique(array_map('strval', $selections)));
    $validIds = array_map(function($option) { return (string)($option['id'] ?? ''); }, $poll['options'] ?? []);
    $selections = array_values(array_intersect($selections, $validIds));
    if (count($selections) < 1 || (empty($poll['multiple']) && count($selections) !== 1)) {
        http_response_code(422); echo json_encode(['code' => 'invalid_selection'], JSON_UNESCAPED_UNICODE); exit;
    }
    $poll['voters'][$userId] = $selections;
    foreach ($poll['options'] as &$option) {
        if (!isset($option['votes']) || !is_array($option['votes'])) $option['votes'] = [];
        if (in_array((string)($option['id'] ?? ''), $selections, true)) $option['votes'][] = $userId;
    }
    unset($option);
    savePosts($posts);
    echo json_encode(['code' => 'ok'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'reply' && $idx >= 0 && !empty($content) && !empty($userId)) {
    if (!isset($posts[$idx])) {
        echo 'fail';
        exit;
    }
    if (!canAccessCategory(normalizeCategory($posts[$idx]['category'] ?? 'daily'), $sectionPass)) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
    $content = trim((string)$content);
    $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $content);
    if ($content === '' || journey_string_length($content) > 800) {
        http_response_code(422);
        echo json_encode(['code' => 'content_too_long'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $replyLimit = journey_rate_limit('forum.reply', $userId, 30, 3600, true);
    $replyBurst = journey_rate_limit('forum.reply.burst', $userId, 8, 60, true);
    if (!$replyLimit['allowed'] || !$replyBurst['allowed']) {
        http_response_code(429);
        echo json_encode(['code' => 'rate_limited'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $users = getUsers();
    $username = '';
    $xpGain = 0;
    foreach ($users as &$u) {
        if ($u['userId'] === $userId) {
            $username = $u['user'];
            $u['lastActive'] = date('Y-m-d H:i:s');
            $xpGain = grantUserXp($u, 1, 10);
        }
    }
    if ($username === '') {
        http_response_code(401);
        echo json_encode(['code' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    saveUsers($users);
    $posts[$idx]['reply'][] = [
        'user' => $username,
        'userId' => $userId,
        'content' => $content,
        'time' => date('Y-m-d H:i:s'),
        'replyLikeNum' => 0,
        'likeUsers' => [],
        'rewardedLikeUsers' => []
    ];
    savePosts($posts);
    recordDailyTaskAction($userId, 'reply', 'post:' . $idx . ':reply:' . (count($posts[$idx]['reply']) - 1));
    echo json_encode(['code' => 'ok', 'xpGain' => $xpGain], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'replyLike' && $idx >= 0 && $pid >= 0 && !empty($userId)) {
    if (!isset($posts[$idx]) || !isset($posts[$idx]['reply'][$pid])) {
        echo 'fail';
        exit;
    }
    if (!canAccessCategory(normalizeCategory($posts[$idx]['category'] ?? 'daily'), $sectionPass)) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
    $r = &$posts[$idx]['reply'][$pid];
    if (!isset($r['rewardedLikeUsers']) || !is_array($r['rewardedLikeUsers'])) {
        $r['rewardedLikeUsers'] = array_values(is_array($r['likeUsers'] ?? null) ? $r['likeUsers'] : []);
    }
    $key = array_search($userId, $r['likeUsers'] ?? []);
    $goldReward = 0;
    if ($key === false) {
        $r['replyLikeNum'] = ($r['replyLikeNum'] ?? 0) + 1;
        $r['likeUsers'][] = $userId;
        if (!isset($r['rewardedLikeUsers']) || !is_array($r['rewardedLikeUsers'])) $r['rewardedLikeUsers'] = [];
        $authorId = (string)($r['userId'] ?? '');
        if ($authorId !== '' && $authorId !== $userId && !in_array($userId, $r['rewardedLikeUsers'], true)) {
            $r['rewardedLikeUsers'][] = $userId;
            $users = getUsers();
            foreach ($users as &$replyAuthor) {
                if (($replyAuthor['userId'] ?? '') !== $authorId) continue;
                ensureEconomyFields($replyAuthor);
                $replyAuthor['gold'] += 5;
                addUserNotification($replyAuthor, 'like_reward', '你的评论首次收到一位玩家的点赞，获得 5 金币。', ['postIndex' => $idx, 'replyIndex' => $pid]);
                $goldReward = 5;
                break;
            }
            unset($replyAuthor);
            saveUsers($users);
        }
    } else {
        $r['replyLikeNum'] = ($r['replyLikeNum'] ?? 1) - 1;
        array_splice($r['likeUsers'], $key, 1);
    }
    savePosts($posts);
    echo json_encode(['replyLikeNum' => $r['replyLikeNum'], 'replyLiked' => $key === false, 'authorGoldReward' => $goldReward], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'del' && $idx >= 0 && !empty($userId)) {
    if (!isset($posts[$idx])) {
        echo 'fail';
        exit;
    }
    if (!canAccessCategory(normalizeCategory($posts[$idx]['category'] ?? 'daily'), $sectionPass)) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
    $p = $posts[$idx];
    if ($p['userId'] !== $userId && $userId !== $admin_uid) {
        echo 'fail';
        exit;
    }
    array_splice($posts, $idx, 1);
    savePosts($posts);
    echo 'ok';
    exit;
}

if ($action === 'delReply' && $idx >= 0 && $pid >= 0 && !empty($userId)) {
    if (!isset($posts[$idx]) || !isset($posts[$idx]['reply'][$pid])) {
        echo 'fail';
        exit;
    }
    if (!canAccessCategory(normalizeCategory($posts[$idx]['category'] ?? 'daily'), $sectionPass)) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
    $r = $posts[$idx]['reply'][$pid];
    if ($r['userId'] !== $userId && $posts[$idx]['userId'] !== $userId && $userId !== $admin_uid) {
        echo 'fail';
        exit;
    }
    array_splice($posts[$idx]['reply'], $pid, 1);
    savePosts($posts);
    echo 'ok';
    exit;
}

if ($action === 'pin' && $idx >= 0 && !empty($userId)) {
    if ($userId !== $admin_uid) {
        echo 'fail';
        exit;
    }
    if (!isset($posts[$idx])) {
        echo 'fail';
        exit;
    }
    if (!canAccessCategory(normalizeCategory($posts[$idx]['category'] ?? 'daily'), $sectionPass)) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
    $posts[$idx]['pinned'] = !$posts[$idx]['pinned'];
    savePosts($posts);
    echo $posts[$idx]['pinned'] ? 'pinned' : 'unpinned';
    exit;
}

if ($action === 'getUser' && !empty($userId)) {
    $users = getUsers();
    foreach ($users as $u) {
        if ($u['userId'] === $userId) {
            echo json_encode(publicUserProfile($u, $posts), JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    echo '{}';
    exit;
}

if ($action === 'getPublicUser' && !empty($userId)) {
    $users = getUsers();
    foreach ($users as $u) {
        if (($u['userId'] ?? '') === $userId) {
            echo json_encode(publicVisibleProfile($u, $posts), JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    echo '{}';
    exit;
}

if ($action === 'getLeaderboard') {
    $type = $_GET['type'] ?? 'gold';
    $allowed = ['gold', 'level', 'xp', 'posts', 'likes', 'dungeonKills', 'dungeonDeaths', 'dungeonEntries', 'dungeonFloors'];
    if (!in_array($type, $allowed, true)) $type = 'gold';
    $users = getUsers();
    $dungeonStats = journey_dungeon_stats_map(journey_db());
    $rows = [];
    foreach ($users as $u) {
        $profile = publicVisibleProfile($u, $posts);
        $stats = $dungeonStats[(string)($u['userId'] ?? '')] ?? ['total_kills'=>0,'total_deaths'=>0,'dungeon_entries'=>0,'total_floors'=>0];
        $profile['dungeonKills']=$stats['total_kills'];$profile['dungeonDeaths']=$stats['total_deaths'];$profile['dungeonEntries']=$stats['dungeon_entries'];$profile['dungeonFloors']=$stats['total_floors'];
        $score = 0;
        if ($type === 'gold') $score = !empty($profile['unlimitedGold']) ? PHP_INT_MAX : (int)($profile['gold'] ?? 0);
        if ($type === 'level') $score = (int)($profile['level'] ?? 1);
        if ($type === 'xp') $score = (int)($profile['xp'] ?? 0);
        if ($type === 'posts') $score = (int)($profile['postCount'] ?? 0) + (int)($profile['replyCount'] ?? 0);
        if ($type === 'likes') $score = (int)($profile['receivedLikes'] ?? 0);
        if ($type === 'dungeonKills') $score = (int)$profile['dungeonKills'];
        if ($type === 'dungeonDeaths') $score = (int)$profile['dungeonDeaths'];
        if ($type === 'dungeonEntries') $score = (int)$profile['dungeonEntries'];
        if ($type === 'dungeonFloors') $score = (int)$profile['dungeonFloors'];
        $profile['score'] = $score;
        $rows[] = $profile;
    }
    usort($rows, function($a, $b) {
        if (($b['score'] ?? 0) === ($a['score'] ?? 0)) {
            return strcmp($a['userId'] ?? '', $b['userId'] ?? '');
        }
        return ($b['score'] ?? 0) - ($a['score'] ?? 0);
    });
    outputCachedJson(['code' => 'ok', 'type' => $type, 'rows' => array_slice($rows, 0, 100)], 15);
}

if ($action === 'getInventory' && (!empty($userId) || !empty($_REQUEST['username']))) {
    $users = getUsers();
    foreach ($users as &$u) {
        $storedUserId = (string)($u['userId'] ?? '');
        $requestedName = trim((string)($_REQUEST['username'] ?? ''));
        $matchesId = $storedUserId !== '' && strcasecmp($storedUserId, (string)$userId) === 0;
        $matchesLegacyName = !$matchesId && $requestedName !== '' && (string)($u['user'] ?? '') === $requestedName;
        if ($matchesId || $matchesLegacyName) {
            ensureEconomyFields($u);
            if (lotteryTenPayload($u)) {
                finalizeLotteryTenBatch($u);
                saveUsers($users);
            }
            $payload = [
                'code' => 'ok',
                'userId' => $storedUserId,
                'inventory' => $u['inventory'],
                'warehouse' => $u['warehouse'],
                'warehouseLevel' => (int)$u['warehouseLevel'],
                'inventoryVersion' => (int)($u['inventoryVersion'] ?? 1),
                'gold' => (int)$u['gold'],
                'unlimitedGold' => hasUnlimitedGold($u),
                'freeDrawAvailable' => ($u['lastFreeDraw'] ?? '') !== date('Y-m-d'),
                'lotteryDrawsToday' => (($u['lotteryDrawDate'] ?? '') === date('Y-m-d')) ? max(0, (int)($u['lotteryDrawCount'] ?? 0)) : 0,
                'lotteryDailyLimit' => lotteryEconomyConfig()['dailyLimit'],
                'lotteryDrawCost' => lotteryEconomyConfig()['drawCost'],
                'lotteryPity' => lotteryPityPayload($u),
                'lastCheckin' => $u['lastCheckin'] ?? ''
            ];
            $jsonFlags = JSON_UNESCAPED_UNICODE;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
            $encoded = json_encode($payload, $jsonFlags);
            echo $encoded !== false ? $encoded : '{"code":"encode_error"}';
            exit;
        }
    }
    echo json_encode(['code' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'getItemCatalog') {
    outputCachedJson([
        'code' => 'ok',
        'items' => itemCatalog()
    ], 300);
}

if ($action === 'getDailyTasks' && !empty($userId)) {
    $users = getUsers();
    foreach ($users as &$candidate) {
        if (($candidate['userId'] ?? '') !== $userId) continue;
        ensureEconomyFields($candidate);
        $before = serialize($candidate['dailyTask'] ?? null);
        ensureDailyTask($candidate);
        if ($before !== serialize($candidate['dailyTask'])) saveUsers($users);
        echo json_encode([
            'code' => 'ok',
            'task' => dailyTaskPayload($candidate, $posts),
            'gold' => (int)$candidate['gold'],
            'xp' => (int)($candidate['xp'] ?? 0),
            'level' => levelFromXp((int)($candidate['xp'] ?? 0))
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    unset($candidate);
    echo json_encode(['code' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'claimDailyTask' && !empty($userId)) {
    $users = getUsers();
    foreach ($users as &$candidate) {
        if (($candidate['userId'] ?? '') !== $userId) continue;
        ensureEconomyFields($candidate);
        $task = ensureDailyTask($candidate);
        if (!empty($task['claimed'])) {
            echo json_encode(['code' => 'already_claimed', 'task' => dailyTaskPayload($candidate, $posts), 'gold' => (int)$candidate['gold']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ((int)$task['progress'] < (int)$task['target']) {
            echo json_encode(['code' => 'not_completed', 'task' => dailyTaskPayload($candidate, $posts)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $rewardKind=(string)($task['rewardKind']??'gold');$rewardGold=0;$rewardItem=null;$delivery='';
        if($rewardKind==='item'){$rewardItem=itemDefinition((string)$task['rewardItemId']);$delivery=deliverOverflowItem($candidate,(string)$task['rewardItemId'],1,'每日地牢任务奖励：背包与仓库空间不足');}
        else{$rewardGold=max(1,(int)$task['rewardGold']);$candidate['gold']=min(2147483647,(int)$candidate['gold']+$rewardGold);}
        $task['claimed'] = true;
        $candidate['dailyTask'] = $task;
        $rewardMessage=$rewardKind==='item'?'获得「'.($rewardItem['name']??'地牢物品').'」。':"获得 {$rewardGold} 金币。";
        addUserNotification($candidate, 'daily_task_reward', '每日任务完成：'.$rewardMessage, ['taskType' => $task['type']]);
        saveUsers($users);
        echo json_encode([
            'code' => 'ok',
            'rewardGold' => $rewardGold,
            'rewardItem' => $rewardItem,
            'delivery' => $delivery,
            'gold' => (int)$candidate['gold'],
            'task' => dailyTaskPayload($candidate, $posts)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    unset($candidate);
    echo json_encode(['code' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'getMarketTrends') {
    $economy = lotteryEconomyConfig();
    outputCachedJson([
        'code' => 'ok',
        'history' => qualityMarketHistory((int)($_GET['days'] ?? 30)),
        'today' => date('Y-m-d'),
        'drawCost' => $economy['drawCost'],
        'dailyLimit' => $economy['dailyLimit']
    ], 300);
}

if ($action === 'buyWarehouseExpansion' && !empty($userId)) {
    $costs = [100, 200, 400, 800, 1200];
    $users = getUsers();
    foreach ($users as &$u) {
        if (($u['userId'] ?? '') !== $userId) continue;
        ensureEconomyFields($u);
        $level = (int)$u['warehouseLevel'];
        if ($level >= count($costs)) {
            echo json_encode(['code' => 'max'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $cost = $costs[$level];
        if (!hasUnlimitedGold($u) && (int)$u['gold'] < $cost) {
            echo json_encode(['code' => 'nogold', 'gold' => (int)$u['gold']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!hasUnlimitedGold($u)) $u['gold'] -= $cost;
        $u['warehouseLevel'] = $level + 1;
        $u['warehouse'] = normalizeWarehouseSlots($u['warehouse'], $u['warehouseLevel'] * 21);
        saveUsers($users);
        echo json_encode([
            'code' => 'ok',
            'gold' => (int)$u['gold'],
            'unlimitedGold' => hasUnlimitedGold($u),
            'warehouse' => $u['warehouse'],
            'warehouseLevel' => (int)$u['warehouseLevel']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'transferStorageItem' && !empty($userId)) {
    $direction = ($_REQUEST['direction'] ?? '') === 'toBag' ? 'toBag' : 'toWarehouse';
    $slotIndex = (int)($_REQUEST['slotIndex'] ?? -1);
    $users = getUsers();
    foreach ($users as &$u) {
        if (($u['userId'] ?? '') !== $userId) continue;
        ensureEconomyFields($u);
        $sourceKey = $direction === 'toBag' ? 'warehouse' : 'inventory';
        $targetKey = $direction === 'toBag' ? 'inventory' : 'warehouse';
        $source = $u[$sourceKey];
        $target = $u[$targetKey];
        $item = $source[$slotIndex] ?? null;
        if (!is_array($item)) {
            echo json_encode(['code' => 'missing'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $targetIndex = -1;
        foreach ($target as $index => $targetItem) {
            if (
                is_array($targetItem)
                && ($targetItem['id'] ?? '') === ($item['id'] ?? '')
                && (string)($targetItem['customName'] ?? '') === (string)($item['customName'] ?? '')
            ) {
                $targetIndex = $index;
                break;
            }
        }
        if ($targetIndex < 0) $targetIndex = array_search(null, $target, true);
        if ($targetIndex === false || $targetIndex < 0) {
            echo json_encode(['code' => 'full'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (is_array($target[$targetIndex] ?? null)) {
            $target[$targetIndex]['count'] += 1;
        } else {
            $target[$targetIndex] = ['id' => $item['id'], 'count' => 1, 'createdAt' => date('Y-m-d H:i:s')];
            if (!empty($item['customName'])) $target[$targetIndex]['customName'] = $item['customName'];
        }
        $source[$slotIndex]['count'] -= 1;
        if ($source[$slotIndex]['count'] <= 0) $source[$slotIndex] = null;
        $u[$sourceKey] = $source;
        $u[$targetKey] = $target;
        touchInventoryVersion($u);
        saveUsers($users);
        echo json_encode([
            'code' => 'ok',
            'inventory' => $u['inventory'],
            'warehouse' => $u['warehouse'],
            'inventoryVersion' => (int)($u['inventoryVersion'] ?? 1)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'renameStorageItem' && !empty($userId)) {
    $storage = ($_REQUEST['storage'] ?? '') === 'warehouse' ? 'warehouse' : 'inventory';
    $slotIndex = (int)($_REQUEST['slotIndex'] ?? -1);
    $customName = trim(strip_tags((string)($_REQUEST['customName'] ?? '')));
    $customName = function_exists('mb_substr') ? mb_substr($customName, 0, 20, 'UTF-8') : substr($customName, 0, 60);
    $users = getUsers();
    foreach ($users as &$u) {
        if (($u['userId'] ?? '') !== $userId) continue;
        ensureEconomyFields($u);
        if (!is_array($u[$storage][$slotIndex] ?? null)) {
            echo json_encode(['code' => 'missing'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($customName === '') {
            unset($u[$storage][$slotIndex]['customName']);
        } else {
            $u[$storage][$slotIndex]['customName'] = $customName;
        }
        touchInventoryVersion($u);
        saveUsers($users);
        echo json_encode([
            'code' => 'ok',
            'inventory' => $u['inventory'],
            'warehouse' => $u['warehouse'],
            'inventoryVersion' => (int)($u['inventoryVersion'] ?? 1)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'moveWarehouseItem' && !empty($userId)) {
    $from = (int)($_REQUEST['from'] ?? -1);
    $to = (int)($_REQUEST['to'] ?? -1);
    $users = getUsers();
    foreach ($users as &$u) {
        if (($u['userId'] ?? '') !== $userId) continue;
        ensureEconomyFields($u);
        $capacity = count($u['warehouse']);
        if ($from < 0 || $to < 0 || $from >= $capacity || $to >= $capacity || !is_array($u['warehouse'][$from] ?? null)) {
            echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($from !== $to) {
            $moved = $u['warehouse'][$from];
            $u['warehouse'][$from] = $u['warehouse'][$to] ?? null;
            $u['warehouse'][$to] = $moved;
            touchInventoryVersion($u);
            saveUsers($users);
        }
        echo json_encode(['code' => 'ok', 'warehouse' => $u['warehouse'], 'inventoryVersion' => (int)($u['inventoryVersion'] ?? 1)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'discardWarehouseItem' && !empty($userId)) {
    $slotIndex = (int)($_REQUEST['slotIndex'] ?? -1);
    $users = getUsers();
    foreach ($users as &$u) {
        if (($u['userId'] ?? '') !== $userId) continue;
        ensureEconomyFields($u);
        $item = $u['warehouse'][$slotIndex] ?? null;
        if (!is_array($item)) {
            echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $u['warehouse'][$slotIndex]['count'] -= 1;
        if ($u['warehouse'][$slotIndex]['count'] <= 0) $u['warehouse'][$slotIndex] = null;
        touchInventoryVersion($u);
        saveUsers($users);
        echo json_encode(['code' => 'ok', 'warehouse' => $u['warehouse'], 'inventoryVersion' => (int)($u['inventoryVersion'] ?? 1)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'drawLottery' && !empty($userId)) {
    $drawLimit = journey_rate_limit('economy.draw', $userId, 1, 2, true);
    if (!$drawLimit['allowed']) {
        http_response_code(429);
        header('Retry-After: ' . (int)$drawLimit['retryAfter']);
        echo json_encode(['code' => 'rate_limited', 'retryAfter' => $drawLimit['retryAfter']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $users = getUsers();
    foreach ($users as &$u) {
        if (($u['userId'] ?? '') === $userId) {
            ensureEconomyFields($u);
            $today = date('Y-m-d');
            $economy = lotteryEconomyConfig();
            if (($u['lotteryDrawDate'] ?? '') !== $today) {
                $u['lotteryDrawDate'] = $today;
                $u['lotteryDrawCount'] = 0;
            }
            $drawCount = max(0, (int)($u['lotteryDrawCount'] ?? 0));
            $isFreeDraw = ($u['lastFreeDraw'] ?? '') !== $today;
            $drawCost = (int)$economy['drawCost'];
            if (!$isFreeDraw && !hasUnlimitedGold($u) && (int)($u['gold'] ?? 0) < $drawCost) {
                echo json_encode(['code' => 'nogold', 'gold' => (int)($u['gold'] ?? 0)], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $pityDraw = drawLotteryItemWithPity($u);
            $itemId = $pityDraw['itemId'];
            $drawDelivery = deliverOverflowItem($u, $itemId, 1, '抽奖物品：背包与仓库已满');
            $inventory = normalizeInventorySlots($u['inventory'] ?? [], false);
            $lotterySlotIndex = -1;
            foreach ($inventory as $slotIndex => $inventoryItem) {
                if (is_array($inventoryItem) && ($inventoryItem['id'] ?? '') === $itemId) {
                    $lotterySlotIndex = $slotIndex;
                    break;
                }
            }
            if ($isFreeDraw) {
                $u['lastFreeDraw'] = $today;
            } elseif (!hasUnlimitedGold($u)) {
                $u['gold'] -= $drawCost;
            }
            $u['lotteryDrawCount'] = $drawCount + 1;
            $u['xp'] = max(0, (int)($u['xp'] ?? 0)) + 5;
            saveUsers($users);
            $drawnItem = itemDefinition($itemId);
            $drawnItem['systemPrice'] = itemSystemPrice($itemId);
            if (($drawnItem['quality'] ?? '') === 'legendary') {
                $playerName = $u['user'] ?? $userId;
                appendLegendaryLotteryHistory($u, $drawnItem);
                appendWorldSystemMessage(
                    $playerName . ' 抽到了传说品质物品「' . ($drawnItem['name'] ?? $itemId) . '」',
                    'legendaryLottery'
                );
            } else {
                appendNormalLotteryHistory($u, $drawnItem);
            }
            echo json_encode([
                'code' => 'ok',
                'item' => $drawnItem,
                'inventory' => $inventory,
                'slotIndex' => $lotterySlotIndex,
                'delivery' => $drawDelivery,
                'gold' => (int)($u['gold'] ?? 0),
                'unlimitedGold' => hasUnlimitedGold($u),
                'freeDraw' => $isFreeDraw,
                'freeDrawAvailable' => false,
                'drawsToday' => (int)$u['lotteryDrawCount'],
                'dailyLimit' => (int)$economy['dailyLimit'],
                'drawCost' => $drawCost,
                'pity' => lotteryPityPayload($u),
                'pityTriggered' => $pityDraw['triggered'],
                'xpGain' => 5,
                'xp' => (int)$u['xp'],
                'level' => levelFromXp((int)$u['xp'])
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'discardLotteryItem' && !empty($userId)) {
    $slotIndex = (int)($_REQUEST['slotIndex'] ?? -1);
    $itemId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_REQUEST['itemId'] ?? ''));
    $users = getUsers();
    foreach ($users as &$u) {
        if (($u['userId'] ?? '') !== $userId) continue;
        ensureEconomyFields($u);
        $inventory = normalizeInventorySlots($u['inventory'] ?? [], false);
        $target = $inventory[$slotIndex] ?? null;
        if (!is_array($target) || ($target['id'] ?? '') !== $itemId) {
            echo json_encode(['code' => 'changed'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!removeInventoryItem($u, $slotIndex, 1)) {
            echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        saveUsers($users);
        echo json_encode(['code' => 'ok'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'getLegendaryLotteryHistory') {
    $history = journey_store_get('lottery_history', []);
    if (!is_array($history)) $history = [];
    $recentHistory = array_slice($history, -30);
    if (count($recentHistory) !== count($history)) {
        journey_store_set('lottery_history', $recentHistory);
    }
    echo json_encode(['code' => 'ok', 'items' => array_reverse($recentHistory)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'getNormalLotteryHistory') {
    $history = journey_store_get('normal_lottery_history', []);
    if (!is_array($history)) $history = [];
    $recentHistory = array_slice($history, -30);
    if (count($recentHistory) !== count($history)) {
        journey_store_set('normal_lottery_history', $recentHistory);
    }
    echo json_encode(['code' => 'ok', 'items' => array_reverse($recentHistory)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'sellLotteryItemToSystem' && !empty($userId)) {
    $slotIndex = (int)($_REQUEST['slotIndex'] ?? -1);
    $itemId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_REQUEST['itemId'] ?? ''));
    $users = getUsers();
    foreach ($users as &$u) {
        if (($u['userId'] ?? '') !== $userId) continue;
        ensureEconomyFields($u);
        $inventory = normalizeInventorySlots($u['inventory'] ?? [], false);
        $target = $inventory[$slotIndex] ?? null;
        if (!is_array($target) || ($target['id'] ?? '') !== $itemId || !empty($target['customName'])) {
            echo json_encode(['code' => 'changed'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (journey_dungeon_item_definition($itemId)) { echo json_encode(['code'=>'not_recyclable'],JSON_UNESCAPED_UNICODE); exit; }
        if (!removeInventoryItem($u, $slotIndex, 1)) {
            echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $price = itemSystemPrice($itemId);
        $u['gold'] = (int)($u['gold'] ?? 0) + $price;
        saveUsers($users);
        echo json_encode([
            'code' => 'ok',
            'price' => $price,
            'gold' => (int)$u['gold'],
            'unlimitedGold' => hasUnlimitedGold($u)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'grantInventoryItem' && !empty($userId)) {
    journey_audit('security.deprecated_grant_blocked', ['action' => $action], $userId, 'user', $userId);
    http_response_code(410);
    echo json_encode(['code' => 'disabled'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'saveInventory') {
    if (!$sessionUser) {
        http_response_code(401);
        echo json_encode(['code' => 'not_logged_in'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $gameUserId = (string)$sessionUser['userId'];
    $inventory = json_decode($_POST['inventory'] ?? '[]', true);
    $hasHotbar = array_key_exists('hotbar', $_POST);
    $hotbar = $hasHotbar ? json_decode($_POST['hotbar'] ?? '[]', true) : null;
    if (!is_array($inventory)) {
        http_response_code(400);
        echo json_encode(['code' => 'invalid_inventory'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $submittedInventory = normalizeInventorySlots($inventory, false);
    $clientVersion = max(0, (int)($_POST['inventoryVersion'] ?? 0));
    if ($hasHotbar && !is_array($hotbar)) {
        http_response_code(400);
        echo json_encode(['code' => 'invalid_hotbar'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $currentUser = journey_find_user($gameUserId);
    if (!is_array($currentUser)) {
        echo json_encode(['code' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ensureEconomyFields($currentUser);
    $currentVersion = max(1, (int)($currentUser['inventoryVersion'] ?? 1));
    if ($clientVersion > 0 && $clientVersion !== $currentVersion) {
        echo json_encode([
            'code' => 'conflict',
            'inventory' => $currentUser['inventory'],
            'hotbar' => $currentUser['gameHotbar'],
            'inventoryVersion' => $currentVersion
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $submittedHotbar = $hasHotbar ? normalizeGameHotbarSlots($hotbar, false) : ($currentUser['gameHotbar'] ?? defaultGameHotbar());
    $currentUser['inventory'] = $submittedInventory;
    $currentUser['gameHotbar'] = $submittedHotbar;
    touchInventoryVersion($currentUser);
    $pdo = journey_db();
    journey_upsert_legacy_user_internal($pdo, $currentUser);
    echo json_encode([
        'code' => 'ok',
        'inventory' => formatGameInventory($currentUser['inventory']),
        'hotbar' => formatGameHotbar($currentUser['gameHotbar']),
        'inventoryVersion' => (int)($currentUser['inventoryVersion'] ?? 1)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 游戏内拖拽专用：由服务器基于最新云端数据原子交换两个槽位。
// 0-20 是网页与游戏共用的主背包，21-27 是仅游戏可见的快捷栏。
if ($action === 'moveGameInventorySlot') {
    if (!$sessionUser) {
        http_response_code(401);
        echo json_encode(['code' => 'not_logged_in'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fromIndex = filter_var($_POST['from'] ?? null, FILTER_VALIDATE_INT);
    $toIndex = filter_var($_POST['to'] ?? null, FILTER_VALIDATE_INT);
    if ($fromIndex === false || $toIndex === false || $fromIndex < 0 || $fromIndex > 27 || $toIndex < 0 || $toIndex > 27) {
        http_response_code(400);
        echo json_encode(['code' => 'invalid_slot'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $gameUserId = (string)$sessionUser['userId'];
    $pdo = journey_db();
    $started = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }

        $currentUser = journey_find_user($gameUserId);
        if (!is_array($currentUser)) {
            if ($started && $pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['code' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        ensureEconomyFields($currentUser);
        $allSlots = array_merge(
            normalizeInventorySlots($currentUser['inventory'] ?? [], false),
            normalizeGameHotbarSlots($currentUser['gameHotbar'] ?? [], false)
        );
        $movedItem = $allSlots[$fromIndex] ?? null;
        if (!is_array($movedItem)) {
            if ($started && $pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['code' => 'empty_source'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $allSlots[$fromIndex] = $allSlots[$toIndex] ?? null;
        $allSlots[$toIndex] = $movedItem;
        $currentUser['inventory'] = array_slice($allSlots, 0, 21);
        $currentUser['gameHotbar'] = array_slice($allSlots, 21, 7);
        journey_upsert_legacy_user_internal($pdo, $currentUser);
        if ($started) $pdo->commit();

        echo json_encode([
            'code' => 'ok',
            'inventory' => formatGameInventory($currentUser['inventory']),
            'hotbar' => formatGameHotbar($currentUser['gameHotbar'])
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        error_log('moveGameInventorySlot failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['code' => 'save_failed'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 游戏客户端专用：捡起掉落物时添加物品到背包
if ($action === 'gameAddItem') {
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if ($origin) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
    if (!$sessionUser) {
        echo json_encode(['code' => 'not_logged_in'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $gameUserId = (string)$sessionUser['userId'];
    $itemId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_POST['itemId'] ?? $_REQUEST['itemId'] ?? ''));
    $addCount = max(1, min(99, (int)($_POST['count'] ?? $_REQUEST['count'] ?? 1)));
    $customName = trim((string)($_POST['customName'] ?? $_REQUEST['customName'] ?? ''));
    if ($itemId === '') {
        echo json_encode(['code' => 'invalid_item'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $users = getUsers();
    foreach ($users as &$u) {
        if ((string)($u['userId'] ?? '') !== $gameUserId) continue;
        ensureEconomyFields($u);
        $added = addInventoryItem($u, $itemId, $addCount, $customName);
        if (!$added) {
            echo json_encode(['code' => 'inventory_full'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        saveUsers($users);
        // 返回游戏格式的背包（21格）
        $gameSlots = formatGameInventory($u['inventory']);
        journey_audit('game.item_added', ['itemId' => $itemId, 'count' => $addCount], $gameUserId, 'user', $gameUserId);
        echo json_encode(['code' => 'ok', 'inventory' => $gameSlots], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['code' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'discardInventoryItem') {
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if ($origin) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
    if (!$sessionUser) {
        http_response_code(401);
        echo json_encode(['code' => 'not_logged_in'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $gameUserId = (string)$sessionUser['userId'];
    $slotIndex = (int)($_REQUEST['slotIndex'] ?? -1);
    $count = (int)($_REQUEST['count'] ?? -1);
    $currentUser = journey_find_user($gameUserId);
    if (!is_array($currentUser)) {
        echo json_encode(['code' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ensureEconomyFields($currentUser);
    $inventory = normalizeInventorySlots($currentUser['inventory'] ?? [], false);
    $item = $inventory[$slotIndex] ?? null;
    if (!$item) {
        echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $removeCount = ($count <= 0) ? (int)($item['count'] ?? 1) : $count;
    $removed = removeInventoryItem($currentUser, $slotIndex, $removeCount);
    if (!$removed) {
        echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    touchInventoryVersion($currentUser);
    $pdo = journey_db();
    journey_upsert_legacy_user_internal($pdo, $currentUser);
    echo json_encode([
        'code' => 'ok',
        'inventory' => formatGameInventory($currentUser['inventory']),
        'inventoryVersion' => (int)($currentUser['inventoryVersion'] ?? 1)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'moveInventoryItem' && !empty($userId)) {
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if ($origin) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
    $fromSlot = (int)($_REQUEST['fromSlot'] ?? -1);
    $toSlot = (int)($_REQUEST['toSlot'] ?? -1);
    // 只允许主背包0-20槽位移动
    if ($fromSlot < 0 || $fromSlot >= 21 || $toSlot < 0 || $toSlot >= 21) {
        echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $clientVersion = max(0, (int)($_POST['inventoryVersion'] ?? $_REQUEST['inventoryVersion'] ?? 0));
    $users = getUsers();
    foreach ($users as &$u) {
        if (($u['userId'] ?? '') === $userId) {
            ensureEconomyFields($u);
            $currentVersion = max(1, (int)($u['inventoryVersion'] ?? 1));
            if ($clientVersion > 0 && $clientVersion !== $currentVersion) {
                echo json_encode([
                    'code' => 'conflict',
                    'inventory' => normalizeInventorySlots($u['inventory'] ?? [], false),
                    'inventoryVersion' => $currentVersion
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $inventory = normalizeInventorySlots($u['inventory'] ?? [], false);
            // 交换两个槽位
            $temp = $inventory[$fromSlot] ?? null;
            $inventory[$fromSlot] = $inventory[$toSlot] ?? null;
            $inventory[$toSlot] = $temp;
            $u['inventory'] = $inventory;
            touchInventoryVersion($u);
            saveUsers($users);
            echo json_encode([
                'code' => 'ok',
                'inventory' => normalizeInventorySlots($u['inventory'], false),
                'inventoryVersion' => (int)($u['inventoryVersion'] ?? 1)
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'sellInventoryToSystem' && !empty($userId)) {
    $slotIndex = (int)($_REQUEST['slotIndex'] ?? -1);
    $users = getUsers();
    foreach ($users as &$u) {
        if (($u['userId'] ?? '') === $userId) {
            ensureEconomyFields($u);
            $inventory = normalizeInventorySlots($u['inventory'] ?? [], false);
            $target = $inventory[$slotIndex] ?? null;
            if (!$target) {
                echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (journey_dungeon_item_definition((string)($target['id'] ?? ''))) { echo json_encode(['code'=>'not_recyclable'],JSON_UNESCAPED_UNICODE); exit; }
            $price = itemSystemPrice($target['id'] ?? '');
            $removed = removeInventoryItem($u, $slotIndex, 1);
            if (!$removed) {
                echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $u['gold'] = (int)($u['gold'] ?? 0) + $price;
            touchInventoryVersion($u);
            saveUsers($users);
            echo json_encode([
                'code' => 'ok',
                'price' => $price,
                'gold' => (int)$u['gold'],
                'unlimitedGold' => hasUnlimitedGold($u),
                'inventory' => normalizeInventorySlots($u['inventory'] ?? [], false),
                'inventoryVersion' => (int)($u['inventoryVersion'] ?? 1)
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'batchSellInventoryToSystem' && !empty($userId)) {
    $slotIndexes = json_decode($_POST['slotIndexes'] ?? '[]', true);
    if (!is_array($slotIndexes)) $slotIndexes = [];
    $slotIndexes = array_values(array_unique(array_filter(array_map('intval', $slotIndexes), function($index) {
        return $index >= 0 && $index < 21;
    })));
    if (!$slotIndexes) {
        echo json_encode(['code' => 'empty'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $users = getUsers();
    foreach ($users as &$u) {
        if (($u['userId'] ?? '') !== $userId) continue;
        ensureEconomyFields($u);
        $inventory = normalizeInventorySlots($u['inventory'] ?? [], false);
        $totalGold = 0;
        $totalItems = 0;
        foreach ($slotIndexes as $slotIndex) {
            $item = $inventory[$slotIndex] ?? null;
            if (!is_array($item)) continue;
            if (journey_dungeon_item_definition((string)($item['id'] ?? ''))) { echo json_encode(['code'=>'contains_non_recyclable'],JSON_UNESCAPED_UNICODE); exit; }
            $count = max(1, (int)($item['count'] ?? 1));
            $totalGold += itemSystemPrice($item['id'] ?? '') * $count;
            $totalItems += $count;
            $inventory[$slotIndex] = null;
        }
        if ($totalItems < 1) {
            echo json_encode(['code' => 'empty'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $u['inventory'] = normalizeInventorySlots($inventory, false);
        $u['gold'] = (int)($u['gold'] ?? 0) + $totalGold;
        touchInventoryVersion($u);
        saveUsers($users);
        echo json_encode([
            'code' => 'ok',
            'inventory' => $u['inventory'],
            'gold' => (int)$u['gold'],
            'unlimitedGold' => hasUnlimitedGold($u),
            'inventoryVersion' => (int)($u['inventoryVersion'] ?? 1),
            'totalGold' => $totalGold,
            'totalItems' => $totalItems
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'getRpsLobby' && !empty($userId)) {
    try {
        $payload = rpsTransaction(function(&$state) use ($userId) {
            $roomId = rpsFindRoomIdForUser($state, $userId);
            if ($roomId !== '') {
                $side = rpsParticipantSide($state['rooms'][$roomId], $userId);
                if ($side !== '') $state['rooms'][$roomId][$side]['lastSeen'] = time();
            }
            return rpsLobbyPayload($state, $userId);
        });
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        error_log('getRpsLobby failed: ' . $exception->getMessage());
        echo json_encode(['code' => 'save_failed'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'createRpsRoom' && !empty($userId)) {
    $limit = journey_rate_limit('rps.create', $userId, 2, 2, true);
    if (!$limit['allowed']) { echo json_encode(['code' => 'rate_limited', 'retryAfter' => $limit['retryAfter']], JSON_UNESCAPED_UNICODE); exit; }
    try {
        $payload = rpsTransaction(function(&$state, $pdo) use ($userId) {
            if (rpsFindRoomIdForUser($state, $userId) !== '') throw new RuntimeException('already_in_room');
            if (count($state['rooms']) >= 10) throw new RuntimeException('room_limit');
            game_lock_user_for_update($pdo, $userId);
            $user = journey_find_user($userId);
            if (!is_array($user)) throw new RuntimeException('user_not_found');
            ensureEconomyFields($user);
            if (hasUnlimitedGold($user)) throw new RuntimeException('unlimited_gold');
            if (count(rpsItemOptions($user)) < 1) throw new RuntimeException('item_required');
            if ((int)$user['gold'] < 10) throw new RuntimeException('not_enough_gold');
            $user['gold'] -= 10;
            journey_upsert_legacy_user_internal($pdo, $user);
            do { $roomId = 'rps_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)); } while (isset($state['rooms'][$roomId]));
            $state['rooms'][$roomId] = [
                'roomId' => $roomId, 'status' => 'waiting', 'createdAt' => time(),
                'host' => ['userId' => $userId, 'name' => rpsUserName($user), 'ticket' => 10, 'stake' => null, 'lastSeen' => time()],
                'guest' => null, 'wins' => [], 'choices' => [], 'round' => 0, 'lastRound' => null
            ];
            return rpsLobbyPayload($state, $userId);
        });
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        $code = $exception->getMessage();
        if (!in_array($code, ['already_in_room','room_limit','user_not_found','unlimited_gold','item_required','not_enough_gold'], true)) { error_log('createRpsRoom failed: ' . $code); $code = 'save_failed'; }
        echo json_encode(['code' => $code], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'joinRpsRoom' && !empty($userId)) {
    $roomId = trim((string)($_POST['roomId'] ?? ''));
    try {
        $payload = rpsTransaction(function(&$state, $pdo) use ($userId, $roomId) {
            if (rpsFindRoomIdForUser($state, $userId) !== '') throw new RuntimeException('already_in_room');
            if (!is_array($state['rooms'][$roomId] ?? null)) throw new RuntimeException('room_not_found');
            if (($state['rooms'][$roomId]['status'] ?? '') !== 'waiting' || !empty($state['rooms'][$roomId]['guest'])) throw new RuntimeException('room_full');
            if ((string)$state['rooms'][$roomId]['host']['userId'] === $userId) throw new RuntimeException('own_room');
            game_lock_user_for_update($pdo, $userId);
            $user = journey_find_user($userId);
            if (!is_array($user)) throw new RuntimeException('user_not_found');
            ensureEconomyFields($user);
            if (hasUnlimitedGold($user)) throw new RuntimeException('unlimited_gold');
            if (count(rpsItemOptions($user)) < 1) throw new RuntimeException('item_required');
            if ((int)$user['gold'] < 10) throw new RuntimeException('not_enough_gold');
            $user['gold'] -= 10;
            journey_upsert_legacy_user_internal($pdo, $user);
            $state['rooms'][$roomId]['guest'] = ['userId' => $userId, 'name' => rpsUserName($user), 'ticket' => 10, 'stake' => null, 'lastSeen' => time()];
            $state['rooms'][$roomId]['status'] = 'staking';
            $state['rooms'][$roomId]['stakeDeadline'] = time() + 60;
            $state['rooms'][$roomId]['matchStartedAt'] = time();
            return rpsLobbyPayload($state, $userId);
        });
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        $code = $exception->getMessage();
        if (!in_array($code, ['already_in_room','room_not_found','room_full','own_room','user_not_found','unlimited_gold','item_required','not_enough_gold'], true)) { error_log('joinRpsRoom failed: ' . $code); $code = 'save_failed'; }
        echo json_encode(['code' => $code], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'cancelRpsRoom' && !empty($userId)) {
    try {
        $payload = rpsTransaction(function(&$state) use ($userId) {
            $roomId = rpsFindRoomIdForUser($state, $userId);
            if ($roomId === '') throw new RuntimeException('room_not_found');
            $room = $state['rooms'][$roomId];
            if (($room['status'] ?? '') !== 'waiting' || (string)($room['host']['userId'] ?? '') !== $userId) throw new RuntimeException('cannot_cancel');
            rpsSettleRoom($state, $roomId, 'host_cancelled');
            return rpsLobbyPayload($state, $userId);
        });
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        $code = in_array($exception->getMessage(), ['room_not_found','cannot_cancel'], true) ? $exception->getMessage() : 'save_failed';
        echo json_encode(['code' => $code], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'lockRpsStake' && !empty($userId)) {
    $storage = ($_POST['storage'] ?? '') === 'warehouse' ? 'warehouse' : 'inventory';
    $slotIndex = (int)($_POST['slotIndex'] ?? -1);
    $extraGold = max(0, min(1000000000, (int)($_POST['extraGold'] ?? 0)));
    try {
        $payload = rpsTransaction(function(&$state, $pdo) use ($userId, $storage, $slotIndex, $extraGold) {
            $roomId = rpsFindRoomIdForUser($state, $userId);
            if ($roomId === '') throw new RuntimeException('room_not_found');
            $room = &$state['rooms'][$roomId];
            if (($room['status'] ?? '') !== 'staking') throw new RuntimeException('not_staking');
            $side = rpsParticipantSide($room, $userId);
            if ($side === '') throw new RuntimeException('not_member');
            if (is_array($room[$side]['stake'] ?? null)) throw new RuntimeException('already_selected');
            game_lock_user_for_update($pdo, $userId);
            $user = journey_find_user($userId);
            if (!is_array($user)) throw new RuntimeException('user_not_found');
            ensureEconomyFields($user);
            if ((int)$user['gold'] < $extraGold) throw new RuntimeException('not_enough_gold');
            $taken = rpsTakeItem($user, $storage, $slotIndex);
            if (!is_array($taken)) throw new RuntimeException('item_not_found');
            $user['gold'] -= $extraGold;
            journey_upsert_legacy_user_internal($pdo, $user);
            $room[$side]['stake'] = array_merge($taken, ['extraGold' => $extraGold, 'storage' => $storage]);
            $room[$side]['lastSeen'] = time();
            $otherSide = $side === 'host' ? 'guest' : 'host';
            if (is_array($room[$otherSide]['stake'] ?? null)) {
                $hostId = (string)$room['host']['userId'];
                $guestId = (string)$room['guest']['userId'];
                $room['status'] = 'playing';
                $room['round'] = 1;
                $room['wins'] = [$hostId => 0, $guestId => 0];
                $room['choices'] = [];
                $room['roundDeadline'] = time() + 10;
            }
            unset($room);
            return rpsLobbyPayload($state, $userId);
        });
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        $code = $exception->getMessage();
        if (!in_array($code, ['room_not_found','not_staking','not_member','already_selected','user_not_found','not_enough_gold','item_not_found'], true)) { error_log('lockRpsStake failed: ' . $code); $code = 'save_failed'; }
        echo json_encode(['code' => $code], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'chooseRpsMove' && !empty($userId)) {
    $move = trim((string)($_POST['move'] ?? ''));
    if (!in_array($move, ['rock', 'scissors', 'paper'], true)) { echo json_encode(['code' => 'invalid_move'], JSON_UNESCAPED_UNICODE); exit; }
    try {
        $payload = rpsTransaction(function(&$state) use ($userId, $move) {
            $roomId = rpsFindRoomIdForUser($state, $userId);
            if ($roomId === '') throw new RuntimeException('room_not_found');
            if (($state['rooms'][$roomId]['status'] ?? '') !== 'playing') throw new RuntimeException('not_playing');
            if (rpsParticipantSide($state['rooms'][$roomId], $userId) === '') throw new RuntimeException('not_member');
            if (!empty($state['rooms'][$roomId]['choices'][$userId])) throw new RuntimeException('already_chosen');
            $state['rooms'][$roomId]['choices'][$userId] = $move;
            $side = rpsParticipantSide($state['rooms'][$roomId], $userId);
            if ($side !== '') $state['rooms'][$roomId][$side]['lastSeen'] = time();
            return rpsLobbyPayload($state, $userId);
        });
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        $code = in_array($exception->getMessage(), ['room_not_found','not_playing','not_member','already_chosen'], true) ? $exception->getMessage() : 'save_failed';
        echo json_encode(['code' => $code], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'surrenderRpsRoom' && !empty($userId)) {
    try {
        $payload = rpsTransaction(function(&$state) use ($userId) {
            $roomId = rpsFindRoomIdForUser($state, $userId);
            if ($roomId === '') throw new RuntimeException('room_not_found');
            $room = $state['rooms'][$roomId];
            if (($room['status'] ?? '') !== 'playing') throw new RuntimeException('not_playing');
            $side = rpsParticipantSide($room, $userId);
            if ($side === '') throw new RuntimeException('not_member');
            $winnerId = (string)$room[$side === 'host' ? 'guest' : 'host']['userId'];
            rpsSettleRoom($state, $roomId, 'surrender', $winnerId);
            return rpsLobbyPayload($state, $userId);
        });
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        $code = in_array($exception->getMessage(), ['room_not_found','not_playing','not_member'], true) ? $exception->getMessage() : 'save_failed';
        echo json_encode(['code' => $code], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'dismissRpsResult' && !empty($userId)) {
    try {
        $payload = rpsTransaction(function(&$state) use ($userId) {
            unset($state['results'][$userId]);
            return rpsLobbyPayload($state, $userId);
        });
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode(['code' => 'save_failed'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'getWishingWellStatus' && !empty($userId)) {
    $users = getUsers();
    foreach ($users as $candidate) {
        if (($candidate['userId'] ?? '') !== $userId) continue;
        ensureEconomyFields($candidate);
        $uses = (($candidate['wishingWellDate'] ?? '') === date('Y-m-d')) ? max(0, (int)($candidate['wishingWellUses'] ?? 0)) : 0;
        echo json_encode([
            'code' => 'ok',
            'date' => date('Y-m-d'),
            'uses' => $uses,
            'left' => max(0, 3 - $uses),
            'gold' => (int)$candidate['gold'],
            'unlimitedGold' => hasUnlimitedGold($candidate),
            'inventory' => wishingWellInventoryPayload($candidate),
            'history' => wishingWellHistoryPayload()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['code' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'useWishingWell' && !empty($userId)) {
    $rateLimit = journey_rate_limit('economy.wishing_well', $userId, 2, 3, true);
    if (!$rateLimit['allowed']) {
        http_response_code(429);
        echo json_encode(['code' => 'rate_limited', 'retryAfter' => $rateLimit['retryAfter']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $kind = ($_POST['kind'] ?? '') === 'item' ? 'item' : 'gold';
    $amount = max(1, min(1000000000, (int)($_POST['amount'] ?? 1)));
    $slotIndex = (int)($_POST['slotIndex'] ?? -1);
    $pdo = journey_db();
    $started = false;
    try {
        if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $started = true; }
        game_lock_user_for_update($pdo, $userId);
        $currentUser = journey_find_user($userId);
        if (!is_array($currentUser)) throw new RuntimeException('user_not_found');
        ensureEconomyFields($currentUser);
        $today = date('Y-m-d');
        if (($currentUser['wishingWellDate'] ?? '') !== $today) {
            $currentUser['wishingWellDate'] = $today;
            $currentUser['wishingWellUses'] = 0;
        }
        $uses = max(0, (int)($currentUser['wishingWellUses'] ?? 0));
        if ($uses >= 3) throw new RuntimeException('daily_limit');

        $offering = null;
        if ($kind === 'gold') {
            if (hasUnlimitedGold($currentUser)) throw new RuntimeException('unlimited_gold');
            if ((int)$currentUser['gold'] < $amount) throw new RuntimeException('not_enough_gold');
            $currentUser['gold'] -= $amount;
            $offering = ['kind' => 'gold', 'amount' => $amount];
        } else {
            if ($slotIndex < 0 || $slotIndex >= 21) throw new RuntimeException('invalid_slot');
            $removed = removeInventoryItem($currentUser, $slotIndex, 1);
            if (!is_array($removed) || empty($removed['id'])) throw new RuntimeException('item_not_found');
            $offering = [
                'kind' => 'item',
                'itemId' => (string)$removed['id'],
                'customName' => (string)($removed['customName'] ?? ''),
                'item' => itemDefinition((string)$removed['id'])
            ];
            if ($offering['customName'] !== '') $offering['item']['name'] = $offering['customName'];
        }

        $doubled = random_int(0, 1) === 1;
        $delivery = '';
        if ($doubled && $kind === 'gold') {
            $currentUser['gold'] = min(2147483647, (int)$currentUser['gold'] + $amount * 2);
        } elseif ($doubled) {
            $delivery = deliverOverflowItem($currentUser, $offering['itemId'], 2, '许愿井返还：背包与仓库空间不足', $offering['customName']);
        }
        $currentUser['wishingWellUses'] = $uses + 1;
        journey_upsert_legacy_user_internal($pdo, $currentUser);
        if ($started) $pdo->commit();
        try { $history = recordWishingWellHistory($currentUser, $offering, $doubled); }
        catch (Throwable $historyError) { error_log('wishing history failed: ' . $historyError->getMessage()); $history = wishingWellHistoryPayload(); }
        journey_audit('wishing_well.used', ['kind' => $kind, 'doubled' => $doubled], $userId, 'user', $userId);
        echo json_encode([
            'code' => 'ok',
            'result' => $doubled ? 'doubled' : 'lost',
            'offering' => $offering,
            'rewardAmount' => $doubled ? ($kind === 'gold' ? $amount * 2 : 2) : 0,
            'delivery' => $delivery,
            'uses' => $uses + 1,
            'left' => max(0, 2 - $uses),
            'gold' => (int)$currentUser['gold'],
            'inventory' => wishingWellInventoryPayload($currentUser),
            'history' => $history
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $exception) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        $code = $exception->getMessage();
        $known = ['user_not_found','daily_limit','unlimited_gold','not_enough_gold','invalid_slot','item_not_found'];
        if (!in_array($code, $known, true)) { error_log('useWishingWell failed: ' . $code); $code = 'save_failed'; }
        echo json_encode(['code' => $code], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($action === 'getContactMarket' && !empty($userId)) {
    $contactId = trim((string)($_GET['contactId'] ?? 'keyi'));
    $users = getUsers();
    foreach ($users as $candidate) {
        if (($candidate['userId'] ?? '') !== $userId) continue;
        ensureEconomyFields($candidate);
        $mode = trim((string)($_GET['mode'] ?? 'buy'));
        $payload = $contactId === 'hotdog' && $mode === 'sell' ? alphaSellMarketPayload($candidate) : ($contactId === 'hotdog' ? hotdogMarketPayload($candidate) : ($contactId === 'jack' ? jackMarketPayload($candidate) : keyiMarketPayload($candidate)));
        if ($contactId === 'jack') { $coinStmt=journey_db()->prepare('SELECT wing_coins FROM dungeon_player_state WHERE user_id=?'); $coinStmt->execute([$userId]); $payload['wingCoins']=(int)$coinStmt->fetchColumn(); }
        if ($contactId === 'hotdog' && $mode === 'sell') {
            $coinStmt = journey_db()->prepare('SELECT wing_coins FROM dungeon_player_state WHERE user_id = ?');
            $coinStmt->execute([$userId]);
            $payload['wingCoins'] = (int)$coinStmt->fetchColumn();
        }
        echo json_encode(array_merge(['code' => 'ok', 'gold' => (int)$candidate['gold'], 'unlimitedGold' => hasUnlimitedGold($candidate)], $payload), JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['code' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'refreshKeyiMarket' && !empty($userId)) {
    $pdo = journey_db(); $started = false;
    try {
        if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $started = true; }
        game_lock_user_for_update($pdo, $userId);
        $currentUser = journey_find_user($userId);
        if (!is_array($currentUser)) throw new RuntimeException('user_not_found');
        ensureEconomyFields($currentUser);
        $today = date('Y-m-d');
        $used = ($currentUser['keyiRefreshDate'] ?? '') === $today ? (int)($currentUser['keyiRefreshCount'] ?? 0) : 0;
        if ($used >= 3) throw new RuntimeException('refresh_limit');
        $currentUser['keyiRefreshDate'] = $today; $currentUser['keyiRefreshCount'] = $used + 1;
        journey_store_set('contact_keyi_market', []);
        journey_upsert_legacy_user_internal($pdo, $currentUser);
        if ($started) $pdo->commit();
        $market = keyiDailyMarket();
        echo json_encode(['code'=>'ok','left'=>2-$used,'date'=>$market['date']], JSON_UNESCAPED_UNICODE); exit;
    } catch (Throwable $exception) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        $code = $exception->getMessage(); if (!in_array($code, ['refresh_limit','user_not_found'], true)) $code = 'refresh_failed';
        echo json_encode(['code'=>$code], JSON_UNESCAPED_UNICODE); exit;
    }
}

if ($action === 'sellContactItem' && !empty($userId)) {
    $slot = filter_var($_POST['slot'] ?? null, FILTER_VALIDATE_INT);
    if ($slot === false || $slot < 0 || $slot > 20) { echo json_encode(['code'=>'invalid_slot'], JSON_UNESCAPED_UNICODE); exit; }
    $pdo = journey_db(); $started = false;
    try {
        if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $started = true; }
        game_lock_user_for_update($pdo, $userId);
        $currentUser = journey_find_user($userId);
        if (!is_array($currentUser)) throw new RuntimeException('user_not_found');
        $inventory = normalizeInventorySlots($currentUser['inventory'] ?? [], false);
        $entry = $inventory[$slot] ?? null;
        if (!is_array($entry) || empty($entry['id'])) throw new RuntimeException('item_not_found');
        $definition = alphaSellDefinition((string)$entry['id']);
        if (!$definition) throw new RuntimeException('not_sellable');
        $quality = (string)($definition['quality'] ?? 'common');
        $price = alphaSellPrice($quality, (string)$entry['id']);
        $itemId = (string)$entry['id'];
        $inventory[$slot] = null;
        $currentUser['inventory'] = normalizeInventorySlots($inventory, false);
        journey_dungeon_ensure_player($pdo, $userId);
        $pdo->prepare('UPDATE dungeon_player_state SET wing_coins = wing_coins + ?, updated_at = ? WHERE user_id = ?')->execute([$price, date('Y-m-d H:i:s'), $userId]);
        journey_upsert_legacy_user_internal($pdo, $currentUser);
        $coinStmt = $pdo->prepare('SELECT wing_coins FROM dungeon_player_state WHERE user_id = ?'); $coinStmt->execute([$userId]); $wingCoins = (int)$coinStmt->fetchColumn();
        if ($started) $pdo->commit();
        journey_audit('contact.item_sold', ['contactId'=>'hotdog','slot'=>$slot,'itemId'=>$itemId,'price'=>$price], $userId, 'item', $itemId);
        echo json_encode(['code'=>'ok','price'=>$price,'wingCoins'=>$wingCoins,'inventory'=>$currentUser['inventory']], JSON_UNESCAPED_UNICODE); exit;
    } catch (Throwable $exception) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        $code = $exception->getMessage(); if (!in_array($code, ['invalid_slot','item_not_found','not_sellable','user_not_found'], true)) $code = 'sell_failed';
        echo json_encode(['code'=>$code], JSON_UNESCAPED_UNICODE); exit;
    }
}

if ($action === 'buyContactOffer' && !empty($userId)) {
    $rateLimit = journey_rate_limit('economy.contact_buy', $userId, 2, 2, true);
    if (!$rateLimit['allowed']) {
        http_response_code(429);
        echo json_encode(['code' => 'rate_limited', 'retryAfter' => $rateLimit['retryAfter']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $contactId = trim((string)($_POST['contactId'] ?? 'keyi'));
    $offerId = trim((string)($_POST['offerId'] ?? ''));
    $market = $contactId === 'hotdog' ? hotdogDailyMarket() : ($contactId === 'jack' ? jackMarketPayload([]) : keyiDailyMarket());
    $offer = null;
    foreach ($market['offers'] as $candidateOffer) {
        if (($candidateOffer['offerId'] ?? '') === $offerId) { $offer = $candidateOffer; break; }
    }
    if (!$offer) { echo json_encode(['code' => 'offer_not_found'], JSON_UNESCAPED_UNICODE); exit; }

    if ($contactId === 'jack') {
        $pdo=journey_db(); $started=false;
        try {
            if(!$pdo->inTransaction()){$pdo->beginTransaction();$started=true;}
            game_lock_user_for_update($pdo,$userId); $currentUser=journey_find_user($userId); if(!is_array($currentUser)) throw new RuntimeException('user_not_found');
            journey_dungeon_ensure_player($pdo,$userId); $coin=$pdo->prepare('SELECT wing_coins FROM dungeon_player_state WHERE user_id=?'.(journey_db_driver($pdo)==='mysql'?' FOR UPDATE':''));$coin->execute([$userId]);$wing=(int)$coin->fetchColumn();$price=(int)$offer['price'];if($wing<$price)throw new RuntimeException('not_enough_wing_coins');
            $delivery=deliverOverflowItem($currentUser,$offer['itemId'],(int)($offer['count']??1),'杰克军火商：空间不足');$pdo->prepare('UPDATE dungeon_player_state SET wing_coins=wing_coins-?,updated_at=? WHERE user_id=?')->execute([$price,date('Y-m-d H:i:s'),$userId]);journey_upsert_legacy_user_internal($pdo,$currentUser);if($started)$pdo->commit();
            $item=itemDefinition($offer['itemId']);echo json_encode(['code'=>'ok','item'=>$item,'price'=>$price,'delivery'=>$delivery,'wingCoins'=>$wing-$price],JSON_UNESCAPED_UNICODE);exit;
        } catch(Throwable $e){if($started&&$pdo->inTransaction())$pdo->rollBack();$code=in_array($e->getMessage(),['not_enough_wing_coins','user_not_found'],true)?$e->getMessage():'offer_buy_failed';echo json_encode(['code'=>$code],JSON_UNESCAPED_UNICODE);exit;}
    }

    $pdo = journey_db();
    $started = false;
    try {
        if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $started = true; }
        game_lock_user_for_update($pdo, $userId);
        $currentUser = journey_find_user($userId);
        if (!is_array($currentUser)) throw new RuntimeException('user_not_found');
        ensureEconomyFields($currentUser);
        if ($contactId === 'hotdog') {
            if (($currentUser['hotdogPurchaseDate'] ?? '') !== $market['date']) {
                $currentUser['hotdogPurchaseDate'] = $market['date'];
                $currentUser['hotdogPurchasedOffers'] = [];
            }
            $purchased = is_array($currentUser['hotdogPurchasedOffers'] ?? null) ? array_map('strval', $currentUser['hotdogPurchasedOffers']) : [];
            if (in_array($offerId, $purchased, true)) throw new RuntimeException('already_purchased');
            $materialId = (string)($offer['materialItemId'] ?? '');
            $materialCount = max(1, (int)($offer['materialCount'] ?? 1));
            if (userItemCount($currentUser, $materialId) < $materialCount) throw new RuntimeException('material_missing');
            if (!removeUserItemById($currentUser, $materialId, $materialCount)) throw new RuntimeException('material_missing');
            $delivery = deliverOverflowItem($currentUser, $offer['itemId'], 1, '热狗兑换：空间不足');
            $purchased[] = $offerId;
            $currentUser['hotdogPurchasedOffers'] = array_values(array_unique($purchased));
            journey_upsert_legacy_user_internal($pdo, $currentUser);
            if ($started) $pdo->commit();
            $item = itemDefinition($offer['itemId']);
            journey_audit('contact.offer_bartered', ['contactId' => 'hotdog', 'materialItemId' => $materialId], $userId, 'item', $offer['itemId']);
            echo json_encode([
                'code' => 'ok', 'offerId' => $offerId, 'item' => $item, 'material' => itemDefinition($materialId),
                'delivery' => $delivery, 'gold' => (int)$currentUser['gold']
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (($currentUser['keyiPurchaseDate'] ?? '') !== $market['date']) {
            $currentUser['keyiPurchaseDate'] = $market['date'];
            $currentUser['keyiPurchasedOffers'] = [];
        }
        $purchased = is_array($currentUser['keyiPurchasedOffers'] ?? null) ? array_map('strval', $currentUser['keyiPurchasedOffers']) : [];
        if (in_array($offerId, $purchased, true)) throw new RuntimeException('already_purchased');
        $price = max(1, (int)$offer['price']);
        if (!hasUnlimitedGold($currentUser) && (int)$currentUser['gold'] < $price) throw new RuntimeException('not_enough_gold');
        if (!hasUnlimitedGold($currentUser)) $currentUser['gold'] -= $price;
        $delivery = deliverOverflowItem($currentUser, $offer['itemId'], 1, '可翼黑市购买：空间不足');
        $purchased[] = $offerId;
        $currentUser['keyiPurchasedOffers'] = array_values(array_unique($purchased));
        journey_upsert_legacy_user_internal($pdo, $currentUser);
        if ($started) $pdo->commit();
        $item = itemDefinition($offer['itemId']);
        journey_audit('contact.offer_bought', ['contactId' => 'keyi', 'price' => $price], $userId, 'item', $offer['itemId']);
        echo json_encode([
            'code' => 'ok', 'offerId' => $offerId, 'item' => $item, 'price' => $price,
            'delivery' => $delivery, 'gold' => (int)$currentUser['gold']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $exception) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        $code = $exception->getMessage();
        $known = ['user_not_found','already_purchased','not_enough_gold','material_missing'];
        if (!in_array($code, $known, true)) { error_log('buyContactOffer failed: ' . $code); $code = 'save_failed'; }
        echo json_encode(['code' => $code], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($action === 'dailyCheckin' && !empty($userId)) {
    $today = date('Y-m-d');
    $users = getUsers();
    foreach ($users as &$u) {
        if ($u['userId'] === $userId) {
            ensureEconomyFields($u);
            if (($u['lastCheckin'] ?? '') === $today) {
                echo json_encode(['code' => 'already', 'gold' => (int)$u['gold'], 'unlimitedGold' => hasUnlimitedGold($u), 'lastCheckin' => $u['lastCheckin']], JSON_UNESCAPED_UNICODE);
                exit;
            }
            // 日常金币负责提供抽奖启动资金，抽奖回收本身仍保持长期负收益。
            $gain = random_int(30, 50);
            $u['gold'] += $gain;
            $u['lastCheckin'] = $today;
            saveUsers($users);
            echo json_encode(['code' => 'ok', 'gain' => $gain, 'gold' => (int)$u['gold'], 'unlimitedGold' => hasUnlimitedGold($u), 'lastCheckin' => $today], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'createGoldTransfer' && !empty($userId)) {
    $targetUserId = trim((string)($_REQUEST['targetUserId'] ?? ''));
    $amount = (int)($_REQUEST['amount'] ?? 0);
    if ($targetUserId === '' || $targetUserId === $userId || $amount < 1 || $amount > 1000000) {
        http_response_code(422);
        echo json_encode(['code' => $targetUserId === $userId ? 'self' : 'invalid'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $limit = journey_rate_limit('economy.gold_transfer', $userId, 10, 3600, true);
    if (!$limit['allowed']) {
        http_response_code(429);
        echo json_encode(['code' => 'rate_limited', 'retryAfter' => $limit['retryAfter']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $resultCode = 'fail';
    $senderGold = 0;
    $senderUnlimited = false;
    $transfer = null;
    journey_store_mutate('gold_transfers', function($transfers) use ($userId, $targetUserId, $amount, &$resultCode, &$senderGold, &$senderUnlimited, &$transfer) {
        if (!is_array($transfers)) $transfers = [];
        $users = getUsers();
        $senderIndex = -1;
        $receiverIndex = -1;
        foreach ($users as $index => $candidate) {
            if (($candidate['userId'] ?? '') === $userId) $senderIndex = $index;
            if (strcasecmp((string)($candidate['userId'] ?? ''), $targetUserId) === 0) $receiverIndex = $index;
        }
        if ($senderIndex < 0 || $receiverIndex < 0) {
            $resultCode = 'user_not_found';
            return $transfers;
        }
        ensureEconomyFields($users[$senderIndex]);
        $senderGold = (int)$users[$senderIndex]['gold'];
        $senderUnlimited = hasUnlimitedGold($users[$senderIndex]);
        if (!$senderUnlimited && $senderGold < $amount) {
            $resultCode = 'nogold';
            return $transfers;
        }
        if (!$senderUnlimited) $users[$senderIndex]['gold'] -= $amount;
        $senderGold = (int)$users[$senderIndex]['gold'];
        $transfer = [
            'transferId' => date('YmdHis') . '_' . bin2hex(random_bytes(6)),
            'senderId' => $userId,
            'senderName' => (string)($users[$senderIndex]['user'] ?? $userId),
            'receiverId' => (string)($users[$receiverIndex]['userId'] ?? $targetUserId),
            'receiverName' => (string)($users[$receiverIndex]['user'] ?? $targetUserId),
            'amount' => $amount,
            'status' => 'pending',
            'time' => date('Y-m-d H:i:s')
        ];
        $transfers[] = $transfer;
        saveUsers($users);
        $resultCode = 'ok';
        return $transfers;
    }, []);
    if ($resultCode !== 'ok') {
        if ($resultCode === 'user_not_found') http_response_code(404);
        echo json_encode(['code' => $resultCode, 'gold' => $senderGold], JSON_UNESCAPED_UNICODE);
        exit;
    }
    journey_audit('gold_transfer_created', ['amount' => $amount], $userId, 'user', $transfer['receiverId']);
    echo json_encode(['code' => 'ok', 'transfer' => $transfer, 'gold' => $senderGold, 'unlimitedGold' => $senderUnlimited], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'getMyMessages' && !empty($userId)) {
    $users = getUsers();
    $notifications = [];
    $unreadNotificationCount = 0;
    foreach ($users as $candidate) {
        if (($candidate['userId'] ?? '') === $userId) {
            $notifications = array_values(array_filter(is_array($candidate['notifications'] ?? null) ? $candidate['notifications'] : [], function($notice) {
                return is_array($notice) && !empty($notice['id']);
            }));
            $unreadNotificationCount = count(array_filter($notifications, function($notice) {
                return empty($notice['processed']);
            }));
            break;
        }
    }
    $pendingTransfers = array_values(array_filter(getGoldTransfers(), function($transfer) use ($userId) {
        return ($transfer['receiverId'] ?? '') === $userId && ($transfer['status'] ?? '') === 'pending';
    }));
    $gifts = journey_store_get('item_gifts', []);
    $pendingGifts = array_values(array_filter(is_array($gifts) ? $gifts : [], function($gift) use ($userId) {
        return ($gift['receiverId'] ?? '') === $userId && ($gift['status'] ?? '') === 'pending';
    }));
    $now = time();
    $mails = getItemMails();
    $pendingMails = array_values(array_filter($mails, function($mail) use ($userId, $now) {
        return ($mail['recipientId'] ?? '') === $userId
            && ($mail['status'] ?? '') === 'pending'
            && strtotime((string)($mail['expiresAt'] ?? '')) > $now;
    }));
    foreach ($pendingGifts as &$gift) $gift['item'] = itemDefinition($gift['itemId'] ?? '');
    foreach ($pendingMails as &$mail) {
        $mail['item'] = !empty($mail['itemId']) ? itemDefinition($mail['itemId']) : null;
        $mail['xp'] = max(0, (int)($mail['xp'] ?? 0));
        $mail['gold'] = max(0, (int)($mail['gold'] ?? 0));
        $mail['body'] = (string)($mail['body'] ?? '');
    }
    unset($gift, $mail);
    usort($pendingTransfers, function($a, $b) { return strcmp((string)($b['time'] ?? ''), (string)($a['time'] ?? '')); });
    usort($notifications, function($a, $b) { return strcmp((string)($b['time'] ?? ''), (string)($a['time'] ?? '')); });
    echo json_encode(['code' => 'ok', 'pendingCount' => count($pendingTransfers) + count($pendingGifts) + count($pendingMails) + $unreadNotificationCount, 'transfers' => $pendingTransfers, 'gifts' => $pendingGifts, 'mails' => $pendingMails, 'notifications' => $notifications], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'respondGoldTransfer' && !empty($userId)) {
    $transferId = trim((string)($_REQUEST['transferId'] ?? ''));
    $decision = ($_REQUEST['decision'] ?? '') === 'accept' ? 'accept' : 'reject';
    $resultCode = 'not_found';
    $status = '';
    $amount = 0;
    $receiverGold = 0;
    journey_store_mutate('gold_transfers', function($transfers) use ($userId, $transferId, $decision, &$resultCode, &$status, &$amount, &$receiverGold) {
        if (!is_array($transfers)) $transfers = [];
        $transferIndex = -1;
        foreach ($transfers as $index => $transfer) {
            if (($transfer['transferId'] ?? '') === $transferId && ($transfer['receiverId'] ?? '') === $userId && ($transfer['status'] ?? '') === 'pending') {
                $transferIndex = $index;
                break;
            }
        }
        if ($transferIndex < 0) return $transfers;
        $users = getUsers();
        $receiverIndex = -1;
        $senderIndex = -1;
        foreach ($users as $index => $candidate) {
            if (($candidate['userId'] ?? '') === $userId) $receiverIndex = $index;
            if (($candidate['userId'] ?? '') === ($transfers[$transferIndex]['senderId'] ?? '')) $senderIndex = $index;
        }
        if ($receiverIndex < 0) {
            $resultCode = 'fail';
            return $transfers;
        }
        $amount = max(1, (int)($transfers[$transferIndex]['amount'] ?? 0));
        ensureEconomyFields($users[$receiverIndex]);
        if ($decision === 'accept') {
            $users[$receiverIndex]['gold'] += $amount;
            $status = 'accepted';
        } else {
            if ($senderIndex >= 0) {
                ensureEconomyFields($users[$senderIndex]);
                if (!hasUnlimitedGold($users[$senderIndex])) $users[$senderIndex]['gold'] += $amount;
                addUserNotification($users[$senderIndex], 'transfer_rejected', "对方拒绝了 {$amount} 金币，金币已退回。", ['transferId' => $transferId]);
            }
            $status = 'rejected';
        }
        $receiverGold = (int)$users[$receiverIndex]['gold'];
        $transfers[$transferIndex]['status'] = $status;
        $transfers[$transferIndex]['processedAt'] = date('Y-m-d H:i:s');
        saveUsers($users);
        $resultCode = 'ok';
        return $transfers;
    }, []);
    if ($resultCode !== 'ok') {
        if ($resultCode === 'not_found') http_response_code(404);
        echo json_encode(['code' => $resultCode], JSON_UNESCAPED_UNICODE);
        exit;
    }
    journey_audit('gold_transfer_' . $status, ['amount' => $amount], $userId, 'transfer', $transferId);
    echo json_encode(['code' => 'ok', 'status' => $status, 'gold' => $receiverGold], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'dismissNotification' && !empty($userId)) {
    $notificationId = trim((string)($_REQUEST['notificationId'] ?? ''));
    $users = getUsers();
    foreach ($users as &$candidate) {
        if (($candidate['userId'] ?? '') !== $userId) continue;
        if (!isset($candidate['notifications']) || !is_array($candidate['notifications'])) {
            $candidate['notifications'] = [];
        }
        $updated = false;
        foreach ($candidate['notifications'] as &$notice) {
            if (($notice['id'] ?? '') !== $notificationId) continue;
            $notice['processed'] = true;
            $updated = true;
            break;
        }
        unset($notice);
        if (!$updated) {
            echo json_encode(['code' => 'not_found'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        saveUsers($users);
        journey_audit('notification.read', [], $userId, 'notification', $notificationId);
        echo json_encode(['code' => 'ok'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    unset($candidate);
    echo json_encode(['code' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'deleteNotification' && !empty($userId)) {
    $notificationId = trim((string)($_REQUEST['notificationId'] ?? ''));
    $users = getUsers();
    foreach ($users as &$candidate) {
        if (($candidate['userId'] ?? '') !== $userId) continue;
        $notifications = is_array($candidate['notifications'] ?? null) ? array_values($candidate['notifications']) : [];
        foreach ($notifications as $index => $notice) {
            if (($notice['id'] ?? '') !== $notificationId) continue;
            if (empty($notice['processed'])) {
                http_response_code(409);
                echo json_encode(['code' => 'notification_unread'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            array_splice($notifications, $index, 1);
            $candidate['notifications'] = $notifications;
            saveUsers($users);
            journey_audit('notification.deleted', [], $userId, 'notification', $notificationId);
            echo json_encode(['code' => 'ok'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['code' => 'not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    unset($candidate);
    echo json_encode(['code' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'createItemGift' && !empty($userId)) {
    $targetUserId = trim((string)($_REQUEST['targetUserId'] ?? ''));
    $storage = ($_REQUEST['storage'] ?? '') === 'warehouse' ? 'warehouse' : 'inventory';
    $slotIndex = (int)($_REQUEST['slotIndex'] ?? -1);
    $count = max(1, min(999, (int)($_REQUEST['count'] ?? 1)));
    if ($targetUserId === '' || $targetUserId === $userId || $slotIndex < 0) {
        http_response_code(422); echo json_encode(['code' => $targetUserId === $userId ? 'self' : 'invalid'], JSON_UNESCAPED_UNICODE); exit;
    }
    $result = 'fail';
    $gift = null;
    journey_store_mutate('item_gifts', function($gifts) use ($userId, $targetUserId, $storage, $slotIndex, $count, &$result, &$gift) {
        if (!is_array($gifts)) $gifts = [];
        $users = getUsers(); $senderIndex = -1; $receiverIndex = -1;
        foreach ($users as $index => $candidate) {
            if (($candidate['userId'] ?? '') === $userId) $senderIndex = $index;
            if (strcasecmp((string)($candidate['userId'] ?? ''), $targetUserId) === 0) $receiverIndex = $index;
        }
        if ($senderIndex < 0 || $receiverIndex < 0) { $result = 'user_not_found'; return $gifts; }
        ensureEconomyFields($users[$senderIndex]);
        $slot = $users[$senderIndex][$storage][$slotIndex] ?? null;
        if (!is_array($slot) || (int)($slot['count'] ?? 0) < $count) { $result = 'missing'; return $gifts; }
        $users[$senderIndex][$storage][$slotIndex]['count'] -= $count;
        if ($users[$senderIndex][$storage][$slotIndex]['count'] <= 0) $users[$senderIndex][$storage][$slotIndex] = null;
        $gift = [
            'giftId' => date('YmdHis') . '_' . bin2hex(random_bytes(6)), 'senderId' => $userId,
            'senderName' => (string)($users[$senderIndex]['user'] ?? $userId),
            'receiverId' => (string)$users[$receiverIndex]['userId'], 'receiverName' => (string)($users[$receiverIndex]['user'] ?? $targetUserId),
            'itemId' => (string)$slot['id'], 'customName' => (string)($slot['customName'] ?? ''), 'count' => $count,
            'status' => 'pending', 'time' => date('Y-m-d H:i:s')
        ];
        $gifts[] = $gift; saveUsers($users); $result = 'ok'; return $gifts;
    }, []);
    if ($result !== 'ok') { if ($result === 'user_not_found') http_response_code(404); echo json_encode(['code' => $result], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(['code' => 'ok', 'gift' => $gift], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'respondItemGift' && !empty($userId)) {
    $giftId = trim((string)($_REQUEST['giftId'] ?? ''));
    $decision = ($_REQUEST['decision'] ?? '') === 'accept' ? 'accept' : 'reject';
    $result = 'not_found'; $delivery = '';
    journey_store_mutate('item_gifts', function($gifts) use ($giftId, $userId, $decision, &$result, &$delivery) {
        if (!is_array($gifts)) $gifts = [];
        foreach ($gifts as &$gift) {
            if (($gift['giftId'] ?? '') !== $giftId || ($gift['receiverId'] ?? '') !== $userId || ($gift['status'] ?? '') !== 'pending') continue;
            if ($decision === 'accept') {
                $users = getUsers();
                foreach ($users as &$receiver) {
                    if (($receiver['userId'] ?? '') !== $userId) continue;
                    if (addWarehouseItem($receiver, $gift['itemId'], (int)$gift['count'], $gift['customName'] ?? '')) {
                        $delivery = 'warehouse'; saveUsers($users);
                    } else {
                        sendItemMail($userId, $gift['itemId'], (int)$gift['count'], '仓库已满：收到的礼物', $gift['customName'] ?? '', $gift['senderId'] ?? 'SYSTEM');
                        $delivery = 'mail';
                    }
                    break;
                }
                unset($receiver);
                $gift['status'] = 'accepted';
            } else {
                sendItemMail($gift['senderId'] ?? '', $gift['itemId'], (int)$gift['count'], '礼物被拒绝，物品退回', $gift['customName'] ?? '', $userId);
                $delivery = 'returned_mail'; $gift['status'] = 'rejected';
            }
            $gift['processedAt'] = date('Y-m-d H:i:s'); $result = 'ok'; break;
        }
        unset($gift); return $gifts;
    }, []);
    if ($result !== 'ok') { http_response_code(404); echo json_encode(['code' => $result], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(['code' => 'ok', 'delivery' => $delivery], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'claimItemMail' && !empty($userId)) {
    $mailId = trim((string)($_REQUEST['mailId'] ?? ''));
    $result = 'not_found';
    $claimedRewards = ['itemId' => '', 'count' => 0, 'xp' => 0, 'gold' => 0];
    journey_store_mutate('item_mail', function($mails) use ($mailId, $userId, &$result, &$claimedRewards) {
        if (!is_array($mails)) $mails = [];
        foreach ($mails as &$mail) {
            if (($mail['mailId'] ?? '') !== $mailId || ($mail['recipientId'] ?? '') !== $userId || ($mail['status'] ?? '') !== 'pending') continue;
            if (strtotime((string)($mail['expiresAt'] ?? '')) <= time()) { $mail['status'] = 'expired'; $result = 'expired'; break; }
            $users = getUsers();
            foreach ($users as &$recipient) {
                if (($recipient['userId'] ?? '') !== $userId) continue;
                ensureEconomyFields($recipient);
                $itemId = (string)($mail['itemId'] ?? '');
                $itemCount = $itemId === '' ? 0 : max(1, (int)($mail['count'] ?? 1));
                $xpReward = max(0, (int)($mail['xp'] ?? 0));
                $goldReward = max(0, (int)($mail['gold'] ?? 0));
                if ($itemId !== '') {
                    // 优先加到仓库，仓库满则加到背包
                    if (!addWarehouseItem($recipient, $itemId, $itemCount, $mail['customName'] ?? '')
                        && !addInventoryItem($recipient, $itemId, $itemCount, $mail['customName'] ?? '')) {
                        $result = 'full'; break 2;
                    }
                }
                $recipient['xp'] = min(2147483647, max(0, (int)($recipient['xp'] ?? 0)) + $xpReward);
                $recipient['gold'] = min(2147483647, max(0, (int)($recipient['gold'] ?? 0)) + $goldReward);
                $claimedRewards = ['itemId' => $itemId, 'count' => $itemCount, 'xp' => $xpReward, 'gold' => $goldReward];
                saveUsers($users); $mail['status'] = 'claimed'; $mail['claimedAt'] = date('Y-m-d H:i:s'); $result = 'ok'; break 2;
            }
        }
        unset($mail, $recipient); return $mails;
    }, []);
    if ($result !== 'ok') { echo json_encode(['code' => $result], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(['code' => 'ok', 'rewards' => $claimedRewards], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'synthesizeItems' && !empty($userId)) {
    $ingredients = json_decode((string)($_REQUEST['ingredients'] ?? '[]'), true);
    if (!is_array($ingredients)) $ingredients = [];
    $aggregated = [];
    foreach ($ingredients as $ingredient) {
        if (!is_array($ingredient)) continue;
        $storage = (string)($ingredient['storage'] ?? '');
        if ($storage !== 'inventory') {
            http_response_code(422);
            echo json_encode(['code' => 'inventory_only'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $slotIndex = (int)($ingredient['slotIndex'] ?? -1);
        $count = max(0, (int)($ingredient['count'] ?? 0));
        if ($slotIndex < 0 || $count < 1) continue;
        $key = $storage . ':' . $slotIndex;
        if (!isset($aggregated[$key])) $aggregated[$key] = ['storage' => $storage, 'slotIndex' => $slotIndex, 'count' => 0];
        $aggregated[$key]['count'] += $count;
    }
    if (array_sum(array_column($aggregated, 'count')) !== 10) {
        http_response_code(422);
        echo json_encode(['code' => 'need_ten'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $users = getUsers();
    foreach ($users as &$candidate) {
        if (($candidate['userId'] ?? '') !== $userId) continue;
        ensureEconomyFields($candidate);
        foreach ($aggregated as $ingredient) {
            $storedItem = $candidate['inventory'][$ingredient['slotIndex']] ?? null;
            if (!is_array($storedItem) || (int)($storedItem['count'] ?? 0) < $ingredient['count']) {
                echo json_encode(['code' => 'missing'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $itemQuality = (string)(itemDefinition($storedItem['id'] ?? '')['quality'] ?? 'common');
            if ($itemQuality !== 'common') {
                echo json_encode(['code' => 'common_only'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        foreach ($aggregated as $ingredient) {
            $slotIndex = $ingredient['slotIndex'];
            $candidate['inventory'][$slotIndex]['count'] -= $ingredient['count'];
            if ($candidate['inventory'][$slotIndex]['count'] <= 0) $candidate['inventory'][$slotIndex] = null;
        }
        $pool = lotteryPoolForQuality('uncommon');
        $outputId = $pool[random_int(0, count($pool) - 1)];
        $synthesisDelivery = deliverOverflowItem($candidate, $outputId, 1, '白色物品兑换奖励：空间不足');
        $outputItem = itemDefinition($outputId);
        $outputItem['systemPrice'] = itemSystemPrice($outputId);
        saveUsers($users);
        journey_audit('item_synthesis', ['quality' => 'common', 'outputQuality' => 'uncommon', 'success' => true], $userId, 'user', $userId);
        echo json_encode(['code' => 'ok', 'success' => true, 'delivery' => $synthesisDelivery, 'item' => $outputItem, 'inventory' => $candidate['inventory'], 'warehouse' => $candidate['warehouse']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    unset($candidate);
    echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'getMarket') {
    $users = getUsers();
    $userProfiles = [];
    foreach ($users as $u) {
        $profileUserId = $u['userId'] ?? '';
        if ($profileUserId === '') {
            continue;
        }
        $level = levelFromXp((int)($u['xp'] ?? 0));
        $userProfiles[$profileUserId] = [
            'userId' => $profileUserId,
            'user' => $u['user'] ?? '玩家',
            'avatar' => $u['avatar'] ?? defaultAvatar($u['user'] ?? ''),
            'level' => $level,
            'selectedTitle' => selectedTitleForUser($u, $level)
        ];
    }
    $items = array_values(array_filter(getMarketItems(), function($item) {
        return empty($item['sold']) && empty($item['delisted']);
    }));
    usort($items, function($left, $right) {
        $leftTime = strtotime($left['time'] ?? '') ?: 0;
        $rightTime = strtotime($right['time'] ?? '') ?: 0;
        if ($leftTime === $rightTime) {
            return strcmp((string)($right['marketId'] ?? ''), (string)($left['marketId'] ?? ''));
        }
        return $rightTime <=> $leftTime;
    });
    foreach ($items as &$item) {
        $seller = $userProfiles[$item['sellerId'] ?? ''] ?? null;
        $item['item'] = itemDefinition($item['itemId'] ?? '');
        if (!empty($item['customName'])) {
            $item['item']['originalName'] = $item['item']['name'] ?? '';
            $item['item']['name'] = $item['customName'];
            $item['item']['customName'] = $item['customName'];
        }
        if ($seller) {
            $item['sellerName'] = $seller['user'] ?? ($item['sellerName'] ?? '玩家');
            $item['sellerAvatar'] = $seller['avatar'] ?? defaultAvatar($item['sellerName']);
            $item['sellerLevel'] = $seller['level'] ?? 1;
            $item['sellerTitle'] = $seller['selectedTitle'] ?? '初来乍到';
        }
    }
    unset($item);
    outputCachedJson(['code' => 'ok', 'items' => $items], 5);
}

if ($action === 'listMarketItem' && !empty($userId)) {
    $slotIndex = (int)($_REQUEST['slotIndex'] ?? -1);
    $storage = ($_REQUEST['storage'] ?? '') === 'warehouse' ? 'warehouse' : 'inventory';
    $requestedPrice = (int)($_REQUEST['price'] ?? 0);
    $sellerNote = trim(strip_tags((string)($_REQUEST['sellerNote'] ?? '')));
    $sellerNote = function_exists('mb_substr') ? mb_substr($sellerNote, 0, 80, 'UTF-8') : substr($sellerNote, 0, 240);
    $users = getUsers();
    foreach ($users as &$u) {
        if ($u['userId'] === $userId) {
            ensureEconomyFields($u);
            if ($storage === 'warehouse') {
                $removed = $u['warehouse'][$slotIndex] ?? null;
                if (is_array($removed)) {
                    $removed['count'] = 1;
                    $u['warehouse'][$slotIndex]['count'] -= 1;
                    if ($u['warehouse'][$slotIndex]['count'] <= 0) $u['warehouse'][$slotIndex] = null;
                }
            } else {
                $removed = removeInventoryItem($u, $slotIndex, 1);
            }
            if (!$removed) {
                echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $price = $requestedPrice > 0 ? max(1, $requestedPrice) : itemSystemPrice($removed['id'] ?? '');
            $market = getMarketItems();
            $market[] = [
                'marketId' => date('YmdHis') . '_' . bin2hex(random_bytes(4)),
                'sellerId' => $userId,
                'sellerName' => $u['user'] ?? '玩家',
                'itemId' => $removed['id'],
                'customName' => $removed['customName'] ?? '',
                'count' => 1,
                'price' => $price,
                'sellerNote' => $sellerNote,
                'time' => date('Y-m-d H:i:s'),
                'sold' => false
            ];
            saveUsers($users);
            saveMarketItems($market);
            echo json_encode(['code' => 'ok', 'inventory' => $u['inventory'], 'warehouse' => $u['warehouse']], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delistMarketItem' && !empty($userId)) {
    $marketId = $_REQUEST['marketId'] ?? '';
    $users = getUsers();
    $market = getMarketItems();
    foreach ($market as &$marketItem) {
        if (($marketItem['marketId'] ?? '') === $marketId && empty($marketItem['sold']) && empty($marketItem['delisted'])) {
            if (($marketItem['sellerId'] ?? '') !== $userId) {
                echo json_encode(['code' => 'forbidden'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            foreach ($users as &$u) {
                if (($u['userId'] ?? '') === $userId) {
                    ensureEconomyFields($u);
                    $delistDelivery = deliverOverflowItem($u, $marketItem['itemId'], (int)($marketItem['count'] ?? 1), '市场下架退回物品', $marketItem['customName'] ?? '');
                    $marketItem['delisted'] = true;
                    $marketItem['delistedAt'] = date('Y-m-d H:i:s');
                    saveUsers($users);
                    saveMarketItems($market);
                    echo json_encode(['code' => 'ok', 'delivery' => $delistDelivery, 'inventory' => $u['inventory']], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
        }
    }
    echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'buyMarketItem' && !empty($userId)) {
    $marketId = $_REQUEST['marketId'] ?? '';
    $users = getUsers();
    $market = getMarketItems();
    foreach ($market as &$marketItem) {
        if (($marketItem['marketId'] ?? '') === $marketId && empty($marketItem['sold']) && empty($marketItem['delisted'])) {
            if (($marketItem['sellerId'] ?? '') === $userId) {
                echo json_encode(['code' => 'self'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $buyerIndex = -1;
            $sellerIndex = -1;
            foreach ($users as $i => $u) {
                if (($u['userId'] ?? '') === $userId) $buyerIndex = $i;
                if (($u['userId'] ?? '') === ($marketItem['sellerId'] ?? '')) $sellerIndex = $i;
            }
            if ($buyerIndex < 0) break;
            ensureEconomyFields($users[$buyerIndex]);
            if (!hasUnlimitedGold($users[$buyerIndex]) && (int)$users[$buyerIndex]['gold'] < (int)$marketItem['price']) {
                echo json_encode(['code' => 'nogold'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (!hasUnlimitedGold($users[$buyerIndex])) {
                $users[$buyerIndex]['gold'] -= (int)$marketItem['price'];
            }
            $marketDelivery = deliverOverflowItem($users[$buyerIndex], $marketItem['itemId'], 1, '市场购买物品：空间不足', $marketItem['customName'] ?? '');
            if ($sellerIndex >= 0) {
                ensureEconomyFields($users[$sellerIndex]);
                $users[$sellerIndex]['gold'] += (int)$marketItem['price'];
            }
            $marketItem['sold'] = true;
            $marketItem['buyerId'] = $userId;
            $marketItem['soldAt'] = date('Y-m-d H:i:s');
            saveUsers($users);
            saveMarketItems($market);
            echo json_encode(['code' => 'ok', 'gold' => (int)$users[$buyerIndex]['gold'], 'unlimitedGold' => hasUnlimitedGold($users[$buyerIndex]), 'delivery' => $marketDelivery, 'inventory' => $users[$buyerIndex]['inventory']], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    echo json_encode(['code' => 'fail'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'updateProfile' && !empty($userId)) {
    $users = getUsers();
    $gender = trim((string)($_POST['gender'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $bio = trim(strip_tags((string)($_POST['bio'] ?? '')));
    $bio = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $bio);
    $selectedTitle = trim($_POST['selectedTitle'] ?? '');
    $avatar = trim($_POST['avatar'] ?? '');
    $avatarReset = ($_POST['avatarReset'] ?? '') === '1';
    $avatarChanged = false;
    if (!$avatarReset && $avatar !== '') {
        if (preg_match('/^data:image\/(?:png|jpe?g|gif|webp);base64,/i', $avatar)) {
            $avatarChanged = true;
        } else {
            // Older cached clients submitted the existing avatar URL on every profile save.
            $avatarReference = html_entity_decode($avatar, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/(?:^|\/)board\.php\?(?:[^#]*&)?action=avatar(?:&|$)/i', $avatarReference)) {
                $avatar = '';
            } else {
                http_response_code(422);
                echo 'avatar_invalid';
                exit;
            }
        }
    }
    if ($gender !== '' && !in_array($gender, ['男', '女', '保密'], true)) {
        http_response_code(422);
        echo 'gender_invalid';
        exit;
    }
    if (isset($_POST['email']) && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo 'email_invalid';
        exit;
    }
    if ($email !== '') {
        foreach ($users as $candidate) {
            if (($candidate['userId'] ?? '') !== $userId && isset($candidate['email']) && strcasecmp((string)$candidate['email'], $email) === 0) {
                http_response_code(409);
                echo 'email_exists';
                exit;
            }
        }
    }
    foreach ($users as &$u) {
        if ($u['userId'] === $userId) {
            if ($gender) $u['gender'] = $gender;
            if (isset($_POST['email'])) $u['email'] = $email;
            if (isset($_POST['bio'])) $u['bio'] = function_exists('mb_substr') ? mb_substr($bio, 0, 180, 'UTF-8') : substr($bio, 0, 540);
            $profile = publicUserProfile($u, $posts);
            if ($selectedTitle !== '') {
                if (!in_array($selectedTitle, $profile['unlockedTitles'] ?? [], true)) {
                    echo 'title_locked';
                    exit;
                }
                $u['selectedTitle'] = $selectedTitle;
            }
            if ($avatarReset) {
                $u['avatar'] = defaultAvatar($u['user'] ?? '');
            } elseif ($avatarChanged) {
                $storedAvatar = storeAvatarImage($avatar, $userId);
                if ($storedAvatar === null) {
                    http_response_code(422);
                    echo 'avatar_invalid';
                    exit;
                }
                $u['avatar'] = $storedAvatar;
            }
            saveUsers($users);
            journey_audit('profile.updated', ['avatarChanged' => $avatarReset || $avatarChanged], $userId, 'user', $userId);
            echo 'ok';
            exit;
        }
    }
    echo 'fail';
    exit;
}

if ($action === 'addFriend' && !empty($userId) && !empty($friendId)) {
    if ($userId === $friendId) {
        echo 'self';
        exit;
    }
    $users = getUsers();
    $requesterIndex = -1;
    $targetIndex = -1;
    foreach ($users as $index => $candidate) {
        if (($candidate['userId'] ?? '') === $userId) $requesterIndex = $index;
        if (($candidate['userId'] ?? '') === $friendId) $targetIndex = $index;
    }
    if ($requesterIndex < 0 || $targetIndex < 0) {
        echo 'notfound';
        exit;
    }
    if (userHasFriend($users[$requesterIndex], $friendId)) {
        echo 'already';
        exit;
    }
    if (!isset($users[$targetIndex]['friendRequests']) || !is_array($users[$targetIndex]['friendRequests'])) {
        $users[$targetIndex]['friendRequests'] = [];
    }
    foreach ($users[$targetIndex]['friendRequests'] as $request) {
        if (($request['fromUserId'] ?? '') === $userId) {
            echo 'pending';
            exit;
        }
    }
    $users[$targetIndex]['friendRequests'][] = [
        'fromUserId' => $userId,
        'time' => date('Y-m-d H:i:s')
    ];
    saveUsers($users);
    echo 'pending';
    exit;
}

if ($action === 'getFriendRequests' && !empty($userId)) {
    $users = getUsers();
    $userMap = [];
    foreach ($users as $candidate) {
        $userMap[$candidate['userId'] ?? ''] = $candidate;
    }
    $requests = [];
    foreach ($users as $targetUser) {
        if (($targetUser['userId'] ?? '') !== $userId) continue;
        foreach (($targetUser['friendRequests'] ?? []) as $request) {
            $requester = $userMap[$request['fromUserId'] ?? ''] ?? null;
            if (!$requester) continue;
            $requests[] = [
                'userId' => $requester['userId'],
                'user' => $requester['user'] ?? '玩家',
                'avatar' => $requester['avatar'] ?? defaultAvatar($requester['user'] ?? ''),
                'time' => $request['time'] ?? ''
            ];
        }
        break;
    }
    echo json_encode($requests, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'respondFriendRequest' && !empty($userId) && !empty($friendId)) {
    $decision = ($_REQUEST['decision'] ?? '') === 'accept' ? 'accept' : 'reject';
    $users = getUsers();
    $targetIndex = -1;
    $requesterIndex = -1;
    foreach ($users as $index => $candidate) {
        if (($candidate['userId'] ?? '') === $userId) $targetIndex = $index;
        if (($candidate['userId'] ?? '') === $friendId) $requesterIndex = $index;
    }
    if ($targetIndex < 0 || $requesterIndex < 0) {
        echo 'notfound';
        exit;
    }
    $requests = $users[$targetIndex]['friendRequests'] ?? [];
    $foundRequest = false;
    $users[$targetIndex]['friendRequests'] = array_values(array_filter($requests, function($request) use ($friendId, &$foundRequest) {
        if (($request['fromUserId'] ?? '') === $friendId) {
            $foundRequest = true;
            return false;
        }
        return true;
    }));
    if (!$foundRequest) {
        echo 'notfound';
        exit;
    }
    if ($decision === 'accept') {
        if (!isset($users[$targetIndex]['friends'])) $users[$targetIndex]['friends'] = [];
        if (!isset($users[$requesterIndex]['friends'])) $users[$requesterIndex]['friends'] = [];
        if (!userHasFriend($users[$targetIndex], $friendId)) {
            $users[$targetIndex]['friends'][] = ['friendId' => $friendId, 'remark' => ''];
        }
        if (!userHasFriend($users[$requesterIndex], $userId)) {
            $users[$requesterIndex]['friends'][] = ['friendId' => $userId, 'remark' => ''];
        }
    }
    saveUsers($users);
    echo 'ok';
    exit;
}

if ($action === 'getFriends' && !empty($userId)) {
    $users = getUsers();
    $friends = [];
    foreach ($users as $u) {
        if ($u['userId'] === $userId && isset($u['friends'])) {
            foreach ($u['friends'] as $f) {
                $friendId = is_array($f) ? $f['friendId'] : $f;
                $remark = is_array($f) ? ($f['remark'] ?? '') : '';
                foreach ($users as $friend) {
                    if ($friend['userId'] === $friendId) {
                        $lastActive = isset($friend['lastActive']) ? $friend['lastActive'] : '';
                        $now = time();
                        $last = strtotime($lastActive);
                        $isOnline = ($now - $last) < 300;
                        $friends[] = [
                            'userId' => $friend['userId'],
                            'user' => $friend['user'],
                            'remark' => $remark,
                            'online' => $isOnline,
                            'avatar' => $friend['avatar'] ?? defaultAvatar($friend['user'] ?? '')
                        ];
                        break;
                    }
                }
            }
            break;
        }
    }
    echo json_encode($friends, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'updateFriendRemark' && !empty($userId) && !empty($friendId)) {
    $users = getUsers();
    foreach ($users as &$u) {
        if ($u['userId'] === $userId && isset($u['friends'])) {
            foreach ($u['friends'] as &$f) {
                $fId = is_array($f) ? $f['friendId'] : $f;
                if ($fId === $friendId) {
                    if (is_array($f)) {
                        $f['remark'] = $friendRemark;
                    } else {
                        $f = ['friendId' => $friendId, 'remark' => $friendRemark];
                    }
                    saveUsers($users);
                    echo 'ok';
                    exit;
                }
            }
            break;
        }
    }
    echo 'fail';
    exit;
}

if ($action === 'sendMessage' && !empty($userId) && !empty($otherUserId) && !empty($messageContent)) {
    $messageLimit = journey_rate_limit('chat.private', $userId, 20, 60, true);
    if (!$messageLimit['allowed']) {
        http_response_code(429);
        echo json_encode(['code' => 'rate_limited', 'retryAfter' => $messageLimit['retryAfter']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $users = getUsers();
    $sender = null;
    foreach ($users as $candidate) {
        if (($candidate['userId'] ?? '') === $userId) {
            $sender = $candidate;
            break;
        }
    }
    if (!$sender || !userHasFriend($sender, $otherUserId)) {
        echo 'notfriend';
        exit;
    }
    $messageContent = trim(strip_tags((string)$messageContent));
    $messageContent = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $messageContent);
    $messageContent = function_exists('mb_substr') ? mb_substr($messageContent, 0, 300, 'UTF-8') : substr($messageContent, 0, 900);
    if ($messageContent === '') {
        http_response_code(422);
        echo 'empty';
        exit;
    }
    journey_store_mutate('messages', function($messages) use ($userId, $otherUserId, $messageContent) {
        if (!is_array($messages)) $messages = [];
        $messages[] = [
            'from' => $userId,
            'to' => $otherUserId,
            'content' => $messageContent,
            'time' => date('Y-m-d H:i:s'),
            'read' => false
        ];
        return array_slice($messages, -5000);
    }, []);
    foreach ($users as &$u) {
        if ($u['userId'] === $userId) {
            $u['lastActive'] = date('Y-m-d H:i:s');
        }
    }
    saveUsers($users);
    echo 'ok';
    exit;
}

if ($action === 'getMessages' && !empty($userId) && !empty($otherUserId)) {
    $messages = getMessages();
    $chatMessages = [];
    foreach ($messages as $msg) {
        if (($msg['from'] === $userId && $msg['to'] === $otherUserId) ||
            ($msg['from'] === $otherUserId && $msg['to'] === $userId)) {
            $chatMessages[] = $msg;
        }
    }
    echo json_encode(array_slice($chatMessages, -200), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'sendWorldMessage' && !empty($userId) && !empty($messageContent)) {
    $worldLimit = journey_rate_limit('chat.world', $userId, 10, 60, true);
    if (!$worldLimit['allowed']) {
        http_response_code(429);
        echo json_encode(['code' => 'rate_limited', 'retryAfter' => $worldLimit['retryAfter']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $messageContent = trim(strip_tags((string)$messageContent));
    $messageContent = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $messageContent);
    $messageContent = function_exists('mb_substr') ? mb_substr($messageContent, 0, 300, 'UTF-8') : substr($messageContent, 0, 900);
    if ($messageContent === '') {
        http_response_code(422);
        echo 'empty';
        exit;
    }
    $users = getUsers();
    $username = '';
    $senderAvatar = null;
    foreach ($users as &$u) {
        if ($u['userId'] === $userId) {
            $username = $u['user'];
            $senderAvatar = $u['avatar'] ?? defaultAvatar($username);
            $u['lastActive'] = date('Y-m-d H:i:s');
            break;
        }
    }
    unset($u);
    saveUsers($users);
    if ($username === '') {
        http_response_code(401);
        echo 'unauthorized';
        exit;
    }
    journey_store_mutate('worldchat', function($worldChat) use ($userId, $username, $messageContent, $senderAvatar) {
        if (!is_array($worldChat)) $worldChat = [];
        $worldChat[] = [
            'from' => $userId,
            'user' => $username,
            'content' => $messageContent,
            'time' => date('Y-m-d H:i:s'),
            'avatar' => $senderAvatar
        ];
        return array_slice($worldChat, -200);
    }, []);
    echo 'ok';
    exit;
}

if ($action === 'getWorldMessages') {
    $worldChat = journey_store_get('worldchat', []);
    if (!is_array($worldChat)) $worldChat = [];
    foreach ($worldChat as &$worldMessage) {
        if (!isset($worldMessage['avatar'])) $worldMessage['avatar'] = defaultAvatar($worldMessage['user'] ?? '');
    }
    unset($worldMessage);
    outputCachedJson($worldChat, 3);
}

// ==================== 地牢聊天 ====================
// 地牢内全局聊天，保留最近30条；所有在地牢中的玩家共享同一频道
if ($action === 'sendDungeonChat' && !empty($userId)) {
    $chatLimit = journey_rate_limit('chat.dungeon', $userId, 8, 20, true);
    if (!$chatLimit['allowed']) {
        http_response_code(429);
        echo json_encode(['code' => 'rate_limited', 'retryAfter' => $chatLimit['retryAfter']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $message = trim(strip_tags((string)($_POST['message'] ?? '')));
    $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message);
    $message = function_exists('mb_substr') ? mb_substr($message, 0, 100, 'UTF-8') : substr($message, 0, 300);
    if ($message === '') {
        echo json_encode(['code' => 'empty_message'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $fullUser = journey_find_user($userId);
    $username = trim((string)($fullUser['displayName'] ?? $fullUser['user'] ?? $fullUser['username'] ?? $sessionUser['displayName'] ?? $sessionUser['user'] ?? $sessionUser['username'] ?? ''));
    if ($username === '') $username = '玩家' . $userId;
    $chatMsg = [
        'id' => uniqid('dc_'),
        'from' => $userId,
        'name' => $username,
        'content' => htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
        'time' => time()
    ];
    journey_store_mutate('dungeon_chat', function($chat) use ($chatMsg) {
        if (!is_array($chat)) $chat = [];
        $chat[] = $chatMsg;
        return array_slice($chat, -30);
    }, []);
    echo json_encode(['code' => 'ok', 'message' => $chatMsg], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'getDungeonChat') {
    $chat = journey_store_get('dungeon_chat', []);
    if (!is_array($chat)) $chat = [];
    // 只返回最近30条
    $chat = array_slice($chat, -30);
    echo json_encode(['code' => 'ok', 'messages' => $chat], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ==================== 游戏HTTP接口开始 ====================
$GAME_ROOM_ID = 'main_world'; // 默认主世界房间
$PLAYER_TIMEOUT = 30; // 玩家超时30秒自动离线

function game_get_players_store() {
    $players = journey_store_get('game_players', []);
    return is_array($players) ? $players : [];
}
function game_save_players_store($players) {
    journey_store_set('game_players', $players);
}
function game_get_chat_store() {
    $chat = journey_store_get('game_chat', []);
    return is_array($chat) ? $chat : [];
}
function game_save_chat_store($chat) {
    journey_store_set('game_chat', $chat);
}
function game_get_drops_store() {
    $drops = journey_store_get('game_drops', []);
    return is_array($drops) ? $drops : [];
}
function game_save_drops_store($drops) {
    journey_store_set('game_drops', $drops);
}
function game_lock_store_for_update(PDO $pdo, $storeKey) {
    $now = journey_now();
    if (journey_db_driver($pdo) === 'sqlite') {
        $statement = $pdo->prepare(
            "INSERT INTO json_store (store_key, data_json, updated_at) VALUES (?, '{}', ?) " .
            'ON CONFLICT(store_key) DO UPDATE SET updated_at = json_store.updated_at'
        );
    } else {
        $statement = $pdo->prepare(
            "INSERT INTO json_store (store_key, data_json, updated_at) VALUES (?, '{}', ?) " .
            'ON DUPLICATE KEY UPDATE updated_at = updated_at'
        );
    }
    $statement->execute([$storeKey, $now]);
}
function game_lock_user_for_update(PDO $pdo, $userId) {
    if (journey_db_driver($pdo) === 'mysql') {
        $statement = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? FOR UPDATE');
        $statement->execute([(string)$userId]);
        $statement->fetchColumn();
        return;
    }
    $statement = $pdo->prepare('UPDATE users SET updated_at = updated_at WHERE user_id = ?');
    $statement->execute([(string)$userId]);
}

// 加入游戏
if ($action === 'game_join') {
    header('Access-Control-Allow-Credentials: true');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin) header('Access-Control-Allow-Origin: ' . $origin);
    
    if (!$sessionUser) {
        echo json_encode(['code' => 'not_logged_in'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $userId = (string)$sessionUser['userId'];
    $fullUser = journey_find_user($userId);
    $username = trim((string)(
        $fullUser['displayName'] ?? $fullUser['user'] ?? $fullUser['username']
        ?? $sessionUser['displayName'] ?? $sessionUser['user'] ?? $sessionUser['username'] ?? ''
    ));
    if ($username === '') $username = '玩家' . $userId;
    $level = (int)($fullUser['level'] ?? $sessionUser['level'] ?? 1);
    $title = (string)($fullUser['title'] ?? $sessionUser['title'] ?? '新手冒险家');
    
    $players = game_get_players_store();
    $roomId = $GAME_ROOM_ID;
    if (!isset($players[$roomId]) || !is_array($players[$roomId])) $players[$roomId] = [];
    $hadOtherPlayers = !empty($players[$roomId]);
    
    // 初始化玩家位置（默认出生点）
    $spawnX = 960;
    $spawnY = 540;
    
    // 如果玩家之前有保存位置，使用保存的位置
    if (isset($players[$roomId][$userId]) && is_array($players[$roomId][$userId])) {
        $spawnX = (float)($players[$roomId][$userId]['x'] ?? $spawnX);
        $spawnY = (float)($players[$roomId][$userId]['y'] ?? $spawnY);
    } else {
        // 保存出生位置到用户数据
        $users = getUsers();
        foreach ($users as &$u) {
            if ((string)($u['userId'] ?? '') === $userId) {
                if (isset($u['lastPosition']) && is_array($u['lastPosition'])) {
                    $spawnX = (float)($u['lastPosition']['x'] ?? $spawnX);
                    $spawnY = (float)($u['lastPosition']['y'] ?? $spawnY);
                }
                break;
            }
        }
        unset($u);
    }
    
    $players[$roomId][$userId] = [
        'id' => $userId,
        'name' => $username,
        'x' => $spawnX,
        'y' => $spawnY,
        'targetX' => $spawnX,
        'targetY' => $spawnY,
        'direction' => 'right',
        'isMoving' => false,
        'heldItem' => null,
        'level' => $level,
        'title' => $title,
        'bubble' => null,
        'bubbleTime' => 0,
        'lastSeen' => time()
    ];
    game_save_players_store($players);
    
    // 系统消息欢迎
    $chat = game_get_chat_store();
    if (!isset($chat[$roomId]) || !is_array($chat[$roomId])) $chat[$roomId] = [];
    $chatCursor = (int)floor(microtime(true) * 1000000);
    $joinNotice = [
        'id' => uniqid('chat_'),
        'from' => 'SYSTEM',
        'name' => '系统',
        'content' => $username . '（ID: ' . $userId . '）加入了游戏',
        'time' => $chatCursor,
        'system' => true
    ];
    // 新玩家自己使用响应直接显示，其他玩家通过轮询看到，避免自己重复。
    if ($hadOtherPlayers) $chat[$roomId][] = $joinNotice;
    $chat[$roomId] = array_slice($chat[$roomId], -50);
    game_save_chat_store($chat);
    
    echo json_encode([
        'code' => 'ok',
        'playerId' => $userId,
        'playerName' => $username,
        'roomId' => $roomId,
        'x' => $spawnX,
        'y' => $spawnY,
        'joinNotice' => $joinNotice,
        'chatCursor' => $chatCursor,
        'serverTime' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 更新玩家状态（位置等）
if ($action === 'game_update') {
    header('Access-Control-Allow-Credentials: true');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin) header('Access-Control-Allow-Origin: ' . $origin);
    
    if (!$sessionUser) {
        echo json_encode(['code' => 'not_logged_in'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $userId = (string)$sessionUser['userId'];
    $roomId = $GAME_ROOM_ID;
    
    $x = (float)($_POST['x'] ?? $_REQUEST['x'] ?? 960);
    $y = (float)($_POST['y'] ?? $_REQUEST['y'] ?? 540);
    $targetX = (float)($_POST['targetX'] ?? $_REQUEST['targetX'] ?? $x);
    $targetY = (float)($_POST['targetY'] ?? $_REQUEST['targetY'] ?? $y);
    $direction = (string)($_POST['direction'] ?? $_REQUEST['direction'] ?? 'right');
    $direction = $direction === 'left' ? 'left' : 'right';
    $isMoving = ($_POST['isMoving'] ?? $_REQUEST['isMoving'] ?? 'false') === 'true';
    $bubble = $_POST['bubble'] ?? $_REQUEST['bubble'] ?? null;
    $hasHeldItem = array_key_exists('heldItem', $_POST) || array_key_exists('heldItem', $_REQUEST);
    $heldItemRaw = $_POST['heldItem'] ?? $_REQUEST['heldItem'] ?? 'null';
    $heldItemInput = $hasHeldItem ? json_decode((string)$heldItemRaw, true) : null;
    $heldItem = null;
    if (is_array($heldItemInput) && !empty($heldItemInput['id'])) {
        $heldDefinition = itemDefinition((string)$heldItemInput['id']);
        $heldItem = [
            'id' => (string)$heldItemInput['id'],
            'name' => function_exists('mb_substr')
                ? mb_substr(trim((string)($heldItemInput['name'] ?? $heldDefinition['name'] ?? '物品')), 0, 50, 'UTF-8')
                : substr(trim((string)($heldItemInput['name'] ?? $heldDefinition['name'] ?? '物品')), 0, 150),
            'icon' => (string)($heldDefinition['icon'] ?? '?'),
            'quality' => (string)($heldDefinition['quality'] ?? 'common')
        ];
    }
    
    $players = game_get_players_store();
    if (!isset($players[$roomId]) || !is_array($players[$roomId])) $players[$roomId] = [];
    
    if (!isset($players[$roomId][$userId])) {
        // 玩家不存在，返回错误让客户端重新join
        echo json_encode(['code' => 'not_joined'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $players[$roomId][$userId]['x'] = $x;
    $players[$roomId][$userId]['y'] = $y;
    $players[$roomId][$userId]['targetX'] = $targetX;
    $players[$roomId][$userId]['targetY'] = $targetY;
    $players[$roomId][$userId]['direction'] = $direction;
    $players[$roomId][$userId]['isMoving'] = $isMoving;
    if ($hasHeldItem) $players[$roomId][$userId]['heldItem'] = $heldItem;
    $players[$roomId][$userId]['lastSeen'] = time();
    
    if ($bubble !== null && $bubble !== '') {
        $players[$roomId][$userId]['bubble'] = (string)$bubble;
        $players[$roomId][$userId]['bubbleTime'] = time();
    } else {
        // 气泡3秒后消失
        if (isset($players[$roomId][$userId]['bubbleTime']) && time() - $players[$roomId][$userId]['bubbleTime'] > 3) {
            $players[$roomId][$userId]['bubble'] = null;
        }
    }
    
    // 清理超时玩家
    $now = time();
    foreach ($players[$roomId] as $pid => $p) {
        if (isset($p['lastSeen']) && $now - $p['lastSeen'] > $PLAYER_TIMEOUT) {
            unset($players[$roomId][$pid]);
        }
    }
    
    game_save_players_store($players);
    
    echo json_encode(['code' => 'ok'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取游戏世界状态（轮询用）
if ($action === 'game_get_state') {
    header('Access-Control-Allow-Credentials: true');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin) header('Access-Control-Allow-Origin: ' . $origin);
    
    if (!$sessionUser) {
        echo json_encode(['code' => 'not_logged_in'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $userId = (string)$sessionUser['userId'];
    $roomId = $GAME_ROOM_ID;
    $lastChatTime = (int)($_GET['lastChatTime'] ?? $_REQUEST['lastChatTime'] ?? 0);
    $lastDropTime = (int)($_GET['lastDropTime'] ?? $_REQUEST['lastDropTime'] ?? 0);
    
    $players = game_get_players_store();
    $roomPlayers = $players[$roomId] ?? [];
    
    // 清理超时玩家
    $now = time();
    $activePlayers = [];
    foreach ($roomPlayers as $pid => $p) {
        if (isset($p['lastSeen']) && $now - $p['lastSeen'] <= $PLAYER_TIMEOUT) {
            // 气泡3秒后消失
            if (isset($p['bubbleTime']) && $now - $p['bubbleTime'] > 3) {
                $p['bubble'] = null;
            }
            $activePlayers[] = $p;
        }
    }
    
    // 获取新聊天消息
    $chat = game_get_chat_store();
    $roomChat = $chat[$roomId] ?? [];
    $newChat = [];
    foreach ($roomChat as $msg) {
        if ((int)($msg['time'] ?? 0) > $lastChatTime) {
            $newChat[] = $msg;
        }
    }
    
    // 获取掉落物
    $drops = game_get_drops_store();
    $roomDrops = $drops[$roomId] ?? [];
    $activeDrops = [];
    $activeDropIds = [];
    foreach ($roomDrops as $drop) {
        // 掉落物5分钟后消失
        if (!isset($drop['time']) || $now - $drop['time'] <= 300) {
            if (!empty($drop['id'])) $activeDropIds[] = (string)$drop['id'];
            $activeDrops[] = $drop;
        }
    }
    
    echo json_encode([
        'code' => 'ok',
        'players' => array_values($activePlayers),
        'chat' => $newChat,
        'drops' => $activeDrops,
        'dropIds' => $activeDropIds,
        'serverTime' => $now
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 发送聊天消息
if ($action === 'game_chat') {
    header('Access-Control-Allow-Credentials: true');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin) header('Access-Control-Allow-Origin: ' . $origin);
    
    if (!$sessionUser) {
        echo json_encode(['code' => 'not_logged_in'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $userId = (string)$sessionUser['userId'];
    $fullUser = journey_find_user($userId);
    $username = trim((string)(
        $fullUser['displayName'] ?? $fullUser['user'] ?? $fullUser['username']
        ?? $sessionUser['displayName'] ?? $sessionUser['user'] ?? $sessionUser['username'] ?? ''
    ));
    if ($username === '') $username = '玩家' . $userId;
    $message = trim((string)($_POST['message'] ?? $_REQUEST['message'] ?? ''));
    
    if ($message === '') {
        echo json_encode(['code' => 'empty_message'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (mb_strlen($message) > 100) {
        $message = mb_substr($message, 0, 100, 'UTF-8');
    }
    
    $roomId = $GAME_ROOM_ID;
    $chat = game_get_chat_store();
    if (!isset($chat[$roomId]) || !is_array($chat[$roomId])) $chat[$roomId] = [];
    
    $chatMsg = [
        'id' => uniqid('chat_'),
        'from' => $userId,
        'name' => $username,
        'content' => htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
        'time' => (int)floor(microtime(true) * 1000000),
        'system' => false
    ];
    $chat[$roomId][] = $chatMsg;
    $chat[$roomId] = array_slice($chat[$roomId], -50);
    game_save_chat_store($chat);
    
    // 同时给玩家设置头顶气泡
    $players = game_get_players_store();
    if (isset($players[$roomId][$userId])) {
        $players[$roomId][$userId]['bubble'] = $chatMsg['content'];
        $players[$roomId][$userId]['bubbleTime'] = time();
        game_save_players_store($players);
    }
    
    echo json_encode(['code' => 'ok', 'message' => $chatMsg], JSON_UNESCAPED_UNICODE);
    exit;
}

// 丢弃物品：服务器验证真实槽位，成功扣除后才生成场景掉落。
if ($action === 'game_drop') {
    header('Access-Control-Allow-Credentials: true');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin) header('Access-Control-Allow-Origin: ' . $origin);
    if (!$sessionUser) {
        echo json_encode(['code' => 'not_logged_in'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = (string)$sessionUser['userId'];
    $slotIndex = filter_var($_POST['slotIndex'] ?? null, FILTER_VALIDATE_INT);
    if ($slotIndex === false || $slotIndex < 0 || $slotIndex > 27) {
        echo json_encode(['code' => 'invalid_slot'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = journey_db();
    $started = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started = true;
    }
    game_lock_store_for_update($pdo, 'game_drops');
    game_lock_user_for_update($pdo, $userId);

    $currentUser = journey_find_user($userId);
    if (!is_array($currentUser)) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['code' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ensureEconomyFields($currentUser);
    $inventory = normalizeInventorySlots($currentUser['inventory'] ?? [], false);
    $hotbar = normalizeGameHotbarSlots($currentUser['gameHotbar'] ?? [], false);
    $sourceIndex = $slotIndex < 21 ? $slotIndex : $slotIndex - 21;
    $sourceSlots = $slotIndex < 21 ? $inventory : $hotbar;
    $item = $sourceSlots[$sourceIndex] ?? null;
    if (!is_array($item) || empty($item['id'])) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['code' => 'empty_slot'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $itemId = (string)$item['id'];
    $count = max(1, (int)($item['count'] ?? 1));
    $definition = itemDefinition($itemId);
    $roomId = $GAME_ROOM_ID;
    $dropId = uniqid('drop_', true);
    $players = game_get_players_store();
    $playerState = is_array($players[$GAME_ROOM_ID][$userId] ?? null) ? $players[$GAME_ROOM_ID][$userId] : [];
    $playerX = (float)($playerState['x'] ?? 960);
    $playerY = (float)($playerState['y'] ?? 540);
    $playerDirection = ($playerState['direction'] ?? 'right') === 'left' ? -1 : 1;
    $drop = [
        'id' => $dropId,
        'itemId' => $itemId,
        'itemName' => (string)($item['customName'] ?? $definition['name'] ?? $itemId),
        'name' => (string)($item['customName'] ?? $definition['name'] ?? $itemId),
        'itemIcon' => (string)($definition['icon'] ?? '?'),
        'icon' => (string)($definition['icon'] ?? '?'),
        'quality' => (string)($definition['quality'] ?? 'common'),
        'description' => (string)($definition['desc'] ?? ''),
        'customName' => (string)($item['customName'] ?? ''),
        'count' => $count,
        'x' => max(30, min(1890, $playerX + $playerDirection * 55)),
        'y' => max(55, min(1050, $playerY + 15)),
        'droppedBy' => $userId,
        'time' => time()
    ];

    if ($slotIndex < 21) $inventory[$sourceIndex] = null;
    else $hotbar[$sourceIndex] = null;
    $currentUser['inventory'] = $inventory;
    $currentUser['gameHotbar'] = $hotbar;
    $drops = game_get_drops_store();
    if (!isset($drops[$roomId]) || !is_array($drops[$roomId])) $drops[$roomId] = [];
    $drops[$roomId][$dropId] = $drop;

    try {
        journey_upsert_legacy_user_internal($pdo, $currentUser);
        game_save_drops_store($drops);
        if ($started) $pdo->commit();
    } catch (Throwable $exception) {
        if (isset($started) && $started && isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log('game_drop failed: ' . $exception->getMessage());
        echo json_encode(['code' => 'save_failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'code' => 'ok',
        'drop' => $drop,
        'inventory' => formatGameInventory($inventory),
        'hotbar' => formatGameHotbar($hotbar)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 拾取物品：快捷栏优先，快捷栏满后进入主背包，两边都满时保留掉落。
if ($action === 'game_pickup') {
    header('Access-Control-Allow-Credentials: true');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin) header('Access-Control-Allow-Origin: ' . $origin);
    if (!$sessionUser) {
        echo json_encode(['code' => 'not_logged_in'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = (string)$sessionUser['userId'];
    $dropId = (string)($_POST['dropId'] ?? $_REQUEST['dropId'] ?? '');
    $roomId = $GAME_ROOM_ID;
    $pdo = journey_db();
    $started = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started = true;
    }
    game_lock_store_for_update($pdo, 'game_drops');
    game_lock_user_for_update($pdo, $userId);
    $drops = game_get_drops_store();
    if ($dropId === '' || !isset($drops[$roomId][$dropId])) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['code' => 'drop_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $drop = $drops[$roomId][$dropId];
    $currentUser = journey_find_user($userId);
    if (!is_array($currentUser)) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['code' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ensureEconomyFields($currentUser);
    $inventory = normalizeInventorySlots($currentUser['inventory'] ?? [], false);
    $hotbar = normalizeGameHotbarSlots($currentUser['gameHotbar'] ?? [], false);
    $itemId = (string)($drop['itemId'] ?? '');
    $count = max(1, (int)($drop['count'] ?? 1));
    $customName = trim((string)($drop['customName'] ?? ''));
    $destination = '';

    // 相同物品先叠加，否则使用第一个空快捷栏。
    $hotbarIndex = -1;
    foreach ($hotbar as $index => $hotbarItem) {
        if (is_array($hotbarItem) && (string)($hotbarItem['id'] ?? '') === $itemId
            && (string)($hotbarItem['customName'] ?? '') === $customName) {
            $hotbarIndex = $index;
            break;
        }
    }
    if ($hotbarIndex < 0) {
        foreach ($hotbar as $index => $hotbarItem) {
            if ($hotbarItem === null) {
                $hotbarIndex = $index;
                break;
            }
        }
    }
    if ($hotbarIndex >= 0) {
        if (is_array($hotbar[$hotbarIndex])) {
            $hotbar[$hotbarIndex]['count'] = max(1, (int)($hotbar[$hotbarIndex]['count'] ?? 1)) + $count;
        } else {
            $hotbar[$hotbarIndex] = ['id' => $itemId, 'count' => $count, 'createdAt' => date('Y-m-d H:i:s')];
            if ($customName !== '') $hotbar[$hotbarIndex]['customName'] = $customName;
        }
        $currentUser['gameHotbar'] = $hotbar;
        $destination = 'hotbar';
    } else {
        $currentUser['inventory'] = $inventory;
        if (addInventoryItem($currentUser, $itemId, $count, $customName)) {
            $inventory = normalizeInventorySlots($currentUser['inventory'] ?? [], false);
            $destination = 'inventory';
        }
    }

    if ($destination === '') {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['code' => 'inventory_full'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    unset($drops[$roomId][$dropId]);
    try {
        journey_upsert_legacy_user_internal($pdo, $currentUser);
        game_save_drops_store($drops);
        if ($started) $pdo->commit();
    } catch (Throwable $exception) {
        if (isset($started) && $started && isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log('game_pickup failed: ' . $exception->getMessage());
        echo json_encode(['code' => 'save_failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'code' => 'ok',
        'dropId' => $dropId,
        'destination' => $destination,
        'inventory' => formatGameInventory($currentUser['inventory'] ?? $inventory),
        'hotbar' => formatGameHotbar($currentUser['gameHotbar'] ?? $hotbar)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 保存玩家下线位置
if ($action === 'game_save_position') {
    header('Access-Control-Allow-Credentials: true');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin) header('Access-Control-Allow-Origin: ' . $origin);
    
    if (!$sessionUser) {
        echo json_encode(['code' => 'not_logged_in'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $userId = (string)$sessionUser['userId'];
    $x = (float)($_POST['x'] ?? $_REQUEST['x'] ?? 960);
    $y = (float)($_POST['y'] ?? $_REQUEST['y'] ?? 540);
    
    $users = getUsers();
    foreach ($users as &$u) {
        if ((string)($u['userId'] ?? '') === $userId) {
            $u['lastPosition'] = ['x' => $x, 'y' => $y];
            saveUsers($users);
            break;
        }
    }
    unset($u);
    
    echo json_encode(['code' => 'ok'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 离开游戏
if ($action === 'game_leave') {
    header('Access-Control-Allow-Credentials: true');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin) header('Access-Control-Allow-Origin: ' . $origin);
    
    if ($sessionUser) {
        $userId = (string)$sessionUser['userId'];
        $roomId = $GAME_ROOM_ID;
        
        // 保存下线位置
        $x = (float)($_POST['x'] ?? $_REQUEST['x'] ?? null);
        $y = (float)($_POST['y'] ?? $_REQUEST['y'] ?? null);
        
        $players = game_get_players_store();
        if (isset($players[$roomId][$userId])) {
            if ($x !== null && $y !== null) {
                $players[$roomId][$userId]['x'] = $x;
                $players[$roomId][$userId]['y'] = $y;
                // 保存到用户数据
                $users = getUsers();
                foreach ($users as &$u) {
                    if ((string)($u['userId'] ?? '') === $userId) {
                        $u['lastPosition'] = ['x' => $x, 'y' => $y];
                        saveUsers($users);
                        break;
                    }
                }
                unset($u);
            }
            unset($players[$roomId][$userId]);
            game_save_players_store($players);
        }
    }
    
    echo json_encode(['code' => 'ok'], JSON_UNESCAPED_UNICODE);
    exit;
}
// ==================== 游戏HTTP接口结束 ====================

// 添加物品到背包接口（游戏内拾取用）
if ($action === 'gameAddItem') {
    header('Access-Control-Allow-Credentials: true');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin) header('Access-Control-Allow-Origin: ' . $origin);
    
    if (!$sessionUser) {
        echo json_encode(['code' => 'not_logged_in'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $userId = (string)$sessionUser['userId'];
    $itemId = trim($_POST['itemId'] ?? $_REQUEST['itemId'] ?? '');
    $count = max(1, (int)($_POST['count'] ?? $_REQUEST['count'] ?? 1));
    
    if ($itemId === '') {
        echo json_encode(['code' => 'invalid_item'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $users = getUsers();
    $added = false;
    $targetUser = null;
    foreach ($users as &$u) {
        if ((string)($u['userId'] ?? '') === $userId) {
            ensureEconomyFields($u);
            $added = addInventoryItem($u, $itemId, $count);
            $targetUser =& $u;
            if ($added) {
                saveUsers($users);
            }
            break;
        }
    }
    unset($u);
    
    if ($added && $targetUser) {
        echo json_encode([
            'code' => 'ok',
            'inventory' => formatGameInventory($targetUser['inventory'] ?? [])
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['code' => 'inventory_full'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

echo 'error';
?>
