<?php
// 先引入board.php的所有函数和初始化
define('JOURNEY_ADMIN_LOADED', true);
require_once __DIR__ . '/board.php';

// 检查管理员权限（board.php已经做了security bootstrap）
if (!journey_is_admin()) {
    header('Location: auth.html');
    exit;
}

$message = '';
$messageType = '';

// 处理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 表单被服务器整体拒绝（通常是上传体积超出 post_max_size / client_max_body_size）
    if ($action === '' && empty($_POST) && empty($_FILES)) {
        $message = '提交内容过大被服务器拒绝：请压缩图片（单张不超过 5MB）或逐张上传';
        $messageType = 'error';
    } elseif ($action === 'send_announcement') {
        $content = trim($_POST['content'] ?? '');
        if ($content !== '') {
            $roomId = 'main_world';
            journey_store_mutate('game_chat', function($chat) use ($content, $roomId) {
                if (!is_array($chat)) $chat = [];
                if (!isset($chat[$roomId])) $chat[$roomId] = [];
                $chat[$roomId][] = [
                    'id' => uniqid('chat_'),
                    'from' => 'SYSTEM',
                    'name' => '系统公告',
                    'content' => htmlspecialchars($content, ENT_QUOTES, 'UTF-8'),
                    'time' => time(),
                    'system' => true
                ];
                $chat[$roomId] = array_slice($chat[$roomId], -50);
                return $chat;
            }, []);
            $message = '公告发送成功！';
            $messageType = 'success';
        }
    } elseif ($action === 'give_item') {
        $userId = trim($_POST['userId'] ?? '');
        $itemId = trim($_POST['itemId'] ?? '');
        $count = max(1, (int)($_POST['count'] ?? 1));
        if ($userId !== '' && $itemId !== '') {
            $users = getUsers();
            $found = false;
            foreach ($users as &$u) {
                if ((string)($u['userId'] ?? '') === $userId) {
                    ensureEconomyFields($u);
                    if (addInventoryItem($u, $itemId, $count)) {
                        saveUsers($users);
                        $message = '物品发放成功！';
                        $messageType = 'success';
                        $found = true;
                    } else {
                        $message = '背包已满，发放失败';
                        $messageType = 'error';
                        $found = true;
                    }
                    break;
                }
            }
            unset($u);
            if (!$found) {
                $message = '用户不存在';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'kick_player') {
        $userId = trim($_POST['userId'] ?? '');
        if ($userId !== '') {
            $players = journey_store_get('game_players', []);
            if (isset($players['main_world'][$userId])) {
                unset($players['main_world'][$userId]);
                journey_store_set('game_players', $players);
                $message = '玩家已踢出游戏';
                $messageType = 'success';
            }
        }
    } elseif ($action === 'clear_drops') {
        journey_store_set('game_drops', ['main_world' => []]);
        $message = '所有掉落物已清理';
        $messageType = 'success';
    } elseif ($action === 'save_dungeon_appearance') {
        // 支持直接上传图片：文件保存到 .uploads/dungeon/，经 image.php 公开访问；上传优先于 URL
        $dungeonDir = rtrim((string)journey_config('data_dir'), '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'dungeon';
        if (!is_dir($dungeonDir)) mkdir($dungeonDir, 0755, true);
        $appearanceKeys = ['dungeon_background','floor_spawn','floor_chest','floor_merchant','floor_camp','floor_shrine','floor_boss','floor_normal','floor_elite','floor_town','floor_bridge'];
        $fileMap = journey_setting_get('dungeon_appearance_files', []);
        if (!is_array($fileMap)) $fileMap = [];
        $currentBg = (string)journey_setting_get('dungeon_background', '');
        $currentFloors = journey_setting_get('dungeon_floor_textures', []) ?: [];
        if (!is_array($currentFloors)) $currentFloors = [];
        $floorTypes = ['spawn','chest','merchant','camp','shrine','boss','normal','elite','town','bridge'];
        $currentFloorColors = journey_setting_get('dungeon_floor_colors', []) ?: [];
        if (!is_array($currentFloorColors)) $currentFloorColors = [];
        $uploadError = '';
        $uploads = [];
        // 提前检查上传目录是否可写，避免文件校验通过后 move_uploaded_file 才失败
        if (!is_writable($dungeonDir)) {
            @chmod($dungeonDir, 0755);
            if (!is_writable($dungeonDir)) {
                $uploadError = '上传目录不可写，请检查服务器目录权限：' . $dungeonDir . '（PHP 运行用户需要有写入权限）';
            }
        }
        // 阶段一：先校验全部上传文件与 URL 格式，全部合法才开始落盘
        foreach ($appearanceKeys as $uKey) {
            $field = 'upload_' . $uKey;
            if (empty($_FILES[$field]) || !is_array($_FILES[$field]) || (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $file = $_FILES[$field];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $hints = [UPLOAD_ERR_INI_SIZE => '图片超过 PHP 上传限制（upload_max_filesize），请压缩后重试', UPLOAD_ERR_FORM_SIZE => '图片超过表单限制', UPLOAD_ERR_PARTIAL => '图片只上传了一部分，请重试', UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录', UPLOAD_ERR_CANT_WRITE => '服务器写入临时文件失败', UPLOAD_ERR_EXTENSION => 'PHP 扩展阻止了上传'];
                $uploadError = $hints[(int)$file['error']] ?? '图片上传失败（错误码 ' . (int)$file['error'] . '）';
                break;
            }
            if ($file['size'] > 5 * 1024 * 1024) { $uploadError = '单张图片不能超过 5MB'; break; }
            if (!is_uploaded_file($file['tmp_name'])) { $uploadError = '上传的文件无效'; break; }
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
            $mime = $finfo ? (string)finfo_file($finfo, $file['tmp_name']) : '';
            if ($finfo) finfo_close($finfo);
            if ($mime === '' && function_exists('mime_content_type')) $mime = (string)@mime_content_type($file['tmp_name']);
            if ($mime === '' && function_exists('getimagesize')) {
                $imageInfo = @getimagesize($file['tmp_name']);
                $mime = is_array($imageInfo) ? (string)($imageInfo['mime'] ?? '') : '';
            }
            $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            if (!isset($allowedMimes[$mime]) || @getimagesize($file['tmp_name']) === false) { $uploadError = '只支持 JPG / PNG / WebP / GIF 图片文件'; break; }
            $uploads[$uKey] = ['hash' => bin2hex(random_bytes(8)), 'ext' => $allowedMimes[$mime]];
        }
        if ($uploadError === '') {
            foreach ($appearanceKeys as $uKey) {
                $urlField = trim((string)($_POST[$uKey] ?? ''));
                if ($urlField !== '' && !filter_var($urlField, FILTER_VALIDATE_URL) && !preg_match('#^(?:\./|/)?image\.php\?key=[a-z_]+&v=[a-f0-9]{16}$#', $urlField)) {
                    $isFloor = $uKey !== 'dungeon_background';
                    $uploadError = ($isFloor ? substr($uKey, 6) . ' 地板' : '背景') . '图片 URL 格式不正确';
                    break;
                }
            }
        }
        $bgUrl = '';
        $textures = [];
        if ($uploadError === '') {
            // 阶段二：移动新文件并清理被替换的旧文件
            foreach ($uploads as $uKey => $info) {
                $storedName = $uKey . '_' . $info['hash'] . '.' . $info['ext'];
                $targetPath = $dungeonDir . DIRECTORY_SEPARATOR . $storedName;
                if (!@move_uploaded_file($_FILES['upload_' . $uKey]['tmp_name'], $targetPath)) {
                    $uploadError = '图片保存到服务器失败，请检查目录权限：' . $dungeonDir;
                    break;
                }
                $old = (string)($fileMap[$uKey] ?? '');
                if ($old !== '' && preg_match('/^[a-z0-9_]{1,80}\.(?:jpe?g|png|webp|gif)$/i', $old)) {
                    @unlink($dungeonDir . DIRECTORY_SEPARATOR . $old);
                }
                $fileMap[$uKey] = $storedName;
            }
        }
        if ($uploadError === '') {
            // 阶段三：逐键决定最终取值（上传 > 清除勾选 > 保持当前配置）
            foreach ($appearanceKeys as $sKey) {
                $isFloor = $sKey !== 'dungeon_background';
                $floorType = $isFloor ? substr($sKey, 6) : '';
                if (isset($uploads[$sKey])) {
                    $value = 'image.php?key=' . $sKey . '&v=' . $uploads[$sKey]['hash'];
                } elseif (!empty($_POST['clear_' . $sKey])) {
                    $value = '';
                    $old = (string)($fileMap[$sKey] ?? '');
                    if ($old !== '') {
                        if (preg_match('/^[a-z0-9_]{1,80}\.(?:jpe?g|png|webp|gif)$/i', $old)) {
                            @unlink($dungeonDir . DIRECTORY_SEPARATOR . $old);
                        }
                        unset($fileMap[$sKey]);
                    }
                } else {
                    $urlField = trim((string)($_POST[$sKey] ?? ''));
                    if ($urlField !== '') {
                        $value = $urlField;
                    } else {
                        $current = $isFloor ? (string)($currentFloors[$floorType] ?? '') : $currentBg;
                        // 页面不再提供 URL 输入框：未选择新文件时保留当前配置，勾选清除才移除。
                        $value = $current;
                    }
                }
                if ($isFloor) { if ($value !== '') $textures[$floorType] = $value; }
                else { $bgUrl = $value; }
            }
        }
        if ($uploadError === '') {
            journey_setting_set('dungeon_appearance_files', $fileMap);
            journey_setting_set('dungeon_background', $bgUrl);
            journey_setting_set('dungeon_floor_textures', $textures);
            $floorColors=[];
            foreach($floorTypes as $floorType){$color=strtolower(trim((string)($_POST['color_'.$floorType]??'')));if(preg_match('/^#[0-9a-f]{6}$/',$color))$floorColors[$floorType]=$color;}
            journey_setting_set('dungeon_floor_colors', $floorColors);
            $message = count($uploads) > 0 ? '图片上传成功，地牢外观设置已保存' : '地牢外观设置已保存';
            $messageType = 'success';
        } else {
            $message = $uploadError;
            $messageType = 'error';
        }
    }
}

// 获取数据
$players = journey_store_get('game_players', []);
$dungeonBg = journey_setting_get('dungeon_background', '');
$dungeonFloorTex = journey_setting_get('dungeon_floor_textures', []) ?: [];
$dungeonFloorColors = journey_setting_get('dungeon_floor_colors', []) ?: [];
$onlinePlayers = [];
$now = time();
if (isset($players['main_world']) && is_array($players['main_world'])) {
    foreach ($players['main_world'] as $pid => $p) {
        if (isset($p['lastSeen']) && $now - $p['lastSeen'] <= 30) {
            $onlinePlayers[] = $p;
        }
    }
}

$chat = journey_store_get('game_chat', []);
$recentChat = array_slice(array_reverse($chat['main_world'] ?? []), 0, 20);

$drops = journey_store_get('game_drops', []);
$dropCount = count($drops['main_world'] ?? []);

// 获取所有用户
$allUsers = getUsers();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>游戏管理后台 - 旅途冒险家新区</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Microsoft YaHei', -apple-system, sans-serif;
            background: #0f0f25;
            color: #e0e0ff;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 {
            color: #4ecdc4;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #4ecdc4;
        }
        .nav { margin-bottom: 20px; }
        .nav a {
            color: #4ecdc4;
            text-decoration: none;
            margin-right: 20px;
        }
        .nav a:hover { text-decoration: underline; }
        .card {
            background: #1a1a35;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #2a2a50;
        }
        .card h2 {
            color: #ffd93d;
            margin-bottom: 15px;
            font-size: 18px;
        }
        .message {
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .message.success { background: #1a4a3a; color: #6ee7b7; border: 1px solid #4ecdc4; }
        .message.error { background: #4a1a1a; color: #ff6b6b; border: 1px solid #ff6b6b; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; color: #aaa; }
        input, textarea, select {
            width: 100%;
            padding: 10px;
            background: #0f0f25;
            border: 1px solid #3a3a60;
            border-radius: 4px;
            color: #fff;
            font-size: 14px;
        }
        textarea { min-height: 80px; resize: vertical; }
        button {
            background: #4ecdc4;
            color: #000;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }
        button:hover { background: #6ee7de; }
        button.danger { background: #ff6b6b; color: #fff; }
        button.danger:hover { background: #ff8888; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 768px) { .grid { grid-template-columns: 1fr; } }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #2a2a50;
        }
        th { color: #4ecdc4; }
        .online-count {
            font-size: 24px;
            color: #6ee7b7;
            font-weight: bold;
        }
        .chat-msg {
            padding: 8px 0;
            border-bottom: 1px solid #2a2a50;
        }
        .chat-msg.system { color: #ffd93d; }
        .chat-msg .name { font-weight: bold; margin-right: 8px; }
        .chat-msg .time { color: #666; font-size: 12px; float: right; }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚔️ 旅途冒险家新区 - 管理后台</h1>
        <div class="nav">
            <a href="index.html">← 返回网站</a>
            <a href="bagdemo.html">背包管理</a>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="grid">
            <div class="card">
                <h2>📊 服务器状态</h2>
                <p>在线玩家: <span class="online-count"><?= count($onlinePlayers) ?></span></p>
                <p>世界掉落物: <?= $dropCount ?> 个</p>
                <form method="POST" style="margin-top:15px;display:inline;">
                    <input type="hidden" name="action" value="clear_drops">
                    <button type="submit" class="danger">清理所有掉落物</button>
                </form>
            </div>

            <div class="card">
                <h2>📢 发送系统公告</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="send_announcement">
                    <div class="form-group">
                        <label>公告内容</label>
                        <textarea name="content" placeholder="输入公告内容..." required></textarea>
                    </div>
                    <button type="submit">发送公告</button>
                </form>
            </div>
        </div>

        <div class="card">
            <h2>👥 在线玩家 (<?= count($onlinePlayers) ?>)</h2>
            <?php if (empty($onlinePlayers)): ?>
                <p style="color:#888;">当前没有玩家在线</p>
            <?php else: ?>
                <table>
                    <tr>
                        <th>玩家ID</th>
                        <th>名字</th>
                        <th>位置</th>
                        <th>等级</th>
                        <th>操作</th>
                    </tr>
                    <?php foreach ($onlinePlayers as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['id'] ?? '') ?></td>
                            <td><?= htmlspecialchars($p['name'] ?? '玩家') ?></td>
                            <td>(<?= round($p['x'] ?? 0) ?>, <?= round($p['y'] ?? 0) ?>)</td>
                            <td>Lv.<?= (int)($p['level'] ?? 1) ?></td>
                            <td>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('确定要踢出该玩家吗？')">
                                    <input type="hidden" name="action" value="kick_player">
                                    <input type="hidden" name="userId" value="<?= htmlspecialchars($p['id'] ?? '') ?>">
                                    <button type="submit" class="danger" style="padding:5px 10px;font-size:12px;">踢出</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>🎁 发放物品</h2>
            <form method="POST">
                <input type="hidden" name="action" value="give_item">
                <div class="form-group">
                    <label>选择玩家</label>
                    <select name="userId" required>
                        <option value="">-- 选择玩家 --</option>
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?= htmlspecialchars($u['userId']) ?>">
                                <?= htmlspecialchars($u['displayName'] ?? $u['username'] ?? $u['userId']) ?> (ID: <?= htmlspecialchars($u['userId']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>物品ID</label>
                    <input type="text" name="itemId" placeholder="例如: wood, stone, apple" required>
                </div>
                <div class="form-group">
                    <label>数量</label>
                    <input type="number" name="count" value="1" min="1" max="99">
                </div>
                <button type="submit">发放物品</button>
            </form>
        </div>

        <div class="card">
            <h2>💬 最近聊天消息</h2>
            <?php if (empty($recentChat)): ?>
                <p style="color:#888;">暂无消息</p>
            <?php else: ?>
                <?php foreach ($recentChat as $msg): ?>
                    <div class="chat-msg <?= !empty($msg['system']) ? 'system' : '' ?>">
                        <span class="time"><?= date('H:i:s', $msg['time'] ?? time()) ?></span>
                        <span class="name"><?= htmlspecialchars($msg['name'] ?? '未知') ?>:</span>
                        <?= htmlspecialchars($msg['content'] ?? '') ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>🎨 地牢外观设置</h2>
            <p style="color:#888;margin-bottom:15px;font-size:13px;">设置地牢背景图和各房间地板纹理：直接<strong style="color:#ffd93d;">选择本地图片上传</strong>（JPG/PNG/WebP/GIF，单张不超过 5MB），不需要填写 URL。</p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_dungeon_appearance">
                <?php
                // 渲染单个外观字段：预览缩略图 + 上传按钮 + 清除勾选
                $renderAppearanceField = function(string $fieldKey, string $label, string $hint, string $currentValue) {
                    $isUploaded = (bool)preg_match('#(?:^|/)image\.php\?key=[a-z_]+&v=[a-f0-9]{16}$#', $currentValue);
                    ?>
                    <div class="form-group">
                        <label><?= $label ?></label>
                        <div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap;">
                            <?php if ($currentValue !== ''): ?>
                            <img src="<?= htmlspecialchars($currentValue) ?>" alt="预览" onerror="this.style.display='none'" style="width:56px;height:56px;object-fit:cover;border:1px solid #3a3a5a;border-radius:4px;flex:none;background:#111;">
                            <?php else: ?>
                            <span style="width:56px;height:56px;display:inline-flex;align-items:center;justify-content:center;border:1px dashed #33335a;border-radius:4px;flex:none;color:#555;font-size:11px;">默认</span>
                            <?php endif; ?>
                            <div style="flex:1;min-width:240px;">
                                <input type="file" name="upload_<?= $fieldKey ?>" accept="image/png,image/jpeg,image/webp,image/gif" style="color:#aaa;font-size:12px;width:100%;">
                                <?php if ($isUploaded): ?>
                                <label style="display:flex;gap:6px;align-items:center;color:#ff9b9b;font-size:12px;margin-top:6px;cursor:pointer;">
                                    <input type="checkbox" name="clear_<?= $fieldKey ?>" value="1"> 清除已上传的图片，恢复默认
                                </label>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($hint !== ''): ?><small style="color:#666;"><?= $hint ?></small><?php endif; ?>
                    </div>
                    <?php
                };
                $renderAppearanceField('dungeon_background', '地牢整体背景图', '显示在地牢瓦片下方，55% 透明度叠加黑色遮罩。留空为纯黑背景。', (string)$dungeonBg);
                ?>
                <div style="margin:20px 0 10px;border-top:1px solid #2a2a50;padding-top:15px;">
                    <strong style="color:#ffd93d;">各房间地板纹理</strong>
                    <p style="color:#888;font-size:12px;margin:5px 0 15px;">每个房间类型可单独设置地板图片，40×40 像素无缝纹理效果最佳。</p>
                </div>
                <?php
                $roomTypes = [
                    'spawn'   => '🏠 出生房间',
                    'chest'   => '📦 宝箱房间',
                    'merchant'=> '🛒 商人房间',
                    'camp'    => '🔥 营火房间',
                    'shrine'  => '✨ 祭坛房间',
                    'normal'  => '⚔️ 普通怪物房间',
                    'elite'   => '💀 精英怪物房间',
                    'boss'    => '👑 Boss 房间',
                    'town'    => '🏘️ 主城',
                    'bridge'  => '🌉 走廊/桥',
                ];
                foreach ($roomTypes as $key => $label):
                    $renderAppearanceField('floor_' . $key, $label, '', (string)($dungeonFloorTex[$key] ?? ''));
                    $color=(string)($dungeonFloorColors[$key]??'#292a22');
                    ?>
                    <div style="display:flex;align-items:center;gap:10px;margin:-8px 0 18px 68px;color:#aaa;font-size:12px;">
                        <label for="color_<?= $key ?>" style="margin:0;">未使用图片时的地板颜色</label>
                        <input id="color_<?= $key ?>" type="color" name="color_<?= $key ?>" value="<?= htmlspecialchars($color) ?>" style="width:52px;height:32px;padding:2px;cursor:pointer;">
                        <code><?= htmlspecialchars($color) ?></code>
                    </div>
                    <?php
                endforeach;
                ?>
                <button type="submit" style="margin-top:15px;">💾 保存外观设置</button>
            </form>
        </div>
    </div>
</body>
</html>
