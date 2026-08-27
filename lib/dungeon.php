<?php

function journey_dungeon_items(): array
{
    static $items = null;
    if ($items !== null) return $items;
    $qualityMap = ['common' => ['普通', 'common'], 'fine' => ['精良', 'uncommon'], 'rare' => ['稀有', 'rare'], 'epic' => ['史诗', 'epic'], 'legendary' => ['传说', 'legendary']];
    $raw = [
        ['old_sword','旧制短剑','†','common','地牢近战武器'], ['hunter_knife','猎人短刀','⌁','fine','地牢近战武器'],
        ['ash_spear','灰烬长枪','↟','fine','地牢近战武器'], ['watcher_axe','看守者战斧','⚒','rare','地牢近战武器'],
        ['clock_crossbow','发条弩','➶','rare','地牢远程武器'], ['echo_blade','回声刃','〆','epic','地牢近战武器'],
        ['ember_staff','余烬法杖','✦','epic','地牢远程武器'], ['nameless_relic','无名者遗器','∞','legendary','地牢近战武器'],
        ['iron_mace','生铁钉锤','⚒','common','地牢近战武器'], ['guard_sabre','卫兵弯刀','⌁','fine','地牢近战武器'],
        ['bone_scythe','白骨战镰','☾','rare','地牢近战武器'], ['violet_halberd','紫晶长戟','ψ','epic','地牢近战武器'],
        ['king_breaker','破王巨剑','‡','legendary','地牢近战武器'], ['short_bow','榆木短弓','➹','common','地牢远程武器'],
        ['long_bow','巡林长弓','➹','fine','地牢远程武器'], ['heavy_crossbow','重型攻城弩','➶','rare','地牢远程武器'],
        ['rust_pistol','锈蚀手铳','⌐','common','地牢远程武器'], ['warden_rifle','看守者步枪','⌐','rare','地牢远程武器'],
        ['scatter_gun','碎岩霰铳','≋','epic','地牢远程武器'], ['laser_gun','脉冲激光枪','⚡','epic','地牢远程武器'], ['frost_wand','霜纹法杖','✧','fine','地牢远程武器'],
        ['storm_orb','风暴法球','◉','rare','地牢远程武器'], ['void_scepter','虚空权杖','♜','epic','地牢远程武器'],
        ['star_cannon','星坠魔炮','✺','legendary','地牢远程武器'],
        ['bandage','旧绷带','▧','common','地牢消耗品'], ['torch','松脂火把','♨','common','地牢道具'],
        ['iron_scrap','生铁零件','⚙','common','地牢材料'], ['monster_fang','穴兽尖牙','⌁','common','地牢材料'],
        ['moss','荧光苔藓','♣','common','地牢材料'], ['smoke_bomb','烟雾弹','●','fine','地牢消耗品'],
        ['holy_water','净化圣水','♢','fine','地牢消耗品'], ['lockpick','精制撬锁器','⌘','fine','地牢道具'],
        ['amber','凝火琥珀','◆','fine','地牢材料'], ['hunter_badge','猎手徽章','✪','fine','地牢材料'],
        ['moon_shard','月蚀碎片','☽','rare','地牢材料'], ['royal_coin','失落王币','¤','rare','地牢材料'],
        ['dragon_scale','幼龙鳞片','◈','rare','地牢材料'], ['void_eye','虚空之眼','◉','epic','地牢材料'],
        ['crown_fragment','破碎王冠','♛','legendary','地牢材料'],
        ['dungeon_potion','地牢药剂','✚','fine','地牢消耗品'], ['brass_key','黄铜钥匙','⌘','rare','地牢道具'],
        ['relic_shard','遗迹碎片','◇','epic','地牢材料'], ['arrow_bundle','箭矢','➹','common','地牢弹药'],
        ['bolt_bundle','弩矢','➶','fine','地牢弹药'], ['bullet_bundle','弹丸','•','fine','地牢弹药'],
        ['mana_charge','魔力结晶','✦','rare','地牢弹药'], ['modern_ammo','现代弹药','▪','common','枪械专用弹药'], ['ammo_bundle','通用弹药','▰','common','旧版通用弹药']
    ];
    $newTools = [
        '便携火种','折叠铁铲','猎人捕兽夹','荧光信标','简易绳索','旧式罗盘','矿工提灯','止血钳','开锁针组','怪物诱饵',
        '静音软靴','防毒面罩','侦察望远镜','驱邪铃铛','爆破雷管','符文粉笔','自动地图仪','便携锻造锤','炼金蒸馏器','灵魂捕捉笼',
        '隐匿斗篷','空间定位锚','护身骨符','时间沙漏','回城卷轴','宝箱探测器','虚空照明灯','王室万能钥匙','命运改写骰','深渊逃生索'
    ];
    $newPotions = [
        '微型治疗药水','耐力药水','解毒药水','石肤药水','夜视药水','迅捷药水','火焰抗性药水','寒霜抗性药水','雷电抗性药水','专注药水',
        '再生药水','狂战药水','隐身药水','幸运药水','净化药水','巨人力量药水','凤凰药水','时间缓滞药水','虚空免疫药水','不朽者秘药'
    ];
    $newWeapons = [
        '缺口匕首','民兵短剑','橡木棍棒','矿工手斧','生锈长枪','双刃砍刀','铁卫长剑','狼牙锤','巡林弯刀','黑铁战镐',
        '月牙双刀','猩红刺剑','雷鸣战锤','寒霜长枪','处刑巨斧','幽灵镰刀','龙骨大剑','圣堂骑枪','深渊链刃','王权裁决剑',
        '木制短弓','猎人反曲弓','袖珍手弩','旧式火枪','双管猎枪','连发弩机','游侠长弓','燧发手炮','毒针吹管','符文弹弓',
        '风暴复合弓','破甲重弩','炼金喷火器','冰晶步枪','雷弧手铳','暗影狙击弩','龙息霰铳','星辉火枪','虚空连弩','审判魔炮',
        '学徒法杖','余火魔杖','冰锥权杖','毒沼法典','雷电魔导书','召魂骨杖','日蚀法球','群星权杖','深渊咏唱书','创世者法杖'
    ];
    $newQuality = static function(int $index, int $total): string {
        $ratio = ($index + 1) / max(1, $total);
        if ($ratio <= 0.40) return 'common';
        if ($ratio <= 0.70) return 'fine';
        if ($ratio <= 0.88) return 'rare';
        if ($ratio <= 0.98) return 'epic';
        return 'legendary';
    };
    foreach ($newTools as $index => $name) {
        $raw[] = ['tool_' . str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT), $name, '◆', $newQuality($index, count($newTools)), '地牢道具'];
    }
    foreach ($newPotions as $index => $name) {
        $raw[] = ['potion_' . str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT), $name, '✚', $newQuality($index, count($newPotions)), '地牢药水'];
    }
    foreach ($newWeapons as $index => $name) {
        $type = $index < 20 ? '地牢近战武器' : ($index < 40 ? '地牢远程武器' : '地牢魔法武器');
        $icon = $index < 20 ? '†' : ($index < 40 ? '➶' : '✦');
        $raw[] = ['weapon_' . str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT), $name, $icon, $newQuality($index, count($newWeapons)), $type];
    }
    $newArmor = [
        ['leather_cap','旧皮兜帽','头部','common',12], ['iron_helmet','生铁头盔','头部','fine',22], ['watcher_helm','看守者面甲','头部','rare',36], ['void_crown','虚空冠冕','头部','epic',54], ['king_guard_helm','王卫战盔','头部','legendary',80],
        ['patched_vest','补丁皮甲','胸甲','common',24], ['chain_mail','锁子胸甲','胸甲','fine',42], ['warden_plate','守望者板甲','胸甲','rare',66], ['dragon_breastplate','幼龙胸铠','胸甲','epic',96], ['abyss_carapace','深渊甲壳','胸甲','legendary',140],
        ['cloth_wraps','粗布护手','护手','common',10], ['iron_gauntlets','铸铁护手','护手','fine',18], ['hunter_grips','猎手臂铠','护手','rare',30], ['storm_gauntlets','风暴拳甲','护手','epic',46], ['titan_fists','泰坦铁拳','护手','legendary',70],
        ['leather_greaves','旧皮护腿','腿甲','common',16], ['guard_greaves','卫兵护腿','腿甲','fine',28], ['frost_legguards','霜纹腿铠','腿甲','rare',44], ['shadow_stride','暗影行胫','腿甲','epic',64], ['immortal_greaves','不朽腿铠','腿甲','legendary',96]
    ];
    $armorSlotKeys = ['头部'=>'head','胸甲'=>'chest','护手'=>'hands','腿甲'=>'legs'];
    foreach ($newArmor as [$id,$name,$slotLabel,$quality,$armorValue]) {
        $raw[] = ['armor_' . $id, $name, '▦', $quality, '地牢护甲', $armorSlotKeys[$slotLabel], $armorValue, $slotLabel];
    }
    $expansionWeapons = [
        '墓穴割喉刀','佣兵破甲剑','疫病骨锤','银月长戟','熔岩斩首斧',
        '荒原猎弓','黄铜转轮枪','毒雾连发弩','极寒射线枪','黑曜石火炮',
        '荆棘魔杖','潮汐法球','血月法典','天穹权杖','终焉咏唱杖'
    ];
    $firearms = [
        ['短管手枪','⌐','common'], ['警用左轮','⌐','common'], ['冲锋手枪','⌐','fine'], ['泵动霰弹枪','≋','fine'],
        ['双管猎枪','≋','fine'], ['战术冲锋枪','⌐','rare'], ['突击步枪','⌐','rare'], ['卡宾步枪','⌐','rare'],
        ['精确射手步枪','⌐','rare'], ['重型机枪','⌐','epic'], ['爆裂冲锋枪','⌐','epic'], ['燃烧喷射枪','♨','epic'],
        ['电弧步枪','⚡','epic'], ['穿甲狙击枪','⌐','epic'], ['龙息霰弹枪','≋','legendary'], ['磁轨手炮','⚡','legendary'],
        ['等离子步枪','⚡','legendary'], ['虚空加农炮','✺','legendary'], ['天穹重炮','✺','legendary'], ['终焉光束枪','⚡','legendary']
    ];
    foreach ($firearms as $index => $gun) {
        $raw[] = ['gun_' . str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT), $gun[0], $gun[1], $gun[2], '地牢枪械'];
    }
    foreach ($expansionWeapons as $index=>$name) {
        $type=$index<5?'地牢近战武器':($index<10?'地牢远程武器':'地牢魔法武器');
        $icon=$index<5?'†':($index<10?'➶':'✦');
        $raw[]=['weapon_'.str_pad((string)($index+51),3,'0',STR_PAD_LEFT),$name,$icon,$newQuality($index,count($expansionWeapons)),$type];
    }
    $expansionTools = ['幽火火折','便携路障','震荡地雷','静默护符','怪物气味瓶','魔力测距仪','秘门显形镜','自动绷带机','亡者通行证','深层撤离信标'];
    foreach($expansionTools as $index=>$name)$raw[]=['tool_'.str_pad((string)($index+31),3,'0',STR_PAD_LEFT),$name,'◆',$newQuality($index,count($expansionTools)),'地牢道具'];
    $expansionPotions = ['小型护甲药剂','止痛药水','鹰眼药水','荆棘反伤药水','吸血药水','魔力沸腾药水','幽灵步药水','龙血药水','命运逆转药水','深渊祝福药水'];
    foreach($expansionPotions as $index=>$name)$raw[]=['potion_'.str_pad((string)($index+21),3,'0',STR_PAD_LEFT),$name,'✚',$newQuality($index,count($expansionPotions)),'地牢药水'];
    $expansionArmor = [
        ['plague_mask','疫医面具','头部','fine',26],['moon_guard_helm','月卫头盔','头部','rare',40],['celestial_crown','天穹冠冕','头部','legendary',88],
        ['mercenary_coat','佣兵重衣','胸甲','common',30],['obsidian_plate','黑曜石板甲','胸甲','rare',74],['sun_guard_plate','日耀圣铠','胸甲','epic',108],['world_heart_armor','世界之心甲','胸甲','legendary',156],
        ['alchemist_gloves','炼金术手套','护手','common',14],['executioner_gauntlets','处刑者臂甲','护手','rare',34],['void_touch_gauntlets','虚空之触','护手','epic',52],['god_hand','古神之手','护手','legendary',76],
        ['scout_boots','斥候长靴','腿甲','common',20],['thorn_greaves','荆棘腿甲','腿甲','fine',34],['thunder_stride','雷霆行胫','腿甲','epic',72],['starwalker_greaves','踏星腿铠','腿甲','legendary',104]
    ];
    foreach($expansionArmor as [$id,$name,$slotLabel,$quality,$armorValue])$raw[]=['armor_'.$id,$name,'▦',$quality,'地牢护甲',$armorSlotKeys[$slotLabel],$armorValue,$slotLabel];
    $items = [];
    foreach ($raw as $row) {
        [$id,$name,$icon,$quality,$type,$armorSlot,$armorValue,$armorSlotLabel] = array_pad($row, 8, null);
        [$qualityLabel,$officialQuality] = $qualityMap[$quality];
        $definition = [
            'id' => 'd_' . $id, 'name' => '[D] ' . $name, 'displayName' => '[D] ' . $name,
            'icon' => $icon, 'quality' => $officialQuality, 'qualityLabel' => $qualityLabel,
            'type' => $type, 'desc' => '可从官网背包带入地牢并实际使用的云端物品。',
            'tags' => ['D'], 'dungeonUsable' => true,
            'recyclable' => false,
            'marketTradable' => true,
            'props' => ['特殊标签' => '[D]', '用途' => '地牢可用', '云端同步' => '是', '分类' => $type]
        ];
        if ($armorSlot !== null) {
            $definition['armorSlot'] = $armorSlot;
            $definition['armorValue'] = (int)$armorValue;
            $definition['props']['穿戴部位'] = (string)$armorSlotLabel;
            $definition['props']['护甲值'] = (string)$armorValue;
        }
        $items[$id] = $definition;
    }
    return $items;
}

function journey_dungeon_item_definition(string $itemId): ?array
{
    if (strpos($itemId, 'd_') !== 0) return null;
    return journey_dungeon_items()[substr($itemId, 2)] ?? null;
}

function journey_dungeon_install_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    $queries = [
        "CREATE TABLE IF NOT EXISTS dungeon_player_state (user_id VARCHAR(32) PRIMARY KEY, scene VARCHAR(16) NOT NULL DEFAULT 'town', pos_x INTEGER NOT NULL DEFAULT 380, pos_y INTEGER NOT NULL DEFAULT 240, floor_no INTEGER NOT NULL DEFAULT 1, wing_coins INTEGER NOT NULL DEFAULT 0, hp INTEGER NOT NULL DEFAULT 100, equipped_item_id VARCHAR(100), updated_at VARCHAR(32) NOT NULL)",
        "CREATE TABLE IF NOT EXISTS dungeon_warehouses (user_id VARCHAR(32) NOT NULL, warehouse_no INTEGER NOT NULL, purchased_at VARCHAR(32) NOT NULL, PRIMARY KEY (user_id, warehouse_no))",
        "CREATE TABLE IF NOT EXISTS dungeon_warehouse_slots (user_id VARCHAR(32) NOT NULL, warehouse_no INTEGER NOT NULL, slot_index INTEGER NOT NULL, item_id VARCHAR(100) NOT NULL, item_count INTEGER NOT NULL DEFAULT 1, custom_name VARCHAR(100), created_at VARCHAR(32) NOT NULL, PRIMARY KEY (user_id, warehouse_no, slot_index))",
        "CREATE TABLE IF NOT EXISTS dungeon_player_stats (user_id VARCHAR(32) PRIMARY KEY, total_kills INTEGER NOT NULL DEFAULT 0, total_deaths INTEGER NOT NULL DEFAULT 0, dungeon_entries INTEGER NOT NULL DEFAULT 0, total_floors INTEGER NOT NULL DEFAULT 0, updated_at VARCHAR(32) NOT NULL)",
        "CREATE TABLE IF NOT EXISTS dungeon_equipment (user_id VARCHAR(32) NOT NULL, equipment_slot VARCHAR(16) NOT NULL, item_id VARCHAR(100) NOT NULL, armor_value INTEGER NOT NULL DEFAULT 0, max_armor INTEGER NOT NULL DEFAULT 0, updated_at VARCHAR(32) NOT NULL, PRIMARY KEY (user_id, equipment_slot))",
        "CREATE TABLE IF NOT EXISTS dungeon_armor_durability (user_id VARCHAR(32) NOT NULL, item_id VARCHAR(100) NOT NULL, armor_value INTEGER NOT NULL DEFAULT 0, max_armor INTEGER NOT NULL DEFAULT 0, updated_at VARCHAR(32) NOT NULL, PRIMARY KEY (user_id, item_id))",
        "CREATE TABLE IF NOT EXISTS dungeon_player_effects (user_id VARCHAR(32) NOT NULL, effect_id VARCHAR(32) NOT NULL, purchased_at VARCHAR(32) NOT NULL, PRIMARY KEY (user_id, effect_id))",
        "CREATE TABLE IF NOT EXISTS dungeon_effect_loadout (user_id VARCHAR(32) PRIMARY KEY, effect_id VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL)",
        "CREATE INDEX IF NOT EXISTS idx_dungeon_warehouse_owner ON dungeon_warehouse_slots(user_id, warehouse_no)"
    ];
    foreach ($queries as $query) {
        try { $pdo->exec($query); } catch (Throwable $e) {
            if (strpos($query, 'CREATE INDEX IF NOT EXISTS') !== false && journey_db_driver($pdo) === 'mysql') {
                try { $pdo->exec('CREATE INDEX idx_dungeon_warehouse_owner ON dungeon_warehouse_slots(user_id, warehouse_no)'); } catch (Throwable $ignored) {}
            } else throw $e;
        }
    }
    $ready = true;
}

function journey_dungeon_ensure_player(PDO $pdo, string $userId): void
{
    journey_dungeon_install_schema($pdo);
    $check = $pdo->prepare('SELECT user_id FROM dungeon_player_state WHERE user_id = ?');
    $check->execute([$userId]);
    if (!$check->fetchColumn()) {
        $pdo->prepare("INSERT INTO dungeon_player_state (user_id, scene, pos_x, pos_y, floor_no, wing_coins, hp, updated_at) VALUES (?, 'town', 380, 240, 1, 0, 100, ?)")->execute([$userId, date('Y-m-d H:i:s')]);
    }
    $warehouse = $pdo->prepare('SELECT warehouse_no FROM dungeon_warehouses WHERE user_id = ? AND warehouse_no = 1');
    $warehouse->execute([$userId]);
    if (!$warehouse->fetchColumn()) $pdo->prepare('INSERT INTO dungeon_warehouses (user_id, warehouse_no, purchased_at) VALUES (?, 1, ?)')->execute([$userId, date('Y-m-d H:i:s')]);
    $stats = $pdo->prepare('SELECT user_id FROM dungeon_player_stats WHERE user_id = ?');
    $stats->execute([$userId]);
    if (!$stats->fetchColumn()) $pdo->prepare('INSERT INTO dungeon_player_stats (user_id, total_kills, total_deaths, dungeon_entries, total_floors, updated_at) VALUES (?, 0, 0, 0, 0, ?)')->execute([$userId, date('Y-m-d H:i:s')]);
}

function journey_dungeon_increment_stat(PDO $pdo, string $userId, string $field, int $amount = 1): void
{
    if (!in_array($field, ['total_kills','total_deaths','dungeon_entries','total_floors'], true) || $amount <= 0) return;
    journey_dungeon_ensure_player($pdo, $userId);
    $pdo->prepare("UPDATE dungeon_player_stats SET {$field} = {$field} + ?, updated_at = ? WHERE user_id = ?")->execute([$amount,date('Y-m-d H:i:s'),$userId]);
    if(function_exists('recordDailyTaskAction')){
        $events=['total_kills'=>'dungeon_kill','dungeon_entries'=>'dungeon_entry','total_floors'=>'dungeon_floor'];
        if(isset($events[$field]))recordDailyTaskAction($userId,$events[$field],$field.':'.microtime(true).':'.bin2hex(random_bytes(3)),$amount);
    }
}

function journey_dungeon_stats_map(PDO $pdo): array
{
    journey_dungeon_install_schema($pdo);
    $rows = $pdo->query('SELECT user_id, total_kills, total_deaths, dungeon_entries, total_floors FROM dungeon_player_stats')->fetchAll(PDO::FETCH_ASSOC);
    $map=[];
    foreach($rows as $row)$map[(string)$row['user_id']]=['total_kills'=>(int)$row['total_kills'],'total_deaths'=>(int)$row['total_deaths'],'dungeon_entries'=>(int)$row['dungeon_entries'],'total_floors'=>(int)$row['total_floors']];
    return $map;
}

function journey_dungeon_payload(PDO $pdo, array $user): array
{
    $userId = (string)$user['userId'];
    journey_dungeon_ensure_player($pdo, $userId);
    $stmt = $pdo->prepare('SELECT scene, pos_x, pos_y, floor_no, wing_coins, hp, equipped_item_id, updated_at FROM dungeon_player_state WHERE user_id = ?');
    $stmt->execute([$userId]);
    $state = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stmt = $pdo->prepare('SELECT warehouse_no FROM dungeon_warehouses WHERE user_id = ? ORDER BY warehouse_no');
    $stmt->execute([$userId]);
    $warehouseNumbers = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $stmt = $pdo->prepare('SELECT warehouse_no, slot_index, item_id, item_count, custom_name, created_at FROM dungeon_warehouse_slots WHERE user_id = ? ORDER BY warehouse_no, slot_index');
    $stmt->execute([$userId]);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $inventory = normalizeInventorySlots($user['inventory'] ?? [], false);
    journey_dungeon_reconcile_equipment($pdo,$userId,$inventory);
    $stmt = $pdo->prepare('SELECT equipment_slot, item_id, armor_value, max_armor FROM dungeon_equipment WHERE user_id = ?');
    $stmt->execute([$userId]);
    $equipment = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt=$pdo->prepare('SELECT effect_id FROM dungeon_player_effects WHERE user_id=? ORDER BY purchased_at');$stmt->execute([$userId]);$ownedEffects=array_values(array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN)));
    $stmt=$pdo->prepare('SELECT effect_id FROM dungeon_effect_loadout WHERE user_id=?');$stmt->execute([$userId]);$equippedEffect=(string)($stmt->fetchColumn()?:'');
    $definitions = journey_dungeon_items();
    $definitionMap = [];
    foreach ($definitions as $definition) $definitionMap[(string)$definition['id']] = $definition;
    foreach ($inventory as $item) {
        if (!is_array($item) || empty($item['id']) || isset($definitionMap[$item['id']])) continue;
        $definitionMap[$item['id']] = itemDefinition((string)$item['id']);
    }
    return ['code'=>'ok', 'userId'=>$userId, 'username'=>(string)($user['user'] ?? ''),
        'avatar'=>$user['avatar'] ?? defaultAvatar((string)($user['user'] ?? '')),
        'title'=>selectedTitleForUser($user, levelFromXp((int)($user['xp'] ?? 0))), 'gold'=>(int)($user['gold'] ?? 0),
        'inventory'=>$inventory, 'state'=>$state, 'equipment'=>$equipment, 'ownedEffects'=>$ownedEffects, 'equippedEffect'=>$equippedEffect,
        'warehouses'=>$warehouseNumbers, 'warehouseSlots'=>$slots, 'warehouseCapacity'=>21,
        'warehouseNextPrice'=>count($warehouseNumbers) >= 5 ? null : 250 * (2 ** max(0, count($warehouseNumbers)-1)),
        'warehousePrices'=>['2'=>250,'3'=>500,'4'=>1000,'5'=>2000],
        'dungeonBackground'=>function_exists('journey_setting_get') ? (string)journey_setting_get('dungeon_background', '') : '',
        'floorTextures'=>function_exists('journey_setting_get') ? (journey_setting_get('dungeon_floor_textures', []) ?: []) : [],
        'floorColors'=>function_exists('journey_setting_get') ? (journey_setting_get('dungeon_floor_colors', []) ?: []) : [],
        'monsterConfig'=>function_exists('journey_setting_get') ? (journey_setting_get('dungeon_monsters', []) ?: []) : [],
        'items'=>array_values($definitionMap)];
}

function journey_george_effects(): array
{
    $effects = [
        'flame_trail'=>['id'=>'flame_trail','name'=>'粒子火焰拖尾','icon'=>'♨','description'=>'移动时在身后留下短暂的火焰粒子。','price'=>500],
        'heart_aura'=>['id'=>'heart_aura','name'=>'爱心特效','icon'=>'♥','description'=>'移动时周期性浮现爱心粒子。','price'=>500],
        'projectile_trail'=>['id'=>'projectile_trail','name'=>'远程弹道拖尾','icon'=>'➶','description'=>'远程武器弹丸带有明亮的金色拖尾。','price'=>500]
    ];
    $names=['青焰足迹','冰晶足迹','雷光足迹','樱花足迹','星尘足迹','暗影足迹','金砂足迹','血月足迹','翡翠足迹','虹光足迹','萤火环绕','蝶群环绕','雪花环绕','金币环绕','羽毛环绕','符文环绕','泡泡环绕','流星环绕','花瓣环绕','灵魂环绕','赤红弹道','冰蓝弹道','雷紫弹道','翠绿弹道','纯白弹道','暗金弹道','彩虹弹道','星辉弹道','熔岩弹道','虚空弹道','王冠光环','月轮光环','太阳光环','荆棘光环','机械光环','天使光环','恶魔光环','龙魂光环','深海光环','极光光环','胜利火花','治疗微光','闪避残影','攻击闪光','斩杀印记','脚步波纹','幽灵残像'];
    foreach($names as $index=>$name){$group=$index%3===0?'trail':($index%3===1?'aura':'projectile');$suffix=chr(97+intdiv($index,26)).chr(97+$index%26);$id=$group.'_'.$suffix;$effects[$id]=['id'=>$id,'name'=>$name,'icon'=>$group==='trail'?'✦':($group==='aura'?'◉':'➶'),'description'=>'黑暗地牢永久外观特效，可随时装备或卸下。','price'=>500];}
    return $effects;
}

function journey_george_payload(PDO $pdo, string $userId): array
{
    journey_dungeon_ensure_player($pdo,$userId);
    $balance=$pdo->prepare('SELECT wing_coins FROM dungeon_player_state WHERE user_id=?');$balance->execute([$userId]);
    $owned=$pdo->prepare('SELECT effect_id FROM dungeon_player_effects WHERE user_id=?');$owned->execute([$userId]);$ownedIds=array_map('strval',$owned->fetchAll(PDO::FETCH_COLUMN));
    $equipped=$pdo->prepare('SELECT effect_id FROM dungeon_effect_loadout WHERE user_id=?');$equipped->execute([$userId]);$equippedId=(string)($equipped->fetchColumn()?:'');
    $offers=[];$slot=0;foreach(journey_george_effects() as $effect){$offers[]=['offerId'=>$effect['id'],'effectId'=>$effect['id'],'slot'=>$slot++,'quality'=>'epic','item'=>['id'=>$effect['id'],'name'=>$effect['name'],'icon'=>$effect['icon'],'desc'=>$effect['description']],'price'=>$effect['price'],'purchased'=>in_array($effect['id'],$ownedIds,true),'equipped'=>$equippedId===$effect['id']];}
    $contact=function_exists('georgeContactProfile')?georgeContactProfile():['id'=>'george','name'=>'乔治','title'=>'特效收藏家','description'=>'我不卖力量，只卖让你的旅程更有辨识度的光。','avatar'=>['type'=>'initial','text'=>'乔','color'=>'#9a6138']];
    return ['code'=>'ok','transactionType'=>'effect','date'=>date('Y-m-d'),'contact'=>$contact,'offers'=>$offers,'purchasedCount'=>count($ownedIds),'wingCoins'=>(int)$balance->fetchColumn(),'equippedEffect'=>$equippedId];
}

function journey_dungeon_reconcile_equipment(PDO $pdo, string $userId, array $inventory): void
{
    $owned=[];
    foreach(normalizeInventorySlots($inventory,false) as $item)if(is_array($item)&&!empty($item['id']))$owned[(string)$item['id']]=true;
    $stmt=$pdo->prepare('SELECT equipment_slot,item_id FROM dungeon_equipment WHERE user_id=?');$stmt->execute([$userId]);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){if(!isset($owned[(string)$row['item_id']]))$pdo->prepare('DELETE FROM dungeon_equipment WHERE user_id=? AND equipment_slot=?')->execute([$userId,$row['equipment_slot']]);}
}

function journey_handle_dungeon_action(string $action, string $userId): bool
{
    $actions = ['getDungeonState','getDungeonOnline','saveDungeonState','equipDungeonArmor','unequipDungeonArmor','repairDungeonArmor','exchangeDungeonCurrency','buyDungeonWarehouse','moveDungeonStorage','grantDungeonItem','discardDungeonItem','clearDungeonCarriedItems','consumeDungeonItem','synthesizeDungeonItems','recordDungeonKill','getGeorgeEffects','buyGeorgeEffect','equipGeorgeEffect','unequipGeorgeEffect'];
    if (!in_array($action, $actions, true)) return false;
    $pdo = journey_db();
    $user = journey_find_user($userId);
    if (!$user) { echo json_encode(['code'=>'user_not_found'], JSON_UNESCAPED_UNICODE); return true; }
    ensureEconomyFields($user);
    journey_dungeon_ensure_player($pdo, $userId);

    if ($action === 'getDungeonOnline') {
        $threshold = date('Y-m-d H:i:s', time() - 15);
        $statement = $pdo->prepare('SELECT COUNT(DISTINCT s.user_id) FROM dungeon_player_state s WHERE s.updated_at >= ?');
        $statement->execute([$threshold]);
        $online = (int)$statement->fetchColumn();
        // 获取在线玩家名字和所在层数
        $listStmt = $pdo->prepare('SELECT u.username, s.scene, s.floor_no FROM dungeon_player_state s JOIN users u ON u.user_id = s.user_id WHERE s.updated_at >= ? ORDER BY s.floor_no DESC');
        $listStmt->execute([$threshold]);
        $players = [];
        foreach ($listStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $players[] = [
                'name' => (string)($row['username'] ?? '未知旅人'),
                'floor' => (int)($row['floor_no'] ?? 1),
                'scene' => (string)($row['scene'] ?? 'town')
            ];
        }
        echo json_encode(['code'=>'ok', 'online'=>$online, 'players'=>$players], JSON_UNESCAPED_UNICODE);
        return true;
    }

    if ($action === 'getDungeonState') {
        $pdo->prepare('UPDATE dungeon_player_state SET updated_at=? WHERE user_id=?')->execute([date('Y-m-d H:i:s'), $userId]);
        echo json_encode(journey_dungeon_payload($pdo, $user), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); return true;
    }
    if($action==='getGeorgeEffects'){echo json_encode(journey_george_payload($pdo,$userId),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return true;}
    if($action==='unequipGeorgeEffect'){$pdo->prepare('DELETE FROM dungeon_effect_loadout WHERE user_id=?')->execute([$userId]);echo json_encode(journey_george_payload($pdo,$userId),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return true;}
    if($action==='buyGeorgeEffect'||$action==='equipGeorgeEffect'){
        $effectId=preg_replace('/[^a-z_]/','',(string)($_POST['effectId']??''));$effects=journey_george_effects();
        if(!isset($effects[$effectId])){echo json_encode(['code'=>'invalid_effect']);return true;}
        $started=false;
        try{
            if(!$pdo->inTransaction()){$pdo->beginTransaction();$started=true;}
            $lock=$pdo->prepare('SELECT wing_coins FROM dungeon_player_state WHERE user_id=?'.(journey_db_driver($pdo)==='mysql'?' FOR UPDATE':''));$lock->execute([$userId]);$wingCoins=(int)$lock->fetchColumn();
            $owned=$pdo->prepare('SELECT 1 FROM dungeon_player_effects WHERE user_id=? AND effect_id=?');$owned->execute([$userId,$effectId]);$hasEffect=(bool)$owned->fetchColumn();
            if($action==='buyGeorgeEffect'&&!$hasEffect){$price=(int)$effects[$effectId]['price'];if($wingCoins<$price)throw new RuntimeException('not_enough_wing_coins');$wingCoins-=$price;$pdo->prepare('UPDATE dungeon_player_state SET wing_coins=?,updated_at=? WHERE user_id=?')->execute([$wingCoins,date('Y-m-d H:i:s'),$userId]);$pdo->prepare('INSERT INTO dungeon_player_effects (user_id,effect_id,purchased_at) VALUES (?,?,?)')->execute([$userId,$effectId,date('Y-m-d H:i:s')]);$hasEffect=true;}
            if(!$hasEffect)throw new RuntimeException('not_owned');
            $now=date('Y-m-d H:i:s');if(journey_db_driver($pdo)==='mysql')$pdo->prepare('INSERT INTO dungeon_effect_loadout (user_id,effect_id,updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE effect_id=VALUES(effect_id),updated_at=VALUES(updated_at)')->execute([$userId,$effectId,$now]);else $pdo->prepare('INSERT INTO dungeon_effect_loadout (user_id,effect_id,updated_at) VALUES (?,?,?) ON CONFLICT(user_id) DO UPDATE SET effect_id=excluded.effect_id,updated_at=excluded.updated_at')->execute([$userId,$effectId,$now]);
            if($started)$pdo->commit();echo json_encode(journey_george_payload($pdo,$userId),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return true;
        }catch(Throwable $e){if($started&&$pdo->inTransaction())$pdo->rollBack();$code=$e->getMessage();if(!in_array($code,['not_enough_wing_coins','not_owned'],true))$code='save_failed';echo json_encode(['code'=>$code],JSON_UNESCAPED_UNICODE);return true;}
    }
    if ($action === 'saveDungeonState') {
        $scene = (string)($_POST['scene'] ?? 'town');
        if (!in_array($scene, ['town','dungeon'], true)) $scene = 'town';
        $x = max(20, min(2700, (int)($_POST['x'] ?? 380))); $y = max(20, min(1900, (int)($_POST['y'] ?? 240)));
        $floor = max(1, min(999, (int)($_POST['floor'] ?? 1))); $hp = max(0, min(9999, (int)($_POST['hp'] ?? 100)));
        $wingCoins = max(0, min(100000000, (int)($_POST['wingCoins'] ?? 0)));
        $equippedItemId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_POST['equippedItemId'] ?? ''));
        $previous=$pdo->prepare('SELECT scene,floor_no FROM dungeon_player_state WHERE user_id=?');$previous->execute([$userId]);$before=$previous->fetch(PDO::FETCH_ASSOC)?:['scene'=>'town','floor_no'=>1];
        if($scene==='dungeon'&&($before['scene']??'town')!=='dungeon'){journey_dungeon_increment_stat($pdo,$userId,'dungeon_entries',1);journey_dungeon_increment_stat($pdo,$userId,'total_floors',1);}
        elseif($scene==='dungeon'&&($before['scene']??'')==='dungeon'&&$floor>(int)($before['floor_no']??1))journey_dungeon_increment_stat($pdo,$userId,'total_floors',$floor-(int)$before['floor_no']);
        $pdo->prepare('UPDATE dungeon_player_state SET scene=?, pos_x=?, pos_y=?, floor_no=?, hp=?, wing_coins=?, equipped_item_id=?, updated_at=? WHERE user_id=?')->execute([$scene,$x,$y,$floor,$hp,$wingCoins,$equippedItemId,date('Y-m-d H:i:s'),$userId]);
        foreach (['head'=>'armorHead','chest'=>'armorChest','hands'=>'armorHands','legs'=>'armorLegs'] as $slot=>$field) {
            if (!isset($_POST[$field])) continue;
            $value=max(0,min(99999,(int)$_POST[$field]));
            $pdo->prepare('UPDATE dungeon_equipment SET armor_value = CASE WHEN armor_value < ? THEN armor_value ELSE ? END, updated_at=? WHERE user_id=? AND equipment_slot=?')->execute([$value,$value,date('Y-m-d H:i:s'),$userId,$slot]);
            $pdo->prepare('UPDATE dungeon_armor_durability SET armor_value = CASE WHEN armor_value < ? THEN armor_value ELSE ? END, updated_at=? WHERE user_id=? AND item_id=(SELECT item_id FROM dungeon_equipment WHERE user_id=? AND equipment_slot=?)')->execute([$value,$value,date('Y-m-d H:i:s'),$userId,$userId,$slot]);
        }
        echo json_encode(['code'=>'ok']); return true;
    }
    if ($action === 'equipDungeonArmor') {
        $inventorySlot=(int)($_POST['inventorySlot'] ?? -1);
        if($inventorySlot<0||$inventorySlot>20){echo json_encode(['code'=>'invalid_slot']);return true;}
        $fresh=journey_find_user($userId);ensureEconomyFields($fresh);$inventory=normalizeInventorySlots($fresh['inventory']??[],false);
        $item=$inventory[$inventorySlot]??null;$definition=is_array($item)?journey_dungeon_item_definition((string)($item['id']??'')):null;
        if(!$definition||empty($definition['armorSlot'])||empty($definition['armorValue'])){echo json_encode(['code'=>'not_armor']);return true;}
        $slot=(string)$definition['armorSlot'];$maxArmor=max(1,(int)$definition['armorValue']);$now=date('Y-m-d H:i:s');
        $durability=$pdo->prepare('SELECT armor_value,max_armor FROM dungeon_armor_durability WHERE user_id=? AND item_id=?');$durability->execute([$userId,$item['id']]);$savedDurability=$durability->fetch(PDO::FETCH_ASSOC);
        $armor=$savedDurability?max(0,(int)$savedDurability['armor_value']):$maxArmor;
        if(!$savedDurability){$pdo->prepare('INSERT INTO dungeon_armor_durability (user_id,item_id,armor_value,max_armor,updated_at) VALUES (?,?,?,?,?)')->execute([$userId,$item['id'],$armor,$maxArmor,$now]);}
        if(journey_db_driver($pdo)==='mysql'){
            $pdo->prepare('INSERT INTO dungeon_equipment (user_id,equipment_slot,item_id,armor_value,max_armor,updated_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE item_id=VALUES(item_id),armor_value=VALUES(armor_value),max_armor=VALUES(max_armor),updated_at=VALUES(updated_at)')->execute([$userId,$slot,$item['id'],$armor,$maxArmor,$now]);
        }else{
            $pdo->prepare('INSERT INTO dungeon_equipment (user_id,equipment_slot,item_id,armor_value,max_armor,updated_at) VALUES (?,?,?,?,?,?) ON CONFLICT(user_id,equipment_slot) DO UPDATE SET item_id=excluded.item_id,armor_value=excluded.armor_value,max_armor=excluded.max_armor,updated_at=excluded.updated_at')->execute([$userId,$slot,$item['id'],$armor,$maxArmor,$now]);
        }
        echo json_encode(journey_dungeon_payload($pdo,$fresh),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return true;
    }
    if ($action === 'unequipDungeonArmor') {
        $slot=(string)($_POST['equipmentSlot']??'');
        if(!in_array($slot,['head','chest','hands','legs'],true)){echo json_encode(['code'=>'invalid_slot']);return true;}
        $pdo->prepare('DELETE FROM dungeon_equipment WHERE user_id=? AND equipment_slot=?')->execute([$userId,$slot]);
        echo json_encode(journey_dungeon_payload($pdo,$user),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return true;
    }
    if ($action === 'repairDungeonArmor') {
        $slot = (string)($_POST['equipmentSlot'] ?? '');
        if (!in_array($slot, ['head','chest','hands','legs'], true)) { echo json_encode(['code'=>'invalid_slot']); return true; }
        $pdo->beginTransaction();
        try {
            game_lock_user_for_update($pdo, $userId);
            $fresh = journey_find_user($userId); ensureEconomyFields($fresh);
            $stmt = $pdo->prepare('SELECT item_id, armor_value, max_armor FROM dungeon_equipment WHERE user_id=? AND equipment_slot=?');
            $stmt->execute([$userId, $slot]);
            $armor = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$armor) throw new RuntimeException('not_equipped');
            $maxArmor = max(1, (int)$armor['max_armor']);
            $currentArmor = max(0, min($maxArmor, (int)$armor['armor_value']));
            if ($currentArmor >= $maxArmor) throw new RuntimeException('already_full');
            $price = max(100, min(500, 100 + (int)round(($maxArmor / 156) * 400)));
            $wingStmt = $pdo->prepare('SELECT wing_coins FROM dungeon_player_state WHERE user_id=?' . (journey_db_driver($pdo)==='mysql' ? ' FOR UPDATE' : ''));
            $wingStmt->execute([$userId]);
            $wingCoins = (int)$wingStmt->fetchColumn();
            if ($wingCoins < $price) throw new RuntimeException('not_enough_wing_coins');
            $newMax = $maxArmor > 50 ? max(1, $maxArmor - 10) : $maxArmor;
            $now = date('Y-m-d H:i:s');
            $pdo->prepare('UPDATE dungeon_player_state SET wing_coins=?, updated_at=? WHERE user_id=?')->execute([$wingCoins - $price, $now, $userId]);
            $pdo->prepare('UPDATE dungeon_equipment SET armor_value=?, max_armor=?, updated_at=? WHERE user_id=? AND equipment_slot=?')->execute([$newMax, $newMax, $now, $userId, $slot]);
            $pdo->prepare('UPDATE dungeon_armor_durability SET armor_value=?, max_armor=?, updated_at=? WHERE user_id=? AND item_id=?')->execute([$newMax, $newMax, $now, $userId, $armor['item_id']]);
            $pdo->commit();
            echo json_encode(array_merge(journey_dungeon_payload($pdo,$fresh), ['repairPrice'=>$price,'repairedSlot'=>$slot,'newMaxArmor'=>$newMax]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $code = $e->getMessage();
            if (!in_array($code, ['invalid_slot','not_equipped','already_full','not_enough_wing_coins'], true)) $code = 'repair_failed';
            echo json_encode(['code'=>$code], JSON_UNESCAPED_UNICODE); return true;
        }
    }
    if ($action === 'exchangeDungeonCurrency') {
        $direction = (string)($_POST['direction'] ?? '');
        $units = max(1, min(100000, (int)($_POST['units'] ?? 1)));
        if (!in_array($direction, ['gold_to_wing','wing_to_gold'], true)) { echo json_encode(['code'=>'invalid_direction']); return true; }
        $started = false;
        try {
            if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $started = true; }
            game_lock_user_for_update($pdo, $userId);
            $fresh = journey_find_user($userId); ensureEconomyFields($fresh);
            $stateLock = $pdo->prepare('SELECT wing_coins FROM dungeon_player_state WHERE user_id = ?' . (journey_db_driver($pdo) === 'mysql' ? ' FOR UPDATE' : ''));
            $stateLock->execute([$userId]);
            $wingCoins = max(0, (int)$stateLock->fetchColumn());
            if ($direction === 'gold_to_wing') {
                $goldCost = $units * 10;
                if (!hasUnlimitedGold($fresh) && (int)$fresh['gold'] < $goldCost) throw new RuntimeException('not_enough_gold');
                if (!hasUnlimitedGold($fresh)) $fresh['gold'] -= $goldCost;
                $wingCoins += $units;
            } else {
                $wingCost = $units * 10;
                if ($wingCoins < $wingCost) throw new RuntimeException('not_enough_wing_coins');
                $wingCoins -= $wingCost;
                $fresh['gold'] = min(2147483647, (int)$fresh['gold'] + $units);
            }
            journey_upsert_legacy_user_internal($pdo, $fresh);
            $pdo->prepare('UPDATE dungeon_player_state SET wing_coins = ?, updated_at = ? WHERE user_id = ?')->execute([$wingCoins,date('Y-m-d H:i:s'),$userId]);
            if ($started) $pdo->commit();
            journey_audit('dungeon.bank_exchange', ['direction'=>$direction,'units'=>$units], $userId, 'user', $userId);
            echo json_encode(['code'=>'ok','gold'=>(int)$fresh['gold'],'wingCoins'=>$wingCoins],JSON_UNESCAPED_UNICODE); return true;
        } catch (Throwable $exception) {
            if ($started && $pdo->inTransaction()) $pdo->rollBack();
            $code = $exception->getMessage();
            if (!in_array($code, ['not_enough_gold','not_enough_wing_coins'], true)) { error_log('exchangeDungeonCurrency failed: ' . $code); $code = 'exchange_failed'; }
            echo json_encode(['code'=>$code],JSON_UNESCAPED_UNICODE); return true;
        }
    }
    if($action==='recordDungeonKill'){journey_dungeon_increment_stat($pdo,$userId,'total_kills',1);echo json_encode(['code'=>'ok']);return true;}
    if ($action === 'grantDungeonItem') {
        $itemId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_POST['itemId'] ?? ''));
        $count = max(1, min(20, (int)($_POST['count'] ?? 1)));
        if (!journey_dungeon_item_definition($itemId)) { echo json_encode(['code'=>'invalid_item']); return true; }
        $limit = journey_rate_limit('dungeon.loot', $userId, 180, 3600, true);
        if (!$limit['allowed']) { echo json_encode(['code'=>'rate_limited']); return true; }
        $fresh = journey_find_user($userId); ensureEconomyFields($fresh);
        if (!addInventoryItem($fresh, $itemId, $count)) { echo json_encode(['code'=>'full']); return true; }
        journey_upsert_legacy_user_internal($pdo, $fresh);
        echo json_encode(['code'=>'ok','inventory'=>$fresh['inventory']],JSON_UNESCAPED_UNICODE); return true;
    }
    if ($action === 'discardDungeonItem') {
        $slot = (int)($_POST['slot'] ?? -1);
        if ($slot < 0 || $slot > 20) { echo json_encode(['code'=>'invalid']); return true; }
        $fresh = journey_find_user($userId); ensureEconomyFields($fresh);
        if (!removeInventoryItem($fresh, $slot, 1)) { echo json_encode(['code'=>'empty']); return true; }
        journey_upsert_legacy_user_internal($pdo, $fresh); journey_dungeon_reconcile_equipment($pdo,$userId,$fresh['inventory']);
        echo json_encode(journey_dungeon_payload($pdo,$fresh),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); return true;
    }
    if ($action === 'clearDungeonCarriedItems') {
        $fresh = journey_find_user($userId); ensureEconomyFields($fresh);
        $inventory = normalizeInventorySlots($fresh['inventory'] ?? [],false); $lost=0;
        foreach($inventory as $index=>$item){if(is_array($item)){$lost+=max(1,(int)($item['count']??1));$inventory[$index]=null;}}
        $coins=$pdo->prepare('SELECT wing_coins FROM dungeon_player_state WHERE user_id=?');$coins->execute([$userId]);$lostWingCoins=max(0,(int)$coins->fetchColumn());
        $fresh['inventory']=$inventory; journey_upsert_legacy_user_internal($pdo,$fresh); $pdo->prepare('UPDATE dungeon_player_state SET wing_coins=0, updated_at=? WHERE user_id=?')->execute([date('Y-m-d H:i:s'),$userId]); $pdo->prepare('DELETE FROM dungeon_equipment WHERE user_id=?')->execute([$userId]); $pdo->prepare('DELETE FROM dungeon_armor_durability WHERE user_id=?')->execute([$userId]); journey_dungeon_increment_stat($pdo,$userId,'total_deaths',1);
        echo json_encode(['code'=>'ok','lost'=>$lost,'lostWingCoins'=>$lostWingCoins,'wingCoins'=>0,'inventory'=>$inventory],JSON_UNESCAPED_UNICODE); return true;
    }
    if ($action === 'consumeDungeonItem') {
        $itemId=preg_replace('/[^a-zA-Z0-9_\-]/','',(string)($_POST['itemId']??'')); $count=max(1,min(20,(int)($_POST['count']??1)));
        if(!journey_dungeon_item_definition($itemId)){echo json_encode(['code'=>'invalid_item']);return true;}
        $fresh=journey_find_user($userId);ensureEconomyFields($fresh);$inventory=normalizeInventorySlots($fresh['inventory']??[],false);$remaining=$count;
        foreach($inventory as $index=>$item){if($remaining<=0)break;if(!is_array($item)||($item['id']??'')!==$itemId)continue;$take=min($remaining,max(1,(int)($item['count']??1)));$inventory[$index]['count']-=$take;$remaining-=$take;if($inventory[$index]['count']<=0)$inventory[$index]=null;}
        if($remaining>0){echo json_encode(['code'=>'missing']);return true;}
        $fresh['inventory']=$inventory;journey_upsert_legacy_user_internal($pdo,$fresh);echo json_encode(['code'=>'ok','inventory'=>$inventory],JSON_UNESCAPED_UNICODE);return true;
    }
    if ($action === 'synthesizeDungeonItems') {
        $slots = json_decode((string)($_POST['slots'] ?? '[]'), true);
        if (!is_array($slots) || count($slots) !== 5) { echo json_encode(['code'=>'need_five']); return true; }
        $slots = array_values(array_unique(array_map('intval', $slots)));
        if (count($slots) !== 5 || min($slots) < 0 || max($slots) > 20) { echo json_encode(['code'=>'invalid_slots']); return true; }
        $pdo->beginTransaction();
        try {
            game_lock_user_for_update($pdo, $userId);
            $fresh = journey_find_user($userId); ensureEconomyFields($fresh);
            $inventory = normalizeInventorySlots($fresh['inventory'] ?? [], false);
            $qualityRank = ['common'=>0, 'uncommon'=>1, 'rare'=>2, 'epic'=>3, 'legendary'=>4];
            $selected = [];
            foreach ($slots as $slot) {
                $entry = $inventory[$slot] ?? null;
                if (!is_array($entry) || empty($entry['id'])) throw new RuntimeException('invalid_item');
                $definition = journey_dungeon_item_definition((string)$entry['id']);
                if (!$definition || empty($definition['dungeonUsable'])) throw new RuntimeException('dungeon_only');
                $selected[] = ['slot'=>$slot, 'entry'=>$entry, 'definition'=>$definition];
            }
            $quality = (string)($selected[0]['definition']['quality'] ?? '');
            if ($quality === '' || !isset($qualityRank[$quality])) throw new RuntimeException('invalid_quality');
            foreach ($selected as $row) {
                if ((string)($row['definition']['quality'] ?? '') !== $quality) throw new RuntimeException('same_quality');
                if ((int)($row['entry']['count'] ?? 1) < 1) throw new RuntimeException('invalid_item');
            }
            if ($qualityRank[$quality] >= 4) throw new RuntimeException('max_quality');

            // 每个格子只消耗 1 件，堆叠材料保留剩余数量。
            foreach ($selected as $row) {
                $remaining = max(0, (int)($inventory[$row['slot']]['count'] ?? 1) - 1);
                $inventory[$row['slot']] = $remaining > 0 ? array_merge($inventory[$row['slot']], ['count'=>$remaining]) : null;
            }
            $succeeded = random_int(1, 100) <= 25;
            $result = null;
            if ($succeeded) {
                $targetQuality = array_search($qualityRank[$quality] + 1, $qualityRank, true);
                $pool = array_values(array_filter(journey_dungeon_items(), static function($item) use ($targetQuality) {
                    return is_array($item) && ($item['quality'] ?? '') === $targetQuality;
                }));
                if (!$pool) throw new RuntimeException('no_upgrade_pool');
                $result = $pool[random_int(0, count($pool) - 1)];
                $newEntry = ['id'=>$result['id'], 'count'=>1, 'createdAt'=>date('Y-m-d H:i:s')];
                $empty = null;
                foreach ($inventory as $index => $value) { if ($value === null) { $empty = $index; break; } }
                if ($empty === null) throw new RuntimeException('full');
                $inventory[$empty] = $newEntry;
            }
            $fresh['inventory'] = normalizeInventorySlots($inventory, false);
            journey_upsert_legacy_user_internal($pdo, $fresh);
            $pdo->commit();
            echo json_encode(['code'=>'ok','success'=>$succeeded,'quality'=>$quality,'result'=>$result,'inventory'=>$fresh['inventory']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $code = $e->getMessage();
            if (!in_array($code, ['need_five','invalid_slots','invalid_item','dungeon_only','invalid_quality','same_quality','max_quality','no_upgrade_pool','full'], true)) $code = 'synthesis_failed';
            echo json_encode(['code'=>$code], JSON_UNESCAPED_UNICODE);
            return true;
        }
    }
    if ($action === 'buyDungeonWarehouse') {
        $pdo->beginTransaction();
        try {
            $warehouseNo=(int)($_POST['warehouseNo']??0);
            $prices=[2=>250,3=>500,4=>1000,5=>2000];
            if(!isset($prices[$warehouseNo])){$pdo->rollBack();echo json_encode(['code'=>'invalid_warehouse']);return true;}
            $numbers = $pdo->prepare('SELECT warehouse_no FROM dungeon_warehouses WHERE user_id = ? ORDER BY warehouse_no'); $numbers->execute([$userId]);
            $owned = array_map('intval',$numbers->fetchAll(PDO::FETCH_COLUMN));
            if(in_array($warehouseNo,$owned,true)){$pdo->rollBack();echo json_encode(['code'=>'already_owned']);return true;}
            $price=$prices[$warehouseNo];
            $fresh = journey_find_user($userId); ensureEconomyFields($fresh);
            if (!hasUnlimitedGold($fresh) && (int)$fresh['gold'] < $price) { $pdo->rollBack(); echo json_encode(['code'=>'nogold','price'=>$price]); return true; }
            if (!hasUnlimitedGold($fresh)) $fresh['gold'] -= $price;
            journey_upsert_legacy_user_internal($pdo,$fresh);
            $pdo->prepare('INSERT INTO dungeon_warehouses (user_id, warehouse_no, purchased_at) VALUES (?, ?, ?)')->execute([$userId,$warehouseNo,date('Y-m-d H:i:s')]);
            $pdo->commit(); echo json_encode(['code'=>'ok','warehouseNo'=>$warehouseNo,'gold'=>(int)$fresh['gold']]); return true;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    if ($action === 'moveDungeonStorage') {
        $from = (string)($_POST['from'] ?? 'bag'); $to = (string)($_POST['to'] ?? 'warehouse');
        $fromSlot=(int)($_POST['fromSlot'] ?? -1); $toSlot=(int)($_POST['toSlot'] ?? -1); $warehouseNo=(int)($_POST['warehouseNo'] ?? 1);
        if (!in_array($from,['bag','warehouse'],true) || !in_array($to,['bag','warehouse'],true) || $fromSlot<0 || $fromSlot>20 || $toSlot<0 || $toSlot>20) { echo json_encode(['code'=>'invalid']); return true; }
        if ($from === 'warehouse' || $to === 'warehouse') {
            $owned=$pdo->prepare('SELECT 1 FROM dungeon_warehouses WHERE user_id=? AND warehouse_no=?'); $owned->execute([$userId,$warehouseNo]);
            if (!$owned->fetchColumn()) { echo json_encode(['code'=>'locked']); return true; }
        }
        $pdo->beginTransaction();
        try {
            $fresh=journey_find_user($userId); ensureEconomyFields($fresh); $bag=normalizeInventorySlots($fresh['inventory'] ?? [],false);
            $getWarehouse=function(int $slot) use($pdo,$userId,$warehouseNo){$s=$pdo->prepare('SELECT item_id AS id,item_count AS count,custom_name AS customName,created_at AS createdAt FROM dungeon_warehouse_slots WHERE user_id=? AND warehouse_no=? AND slot_index=?');$s->execute([$userId,$warehouseNo,$slot]);$v=$s->fetch(PDO::FETCH_ASSOC);return $v?:null;};
            $a=$from==='bag'?($bag[$fromSlot]??null):$getWarehouse($fromSlot); $b=$to==='bag'?($bag[$toSlot]??null):$getWarehouse($toSlot);
            if (!$a) { $pdo->rollBack(); echo json_encode(['code'=>'empty']); return true; }
            $setWarehouse=function(int $slot,$item) use($pdo,$userId,$warehouseNo){$pdo->prepare('DELETE FROM dungeon_warehouse_slots WHERE user_id=? AND warehouse_no=? AND slot_index=?')->execute([$userId,$warehouseNo,$slot]);if($item)$pdo->prepare('INSERT INTO dungeon_warehouse_slots (user_id,warehouse_no,slot_index,item_id,item_count,custom_name,created_at) VALUES (?,?,?,?,?,?,?)')->execute([$userId,$warehouseNo,$slot,$item['id'],max(1,(int)($item['count']??1)),(string)($item['customName']??''),(string)($item['createdAt']??date('Y-m-d H:i:s'))]);};
            if($from==='bag')$bag[$fromSlot]=$b;else $setWarehouse($fromSlot,$b);
            if($to==='bag')$bag[$toSlot]=$a;else $setWarehouse($toSlot,$a);
            $fresh['inventory']=normalizeInventorySlots($bag,false); journey_upsert_legacy_user_internal($pdo,$fresh); journey_dungeon_reconcile_equipment($pdo,$userId,$fresh['inventory']); $pdo->commit();
            echo json_encode(journey_dungeon_payload($pdo,$fresh),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); return true;
        } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    return false;
}
