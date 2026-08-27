(() => {
    'use strict';

    const COLS = 68;
    const ROWS = 48;
    const TILE = 40;
    const canvas = document.getElementById('gameCanvas');
    const ctx = canvas.getContext('2d');
    const minimapCache = document.createElement('canvas');
    const minimapCtx = minimapCache.getContext('2d');
    const $ = id => document.getElementById(id);

    const weaponPool = [
        { id:'old_sword', name:'旧制短剑', icon:'†', quality:'common', label:'普通', damage:12, cooldown:.42, range:58, type:'melee', weight:42 },
        { id:'hunter_knife', name:'猎人短刀', icon:'⌁', quality:'fine', label:'精良', damage:9, cooldown:.22, range:48, type:'melee', weight:24 },
        { id:'ash_spear', name:'灰烬长枪', icon:'↟', quality:'fine', label:'精良', damage:16, cooldown:.52, range:88, type:'melee', weight:18 },
        { id:'watcher_axe', name:'看守者战斧', icon:'⚒', quality:'rare', label:'稀有', damage:27, cooldown:.78, range:66, type:'melee', weight:10 },
        { id:'clock_crossbow', name:'发条弩', icon:'➶', quality:'rare', label:'稀有', damage:15, cooldown:.55, range:360, type:'ranged', projectileSpeed:460, ammoType:'bolt', weight:8 },
        { id:'echo_blade', name:'回声刃', icon:'〆', quality:'epic', label:'史诗', damage:23, cooldown:.34, range:72, type:'melee', echo:true, weight:4 },
        { id:'ember_staff', name:'余烬法杖', icon:'✦', quality:'epic', label:'史诗', damage:22, cooldown:.68, range:420, type:'ranged', projectileSpeed:380, ammoType:'mana', splash:58, weight:3 },
        { id:'nameless_relic', name:'无名者遗器', icon:'∞', quality:'legendary', label:'传说', damage:36, cooldown:.38, range:92, type:'melee', lifesteal:.12, weight:1 },
        {id:'iron_mace',name:'生铁钉锤',icon:'⚒',quality:'common',label:'普通',damage:18,cooldown:.68,range:55,type:'melee',weight:28},{id:'guard_sabre',name:'卫兵弯刀',icon:'⌁',quality:'fine',label:'精良',damage:15,cooldown:.36,range:62,type:'melee',weight:20},{id:'bone_scythe',name:'白骨战镰',icon:'☾',quality:'rare',label:'稀有',damage:31,cooldown:.82,range:96,type:'melee',weight:8},{id:'violet_halberd',name:'紫晶长戟',icon:'ψ',quality:'epic',label:'史诗',damage:34,cooldown:.64,range:108,type:'melee',weight:4},{id:'king_breaker',name:'破王巨剑',icon:'‡',quality:'legendary',label:'传说',damage:52,cooldown:.9,range:84,type:'melee',weight:1},
        {id:'short_bow',name:'榆木短弓',icon:'➹',quality:'common',label:'普通',damage:11,cooldown:.48,range:380,type:'ranged',projectileSpeed:430,ammoType:'arrow',weight:28},{id:'long_bow',name:'巡林长弓',icon:'➹',quality:'fine',label:'精良',damage:17,cooldown:.6,range:500,type:'ranged',projectileSpeed:500,ammoType:'arrow',weight:17},{id:'heavy_crossbow',name:'重型攻城弩',icon:'➶',quality:'rare',label:'稀有',damage:36,cooldown:1.05,range:560,type:'ranged',projectileSpeed:620,ammoType:'bolt',weight:7},{id:'rust_pistol',name:'锈蚀手铳',icon:'⌐',quality:'common',label:'普通',damage:14,cooldown:.42,range:420,type:'ranged',projectileSpeed:600,ammoType:'bullet',weight:24},{id:'warden_rifle',name:'看守者步枪',icon:'⌐',quality:'rare',label:'稀有',damage:25,cooldown:.32,range:620,type:'ranged',projectileSpeed:760,ammoType:'bullet',weight:7},{id:'scatter_gun',name:'碎岩霰铳',icon:'≋',quality:'epic',label:'史诗',damage:42,cooldown:.9,range:300,type:'ranged',projectileSpeed:540,ammoType:'bullet',splash:45,weight:3},{id:'frost_wand',name:'霜纹法杖',icon:'✧',quality:'fine',label:'精良',damage:16,cooldown:.56,range:430,type:'ranged',projectileSpeed:390,ammoType:'mana',weight:15},{id:'storm_orb',name:'风暴法球',icon:'◉',quality:'rare',label:'稀有',damage:23,cooldown:.46,range:470,type:'ranged',projectileSpeed:440,ammoType:'mana',weight:7},{id:'void_scepter',name:'虚空权杖',icon:'♜',quality:'epic',label:'史诗',damage:35,cooldown:.72,range:540,type:'ranged',projectileSpeed:460,ammoType:'mana',splash:70,weight:3},{id:'star_cannon',name:'星坠魔炮',icon:'✺',quality:'legendary',label:'传说',damage:58,cooldown:1.1,range:700,type:'ranged',projectileSpeed:700,ammoType:'mana',splash:90,weight:1}
    ];
    weaponPool.push({id:'laser_gun',name:'脉冲激光枪',icon:'⚡',quality:'epic',label:'史诗',damage:34,cooldown:.5,range:650,type:'ranged',projectileSpeed:900,ammoType:'bullet',weight:3});
    const itemPool=[
        {id:'bandage',name:'旧绷带',icon:'▧',quality:'common',desc:'朴素的地牢医疗物资'},{id:'torch',name:'松脂火把',icon:'♨',quality:'common',desc:'照亮潮湿回廊的火把'},{id:'iron_scrap',name:'生铁零件',icon:'⚙',quality:'common',desc:'可以带回主城收藏的零件'},{id:'monster_fang',name:'穴兽尖牙',icon:'⌁',quality:'common',desc:'穴行兽留下的尖牙'},{id:'moss',name:'荧光苔藓',icon:'♣',quality:'common',desc:'散发微光的地下植物'},{id:'smoke_bomb',name:'烟雾弹',icon:'●',quality:'fine',desc:'刻着逃生标记的投掷物'},{id:'holy_water',name:'净化圣水',icon:'♢',quality:'fine',desc:'封在银瓶中的圣水'},{id:'lockpick',name:'精制撬锁器',icon:'⌘',quality:'fine',desc:'精巧的机械工具'},{id:'amber',name:'凝火琥珀',icon:'◆',quality:'fine',desc:'内部封存着微弱火光'},{id:'hunter_badge',name:'猎手徽章',icon:'✪',quality:'fine',desc:'证明探索经历的徽章'},{id:'moon_shard',name:'月蚀碎片',icon:'☽',quality:'rare',desc:'冰冷的稀有矿物'},{id:'royal_coin',name:'失落王币',icon:'¤',quality:'rare',desc:'旧王朝铸造的金币'},{id:'dragon_scale',name:'幼龙鳞片',icon:'◈',quality:'rare',desc:'拥有异常韧性的鳞片'},{id:'void_eye',name:'虚空之眼',icon:'◉',quality:'epic',desc:'似乎仍在观察持有者'},{id:'crown_fragment',name:'破碎王冠',icon:'♛',quality:'legendary',desc:'遗迹深处的王权残片'}
    ];
    const unarmedWeapon = { id:'unarmed', name:'徒手', icon:'拳', quality:'common', label:'基础', damage:5, cooldown:.5, range:42, type:'melee' };

    const upgrades = [
        { icon:'⚔', name:'锋刃刻印', desc:'所有武器伤害提高20%。', apply:p => p.damageMultiplier *= 1.2 },
        { icon:'♥', name:'坚韧血肉', desc:'生命上限提高25，并立即恢复25。', apply:p => { p.maxHp += 25; p.hp = Math.min(p.maxHp, p.hp + 25); } },
        { icon:'➹', name:'迅捷脚步', desc:'移动速度提高15%。', apply:p => p.speed *= 1.15 },
        { icon:'◌', name:'短距折跃', desc:'闪避冷却缩短25%。', apply:p => p.dashCooldownMax *= .75 },
        { icon:'✚', name:'战地医术', desc:'获得2瓶药剂，药剂效果提高10。', apply:p => { p.potions += 2; p.potionPower += 10; grantCloudItem('dungeon_potion',2); } },
        { icon:'◉', name:'猎手视野', desc:'探索视野扩大2格。', apply:p => p.vision += 2 },
        { icon:'⌁', name:'连贯攻击', desc:'武器攻击间隔缩短18%。', apply:p => p.attackSpeed *= .82 },
        { icon:'◇', name:'碎片共鸣', desc:'每持有1枚碎片，伤害提高2%。', apply:p => p.shardPower += .02 }
    ];

    const state = {
        map: [], bridges: [], rooms: [], explored: [], enemies: [], projectiles: [], interactables: [], pickups: [], traps: [], obstacles: [], effects: [],
        exit: { x:0, y:0 }, seed:0, paused:false, ended:false, lastTime:0, camera:{x:0,y:0}, pendingWeapon:null, pendingWeaponStarter:false, chestTimer:0, bossFightActive:false,
        mode:'town', warehouseItems:[], warehouseOrder:Array(21).fill(''),activeRoom:null,lastUiUpdate:0,lastEmptyAmmoLog:0,lastCosmeticAt:0,ammoMaxSeen:0,selectedWarehouseId:'',
        chat:{ messages:[], knownIds:new Set(), inputOpen:false, hideTimer:null, pinnedUntil:0, lastPoll:0 },
        potionEffect:null
    };

    const player = {
        x:0, y:0, radius:11, hp:100, maxHp:100, speed:175, floor:1, wingCoins:0, officialGold:0, kills:0, potions:0, potionPower:35,
        keys:0, shards:0, weapon:{...unarmedWeapon}, equippedUid:'', armor:{head:null,chest:null,hands:null,legs:null}, inventory:[], bagOrder:Array(21).fill(''), selectedBagId:'', facing:{x:1,y:0}, attackCooldown:0,
        dashCooldown:0, dashCooldownMax:1.25, dashTime:0, invulnerable:0, damageMultiplier:1, attackSpeed:1, shardPower:0, vision:2,ammo:{ammo:0,modern:0,arrow:0,bolt:0,mana:0}
    };

    const cloud = { ready:false, saving:false, saveQueued:false, savePromise:null, userId:'', warehouseNo:1, warehouses:[1], warehouseRows:[], nextWarehousePrice:250, warehousePrices:{2:250,3:500,4:1000,5:2000}, definitions:new Map(), username:'玩家', title:'初来乍到', avatar:null, avatarImage:null, equippedEffect:'', dungeonBackgroundUrl:'', dungeonBackgroundImage:null, floorTextures:{}, floorColors:{}, monsterConfig:{}, monsterImages:{} };
    function cosmeticKind(){const id=cloud.equippedEffect||'';return id.includes('projectile')?'projectile':id.includes('aura')||id==='heart_aura'?'aura':id?'trail':''}
    function cosmeticColor(){const palette=['#ef7b3b','#78c8ff','#b987ff','#72df91','#ffd36a','#ff7898','#f5f0df'];const id=cloud.equippedEffect||'';let hash=0;for(const char of id)hash=(hash*31+char.charCodeAt(0))>>>0;return palette[hash%palette.length]}
    let synthesisSlots = [];
    const csrfToken = () => decodeURIComponent((document.cookie.match(/(?:^|; )journey_csrf=([^;]*)/) || [,''])[1]);
    async function dungeonApi(action, fields = null) {
        const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),5000);
        const options = {credentials:'include', cache:'no-store',signal:controller.signal};
        let url = `board.php?action=${encodeURIComponent(action)}`;
        if (fields) {
            const body = new FormData();
            Object.entries(fields).forEach(([key,value]) => body.append(key,String(value)));
            body.append('_csrf',csrfToken());
            options.method='POST'; options.body=body;
        }
        try{const response=await fetch(url,options);const data=await response.json();if(!response.ok||data.code!=='ok')throw new Error(data.code||`http_${response.status}`);return data}
        catch(error){if(error.name==='AbortError')throw new Error('timeout');throw error}
        finally{clearTimeout(timer)}
    }
    let onlinePlayers = [];
    async function refreshDungeonOnline() {
        try {
            const data = await dungeonApi('getDungeonOnline');
            const online = Math.max(0, Number(data.online || 0));
            onlinePlayers = Array.isArray(data.players) ? data.players : [];
            const element = $('dungeonOnline');
            if (element) {
                element.textContent = `${online} 人正在游玩`;
                element.style.cursor = 'pointer';
                element.onclick = toggleOnlineList;
            }
            const mobileHud = $('mobileAttackHud');
            if (mobileHud) {
                let mobileOnline = $('mobileOnlineText');
                if (!mobileOnline) {
                    const wrapper = document.createElement('span');
                    wrapper.className = 'mobile-online-hud'; wrapper.setAttribute('aria-label', '在线人数');
                    wrapper.innerHTML = '<i>在线</i><b id="mobileOnlineText">0</b>';
                    mobileHud.appendChild(wrapper); mobileOnline = $('mobileOnlineText');
                }
                mobileOnline.textContent = String(online);
            }
        } catch (error) {
            const element = $('dungeonOnline');
            if (element) element.textContent = '人数读取失败';
        }
    }
    function toggleOnlineList(e) {
        if (e) e.stopPropagation();
        const popup = $('onlineListPopup');
        if (!popup) return;
        if (popup.classList.contains('show')) {
            popup.classList.remove('show');
            return;
        }
        // 打开前刷新数据
        refreshDungeonOnline().then(() => {
            const list = $('onlinePlayerList');
            if (!list) return;
            if (onlinePlayers.length === 0) {
                list.innerHTML = '<div class="online-empty">暂无在线玩家</div>';
            } else {
                list.innerHTML = onlinePlayers.map(p => {
                    const location = p.scene === 'dungeon' ? `第 ${p.floor} 层` : '遗迹主城';
                    return `<div class="online-player-item"><span class="op-name">${escapeHtml(p.name)}</span><span class="op-floor">${location}</span></div>`;
                }).join('');
            }
            popup.classList.add('show');
        });
    }
    function hideOnlineList() {
        const popup = $('onlineListPopup');
        if (popup) popup.classList.remove('show');
    }
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = String(str || '');
        return div.innerHTML;
    }
    // 点击外部关闭在线列表
    document.addEventListener('click', (e) => {
        const popup = $('onlineListPopup');
        const onlineEl = $('dungeonOnline');
        if (popup && popup.classList.contains('show') && !popup.contains(e.target) && e.target !== onlineEl) {
            popup.classList.remove('show');
        }
    });
    // ==================== 地牢聊天（聊天框） ====================
    const CHAT_MAX_VISIBLE = 50;
    let chatHideTimer = null;
    function showChatBox() {
        const box = $('chatBox');
        if (!box) return;
        box.classList.remove('chat-hidden');
        if (chatHideTimer) clearTimeout(chatHideTimer);
        chatHideTimer = setTimeout(() => {
            box.classList.add('chat-hidden');
        }, 3000);
    }
    function appendChatBox(name, content, isSelf) {
        const list = $('chatBoxList');
        if (!list) return;
        const el = document.createElement('div');
        el.className = 'chat-msg' + (isSelf ? ' cm-self' : '');
        const nameSpan = document.createElement('span');
        nameSpan.className = 'cm-name';
        nameSpan.textContent = name ? name + '：' : '';
        const contentSpan = document.createElement('span');
        contentSpan.textContent = content || '';
        el.appendChild(nameSpan);
        el.appendChild(contentSpan);
        // 最新消息插在最前面
        list.insertBefore(el, list.firstChild);
        // 限制 DOM 节点数量
        while (list.children.length > CHAT_MAX_VISIBLE) list.removeChild(list.lastChild);
        // 新消息到达，显示聊天框并重置 3 秒隐藏计时
        showChatBox();
    }
    function appendChatMessage(msg) {
        if (!msg || !msg.id || state.chat.knownIds.has(msg.id)) return;
        state.chat.knownIds.add(msg.id);
        state.chat.messages.push(msg);
        if (state.chat.messages.length > 30) state.chat.messages = state.chat.messages.slice(-30);
        appendChatBox(msg.name || '玩家', msg.content || '', false);
    }
    async function sendDungeonChat(message) {
        const text = (message || '').trim();
        if (!text) return;
        try {
            const result = await dungeonApi('sendDungeonChat', { message: text.slice(0, 100) });
            // 服务器返回了消息ID，先标记为已知，防止轮询重复显示
            if (result.message && result.message.id) state.chat.knownIds.add(result.message.id);
            // 用服务器返回的名字显示（避免自己显示"我"，别人显示真名导致不一致）
            appendChatBox(result.message?.name || cloud.username || '我', result.message?.content || text, true);
        } catch (error) {
            if (error.message === 'rate_limited') log('说话太快了，请稍等片刻。');
            else log('消息发送失败。');
        }
    }
    async function pollDungeonChat() {
        try {
            const data = await dungeonApi('getDungeonChat');
            (data.messages || []).forEach(msg => appendChatMessage(msg));
        } catch (error) { /* 静默失败 */ }
    }
    function focusChatInput() {
        const input = $('chatInput');
        if (!input) return;
        input.focus();
        requestAnimationFrame(() => input.focus());
        setTimeout(() => input.focus(), 60);
    }
    function openChatInput() {
        if (state.chat.inputOpen) { focusChatInput(); return; }
        state.chat.inputOpen = true;
        clearInputs();
        const wrap = $('chatInputWrap');
        if (wrap) wrap.hidden = false;
        const input = $('chatInput');
        if (input) { input.value = ''; }
        showChatBox();
        focusChatInput();
    }
    function closeChatInput(send) {
        if (!state.chat.inputOpen) return;
        const input = $('chatInput');
        const text = send && input ? input.value.trim() : '';
        state.chat.inputOpen = false;
        if (input) { input.value = ''; input.blur(); }
        const wrap = $('chatInputWrap');
        if (wrap) wrap.hidden = true;
        // 显式把焦点移到 body，防止手机端键盘不收/输入框残留焦点
        try { document.body.focus(); } catch(e) {}
        if (document.activeElement instanceof HTMLElement) document.activeElement.blur();
        // 清除可能残留的移动状态，确保游戏操作恢复
        clearInputs();
        if (send && text) sendDungeonChat(text);
    }
    function toggleChatInput() {
        if (state.chat.inputOpen) closeChatInput(false);
        else openChatInput();
    }
    function definitionFor(itemId) { return cloud.definitions.get(itemId) || {id:itemId,name:itemId,displayName:itemId,icon:'?',quality:'common',desc:'官网收藏物品',dungeonUsable:false,tags:[]}; }
    function registerExpandedDungeonItems() {
        const qualityStats={common:{damage:13,weight:30},uncommon:{damage:18,weight:19},rare:{damage:26,weight:9},epic:{damage:36,weight:4},legendary:{damage:52,weight:1}};
        for(const def of cloud.definitions.values()){
            const localId=String(def.id||'').replace(/^d_/,''),quality=def.quality==='uncommon'?'fine':def.quality;
            if(/^(?:weapon|gun)_\d{3}$/.test(localId)&&!weaponPool.some(item=>item.id===localId)){
                const stats=qualityStats[def.quality]||qualityStats.common;
                const magic=String(def.type||'').includes('魔法'),firearm=String(def.type||'').includes('枪械'),ranged=magic||firearm||String(def.type||'').includes('远程');
                weaponPool.push({id:localId,name:String(def.displayName||def.name).replace(/^\[D\]\s*/,''),icon:def.icon||'†',quality,label:def.qualityLabel||'普通',damage:stats.damage,cooldown:ranged ? .58 : .48,range:ranged ? 480 : 68,type:ranged ? 'ranged' : 'melee',projectileSpeed:ranged ? 520 : undefined,ammoType:firearm ? 'bullet' : (ranged ? 'ammo' : undefined),firearm,weight:stats.weight});
            }
            if((/^(tool|potion)_\d{3}$/.test(localId)||/^armor_/.test(localId))&&!itemPool.some(item=>item.id===localId)){
                itemPool.push({id:localId,name:String(def.displayName||def.name).replace(/^\[D\]\s*/,''),icon:def.icon||'◆',quality,desc:def.desc||'可带离地牢的云端物品'});
            }
        }
    }
    function officialEntry(item,index) {
        if(!item)return null;
        const def=definitionFor(item.id), localId=item.id.startsWith('d_')?item.id.slice(2):'';
        const weapon=weaponPool.find(entry=>entry.id===localId);
        if(weapon)return{uid:`bag-${index}`,slot:index,type:'weapon',weapon:{...weapon},count:Number(item.count||1),officialItem:item};
        if(def.armorSlot)return{uid:`bag-${index}`,slot:index,type:'armor',armor:{id:def.id,name:def.displayName||def.name,icon:def.icon,quality:def.quality==='uncommon'?'fine':def.quality,slot:def.armorSlot,maxArmor:Number(def.armorValue||0),desc:def.desc},item:{id:def.id,name:def.displayName||def.name,icon:def.icon,quality:def.quality==='uncommon'?'fine':def.quality,desc:def.desc,dungeonUsable:true},count:Number(item.count||1),officialItem:item};
        return{uid:`bag-${index}`,slot:index,type:'item',item:{id:item.id,name:def.displayName||def.name,icon:def.icon,quality:def.quality==='uncommon'?'fine':def.quality,desc:def.desc,dungeonUsable:def.dungeonUsable===true},count:Number(item.count||1),officialItem:item};
    }
    function hydrateCloud(data,preserveRuntime=false) {
        (data.items||[]).forEach(item=>cloud.definitions.set(item.id,item));
        registerExpandedDungeonItems();
        cloud.userId=String(data.userId||'');
        cloud.username=String(data.username||data.userId||'玩家');
        cloud.title=String(data.title||'初来乍到');
        cloud.equippedEffect=String(data.equippedEffect||'');
        cloud.avatar=data.avatar||{type:'initial',text:Array.from(cloud.username)[0]||'旅',color:'#8f2730'};
        renderProfileCard();
        loadPlayerAvatar();
        player.officialGold=Number(data.gold||0);
        if(!preserveRuntime)player.wingCoins=Number(data.state?.wing_coins||0);
        player.inventory=(data.inventory||[]).map(officialEntry).filter(Boolean);
        player.bagOrder=Array.from({length:21},(_,index)=>data.inventory?.[index]?`bag-${index}`:'');
        const equippedRows=Array.isArray(data.equipment)?data.equipment:[];
        player.armor={head:null,chest:null,hands:null,legs:null};
        equippedRows.forEach(row=>{const entry=player.inventory.find(item=>item.type==='armor'&&item.officialItem?.id===row.item_id);if(entry&&player.armor[row.equipment_slot]===null)player.armor[row.equipment_slot]={...entry.armor,uid:entry.uid,itemId:row.item_id,value:Math.max(0,Number(row.armor_value||0)),maxArmor:Math.max(0,Number(row.max_armor||entry.armor.maxArmor||0))}});
        if(!preserveRuntime){const savedWeaponId=String(data.state?.equipped_item_id||'');const equipped=player.inventory.find(item=>item.type==='weapon'&&item.officialItem?.id===savedWeaponId);player.weapon=equipped?{...equipped.weapon}:{...unarmedWeapon};player.equippedUid=equipped?.uid||'';}
        player.potions=0; player.keys=0; player.shards=0; player.ammo={ammo:0,modern:0,arrow:0,bolt:0,bullet:0,mana:0};
        (data.inventory||[]).forEach(item=>{if(!item)return;const count=Number(item.count||1);if(item.id==='d_dungeon_potion')player.potions+=count;else if(item.id==='d_brass_key')player.keys+=count;else if(item.id==='d_relic_shard')player.shards+=count;else if(item.id==='d_modern_ammo'||item.id==='d_bullet_bundle')player.ammo.modern+=count;else if(item.id==='d_ammo_bundle')player.ammo.ammo+=count;else if(item.id==='d_arrow_bundle')player.ammo.arrow+=count;else if(item.id==='d_bolt_bundle')player.ammo.bolt+=count;else if(item.id==='d_mana_charge')player.ammo.mana+=count;});
        cloud.warehouses=(data.warehouses||[1]).map(Number); cloud.warehouseRows=data.warehouseSlots||[]; cloud.nextWarehousePrice=data.warehouseNextPrice;cloud.warehousePrices=data.warehousePrices||cloud.warehousePrices;
        hydrateWarehouse(cloud.warehouseRows,cloud.warehouseNo);
        // 地牢背景图：管理员后台设置后生效，未设置则为 null
        const bgUrl = data.dungeonBackground ? String(data.dungeonBackground) : '';
        if (bgUrl && bgUrl !== cloud.dungeonBackgroundUrl) {
            cloud.dungeonBackgroundUrl = bgUrl;
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => { cloud.dungeonBackgroundImage = img; };
            img.onerror = () => { cloud.dungeonBackgroundImage = null; cloud.dungeonBackgroundUrl = ''; };
            img.src = bgUrl;
        } else if (!bgUrl) {
            cloud.dungeonBackgroundUrl = '';
            cloud.dungeonBackgroundImage = null;
        }
        // 房间地板纹理：管理员可按房间类型设置图片
        const floorTex = data.floorTextures || data.floor_textures || {};
        const validTypes = ['spawn','chest','merchant','camp','shrine','boss','normal','elite','town','bridge'];
        validTypes.forEach(rt => {
            const url = floorTex[rt] ? String(floorTex[rt]) : '';
            if (url && url !== (cloud.floorTextures[rt]?.url || '')) {
                const img = new Image();
                img.crossOrigin = 'anonymous';
                img.onload = () => { if (cloud.floorTextures[rt]) cloud.floorTextures[rt].img = img; };
                img.onerror = () => { delete cloud.floorTextures[rt]; };
                img.src = url;
                cloud.floorTextures[rt] = { url, img:null };
            } else if (!url && cloud.floorTextures[rt]) {
                delete cloud.floorTextures[rt];
            }
        });
        const floorColors=data.floorColors||data.floor_colors||{};
        cloud.floorColors={};
        validTypes.forEach(rt=>{const color=String(floorColors[rt]||'');if(/^#[0-9a-f]{6}$/i.test(color))cloud.floorColors[rt]=color});
        cloud.monsterConfig=data.monsterConfig&&typeof data.monsterConfig==='object'?data.monsterConfig:{};
        Object.entries(cloud.monsterConfig).forEach(([kind,config])=>{const url=String(config?.image||'');if(!url){delete cloud.monsterImages[kind];return}const img=new Image();img.onload=()=>cloud.monsterImages[kind]=img;img.src=url;});
    }
    function renderProfileCard() {
        const portrait=$('profilePortrait');
        $('profileName').textContent=cloud.username;
        $('profileTitle').textContent=cloud.title;
        portrait.innerHTML='';
        portrait.style.background='';
        if(cloud.avatar?.type==='image'&&cloud.avatar.src){const image=document.createElement('img');image.src=cloud.avatar.src;image.alt=cloud.username;portrait.appendChild(image);}
        else{portrait.textContent=cloud.avatar?.text||Array.from(cloud.username)[0]||'旅';portrait.style.background=cloud.avatar?.color||'#8f2730';}
    }
    function loadPlayerAvatar() {
        cloud.avatarImage=null;
        if(cloud.avatar?.type!=='image'||!cloud.avatar.src)return;
        const image=new Image();
        image.decoding='async';
        image.onload=()=>{cloud.avatarImage=image;};
        image.onerror=()=>{cloud.avatarImage=null;};
        image.src=cloud.avatar.src;
    }
    function hydrateWarehouse(rows,warehouseNo) {
        state.warehouseItems=[]; state.warehouseOrder=Array(21).fill('');
        rows.filter(row=>Number(row.warehouse_no)===Number(warehouseNo)).forEach(row=>{
            const item=officialEntry({id:row.item_id,count:row.item_count,customName:row.custom_name,createdAt:row.created_at},Number(row.slot_index));
            if(!item)return; item.uid=`warehouse-${warehouseNo}-${row.slot_index}`; item.slot=Number(row.slot_index); state.warehouseItems.push(item); state.warehouseOrder[item.slot]=item.uid;
        });
    }
    async function saveCloudState() {
        if(!cloud.ready)return;
        if(cloud.saving){cloud.saveQueued=true;return cloud.savePromise;}
        cloud.saving=true;
        cloud.savePromise=dungeonApi('saveDungeonState',{scene:state.mode,x:Math.round(player.x),y:Math.round(player.y),floor:Math.max(1,player.floor),hp:Math.round(player.hp),wingCoins:player.wingCoins,equippedItemId:player.inventory.find(item=>item.uid===player.equippedUid)?.officialItem?.id||'',armorHead:player.armor.head?.value||0,armorChest:player.armor.chest?.value||0,armorHands:player.armor.hands?.value||0,armorLegs:player.armor.legs?.value||0});
        try { await cloud.savePromise; }
        catch(error){ console.warn('Dungeon cloud save failed',error); }
        finally{const queued=cloud.saveQueued;cloud.saving=false;cloud.saveQueued=false;cloud.savePromise=null;if(queued)setTimeout(saveCloudState,0);}
    }
    function saveCloudStateOnExit() {
        if(!cloud.ready)return;
        const body=new FormData();
        body.append('_csrf',csrfToken());body.append('scene',state.mode);body.append('x',String(Math.round(player.x)));body.append('y',String(Math.round(player.y)));body.append('floor',String(Math.max(1,player.floor)));body.append('hp',String(Math.round(player.hp)));body.append('wingCoins',String(player.wingCoins));body.append('equippedItemId',player.inventory.find(item=>item.uid===player.equippedUid)?.officialItem?.id||'');body.append('armorHead',String(player.armor.head?.value||0));body.append('armorChest',String(player.armor.chest?.value||0));body.append('armorHands',String(player.armor.hands?.value||0));body.append('armorLegs',String(player.armor.legs?.value||0));
        navigator.sendBeacon?.('board.php?action=saveDungeonState',body);
    }
    async function grantCloudItem(localId,count=1) {
        try { const data=await dungeonApi('grantDungeonItem',{itemId:`d_${localId}`,count}); player.inventory=(data.inventory||[]).map(officialEntry).filter(Boolean); player.bagOrder=Array.from({length:21},(_,index)=>data.inventory?.[index]?`bag-${index}`:''); return true; }
        catch(error){ if(error.message==='full')log('官网背包已满，无法取得该物品。'); else log('物品云端同步失败，请稍后再试。'); return false; }
    }
    function consumeCloudItem(localId,count=1) {
        const itemId=`d_${localId}`,entry=player.inventory.find(item=>item.officialItem?.id===itemId);
        if(entry){entry.count=Math.max(0,(entry.count||1)-count);entry.officialItem.count=entry.count;if(entry.count===0){player.inventory=player.inventory.filter(item=>item!==entry);if(Number.isInteger(entry.slot))player.bagOrder[entry.slot]='';}}
        dungeonApi('consumeDungeonItem',{itemId,count}).catch(()=>log('云端物品消耗同步失败，系统将在重新登录时校正。'));
    }
    async function moveCloudStorage(from,to,fromSlot,toSlot) {
        if(!cloud.ready||state.mode!=='town'&&('warehouse'===from||'warehouse'===to))return;
        try { const data=await dungeonApi('moveDungeonStorage',{from,to,fromSlot,toSlot,warehouseNo:cloud.warehouseNo}); hydrateCloud(data); renderBag(); }
        catch(error){log(`云端背包操作失败：${error.message}`);}
    }
    async function buyWarehouse(warehouseNo) {
        if(cloud.warehouses.includes(warehouseNo))return;
        const price=Number(cloud.warehousePrices?.[warehouseNo]||0);if(!price)return;
        if(!confirm(`购买 ${warehouseNo} 号仓库需要 ${price} 官网金币，是否购买？`))return;
        try { const result=await dungeonApi('buyDungeonWarehouse',{warehouseNo}); player.officialGold=Number(result.gold||player.officialGold); const data=await dungeonApi('getDungeonState'); hydrateCloud(data); generateTown(); log(`已购买 ${warehouseNo} 号云端仓库。`); }
        catch(error){log(error.message==='nogold'?'官网金币不足，无法购买仓库。':error.message==='already_owned'?'这个仓库已经购买。':'仓库购买失败，请稍后再试。');}
    }

    function openBank() {
        if(state.mode!=='town')return;
        clearInputs();state.paused=true;
        $('bankGold').textContent=player.officialGold.toLocaleString('zh-CN');
        $('bankWingCoins').textContent=player.wingCoins.toLocaleString('zh-CN');
        $('bankNotice').textContent='输入兑换份数后选择兑换方向。';
        $('bankModal').classList.add('show');
    }

    function closeBank() {
        $('bankModal').classList.remove('show');
        state.paused=false;
    }

    async function exchangeBankCurrency(direction) {
        const units=Math.max(1,Math.min(100000,Math.floor(Number($('bankUnits').value)||1)));
        $('bankUnits').value=String(units);
        const buttons=[$('goldToWingBtn'),$('wingToGoldBtn')];buttons.forEach(button=>button.disabled=true);
        $('bankNotice').textContent='银行正在核对云端余额...';
        try{
            await saveCloudState();
            const result=await dungeonApi('exchangeDungeonCurrency',{direction,units});
            player.officialGold=Number(result.gold||0);player.wingCoins=Number(result.wingCoins||0);
            $('bankGold').textContent=player.officialGold.toLocaleString('zh-CN');$('bankWingCoins').textContent=player.wingCoins.toLocaleString('zh-CN');
            $('bankNotice').textContent=direction==='gold_to_wing'?`兑换成功：${units*10} 金币换得 ${units} 翼币。`:`兑换成功：${units*10} 翼币换得 ${units} 金币。`;
            updateUi();
        }catch(error){
            const messages={not_enough_gold:'官网金币不足。',not_enough_wing_coins:'翼币不足。',invalid_direction:'兑换方向无效。'};
            $('bankNotice').textContent=messages[error.message]||'兑换失败，请稍后重试。';
        }finally{buttons.forEach(button=>button.disabled=false)}
    }

    const input = { w:false, a:false, s:false, d:false };
    const touchMove = { x:0, y:0 };
    const touchAutoFacing = window.matchMedia?.('(hover:none) and (pointer:coarse)').matches === true;
    // 手机端有自动瞄准，Boss 攻击更快、更频繁：前摇×0.65，冷却×0.7，弹幕速度×1.2
    const BOSS_MOBILE_WINDUP = touchAutoFacing ? 0.65 : 1;
    const BOSS_MOBILE_COOLDOWN = touchAutoFacing ? 0.7 : 1;
    const BOSS_MOBILE_PROJ_SPEED = touchAutoFacing ? 1.2 : 1;
    function resizeCanvas(){
        const landscape = window.matchMedia?.('(hover:none) and (pointer:coarse) and (orientation:landscape)').matches;
        if(landscape){
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        }else{
            canvas.width = 960;
            canvas.height = 624;
        }
    }
    window.addEventListener('resize', resizeCanvas);
    window.addEventListener('orientationchange', () => setTimeout(resizeCanvas, 100));
    resizeCanvas();
    const rand = () => {
        state.seed |= 0;
        state.seed = state.seed + 0x6D2B79F5 | 0;
        let t = Math.imul(state.seed ^ state.seed >>> 15, 1 | state.seed);
        t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
        return ((t ^ t >>> 14) >>> 0) / 4294967296;
    };
    const ri = (min, max) => Math.floor(rand() * (max - min + 1)) + min;
    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
    const distance = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
    function nearestToPlayer(items, predicate = () => true) {
        let nearest = null;
        let nearestDistance = Infinity;
        for (const item of items) {
            if (!predicate(item)) continue;
            const currentDistance = distance(item, player);
            if (currentDistance < nearestDistance) {
                nearest = item;
                nearestDistance = currentDistance;
            }
        }
        return { item: nearest, distance: nearestDistance };
    }
    const tileCenter = value => value * TILE + TILE / 2;
    const tileOf = value => Math.floor(value / TILE);
    const qualityRank = { common:0, fine:1, rare:2, epic:3, legendary:4 };

    function roomTypeAt(tx, ty) {
        if (state.bridges[ty] && state.bridges[ty][tx]) return 'bridge';
        for (const room of state.rooms) {
            if (tx >= room.x && tx < room.x + room.w && ty >= room.y && ty < room.y + room.h) {
                return room.roomType || 'normal';
            }
        }
        return 'normal';
    }

    function log(message) {
        const line = document.createElement('p');
        line.innerHTML = `<b>第 ${player.floor} 层：</b>${message}`;
        $('log').prepend(line);
        while($('log').children.length>40)$('log').lastElementChild.remove();
    }

    function carveRoom(room) {
        for (let y = room.y; y < room.y + room.h; y++) {
            for (let x = room.x; x < room.x + room.w; x++) {
                state.map[y][x] = 1;
                state.bridges[y][x] = false;
            }
        }
    }

    function connectRooms(from, to) {
        let x = from.cx;
        let y = from.cy;
        const carveBridgeTile = () => {
            if (!state.map[y][x]) state.bridges[y][x] = true;
            state.map[y][x] = 1;
        };
        const carveX = () => { while (x !== to.cx) { carveBridgeTile(); x += Math.sign(to.cx - x); } };
        const carveY = () => { while (y !== to.cy) { carveBridgeTile(); y += Math.sign(to.cy - y); } };
        if (rand() < .5) { carveX(); carveY(); } else { carveY(); carveX(); }
        state.map[to.cy][to.cx] = 1;
    }

    function roomPoint(room, margin = 1) {
        return { x:ri(room.x + margin, room.x + room.w - 1 - margin), y:ri(room.y + margin, room.y + room.h - 1 - margin) };
    }

    function roomOverlaps(room, other, padding = 1) {
        return room.x <= other.x + other.w + padding && room.x + room.w + padding >= other.x && room.y <= other.y + other.h + padding && room.y + room.h + padding >= other.y;
    }

    function floorDifficulty(floor = player.floor) {
        const depth = Math.max(0, floor - 1);
        return {
            hp: 1 + depth * .14,
            damage: 1 + depth * .085,
            speed: 1 + Math.min(.3, depth * .018),
            cooldown: Math.max(.65, 1 - depth * .022),
            maxEnemies: Math.min(5, 1 + Math.floor(floor / 2)),
            trapDamage: 9 + Math.round(depth * 2.2)
        };
    }

    function makeEnemy(kind, x, y, boss = false, room = null, champion = false) {
        const actualKind=boss?'boss':kind,custom=cloud.monsterConfig[actualKind]||{};
        const baseDefault = {
            crawler:{ hp:62, speed:74, damage:9, radius:13, color:'#98483f' },
            archer:{ hp:46, speed:54, damage:10, radius:12, color:'#b06b45' },
            shotgunner:{ hp:54, speed:56, damage:8, radius:13, color:'#a06a9c' },
            brute:{ hp:128, speed:44, damage:18, radius:17, color:'#7f3532' },
            bomber:{ hp:38, speed:92, damage:22, radius:12, color:'#d4783a' },
            juggernaut:{ hp:230, speed:26, damage:30, radius:20, color:'#4a3838' },
            boss:{ hp:200, speed:58, damage:20, radius:25, color:'#8f55bd' }
        }[actualKind];
        const base={hp:Number(custom.hp)>0?Number(custom.hp):baseDefault.hp,speed:Number(custom.speed)>0?Number(custom.speed):baseDefault.speed,damage:Number(custom.damage)>0?Number(custom.damage):baseDefault.damage,radius:baseDefault.radius,color:baseDefault.color};
        const difficulty = floorDifficulty();
        const depth = Math.max(0, player.floor - 1);
        let hp, maxHp, damage, speed, radius, color;
        if (boss) {
            maxHp = Math.min(100000, Math.round(base.hp * (1 + depth * .05)));
            hp = maxHp;
            damage = Math.round(base.damage * (1 + depth * .125));
            speed = base.speed * difficulty.speed;
            radius = base.radius;
            color = base.color;
        } else if (champion) {
            // 精英怪：2.5倍血量、1.6倍伤害、略大、暗红色调
            maxHp = Math.round(base.hp * difficulty.hp * 2.5);
            hp = maxHp;
            damage = Math.round(base.damage * difficulty.damage * 1.6);
            speed = base.speed * difficulty.speed * 1.15;
            radius = base.radius + 3;
            color = '#d44a3a';
        } else {
            maxHp = Math.round(base.hp * difficulty.hp);
            hp = maxHp;
            damage = Math.round(base.damage * difficulty.damage);
            speed = base.speed * difficulty.speed;
            radius = base.radius;
            color = base.color;
        }
        const rangedKind=['archer','shotgunner'].includes(actualKind);
        const weaponCandidates=weaponPool.filter(weapon=>weapon.quality!=='legendary'&&weapon.type===(rangedKind?'ranged':'melee'));
        const heldWeapon=!boss&&kind!=='bomber'&&weaponCandidates.length&&rand()<.7?{...weaponCandidates[ri(0,weaponCandidates.length-1)]}:null;
        if(heldWeapon)damage=Math.max(damage,Math.round(heldWeapon.damage*.65*difficulty.damage));
        damage=Math.max(1,Math.round(damage*(Number(custom.skillDamage)>0?Number(custom.skillDamage):1)));
        return {
            kind:actualKind, x:tileCenter(x), y:tileCenter(y), hp, maxHp,
            speed, damage, cooldownScale:difficulty.cooldown, radius, color, cooldown:rand(), windup:0,
            attackMode:'', targetX:0, targetY:0, dead:false, elite:boss, champion, room:room ? {...room} : null,executeRolled:false,executeReady:false,fuseTime:0,
            damageReduction: kind === 'juggernaut' ? 0.35 : 0, heldWeapon, customName:String(custom.name||''),description:String(custom.description||''),attackSpeed:Number(custom.attackSpeed)>0?Number(custom.attackSpeed):1,attackInterval:Number(custom.attackInterval)>0?Number(custom.attackInterval):0,skillDamage:Number(custom.skillDamage)>0?Number(custom.skillDamage):1
        };
    }

    function generateFloor() {
        state.mode = 'dungeon';
        state.ended = false;
        state.bossFightActive = false;
        state.activeRoom = null;

        // 用有界循环替代递归，防止极端布局下栈溢出导致整个楼层（含Boss房间）生成失败
        let layoutOk = false;
        for (let layoutAttempt = 0; layoutAttempt < 10 && !layoutOk; layoutAttempt++) {
            state.seed = (Date.now() ^ player.floor * 104729 ^ Math.floor(Math.random() * 999999) ^ layoutAttempt) >>> 0;
            state.map = Array.from({length:ROWS}, () => Array(COLS).fill(0));
            state.bridges = Array.from({length:ROWS}, () => Array(COLS).fill(false));
            state.explored = Array.from({length:ROWS}, () => Array(COLS).fill(false));
            state.rooms = [];
            state.enemies = [];
            state.projectiles = [];
            state.interactables = [];
            state.pickups = [];
            state.traps = [];
            state.obstacles = [];
            state.effects = [];
            $('seedText').textContent = `SEED ${String(state.seed).slice(-6)}`;

            for (let attempt = 0; attempt < 900 && state.rooms.length < 16; attempt++) {
                const room = { w:ri(5,9), h:ri(5,8) };
                room.x = ri(1, COLS - room.w - 2);
                room.y = ri(1, ROWS - room.h - 2);
                room.cx = Math.floor(room.x + room.w / 2);
                room.cy = Math.floor(room.y + room.h / 2);
                const overlaps = state.rooms.some(other => roomOverlaps(room, other));
                if (overlaps) continue;
                carveRoom(room);
                if (state.rooms.length) connectRooms(state.rooms[state.rooms.length - 1], room);
                state.rooms.push(room);
            }
            // 前5次要求至少12个房间，之后放宽到4个即可（Boss兜底链保证至少有房间可用）
            if (state.rooms.length >= (layoutAttempt < 5 ? 12 : 4)) layoutOk = true;
        }
        let bossRoom = null;
        let bossRoomIsExisting = false;
        const spawnRoom = state.rooms[0];
        // Boss 房间优先远离出生点：前600次要求中心距离≥22瓦片（约880像素），
        // 之后放宽到≥16瓦片兜底
        for (let attempt = 0; attempt < 1200 && !bossRoom; attempt++) {
            const room = { w:ri(15,17), h:ri(11,14) };
            room.x = ri(1, COLS - room.w - 2);
            room.y = ri(1, ROWS - room.h - 2);
            room.cx = Math.floor(room.x + room.w / 2);
            room.cy = Math.floor(room.y + room.h / 2);
            if (state.rooms.some(other => roomOverlaps(room, other))) continue;
            const dist = Math.hypot(room.cx - spawnRoom.cx, room.cy - spawnRoom.cy);
            const minDist = attempt < 600 ? 22 : 16;
            if (dist < minDist) continue;
            bossRoom = room;
        }
        // 兜底一：放弃距离要求，只要不重叠即可
        if (!bossRoom) {
            for (let attempt = 0; attempt < 500 && !bossRoom; attempt++) {
                const room = { w:ri(15,17), h:ri(11,14) };
                room.x = ri(1, COLS - room.w - 2);
                room.y = ri(1, ROWS - room.h - 2);
                room.cx = Math.floor(room.x + room.w / 2);
                room.cy = Math.floor(room.y + room.h / 2);
                if (state.rooms.some(other => roomOverlaps(room, other))) continue;
                bossRoom = room;
            }
        }
        // 兜底二：缩小房间尺寸再试
        if (!bossRoom) {
            for (let attempt = 0; attempt < 500 && !bossRoom; attempt++) {
                const room = { w:ri(11,14), h:ri(8,11) };
                room.x = ri(1, COLS - room.w - 2);
                room.y = ri(1, ROWS - room.h - 2);
                room.cx = Math.floor(room.x + room.w / 2);
                room.cy = Math.floor(room.y + room.h / 2);
                if (state.rooms.some(other => roomOverlaps(room, other))) continue;
                bossRoom = room;
            }
        }
        // 兜底三：直接将离出生点最远的已有房间改造为 Boss 房间
        if (!bossRoom) {
            let farthest = null, maxDist = -1;
            for (const room of state.rooms) {
                if (room === spawnRoom) continue;
                const d = Math.hypot(room.cx - spawnRoom.cx, room.cy - spawnRoom.cy);
                if (d > maxDist) { maxDist = d; farthest = room; }
            }
            if (farthest) { bossRoom = farthest; bossRoomIsExisting = true; }
        }
        // 终极兜底：无论如何也要有一个 Boss 房间
        if (!bossRoom) bossRoom = state.rooms[state.rooms.length - 1];
        if (bossRoomIsExisting) {
            // 已有房间已挖好且已连接，只需移到末尾作为终点
            state.rooms = state.rooms.filter(r => r !== bossRoom);
            state.rooms.push(bossRoom);
        } else {
            carveRoom(bossRoom);
            connectRooms(state.rooms[state.rooms.length - 1], bossRoom);
            state.rooms.push(bossRoom);
        }
        bossRoom.isBossRoom = true;

        const start = state.rooms[0];
        const end = state.rooms[state.rooms.length - 1];
        start.roomType = 'spawn';
        end.roomType = 'boss';
        player.x = tileCenter(start.cx);
        player.y = tileCenter(start.cy);
        state.exit = { x:tileCenter(end.cx), y:tileCenter(end.cy) };

        const roomTypes = ['chest','camp','shrine','merchant','crate','chest','hidden'];
        // 精英怪房间：第2层后有概率出现，层数越高概率越大
        state.rooms.slice(1, -1).forEach((room, index) => {
            const point = roomPoint(room);
            const type = roomTypes[index % roomTypes.length];
            state.interactables.push({ type, x:tileCenter(point.x), y:tileCenter(point.y), used:false, locked:type === 'chest' && rand() < .45, hidden:type === 'hidden' });
            // 商人和营火房间无怪物
            const isSafeRoom = type === 'camp' || type === 'merchant';
            const enemyCount = isSafeRoom ? 0 : ri(1, floorDifficulty().maxEnemies);
            // 精英怪房间判定：非安全房、层数>=2、概率随层数增加（最高约35%）
            const isEliteRoom = enemyCount > 0 && player.floor >= 2 && rand() < Math.min(.35, .08 + player.floor * .025);
            for (let i = 0; i < enemyCount; i++) {
                const pos = roomPoint(room);
                const roll = rand();
                let kind;
                if (player.floor < 5) {
                    // 第5层前：无石甲巨像，概率重新分配
                    kind = roll < .36 ? 'crawler' : roll < .56 ? 'archer' : roll < .68 ? 'shotgunner' : roll < .80 ? 'bomber' : 'brute';
                } else {
                    // 第5层起：石甲巨像加入池
                    kind = roll < .32 ? 'crawler' : roll < .50 ? 'archer' : roll < .62 ? 'shotgunner' : roll < .74 ? 'bomber' : roll < .90 ? 'brute' : 'juggernaut';
                }
                if (isEliteRoom && i === 0) {
                    const elite = makeEnemy(kind, pos.x, pos.y, false, room, true);
                    state.enemies.push(elite);
                } else {
                    state.enemies.push(makeEnemy(kind, pos.x, pos.y, false, room));
                }
            }
            // 标记房间类型
            if (isEliteRoom) {
                room.roomType = 'elite';
                room.isEliteRoom = true;
                log(`前方房间散发着危险气息……一名精英守卫盘踞其中。`);
            } else if (type === 'merchant') {
                room.roomType = 'merchant';
            } else if (type === 'camp') {
                room.roomType = 'camp';
            } else if (type === 'shrine') {
                room.roomType = 'shrine';
            } else if (type === 'chest' || type === 'hidden') {
                room.roomType = 'chest';
            } else {
                room.roomType = 'normal';
            }
        });

        // 计算本关所有非Boss小怪的平均血量，Boss血量=平均值×5
        const regularEnemies = state.enemies.filter(e => !e.elite);
        const avgMonsterHp = regularEnemies.length > 0
            ? regularEnemies.reduce((sum, e) => sum + e.maxHp, 0) / regularEnemies.length
            : 60;
        const bossTargetHp = Math.round(avgMonsterHp * 5);

        if (player.floor % 5 === 0) {
            const boss = makeEnemy('boss', end.cx, end.cy, true, end);
            boss.maxHp = boss.hp = bossTargetHp;
            boss.majorBoss = true;
            state.enemies.push(boss);
            $('areaTitle').textContent = '看守者的深层';
            log('楼梯被首领的锁链封住，必须先击败它。');
        } else {
            const boss = makeEnemy('boss', end.cx, end.cy, true, end);
            boss.maxHp = boss.hp = bossTargetHp;
            boss.damage = Math.max(8, Math.round(boss.damage * .75));
            boss.radius = 20;
            boss.majorBoss = false;
            state.enemies.push(boss);
            $('areaTitle').textContent = player.floor === 1 ? '被遗忘的入口' : '变动的回廊';
            log('紫色传送门由一名传送门守卫看守。');
        }

        state.rooms.slice(1).forEach(room => {
            // 商人/营火/出生/Boss 房间不放陷阱
            const noTraps = room.roomType === 'merchant' || room.roomType === 'camp' || room.roomType === 'spawn' || room.roomType === 'boss' || room.isBossRoom;
            if (rand() < .65) {
                const pos = roomPoint(room);
                const roll=rand(),type=roll<.45?'potion':roll<.8?'gold':'shard';
                state.pickups.push({ x:tileCenter(pos.x), y:tileCenter(pos.y), type, amount:type==='gold'?ri(5,14)+player.floor:undefined, taken:false });
            }
            // 陷阱增多：70% 概率放 1 个，30% 概率再放第 2 个（安全房不放）
            if (!noTraps && rand() < .7) {
                const pos = roomPoint(room);
                state.traps.push({ x:tileCenter(pos.x), y:tileCenter(pos.y), radius:16, used:false });
                if (rand() < .35) {
                    const pos2 = roomPoint(room);
                    state.traps.push({ x:tileCenter(pos2.x), y:tileCenter(pos2.y), radius:16, used:false });
                }
            }
            if(rand()<.38){const pos=roomPoint(room);const ammo=createAmmoPickup(ri(4,10));state.pickups.push({x:tileCenter(pos.x),y:tileCenter(pos.y),...ammo})}
        });

        // 在普通/精英房间内生成柱子障碍物（不在起始房/Boss房/商人/营火房，不堵门）
        state.rooms.slice(1, -1).forEach(room => {
            if (room.isBossRoom || room.isTown || room.roomType === 'merchant' || room.roomType === 'camp') return;
            // 每个房间 2~5 根柱子，大房间更多
            const area = room.w * room.h;
            const pillarCount = area > 100 ? ri(3, 6) : area > 60 ? ri(2, 4) : ri(1, 3);
            for (let i = 0; i < pillarCount; i++) {
                const px = ri(room.x + 2, room.x + room.w - 3);
                const py = ri(room.y + 2, room.y + room.h - 3);
                // 不堵门：避开房间中心（走廊连接处）和互动物品位置
                const tooCloseCenter = Math.abs(px - room.cx) < 2 && Math.abs(py - room.cy) < 2;
                const tooCloseInteractable = state.interactables.some(it =>
                    Math.abs(tileOf(it.x) - px) < 2 && Math.abs(tileOf(it.y) - py) < 2
                );
                // 柱子之间保持间距，避免挤在一起
                const tooClosePillar = state.obstacles.some(ob =>
                    Math.abs(tileOf(ob.x) - px) < 2 && Math.abs(tileOf(ob.y) - py) < 2
                );
                if (!tooCloseCenter && !tooCloseInteractable && !tooClosePillar) {
                    state.obstacles.push({ x:tileCenter(px), y:tileCenter(py), radius:15 });
                }
            }
        });
        revealMap();
        updateUi();
        log('随机地牢生成完成。寻找宝箱、强化和通往下一层的楼梯。');
    }

    function generateTown() {
        state.mode = 'town';
        state.seed = 0;
        state.map = Array.from({length:ROWS}, () => Array(COLS).fill(0));
        state.bridges = Array.from({length:ROWS}, () => Array(COLS).fill(false));
        state.explored = Array.from({length:ROWS}, () => Array(COLS).fill(true));
        state.rooms = [{x:25,y:18,w:18,h:12,cx:34,cy:24,isTown:true,roomType:'town'}];
        carveRoom(state.rooms[0]);
        state.enemies=[];state.projectiles=[];state.pickups=[];state.traps=[];state.obstacles=[];state.effects=[];
        state.interactables=[{type:'townDoor',x:tileCenter(41),y:tileCenter(24),used:false,hidden:false},{type:'bank',x:tileCenter(38),y:tileCenter(20),used:false,hidden:false},{type:'synthesis',x:tileCenter(35),y:tileCenter(20),used:false,hidden:false},{type:'repair',x:tileCenter(32),y:tileCenter(20),used:false,hidden:false}];
        [[27,21],[27,23],[27,25],[27,27],[30,20]].forEach(([x,y],index)=>state.interactables.push({type:'warehouse',warehouseNo:index+1,locked:!cloud.warehouses.includes(index+1),x:tileCenter(x),y:tileCenter(y),used:false,hidden:false}));
        state.exit={x:0,y:0};state.ended=false;state.paused=false;state.bossFightActive=false;state.camera={x:0,y:0};
        // 返回主城：清除所有在地牢获得的增益祝福（伤害/攻速/血量上限/速度/闪避/药剂强化/视野/碎片共鸣）
        player.damageMultiplier=1;player.attackSpeed=1;player.shardPower=0;
        player.speed=175;player.dashCooldownMax=1.25;player.dashCooldown=0;player.dashTime=0;
        player.potionPower=35;player.maxHp=100;player.vision=100;
        player.x=tileCenter(34);player.y=tileCenter(24);player.floor=0;player.hp=player.maxHp;
        $('seedText').textContent='SAFE ZONE';$('areaTitle').textContent='遗迹主城';
        log('回到了主城。东侧大门通往地牢，西侧是个人仓库，东北侧有银行、品质重铸机和护甲修复机器。');
        updateUi();
    }

    function startDungeon() {
        sessionStorage.setItem(`journey_dungeon_active_${cloud.userId || 'guest'}`, '1');
        sessionStorage.setItem(`journey_dungeon_refreshes_${cloud.userId || 'guest'}`, '0');
        // 地牢初始只保留约 3x3 格的近身视野，避免开局直接看穿整间房。
        player.floor=1;player.hp=player.maxHp;player.kills=0;player.damageMultiplier=1;player.attackSpeed=1;player.shardPower=0;player.vision=2;
        state.camera={x:0,y:0};
        generateFloor();
        if(bagEntries().length===0){
            state.paused=true;
            state.pendingChestPos={x:player.x,y:player.y};
            log('背包为空，地牢正在发放一把基础武器。');
            setTimeout(()=>startWeaponReel(0,{starter:true}),250);
        }
        saveCloudState();
    }

    function dungeonWarningKey(){return `journey_dungeon_risk_v2_${cloud.userId||'guest'}`;}
    function requestDungeonEntry(){
        if(localStorage.getItem(dungeonWarningKey())==='accepted'){startDungeon();return;}
        clearInputs();state.paused=true;$('entryWarningModal').classList.add('show');
    }
    function acceptDungeonRisk(remember=false){
        if(remember)localStorage.setItem(dungeonWarningKey(),'accepted');
        $('entryWarningModal').classList.remove('show');state.paused=false;startDungeon();
    }

    function returnToTown() {
        clearInputs();
        sessionStorage.removeItem(`journey_dungeon_active_${cloud.userId || 'guest'}`);
        sessionStorage.removeItem(`journey_dungeon_refreshes_${cloud.userId || 'guest'}`);
        $('extractModal').classList.remove('show');
        generateTown();
        saveCloudState();
    }

    async function leaveDungeonFromMenu(targetHref) {
        if (state.mode !== 'dungeon') { location.href = targetHref; return; }
        if (!window.confirm('你真的要放弃这一局吗？放弃后会判定死亡，地牢背包物品和翼币将全部掉落。')) return;
        try {
            const cleared = await dungeonApi('clearDungeonCarriedItems', {});
            player.inventory = (cleared.inventory || []).map(officialEntry).filter(Boolean);
            player.bagOrder = Array(21).fill(''); player.wingCoins = 0; state.mode = 'town';
            await saveCloudState();
            sessionStorage.removeItem(`journey_dungeon_active_${cloud.userId || 'guest'}`);
            sessionStorage.removeItem(`journey_dungeon_refreshes_${cloud.userId || 'guest'}`);
            location.href = targetHref;
        } catch (error) {
            log('死亡结算失败，未离开地牢。请检查网络后重试。');
        }
    }
    window.journeyDungeonLeaveGuard = leaveDungeonFromMenu;

    async function handleDungeonRefresh(saved) {
        if (saved.scene !== 'dungeon') return false;
        const key = `journey_dungeon_refreshes_${cloud.userId || 'guest'}`;
        const activeKey = `journey_dungeon_active_${cloud.userId || 'guest'}`;
        if (sessionStorage.getItem(activeKey) !== '1') {
            sessionStorage.setItem(activeKey, '1');
            sessionStorage.setItem(key, '1');
            alert('你已经刷新了 1 次网页，系统判断为误刷新，正在重新加载本关卡。');
            return false;
        }
        const count = Math.max(0, Number(sessionStorage.getItem(key) || 0)) + 1;
        sessionStorage.setItem(key, String(count));
        if (count < 3) {
            alert(`你已经刷新了 ${count} 次网页，正在重新加载本关卡。第 3 次刷新将判定为死亡。`);
            return false;
        }
        alert('你已经刷新网页 3 次，本局判定为死亡，地牢物品和翼币将全部掉落。');
        const cleared = await dungeonApi('clearDungeonCarriedItems', {});
        player.inventory = (cleared.inventory || []).map(officialEntry).filter(Boolean);
        player.bagOrder = Array(21).fill(''); player.wingCoins = 0; player.armor = {head:null,chest:null,hands:null,legs:null};
        sessionStorage.removeItem(activeKey); sessionStorage.removeItem(key);
        return true;
    }

    function walkable(x, y, radius = 10) {
        const points = [[x-radius,y-radius],[x+radius,y-radius],[x-radius,y+radius],[x+radius,y+radius]];
        const tileOk = points.every(([px,py]) => {
            const tx = tileOf(px), ty = tileOf(py);
            return tx >= 0 && ty >= 0 && tx < COLS && ty < ROWS && state.map[ty][tx] === 1;
        });
        if (!tileOk) return false;
        // 柱子障碍物圆形碰撞
        return !state.obstacles.some(ob => Math.hypot(x - ob.x, y - ob.y) < radius + ob.radius);
    }

    function moveWithCollision(entity, dx, dy, radius = entity.radius || 10) {
        if (walkable(entity.x + dx, entity.y, radius)) entity.x += dx;
        if (walkable(entity.x, entity.y + dy, radius)) entity.y += dy;
    }

    function moveEnemyInsideRoom(enemy, dx, dy) {
        if (!enemy.room) return;
        const minX = enemy.room.x * TILE + enemy.radius;
        const maxX = (enemy.room.x + enemy.room.w) * TILE - enemy.radius;
        const minY = enemy.room.y * TILE + enemy.radius;
        const maxY = (enemy.room.y + enemy.room.h) * TILE - enemy.radius;
        const nextX = clamp(enemy.x + dx, minX, maxX);
        const nextY = clamp(enemy.y + dy, minY, maxY);
        if (walkable(nextX, enemy.y, enemy.radius)) enemy.x = nextX;
        if (walkable(enemy.x, nextY, enemy.radius)) enemy.y = nextY;
    }

    function bossRoom() {
        return state.rooms.find(room => room.isBossRoom) || null;
    }

    function bossAlive() {
        return state.enemies.some(enemy => enemy.elite && !enemy.dead);
    }

    function currentVision(){return state.mode==='town'||(state.bossFightActive&&bossAlive())?Math.max(COLS,ROWS):player.vision}

    function insideRoom(entity, room, padding = 0) {
        if (!room) return false;
        return entity.x >= room.x * TILE + padding && entity.x <= (room.x + room.w) * TILE - padding && entity.y >= room.y * TILE + padding && entity.y <= (room.y + room.h) * TILE - padding;
    }

    function updateBossRoomLock() {
        const room = bossRoom();
        if (!room || !bossAlive()) return;
        if (!state.bossFightActive && insideRoom(player, room, player.radius)) {
            state.bossFightActive = true;
            log('Boss 房入口已经封锁，击败看守者后才能离开。');
        }
        if (!state.bossFightActive) return;
        player.x = clamp(player.x, room.x * TILE + player.radius, (room.x + room.w) * TILE - player.radius);
        player.y = clamp(player.y, room.y * TILE + player.radius, (room.y + room.h) * TILE - player.radius);
    }

    function updateRoomLock() {
        if(state.mode!=='dungeon'||state.bossFightActive)return;
        if(state.activeRoom){
            const alive=state.enemies.some(enemy=>!enemy.dead&&!enemy.elite&&enemy.room&&enemy.room.x===state.activeRoom.x&&enemy.room.y===state.activeRoom.y);
            if(!alive){log('房间内的怪物已全部击败，出口重新开启。');state.activeRoom=null;return}
            player.x=clamp(player.x,state.activeRoom.x*TILE+player.radius,(state.activeRoom.x+state.activeRoom.w)*TILE-player.radius);
            player.y=clamp(player.y,state.activeRoom.y*TILE+player.radius,(state.activeRoom.y+state.activeRoom.h)*TILE-player.radius);return;
        }
        const room=state.rooms.find(candidate=>!candidate.isBossRoom&&insideRoom(player,candidate,player.radius)&&state.enemies.some(enemy=>!enemy.dead&&!enemy.elite&&enemy.room&&enemy.room.x===candidate.x&&enemy.room.y===candidate.y));
        if(room){state.activeRoom={...room};log('房门已经封锁，击败房间内全部怪物才能离开。')}
    }

    function updatePlayer(dt) {
        player.attackCooldown = Math.max(0, player.attackCooldown - dt);
        player.dashCooldown = Math.max(0, player.dashCooldown - dt);
        player.invulnerable = Math.max(0, player.invulnerable - dt);
        player.dashTime = Math.max(0, player.dashTime - dt);
        let dx = (input.d ? 1 : 0) - (input.a ? 1 : 0) + touchMove.x;
        let dy = (input.s ? 1 : 0) - (input.w ? 1 : 0) + touchMove.y;
        if (dx || dy) {
            const length = Math.hypot(dx, dy);
            dx /= length;
            dy /= length;
            player.facing = { x:dx, y:dy };
            const speed = player.speed * (player.dashTime > 0 ? 3.2 : 1) * (player.poisonSlow ? 0.6 : 1);
            moveWithCollision(player, dx * speed * dt, dy * speed * dt, player.radius);
            const now=performance.now();
            if(now-state.lastCosmeticAt>120){
                if(cosmeticKind()==='trail')state.effects.push({type:'burst',x:player.x-dx*14,y:player.y-dy*14,life:.38,maxLife:.38,color:cosmeticColor()});
                else if(cosmeticKind()==='aura')state.effects.push({type:'text',x:player.x-dx*12,y:player.y-dy*12,text:cloud.equippedEffect==='heart_aura'?'♥':'✦',life:.65,maxLife:.65,color:cosmeticColor()});
                state.lastCosmeticAt=now;
            }
        }
        if (touchAutoFacing && state.mode === 'dungeon') {
            const isRanged = player.weapon.type === 'ranged';
            // 远程武器：只能锁定玩家当前所在房间内的怪物；同时根据武器品质决定索敌距离（高品质锁得远）
            // 近战武器保持 3 格锁定范围，避免站在走廊被隔壁房间吸引朝向
            let roomX = null, roomY = null;
            if (isRanged) {
                if (state.activeRoom) {
                    roomX = state.activeRoom.x;
                    roomY = state.activeRoom.y;
                } else {
                    const room = state.rooms.find(r => !r.isTown && insideRoom(player, r, player.radius));
                    if (room) { roomX = room.x; roomY = room.y; }
                }
            }
            // 远程武器索敌距离（像素）：品质越高锁得越远；近战固定 3 格
            const rangedLockRange = { common:260, fine:360, rare:480, epic:600, legendary:720 };
            const lockRange = isRanged ? (rangedLockRange[player.weapon.quality] || 300) : TILE * 3;
            const nearest = state.enemies
                .filter(enemy => {
                    if (enemy.dead) return false;
                    if (enemy.elite && !state.bossFightActive) return false;
                    // 远程武器：玩家在房间内时只选同房间的怪物（严格限定当前房间）
                    if (isRanged && roomX !== null && enemy.room) {
                        if (enemy.room.x !== roomX || enemy.room.y !== roomY) return false;
                    }
                    // 玩家在走廊（不在任何房间）时远程武器不自动索敌，避免隔墙瞄准
                    if (isRanged && roomX === null) return false;
                    return true;
                })
                .map(enemy => ({ enemy, distance:distance(enemy, player) }))
                .filter(item => item.distance <= lockRange)
                .sort((left, right) => left.distance - right.distance)[0];
            if (nearest) {
                player.facing = {
                    x:(nearest.enemy.x - player.x) / Math.max(1, nearest.distance),
                    y:(nearest.enemy.y - player.y) / Math.max(1, nearest.distance)
                };
            }
        }
        updateBossRoomLock();
        updateRoomLock();
        collectPickups();
        triggerTraps();
        discoverHidden();
        revealMap();
    }

    function dash() {
        if (state.paused || state.ended || player.dashCooldown > 0) return;
        player.dashTime = .18;
        player.invulnerable = .28;
        player.dashCooldown = player.dashCooldownMax;
        state.effects.push({ type:'ring', x:player.x, y:player.y, life:.3, maxLife:.3, color:'#88b594' });
    }

    function attack() {
        if (state.paused || state.ended || player.attackCooldown > 0) return;
        let weapon = player.weapon;
        if(weapon.type==='ranged'){
            if(sharedAmmoCount()<1){weapon=unarmedWeapon;if(performance.now()-state.lastEmptyAmmoLog>1500){log(`「${player.weapon.name}」弹药耗尽，改用徒手攻击。`);state.lastEmptyAmmoLog=performance.now()}}
            else consumeSharedAmmo();
        }
        player.attackCooldown = weapon.cooldown * player.attackSpeed;
        const damage = Math.round(weapon.damage * player.damageMultiplier * (1 + player.shards * player.shardPower));
        if (weapon.type === 'ranged') {
            state.projectiles.push({ x:player.x + player.facing.x * 18, y:player.y + player.facing.y * 18, vx:player.facing.x * weapon.projectileSpeed, vy:player.facing.y * weapon.projectileSpeed, radius:5, damage, owner:'player', life:weapon.range / weapon.projectileSpeed, color:cosmeticKind()==='projectile'?cosmeticColor():weapon.quality === 'epic' ? '#d58a63' : '#e8d5a7', splash:weapon.splash || 0, cosmeticTrail:cosmeticKind()==='projectile', trailClock:0 });
        } else {
            meleeHit(damage, weapon.range, weapon);
            state.effects.push({ type:'slash', x:player.x, y:player.y, angle:Math.atan2(player.facing.y, player.facing.x), range:weapon.range, life:.16, maxLife:.16, color:qualityColor(weapon.quality) });
            if (weapon.echo) setTimeout(() => { if (!state.ended) meleeHit(Math.round(damage * .55), weapon.range + 10, weapon); }, 140);
        }
    }

    function meleeHit(damage, range, weapon) {
        state.enemies.forEach(enemy => {
            if (enemy.dead) return;
            const dx = enemy.x - player.x, dy = enemy.y - player.y, d = Math.hypot(dx, dy);
            const facingDot = d ? (dx / d) * player.facing.x + (dy / d) * player.facing.y : 1;
            if (d <= range + enemy.radius && facingDot > -.05) {
                damageEnemy(enemy, damage);
                if (weapon.lifesteal) player.hp = Math.min(player.maxHp, player.hp + Math.max(1, Math.round(damage * weapon.lifesteal)));
            }
        });
        state.interactables.filter(item => item.type === 'crate' && !item.used && distance(item, player) <= range + 18).forEach(breakCrate);
    }

    function damageEnemy(enemy, amount) {
        if (enemy.elite && !state.bossFightActive) return;
        // 重装巨像：35% 伤害减免
        if (enemy.damageReduction) amount = Math.max(1, Math.round(amount * (1 - enemy.damageReduction)));
        enemy.hp -= amount;
        state.effects.push({ type:'text', x:enemy.x, y:enemy.y - 18, text:`-${amount}`, life:.65, maxLife:.65, color:'#f3b0a5' });
        if(enemy.hp>0&&enemy.hp<=enemy.maxHp*.25&&!enemy.executeRolled){enemy.executeRolled=true;enemy.executeReady=rand()<.25}
        if (enemy.hp <= 0 && !enemy.dead) {
            enemy.dead = true;
            player.kills++;
            dungeonApi('recordDungeonKill',{}).catch(()=>log('本次击杀统计同步失败。'));
            // 击败怪物恢复少量护甲（普通怪1点，精英怪3点，Boss 5点），分配到已装备且未满的护甲部位
            const armorRestore = enemy.elite ? 5 : enemy.champion ? 3 : 1;
            let restored = 0;
            for (const slot of ['chest','head','legs','hands']) {
                if (restored >= armorRestore) break;
                const armor = player.armor[slot];
                if (armor && armor.value < armor.maxArmor) {
                    const repair = Math.min(1, armor.maxArmor - armor.value, armorRestore - restored);
                    armor.value += repair;
                    restored += repair;
                }
            }
            if (restored > 0) {
                state.effects.push({ type:'text', x:player.x, y:player.y - 30, text:`+${restored}护甲`, life:.8, maxLife:.8, color:'#8bb0bf' });
            }
            const goldMult = enemy.champion ? 2.5 : enemy.kind === 'juggernaut' ? 3 : 1;
            state.pickups.push({x:enemy.x,y:enemy.y,type:'gold',amount:Math.round((ri(2,7)+player.floor)*goldMult),taken:false});
            if(enemy.heldWeapon&&enemy.heldWeapon.quality!=='legendary'&&rand()<.1){state.pickups.push({x:enemy.x+12,y:enemy.y-12,type:'item',item:{...enemy.heldWeapon,desc:`${enemy.heldWeapon.label} · 怪物掉落武器`},taken:false});log(`怪物掉落了「${enemy.heldWeapon.name}」。`)}
            // 精英怪额外掉落
            if (enemy.champion) {
                state.pickups.push({x:enemy.x+10,y:enemy.y-10,type:'shard',taken:false});
                if(rand()<.7)state.pickups.push({x:enemy.x-10,y:enemy.y+10,...createAmmoPickup(ri(6,14))});
                if(rand()<.5)state.pickups.push({x:enemy.x+14,y:enemy.y+6,type:'potion',taken:false});
                if(rand()<.55){const item=itemPool[ri(0,itemPool.length-1)];state.pickups.push({x:enemy.x-14,y:enemy.y-6,type:'item',item,taken:false})}
            } else if (enemy.kind === 'juggernaut') {
                // 重装巨像：高血量高威胁，掉落更丰厚
                if(rand()<.5)state.pickups.push({x:enemy.x-10,y:enemy.y-10,type:'shard',taken:false});
                if(rand()<.7)state.pickups.push({x:enemy.x+10,y:enemy.y+8,...createAmmoPickup(ri(5,10))});
                if(rand()<.4)state.pickups.push({x:enemy.x-8,y:enemy.y+12,type:'potion',taken:false});
                if(rand()<.35){const rareItems=itemPool.filter(it=>['rare','epic','legendary'].includes(it.quality));const item=rareItems.length>0?rareItems[ri(0,rareItems.length-1)]:itemPool[0];state.pickups.push({x:enemy.x+12,y:enemy.y-8,type:'item',item,taken:false})}
            } else {
                if (rand() < .25) state.pickups.push({x:enemy.x-12,y:enemy.y,type:'shard',taken:false});
                if(rand()<.45)state.pickups.push({x:enemy.x+8,y:enemy.y,...createAmmoPickup(ri(3,8))});
                if(rand()<.25)state.pickups.push({x:enemy.x-8,y:enemy.y+8,type:'potion',taken:false});
                if(rand()<.2){const item=itemPool[ri(0,itemPool.length-1)];state.pickups.push({x:enemy.x+12,y:enemy.y,type:'item',item,taken:false})}
            }
            const enemyName = enemy.kind === 'boss' ? '深层看守者' : enemyDisplayName(enemy);
            log(`击败${enemyName}。`);
            if (enemy.kind === 'boss') {
                player.keys++; grantCloudItem('brass_key');
                if (enemy.majorBoss) showUpgrade();
                log('首领锁链已经断裂，楼梯重新亮起。');
            }
        }
    }

    function executeEnemy() {
        if(state.paused||state.ended)return;
        const {item:enemy,distance:enemyDistance}=nearestToPlayer(state.enemies,target=>target.executeReady&&!target.dead);
        if(enemyDistance>78){log('附近没有可以踹飞斩杀的怪物。');return}
        if(!enemy){log('附近没有可以踹飞斩杀的怪物。');return}
        enemy.x=clamp(enemy.x+player.facing.x*48,enemy.room.x*TILE+enemy.radius,(enemy.room.x+enemy.room.w)*TILE-enemy.radius);enemy.y=clamp(enemy.y+player.facing.y*48,enemy.room.y*TILE+enemy.radius,(enemy.room.y+enemy.room.h)*TILE-enemy.radius);enemy.executeReady=false;state.effects.push({type:'burst',x:enemy.x,y:enemy.y,life:.45,maxLife:.45,color:'#f0d29b'});log('你将残血怪物踹飞并完成了斩杀。');damageEnemy(enemy,enemy.hp+1);
    }

    function updateEnemies(dt) {
        state.enemies.forEach(enemy => {
            if (enemy.dead) return;
            if (enemy.elite && !state.bossFightActive) return;
            if (!enemy.elite && (!enemy.room || !insideRoom(player, enemy.room, player.radius))) {
                enemy.windup=0;enemy.attackMode='';
                return;
            }
            if(enemy.dashTime>0){
                enemy.dashTime=Math.max(0,enemy.dashTime-dt);moveEnemyInsideRoom(enemy,enemy.dashX*dt,enemy.dashY*dt);
                if(!enemy.dashHit&&distance(enemy,player)<=enemy.radius+player.radius+8){enemy.dashHit=true;damagePlayer(Math.round(enemy.damage*(enemy.elite?1.35:1.15)),enemy.dashArmorPierce||0)}
                return;
            }
            enemy.cooldown = Math.max(0, enemy.cooldown - dt);
            const dx = player.x - enemy.x, dy = player.y - enemy.y, d = Math.hypot(dx, dy) || 1;
            if (d > 430) return;

            // 自爆怪：靠近后自爆
            if (enemy.kind === 'bomber') {
                if (enemy.fuseTime > 0) {
                    // 自爆倒计时中，不移动，脉冲发光
                    enemy.fuseTime -= dt;
                    if (enemy.fuseTime <= 0) {
                        // 爆炸！
                        const explodeRadius = 80;
                        state.effects.push({ type:'explosion', x:enemy.x, y:enemy.y, life:.5, maxLife:.5, radius:explodeRadius });
                        if (distance(enemy, player) <= explodeRadius + player.radius) {
                            damagePlayer(enemy.damage, 0.25); // 25% 破甲
                        }
                        enemy.hp = 0; enemy.dead = true;
                        log('自爆怪爆炸了！');
                        return;
                    }
                    return;
                }
                if (d <= 55) {
                    enemy.fuseTime = 1.2;
                    enemy.attackMode = 'selfdestruct';
                    return;
                }
                // 高速冲向玩家
                const bx = dx / d, by = dy / d;
                moveEnemyInsideRoom(enemy, bx * enemy.speed * dt, by * enemy.speed * dt);
                return;
            }

            if (enemy.windup > 0) {
                enemy.windup -= dt;
                if (enemy.windup <= 0) resolveEnemyAttack(enemy);
                return;
            }

            const preferred = enemy.kind === 'archer' ? 230 : enemy.kind === 'shotgunner' ? 165 : enemy.kind === 'boss' ? 82 : enemy.kind === 'juggernaut' ? 55 : enemy.kind === 'brute' ? 66 : 48;
            const attackRange=enemy.kind==='boss'?390:enemy.kind==='archer'?340:enemy.kind==='shotgunner'?260:enemy.kind==='juggernaut'?95:preferred+12;
            if (enemy.cooldown <= 0 && d <= attackRange) {
                if(enemy.kind==='boss')enemy.attackMode=chooseBossSkill(d);
                else if(enemy.kind==='archer')enemy.attackMode='shot';
                else if(enemy.kind==='shotgunner')enemy.attackMode='shotgun';
                else if(enemy.kind==='juggernaut')enemy.attackMode='slam';
                else if(enemy.champion){
                    // 精英怪：远距离更频繁冲刺（60%概率），冲刺有几率真实伤害
                    enemy.attackMode = d>80 ? (rand()<.6?'dash':'melee') : (rand()<.35?'dash':'melee');
                    enemy.dashTrueDamage = enemy.attackMode==='dash' && rand()<.4;
                }
                else enemy.attackMode=d>95&&rand()<.3?'dash':'melee';
                enemy.windup=(enemy.attackMode==='dash'||enemy.attackMode==='charge'?(enemy.champion?.65:.85):enemy.attackMode==='summon'?1.05:enemy.attackMode==='homing'?1.0:enemy.attackMode==='slam'?1.15:enemy.kind==='juggernaut'?1.15:enemy.kind==='brute'?.85:enemy.kind==='boss'?.72*BOSS_MOBILE_WINDUP:enemy.kind==='archer'?.6:enemy.kind==='shotgunner'?.7:.42)/Math.max(.1,enemy.attackSpeed||1);
                enemy.targetX = player.x;
                enemy.targetY = player.y;
                return;
            }

            let moveX = dx / d, moveY = dy / d;
            if (enemy.kind === 'archer' && d < 150) { moveX *= -1; moveY *= -1; }
            // 霰弹怪贴近到115像素内会后撤，保持中距离喷射
            if (enemy.kind === 'shotgunner' && d < 115) { moveX *= -1; moveY *= -1; }
            const holdPosition = enemy.kind === 'archer' ? (d >= 125 && d <= 170) : enemy.kind === 'shotgunner' ? (d >= 120 && d <= 160) : false;
            if (!holdPosition) moveEnemyInsideRoom(enemy, moveX * enemy.speed * dt, moveY * enemy.speed * dt);
            // 爬行者：移动时留下毒雾，踩上去减速
            if (enemy.kind === 'crawler' && !enemy.windup) {
                enemy.poisonTimer = (enemy.poisonTimer || 0) - dt;
                if (enemy.poisonTimer <= 0 && d < 280) {
                    enemy.poisonTimer = 1.2 + rand() * 0.6;
                    state.effects.push({ type: 'poison', x: enemy.x, y: enemy.y, life: 5, maxLife: 5, radius: 22 });
                }
            }
        });
        state.enemies = state.enemies.filter(enemy => !enemy.dead);
    }

    function chooseBossSkill(distanceToPlayer){
        const roll=rand();
        // 如果场上已有追踪导弹，则不再释放
        const hasHoming = state.projectiles.some(p => p.homing);
        if(roll<.18&&!hasHoming)return'homing';
        if(player.floor>=5&&roll<.34)return'summon';
        if(distanceToPlayer>190)return roll<.56?'charge':'volley';
        if(roll<.48)return'nova';
        if(roll<.72)return'charge';
        return'melee';
    }

    function startEnemyDash(enemy,speed){
        const dx=enemy.targetX-enemy.x,dy=enemy.targetY-enemy.y,length=Math.hypot(dx,dy)||1;
        enemy.dashX=dx/length*speed;enemy.dashY=dy/length*speed;enemy.dashTime=enemy.elite?.42:.3;enemy.dashHit=false;
        state.effects.push({type:'ring',x:enemy.x,y:enemy.y,life:.3,maxLife:.3,color:enemy.elite?'#b47bdd':'#dc7868'});
    }

    function resolveEnemyAttack(enemy) {
        enemy.cooldown = (enemy.attackInterval|| (enemy.kind === 'boss' ? 1.45 * BOSS_MOBILE_COOLDOWN : enemy.kind === 'archer' ? 1.55 : enemy.kind === 'shotgunner' ? 2.1 : enemy.kind === 'juggernaut' ? 2.4 : enemy.kind === 'brute' ? 1.7 : .9)) * enemy.cooldownScale;
        if (enemy.attackMode === 'shot') {
            shootEnemyProjectile(enemy, Math.atan2(enemy.targetY - enemy.y, enemy.targetX - enemy.x));
        } else if (enemy.attackMode === 'shotgun') {
            // 霰弹怪：一次喷射5颗子弹，呈扇形散开，单颗伤害较低
            const angle = Math.atan2(enemy.targetY - enemy.y, enemy.targetX - enemy.x);
            [-.34, -.17, 0, .17, .34].forEach(offset => shootEnemyProjectile(enemy, angle + offset, .55));
            // 枪口闪光
            state.effects.push({ type:'burst', x:enemy.x+Math.cos(angle)*18, y:enemy.y+Math.sin(angle)*18, life:.2, maxLife:.2, color:'#f0a050' });
        } else if (enemy.attackMode === 'nova') {
            // Boss 虚空弹幕：40% 破甲
            for (let i = 0; i < 14; i++) shootEnemyProjectile(enemy, Math.PI * 2 * i / 14, .72, enemy.elite ? .4 : 0);
        } else if(enemy.attackMode==='volley'){
            const angle=Math.atan2(enemy.targetY-enemy.y,enemy.targetX-enemy.x);[-.24,0,.24].forEach(offset=>shootEnemyProjectile(enemy,angle+offset,.82));
        } else if(enemy.attackMode==='dash'||enemy.attackMode==='charge'){
            // 精英怪冲刺更快，且40%概率真实伤害（armorPierce=1）
            enemy.dashArmorPierce = enemy.champion ? (enemy.dashTrueDamage ? 1 : .3) : (enemy.elite ? .6 : 0);
            startEnemyDash(enemy, (enemy.champion ? 460 : (enemy.elite ? 520 : 390)) * (enemy.elite ? BOSS_MOBILE_PROJ_SPEED : 1));
        } else if(enemy.attackMode==='summon'&&player.floor>=5){
            const summonPool = player.floor >= 5 ? ['crawler','archer','shotgunner','bomber','juggernaut'] : ['crawler','archer','shotgunner','bomber'];
            for(let i=0;i<2;i++){const tx=clamp(tileOf(enemy.x)+ri(-2,2),enemy.room.x+1,enemy.room.x+enemy.room.w-2),ty=clamp(tileOf(enemy.y)+ri(-2,2),enemy.room.y+1,enemy.room.y+enemy.room.h-2);state.enemies.push(makeEnemy(summonPool[ri(0,summonPool.length-1)],tx,ty,false,enemy.room))}
            log('Boss 召唤了两名遗迹守卫。');
        } else if(enemy.attackMode==='homing'){
            // 追踪导弹：慢速、单发、追踪3秒后自爆、造成25%最大生命值伤害
            const angle=Math.atan2(player.y-enemy.y,player.x-enemy.x);
            const speed=95; // 很慢
            state.projectiles.push({
                x:enemy.x, y:enemy.y,
                vx:Math.cos(angle)*speed, vy:Math.sin(angle)*speed,
                radius:9, damage:Math.round(player.maxHp*0.25),
                owner:'enemy', life:3.2, maxLife:3.2,
                color:'#ff4040', room:enemy.room?{...enemy.room}:null,
                armorPierce:0, homing:true, homingTime:3.0,
                turnRate:2.2, speed:speed, explodeRadius:70
            });
            log('Boss 释放了追踪导弹！');
        } else if(enemy.attackMode==='slam'){
            // 重装巨像：巨力砸地，大范围震波，高伤害
            const slamRange = 95;
            if (distance(enemy, player) <= slamRange + player.radius) {
                damagePlayer(enemy.damage, 0.15); // 15% 破甲
            }
            // 多重震波特效
            state.effects.push({ type:'shockwave', x:enemy.x, y:enemy.y, life:.5, maxLife:.5, radius:90 });
            state.effects.push({ type:'shockwave', x:enemy.x, y:enemy.y, life:.35, maxLife:.35, radius:55 });
            // 砸地碎片
            for(let i=0;i<6;i++){
                const a=Math.random()*Math.PI*2;
                state.effects.push({type:'burst',x:enemy.x+Math.cos(a)*20,y:enemy.y+Math.sin(a)*20,life:.3,maxLife:.3,color:'#8a7060'});
            }
        } else {
            const range = enemy.kind === 'brute' ? 84 : enemy.kind === 'boss' ? 105 : 58;
            if (distance(enemy, player) <= range) {
                damagePlayer(enemy.damage);
                // 重击者：命中时产生地面震波
                if (enemy.kind === 'brute') {
                    state.effects.push({ type:'shockwave', x:player.x, y:player.y, life:.4, maxLife:.4, radius:60 });
                }
            }
            // 重击者：即使未命中也产生震波特效（表现其重击砸地）
            if (enemy.kind === 'brute') {
                const angle=Math.atan2(enemy.targetY-enemy.y,enemy.targetX-enemy.x);
                state.effects.push({ type:'shockwave', x:enemy.x+Math.cos(angle)*40, y:enemy.y+Math.sin(angle)*40, life:.35, maxLife:.35, radius:50 });
            }
        }
        enemy.attackMode = '';
    }

    function shootEnemyProjectile(enemy, angle, multiplier = 1, armorPierce = 0) {
        const speed = (enemy.kind === 'boss' ? 230 * BOSS_MOBILE_PROJ_SPEED : enemy.kind === 'shotgunner' ? 250 : 290) * floorDifficulty().speed * Math.max(.1,enemy.attackSpeed||1);
        state.projectiles.push({ x:enemy.x, y:enemy.y, vx:Math.cos(angle)*speed, vy:Math.sin(angle)*speed, radius:enemy.kind === 'boss' ? 7 : enemy.kind === 'shotgunner' ? 6 : 5, damage:Math.round(enemy.damage*multiplier), owner:'enemy', life:2.2, color:enemy.kind === 'boss' ? (armorPierce > 0 ? '#ff5050' : '#b47bdd') : enemy.kind === 'shotgunner' ? '#e08a5d' : '#d65f52', splash:0, room:enemy.room?{...enemy.room}:null, armorPierce });
    }

    function explodeHomingMissile(projectile) {
        // 爆炸特效
        state.effects.push({
            type:'explosion', x:projectile.x, y:projectile.y,
            life:.5, maxLife:.5, radius:projectile.explodeRadius||70
        });
        // 爆炸范围内对玩家造成伤害
        if (distance(projectile, player) <= (projectile.explodeRadius||70) + player.radius) {
            damagePlayer(projectile.damage, projectile.armorPierce || 0);
        }
        projectile.life = 0;
    }

    function updateProjectiles(dt) {
        state.projectiles.forEach(projectile => {
            // 追踪导弹逻辑
            if (projectile.homing) {
                projectile.homingTime -= dt;
                if (projectile.homingTime > 0) {
                    // 追踪阶段：缓慢转向玩家
                    const dx = player.x - projectile.x, dy = player.y - projectile.y;
                    const targetAngle = Math.atan2(dy, dx);
                    const currentAngle = Math.atan2(projectile.vy, projectile.vx);
                    // 角度差归一化到 [-PI, PI]
                    let angleDiff = targetAngle - currentAngle;
                    while (angleDiff > Math.PI) angleDiff -= Math.PI * 2;
                    while (angleDiff < -Math.PI) angleDiff += Math.PI * 2;
                    // 限制转向速率
                    const maxTurn = (projectile.turnRate || 2.2) * dt;
                    const turn = clamp(angleDiff, -maxTurn, maxTurn);
                    const newAngle = currentAngle + turn;
                    const spd = projectile.speed || 95;
                    projectile.vx = Math.cos(newAngle) * spd;
                    projectile.vy = Math.sin(newAngle) * spd;
                    // 追踪尾焰
                    projectile.trailClock = (projectile.trailClock || 0) + dt;
                    if (projectile.trailClock > 0.05) {
                        projectile.trailClock = 0;
                        state.effects.push({type:'burst', x:projectile.x, y:projectile.y, life:.25, maxLife:.25, color:'#ff6030'});
                    }
                } else {
                    // 停止追踪：直线飞行一段后原地爆炸
                    if (!projectile.stoppedTracking) {
                        projectile.stoppedTracking = true;
                        projectile.explodeDelay = 0.3;
                    }
                    projectile.explodeDelay -= dt;
                    if (projectile.explodeDelay <= 0) {
                        explodeHomingMissile(projectile);
                        return;
                    }
                }
            }
            projectile.x += projectile.vx * dt;
            projectile.y += projectile.vy * dt;
            projectile.life -= dt;
            if(projectile.cosmeticTrail&&projectile.life>0){projectile.trailClock=(projectile.trailClock||0)+dt;if(projectile.trailClock>.045){projectile.trailClock=0;state.effects.push({type:'burst',x:projectile.x,y:projectile.y,life:.2,maxLife:.2,color:'#ffd36a'})}}
            // 追踪导弹撞到墙/柱子直接爆炸
            if (projectile.homing) {
                if (!walkable(projectile.x, projectile.y, projectile.radius)) {
                    explodeHomingMissile(projectile);
                    return;
                }
                if (projectile.room && !insideRoom(projectile, projectile.room, projectile.radius)) {
                    explodeHomingMissile(projectile);
                    return;
                }
            } else {
                if(projectile.owner==='enemy'&&projectile.room&&!insideRoom(projectile,projectile.room,projectile.radius))projectile.life=0;
                if (!walkable(projectile.x, projectile.y, projectile.radius)) projectile.life = 0;
            }
            if (projectile.owner === 'enemy' && projectile.life > 0 && (!projectile.room||insideRoom(player,projectile.room,player.radius)) && distance(projectile, player) <= projectile.radius + player.radius) {
                if (projectile.homing) {
                    // 追踪导弹命中玩家直接爆炸
                    explodeHomingMissile(projectile);
                } else {
                    damagePlayer(projectile.damage, projectile.armorPierce || 0);
                    projectile.life = 0;
                }
            }
            if (projectile.owner === 'player' && projectile.life > 0) {
                const enemy = state.enemies.find(target => !target.dead && distance(projectile, target) <= projectile.radius + target.radius);
                if (enemy) {
                    damageEnemy(enemy, projectile.damage);
                    if (projectile.splash) state.enemies.filter(target => target !== enemy && distance(target, enemy) <= projectile.splash).forEach(target => damageEnemy(target, Math.round(projectile.damage * .55)));
                    projectile.life = 0;
                }
            }
        });
        state.projectiles = state.projectiles.filter(projectile => projectile.life > 0);
    }

    function damagePlayer(amount, armorPierce = 0) {
        if (state.mode==='town'||player.invulnerable > 0 || state.ended) return;
        let remaining=Math.max(0,Math.round(amount)),absorbed=0;
        // armorPierce: 0=正常护甲抵消, 0~1=部分穿透(直接扣血比例), 1=完全无视护甲
        const pierceAmount = Math.round(remaining * armorPierce);
        remaining -= pierceAmount;
        for(const slot of ['chest','head','legs','hands']){
            const armor=player.armor[slot];
            if(!armor||armor.value<=0||remaining<=0)continue;
            const blocked=Math.min(armor.value,remaining);armor.value-=blocked;remaining-=blocked;absorbed+=blocked;
        }
        remaining += pierceAmount;
        player.hp -= remaining;
        player.invulnerable = .45;
        state.effects.push({ type:'screen', life:.18, maxLife:.18, color: armorPierce > 0 ? '#5a1a1a' : '#8f2f2a' });
        state.effects.push({ type:'text', x:player.x, y:player.y - 20, text:remaining>0?`-${remaining}生命`:`-${absorbed}护甲`, life:.65, maxLife:.65, color:remaining>0?(armorPierce>0?'#ff6050':'#ff8f84'):'#9cc8d8' });
        if (player.hp <= 0) endRun(false);
    }

    function usePotion() {
        if (state.paused || player.potions < 1 || player.hp >= player.maxHp || state.ended) return;
        if (state.potionEffect) { log('药剂正在生效，请等待恢复完成。'); return; }
        player.potions--;
        consumeCloudItem('dungeon_potion',1);
        const total = Math.min(player.potionPower, player.maxHp - player.hp);
        state.potionEffect = { startedAt:performance.now(), duration:3000, filterDuration:3600, startHp:player.hp, total, lastTextAt:0 };
        log(`使用地牢药剂，3秒内逐渐恢复 ${total} 点生命。`);
    }

    function updatePotionEffect(now) {
        const effect=state.potionEffect;
        if(!effect)return;
        const elapsed=Math.max(0,now-effect.startedAt);
        const ratio=clamp(elapsed/effect.duration,0,1);
        const nextHp=Math.min(player.maxHp,effect.startHp+effect.total*ratio);
        const healed=nextHp-player.hp;
        player.hp=nextHp;
        if(healed>0&&now-effect.lastTextAt>=220){
            state.effects.push({type:'text',x:player.x,y:player.y-20,text:`+${Math.ceil(healed)}`,life:.45,maxLife:.45,color:'#8bc99a'});
            effect.lastTextAt=now;
        }
        if(elapsed>=effect.duration)state.potionEffect=null;
    }

    function createAmmoPickup(count) {
        return { type:'ammo', ammoType:rand()<.5?'modern':'general', count, taken:false };
    }

    function interact() {
        if (state.paused || state.ended) return;
        if (state.mode === 'town') {
            const {item:townTarget,distance:townDistance}=nearestToPlayer(state.interactables);
            if (!townTarget || townDistance > TILE * 2) { log(touchAutoFacing?'靠近主城设施后点击互动按钮。':'靠近主城设施后按 E 互动。'); return; }
            if (townTarget.type==='townDoor') requestDungeonEntry();
            else if (townTarget.type==='bank') openBank();
            else if (townTarget.type==='synthesis') openSynthesisMachine();
            else if (townTarget.type==='repair') openRepairMachine();
            else if (townTarget.type==='armsDealer') showArmsDealer(townTarget);
            else if (townTarget.type==='warehouse') {
                if(townTarget.locked){buyWarehouse(townTarget.warehouseNo);return;}
                cloud.warehouseNo=townTarget.warehouseNo; hydrateWarehouse(cloud.warehouseRows,cloud.warehouseNo); openBag(true);
            }
            return;
        }
        if (portalUnlocked() && distance(state.exit, player) <= 80) {
            nextFloor();
            return;
        }
        // 地牢内互动前置条件：当前房间没有活着的普通怪物（清完怪才能开宝箱/营火等）
        let roomHasEnemies = false;
        if (state.activeRoom) {
            roomHasEnemies = state.enemies.some(enemy => !enemy.dead && !enemy.elite && enemy.room && enemy.room.x === state.activeRoom.x && enemy.room.y === state.activeRoom.y);
        } else {
            const room = state.rooms.find(r => !r.isBossRoom && !r.isTown && insideRoom(player, r, player.radius));
            if (room) roomHasEnemies = state.enemies.some(enemy => !enemy.dead && !enemy.elite && enemy.room && enemy.room.x === room.x && enemy.room.y === room.y);
        }
        if (roomHasEnemies) { log('房间里还有怪物，先清理干净再互动。'); return; }
        const interactMax = TILE * 2;
        const {item:target,distance:targetDistance}=nearestToPlayer(state.interactables,item => !item.used && !item.hidden);
        if (target && targetDistance <= interactMax) {
            if (target.type === 'chest' || target.type === 'hidden') openChest(target);
            else if (target.type === 'camp') useCamp(target);
            else if (target.type === 'shrine') { target.used = true; showUpgrade(); }
            else if (target.type === 'merchant') showMerchant(target);
            else if (target.type === 'crate') breakCrate(target);
            return;
        }
        log('附近没有可以互动的目标。');
    }

    function synthesisEntryAtSlot(slot) {
        return player.inventory.find(entry => Number(entry.slot) === Number(slot)) || null;
    }

    function renderSynthesisMachine() {
        const materials = $('synthesisMaterials');
        const grid = $('synthesisBagGrid');
        const selected = new Set(synthesisSlots);
        const entries = bagEntries().filter(entry => entry.officialItem?.id?.startsWith('d_'));
        materials.innerHTML = Array.from({length:5}, (_, index) => {
            const entry = synthesisEntryAtSlot(synthesisSlots[index]);
            return entry ? `<div class="synthesis-slot filled"><i>${entry.icon}</i><b>${entry.name}</b><small>${entry.quality}</small></div>` : '<div class="synthesis-slot">选择材料</div>';
        }).join('');
        grid.innerHTML = entries.map(entry => `<button type="button" class="bag-slot ${entry.quality}${selected.has(entry.slot)?' chosen':''}" data-synthesis-slot="${entry.slot}"><i>${entry.icon}</i><b>${entry.name}</b><em>${entry.count||1}</em></button>`).join('') || '<p style="grid-column:1/-1;text-align:center;color:var(--muted);font-size:10px;">背包中没有可重铸的 [D] 物品。</p>';
        grid.querySelectorAll('[data-synthesis-slot]').forEach(button => button.addEventListener('click', () => {
            const slot = Number(button.dataset.synthesisSlot);
            const index = synthesisSlots.indexOf(slot);
            if (index >= 0) synthesisSlots.splice(index, 1);
            else if (synthesisSlots.length < 5) synthesisSlots.push(slot);
            renderSynthesisMachine();
        }));
        const qualities = synthesisSlots.map(slot => synthesisEntryAtSlot(slot)?.quality).filter(Boolean);
        const sameQuality = qualities.length === 5 && qualities.every(value => value === qualities[0]);
        $('synthesizeBtn').disabled = !sameQuality;
        $('synthesisNotice').textContent = qualities.length === 0 ? '请从下方背包选择 5 个同品质物品。' : sameQuality ? `已选择 5 个${qualities[0]}品质材料，可以开始重铸。` : '5 个材料必须属于完全相同的品质。';
    }

    function openSynthesisMachine() {
        if (state.mode !== 'town') return;
        clearInputs(); state.paused = true; synthesisSlots = [];
        renderSynthesisMachine(); $('synthesisModal').classList.add('show');
    }

    function closeSynthesisMachine() {
        $('synthesisModal').classList.remove('show'); state.paused = false; synthesisSlots = [];
    }

    function repairCost(maxArmor) { return Math.max(100, Math.min(500, 100 + Math.round((Number(maxArmor || 1) / 156) * 400))); }
    function renderRepairMachine() {
        const list = $('repairList');
        const labels = {head:'头部', chest:'胸甲', hands:'护手', legs:'腿甲'};
        const rows = Object.entries(labels).map(([slot,label]) => ({slot,label,armor:player.armor[slot]})).filter(row => row.armor);
        list.innerHTML = rows.map(row => {
            const armor = row.armor, max = Math.max(1, Number(armor.maxArmor || 1)), value = Math.max(0, Number(armor.value || 0));
            return `<button type="button" class="repair-card" data-repair-slot="${row.slot}" ${value >= max ? 'disabled' : ''}><i>${armor.icon || '▦'}</i><span><b>${armor.name || row.label}</b><small>${row.label} · ${value} / ${max} 护甲</small></span><strong>${value >= max ? '已满' : `${repairCost(max)} 翼币`}</strong></button>`;
        }).join('') || '<p style="grid-column:1/-1;text-align:center;color:var(--muted);font-size:10px;">当前没有已装备的护甲。</p>';
        list.querySelectorAll('[data-repair-slot]').forEach(button => button.addEventListener('click', () => repairArmor(button.dataset.repairSlot)));
    }
    function openRepairMachine() { if (state.mode !== 'town') return; clearInputs(); state.paused = true; renderRepairMachine(); $('repairNotice').textContent = `当前持有 ${player.wingCoins.toLocaleString('zh-CN')} 翼币。`; $('repairModal').classList.add('show'); }
    function closeRepairMachine() { $('repairModal').classList.remove('show'); state.paused = false; }
    async function repairArmor(slot) {
        const armor = player.armor[slot]; if (!armor) return;
        $('repairNotice').textContent = '正在核对云端护甲与翼币余额…';
        try {
            const data = await dungeonApi('repairDungeonArmor', { equipmentSlot: slot });
            player.wingCoins = Number(data.state?.wing_coins ?? player.wingCoins);
            hydrateCloud(data, true); player.wingCoins = Number(data.state?.wing_coins ?? player.wingCoins);
            renderRepairMachine(); updateUi(); saveCloudState();
            $('repairNotice').textContent = `修复完成，当前翼币 ${player.wingCoins.toLocaleString('zh-CN')}。`;
        } catch (error) {
            const messages = {not_equipped:'这件护甲没有装备。',already_full:'这件护甲已经是满耐久。',not_enough_wing_coins:'翼币不足，无法修复。'};
            $('repairNotice').textContent = messages[error.message] || '修复失败，请重新读取云端数据后再试。';
            renderRepairMachine();
        }
    }

    async function synthesizeDungeonItems() {
        if (synthesisSlots.length !== 5) return;
        const slots = [...synthesisSlots];
        $('synthesizeBtn').disabled = true;
        try {
            const data = await dungeonApi('synthesizeDungeonItems', { slots: JSON.stringify(slots) });
            hydrateCloud(data, true); synthesisSlots = []; renderSynthesisMachine();
            if (data.success && data.result) log(`重铸成功，获得「${data.result.displayName || data.result.name}」。`);
            else log('重铸失败，5 个材料已经消耗。');
            updateUi();
        } catch (error) {
            $('synthesisNotice').textContent = ({same_quality:'必须选择 5 个同品质物品。',max_quality:'传说品质已经是最高品质。',full:'背包没有空位放置重铸结果。',dungeon_only:'只能使用 [D] 地牢物品。'}[error.message] || '重铸失败，请重新读取背包后再试。');
            renderSynthesisMachine();
        }
    }

    function openChest(chest) {
        if (chest.locked && player.keys < 1) {
            // 红色提示浮现在箱子上方，持续3秒
            state.keyWarning = { x: chest.x, y: chest.y, until: performance.now() + 3000 };
            log('这是上锁武器箱，需要一把黄铜钥匙。');
            return;
        }
        if (chest.locked) { player.keys--; consumeCloudItem('brass_key',1); }
        chest.used = true;
        state.pendingChestPos = { x: chest.x, y: chest.y };
        state.paused = true;
        startWeaponReel(chest.type === 'hidden' ? 1 : 0);
    }

    function useCamp(camp) {
        camp.used = true;
        const healed = Math.min(42, player.maxHp - player.hp);
        player.hp += healed;
        player.potions++;
        grantCloudItem('dungeon_potion');
        log(`在营火旁恢复 ${healed} 点生命，并补充一瓶药剂。`);
    }

    function breakCrate(crate) {
        if (crate.used) return;
        crate.used = true;
        if (rand() < .6) { player.potions++; grantCloudItem('dungeon_potion'); }
        else if (rand() < .6) { player.keys++; grantCloudItem('brass_key'); }
        else state.pickups.push({x:crate.x,y:crate.y,type:'gold',amount:ri(5,14),taken:false});
        state.effects.push({ type:'burst', x:crate.x, y:crate.y, life:.45, maxLife:.45, color:'#b18a52' });
        log('木箱被击碎，里面掉出了一份补给。');
    }

    // 地牢商人品质售价区间（翼币）
    const MERCHANT_PRICE_RANGES = {
        common: [1, 50],
        fine: [50, 100],
        rare: [100, 150],
        epic: [200, 300],
        legendary: [500, 1000]
    };

    function merchantPriceForQuality(quality) {
        const range = MERCHANT_PRICE_RANGES[quality] || MERCHANT_PRICE_RANGES.common;
        return range[0] + Math.floor(Math.random() * (range[1] - range[0] + 1));
    }

    function createMerchantStock() {
        const qualities = ['common', 'fine', 'rare', 'epic', 'legendary'];
        // 消耗品按品质映射
        const consumableByQuality = {
            fine: { type:'potion', name:'地牢药剂', icon:'✚', desc:() => `恢复 ${player.potionPower} 点生命` },
            rare: { type:'key', name:'黄铜钥匙', icon:'⌘', desc:() => '开启一只上锁武器箱' },
            epic: { type:'shard', name:'遗迹碎片', icon:'◇', desc:() => '提高碎片共鸣的战斗收益' }
        };
        const offers = [];
        for (let i = 0; i < 3; i++) {
            // 每个品质 20% 概率
            const quality = qualities[Math.floor(Math.random() * 5)];
            const qualityWeapons = weaponPool.filter(w => w.quality === quality);
            const consumable = consumableByQuality[quality];
            let offer;
            // 有对应消耗品时 35% 概率出消耗品，否则出武器
            if (consumable && Math.random() < 0.35) {
                offer = { type:consumable.type, name:consumable.name, icon:consumable.icon, quality, desc:consumable.desc() };
            } else if (qualityWeapons.length > 0) {
                const weapon = {...qualityWeapons[Math.floor(Math.random() * qualityWeapons.length)]};
                offer = {
                    type:'weapon', weapon, name:weapon.name, icon:weapon.icon, quality:weapon.quality,
                    desc:`${weapon.label} · ${weapon.damage}伤害 · ${weapon.type==='ranged'?'远程':'近战'}`
                };
            } else {
                // 兜底：该品质无武器时使用加权随机
                const weapon = {...weightedWeapon(0)};
                offer = {
                    type:'weapon', weapon, name:weapon.name, icon:weapon.icon, quality:weapon.quality,
                    desc:`${weapon.label} · ${weapon.damage}伤害 · ${weapon.type==='ranged'?'远程':'近战'}`
                };
            }
            offer.price = merchantPriceForQuality(quality);
            offers.push(offer);
        }
        return offers.map((offer, index) => ({...offer, id:`merchant-${state.seed}-${index}`, sold:false}));
    }

    function createArmsDealerStock() {
        const shotgun = weaponPool.find(item => item.id === 'scatter_gun');
        const laser = weaponPool.find(item => item.id === 'laser_gun');
        return [
            {type:'weapon', weapon:{...shotgun}, name:shotgun.name, icon:shotgun.icon, quality:shotgun.quality, price:500, desc:'霰弹枪 · 近距离高伤害 · 共用弹药'},
            {type:'weapon', weapon:{...laser}, name:laser.name, icon:laser.icon, quality:laser.quality, price:800, desc:'激光枪 · 高射速远程武器 · 共用弹药'},
            {type:'ammo', name:'通用弹药', icon:'•', quality:'common', price:10, count:1, desc:'购买 1 发，共用所有枪械弹药'}
        ].map((item,index)=>({...item,id:`jack-${state.seed}-${index}`,sold:false}));
    }

    function showArmsDealer(dealer) {
        dealer.used = true;
        dealer.stock ||= createArmsDealerStock();
        state.activeMerchant = dealer;
        state.paused = true;
        showMerchantNotice('军火商杰克：现代弹药 10 翼币 1 发，使用翼币购买。');
        renderMerchant();
        $('merchantModal').classList.add('show');
    }

    function showMerchant(merchant) {
        merchant.used = true;
        merchant.stock ||= createMerchantStock();
        state.activeMerchant = merchant;
        state.paused = true;
        showMerchantNotice('选择一件商品进行购买。');
        renderMerchant();
        $('merchantModal').classList.add('show');
    }

    function showMerchantNotice(message, type = '') {
        const notice = $('merchantNotice');
        notice.textContent = message;
        notice.className = `merchant-notice${type ? ` ${type}` : ''}`;
    }

    function renderMerchant() {
        const merchant = state.activeMerchant;
        $('merchantGold').textContent = player.wingCoins;
        $('merchantStock').innerHTML = (merchant?.stock || []).map(item => `<article class="merchant-card ${item.quality} ${item.sold?'sold':''}"><i>${item.icon}</i><h3>${item.name}</h3><p>${item.desc}</p><button data-offer="${item.id}" ${item.sold?'disabled':''}>${item.sold?'已售罄':`${item.price} 翼币购买`}</button></article>`).join('');
        $('merchantStock').querySelectorAll('[data-offer]').forEach(button => button.addEventListener('click', () => buyMerchantItem(button.dataset.offer)));
    }

    async function buyMerchantItem(offerId) {
        const item = state.activeMerchant?.stock.find(offer => offer.id === offerId);
        if (!item || item.sold) return;
        if (player.wingCoins < item.price) {
            const missing = item.price - player.wingCoins;
            showMerchantNotice(`翼币不足，无法购买「${item.name}」，还差 ${missing} 翼币。`, 'error');
            log(`翼币不足，无法购买「${item.name}」。`);
            return;
        }
        const stackUid = {potion:'stack-potion',key:'stack-key',shard:'stack-shard',ammo:'stack-ammo'}[item.type];
        const needsSlot = item.type === 'weapon' || (stackUid && !bagEntries().some(entry => entry.uid === stackUid));
        if (needsSlot && bagEntries().length >= 21) { showMerchantNotice('官网背包已满，无法购买这件物品。', 'error'); log('官网背包已满，无法购买这件物品。'); return; }
        const localId=item.type==='potion'?'dungeon_potion':item.type==='key'?'brass_key':item.type==='shard'?'relic_shard':item.type==='ammo'?'modern_ammo':item.weapon?.id;
        if(!localId||!await grantCloudItem(localId))return;
        player.wingCoins -= item.price;
        if (item.type === 'potion') player.potions++;
        else if (item.type === 'key') player.keys++;
        else if (item.type === 'shard') player.shards++;
        else if (item.type === 'ammo') player.ammo.modern += Number(item.count || 1);
        item.sold = true;
        syncBagOrder();
        log(`从无灯商人处购买了「${item.name}」。`);
        renderMerchant();
        showMerchantNotice(`购买成功：「${item.name}」已放入地牢背包。`, 'success');
        updateUi();
    }

    function collectPickups() {
        state.pickups.forEach(item => {
            if (item.taken || item.collecting || distance(item, player) > 25) return;
            if(item.type==='item'&&bagEntries().length>=21){log('官网背包已满，无法捡起这件道具。');return}
            item.collecting = true;
            if (item.type === 'gold') { const amount = Math.max(1,Number(item.amount||1)); player.wingCoins += amount; log(`捡到 ${amount} 枚翼币。`); }
            else if (item.type === 'shard') { player.shards++; grantCloudItem('relic_shard'); log('取得一枚遗迹碎片。'); }
            else if(item.type==='ammo'){
                const modern=item.ammoType==='modern',ammoKey=modern?'modern':'ammo',cloudItem=modern?'modern_ammo':'ammo_bundle',name=modern?'现代弹药':'通用弹药';
                grantCloudItem(cloudItem,item.count).then(ok=>{if(!ok){item.collecting=false;return}player.ammo[ammoKey]=(player.ammo[ammoKey]||0)+item.count;item.taken=true;log(`捡到 ${item.count} 发${name}。`)})
            }
            else if(item.type==='item'){
                grantCloudItem(item.item.id).then(async ok=>{if(!ok){item.collecting=false;return}item.taken=true;log(`捡到道具「${item.item.name}」，已同步官网背包。`);if(item.item.type==='ranged')await grantStartingAmmoForWeapon(item.item,item.x,item.y)})
            }
            else { player.potions++; grantCloudItem('dungeon_potion'); log('找到一瓶地牢药剂。'); }
            if(item.type!=='ammo'&&item.type!=='item')item.taken=true;
        });
    }

    function weaponUsesModernAmmo(weapon){return Boolean(weapon&&(weapon.firearm===true||weapon.ammoType==='bullet'||/^gun_\d{3}$/.test(String(weapon.id||''))))}
    function ammoTypeForWeapon(weapon){return weaponUsesModernAmmo(weapon)?'modern':'ammo'}
    function sharedAmmoCount(){return Math.max(0,Number(player.ammo[ammoTypeForWeapon(player.weapon)]||0))}
    function weaponAmmoCapacity(weapon){
        if(!weapon||weapon.type!=='ranged')return 0;
        const ranges={common:[10,20],fine:[14,24],uncommon:[14,24],rare:[18,30],epic:[24,40],legendary:[38,50]};
        const [minimum,maximum]=ranges[weapon.quality]||ranges.common;
        const seed=Array.from(String(weapon.id||weapon.name||'weapon')).reduce((hash,char)=>(hash*31+char.charCodeAt(0))>>>0,0);
        return minimum+(seed%(maximum-minimum+1));
    }
    async function grantStartingAmmoForWeapon(weapon,x=player.x,y=player.y){
        if(!weapon||weapon.type!=='ranged')return;
        const modern=weaponUsesModernAmmo(weapon),ammoKey=modern?'modern':'ammo',cloudItem=modern?'modern_ammo':'ammo_bundle',name=modern?'现代弹药':'通用弹药',capacity=weaponAmmoCapacity(weapon);
        let granted=0;
        while(granted<capacity){const count=Math.min(20,capacity-granted);if(!await grantCloudItem(cloudItem,count))break;granted+=count}
        if(granted){player.ammo[ammoKey]=(player.ammo[ammoKey]||0)+granted;log(`「${weapon.name}」配发 ${granted} 发${name}。`)}
        if(granted<capacity){const count=capacity-granted;state.pickups.push({x,y,type:'ammo',ammoType:modern?'modern':'general',count,taken:false});log(`背包无法放入剩余弹药，${count} 发${name}已掉落在地上。`)}
    }
    function consumeSharedAmmo(){
        const type=ammoTypeForWeapon(player.weapon),sources=type==='modern'?['modern_ammo','bullet_bundle']:['ammo_bundle'],source=sources.find(localId=>player.inventory.some(item=>item.officialItem?.id===`d_${localId}`));
        if(!source||!(player.ammo[type]||0))return false;
        player.ammo[type]--;consumeCloudItem(source,1);return true;
    }
    function ammoName(){return ammoTypeForWeapon(player.weapon)==='modern'?'现代弹药':'通用弹药'}

    function triggerTraps() {
        state.traps.forEach(trap => {
            if (!trap.used && distance(trap, player) <= trap.radius + player.radius) {
                trap.used = true;
                damagePlayer(floorDifficulty().trapDamage);
                log('踩中了地面尖刺。');
            }
        });
    }

    function discoverHidden() {
        state.interactables.forEach(item => {
            if (item.hidden && distance(item, player) < 100) {
                item.hidden = false;
                item.type = 'hidden';
                log('附近墙面传来空响，你发现了隐藏武器箱。');
            }
        });
    }

    function revealMap() {
        const px = tileOf(player.x), py = tileOf(player.y), vision = currentVision();
        for (let y = Math.max(0, py-vision); y <= Math.min(ROWS-1, py+vision); y++) {
            for (let x = Math.max(0, px-vision); x <= Math.min(COLS-1, px+vision); x++) {
                if (Math.hypot(x-px,y-py) <= vision+.5) state.explored[y][x] = true;
            }
        }
    }

    function nextFloor() {
        if (player.floor > 0 && player.floor % 5 === 0) {
            state.paused = true;
            $('extractModal').classList.add('show');
            return;
        }
        player.floor++;
        sessionStorage.setItem(`journey_dungeon_refreshes_${cloud.userId || 'guest'}`, '0');
        player.hp = Math.min(player.maxHp, player.hp + 22);
        player.keys++; grantCloudItem('brass_key');
        generateFloor();
    }

    function continueDungeon() {
        $('extractModal').classList.remove('show');
        state.paused = false;
        player.floor++;
        sessionStorage.setItem(`journey_dungeon_refreshes_${cloud.userId || 'guest'}`, '0');
        player.hp = Math.min(player.maxHp, player.hp + 22);
        player.keys++; grantCloudItem('brass_key');
        generateFloor();
    }

    function portalUnlocked() {
        return state.mode === 'dungeon' && !state.enemies.some(enemy => enemy.elite && !enemy.dead);
    }

    function weightedWeapon(minimumRank = 0) {
        const available = weaponPool.filter(weapon => qualityRank[weapon.quality] >= minimumRank);
        const total = available.reduce((sum, weapon) => sum + weapon.weight, 0);
        let roll = rand() * total;
        for (const weapon of available) { roll -= weapon.weight; if (roll <= 0) return weapon; }
        return available[0];
    }

    function weightedWeaponFrom(pool) {
        const available=pool.length?pool:weaponPool;
        const total=available.reduce((sum,weapon)=>sum+Math.max(1,Number(weapon.weight||1)),0);
        let roll=rand()*total;
        for(const weapon of available){roll-=Math.max(1,Number(weapon.weight||1));if(roll<=0)return weapon}
        return available[0];
    }

    // 宝箱武器抽取：5层后每5层增加一次额外抽取机会（取品质最高的一把），提升高品质概率
    function rollChestWeapon(bonusRank = 0) {
        const extraRolls = state.mode === 'dungeon' ? Math.floor(player.floor / 5) : 0;
        let best = weightedWeapon(bonusRank);
        for (let i = 0; i < extraRolls; i++) {
            const candidate = weightedWeapon(bonusRank);
            if (qualityRank[candidate.quality] > qualityRank[best.quality]) best = candidate;
        }
        return best;
    }

    function startWeaponReel(bonusRank = 0,options={}) {
        const strip = $('reelStrip');
        const starter=options.starter===true;
        const starterPool=weaponPool.filter(weapon=>weapon.quality==='common'||weapon.quality==='fine'||weapon.quality==='uncommon');
        const winner = {...(starter?weightedWeaponFrom(starterPool):rollChestWeapon(bonusRank))};
        const items = Array.from({length:42}, (_, index) => index === 35 ? winner : (starter?weightedWeaponFrom(starterPool):weightedWeapon(0)));
        state.pendingWeaponStarter=starter;
        strip.style.transition = 'none';
        strip.style.transform = 'translateX(0px)';
        strip.innerHTML = items.map((weapon,index) => `<div class="reel-item ${weapon.quality}" data-index="${index}"><i>${weapon.icon}</i><b>${weapon.name}</b><small>${weapon.label}</small></div>`).join('');
        $('reelResult').textContent = starter?'基础武器补给正在校准...':'箱体齿轮正在校准...';
        ['equipWeaponBtn','storeWeaponBtn','leaveWeaponBtn'].forEach(id => $(id).disabled = true);
        $('chestModal').classList.add('show');
        requestAnimationFrame(() => requestAnimationFrame(() => {
            const target = strip.querySelector('[data-index="35"]');
            const stage = target.parentElement.parentElement;
            const finalX = stage.clientWidth / 2 - (target.offsetLeft + target.offsetWidth / 2);
            strip.style.transition = 'transform 4.3s cubic-bezier(.08,.68,.12,1)';
            strip.style.transform = `translateX(${finalX}px)`;
        }));
        clearTimeout(state.chestTimer);
        state.chestTimer = setTimeout(() => {
            state.pendingWeapon = winner;
            $('reelResult').textContent = `${winner.label}武器 · ${winner.name} · ${winner.damage}伤害`;
            const bagFull = bagEntries().length >= 21;
            $('equipWeaponBtn').disabled = bagFull;
            $('storeWeaponBtn').disabled = bagFull;
            $('leaveWeaponBtn').disabled = starter;
            $('leaveWeaponBtn').style.display=starter?'none':'';
            if (bagFull) $('reelResult').textContent += ' · 背包已满，只能选择不拿';
        }, 4450);
    }

    async function claimPendingWeapon(equip) {
        if (!state.pendingWeapon) return;
        if (bagEntries().length >= 21) {
            $('reelResult').textContent = '地牢背包已满，请先打开背包丢弃一件物品。';
            return;
        }
        const weapon = {...state.pendingWeapon};
        if(!await grantCloudItem(weapon.id))return;
        await grantStartingAmmoForWeapon(weapon,state.pendingChestPos?.x||player.x,state.pendingChestPos?.y||player.y);
        const uid = player.inventory.find(item=>item.type==='weapon'&&item.weapon.id===weapon.id)?.uid||'';
        if (equip) {
            player.weapon = weapon;
            player.equippedUid = uid;
            log(`装备了${player.weapon.label}武器「${player.weapon.name}」。`);
        } else {
            log(`将${state.pendingWeapon.label}武器「${state.pendingWeapon.name}」放入了地牢背包。`);
        }
        finishWeaponChoice();
    }

    function finishWeaponChoice() {
        state.pendingWeapon = null;
        state.pendingWeaponStarter = false;
        state.pendingChestPos = null;
        $('leaveWeaponBtn').style.display='';
        $('chestModal').classList.remove('show');
        state.paused = false;
        updateUi();
    }

    function declinePendingWeapon() {
        if (!state.pendingWeapon) return;
        const weapon = state.pendingWeapon;
        const dropX = state.pendingChestPos?.x ?? player.x;
        const dropY = state.pendingChestPos?.y ?? player.y;
        state.pickups.push({ x:dropX, y:dropY, type:'item', item:{...weapon, desc:`${weapon.label} · ${weapon.damage}伤害 · ${weapon.type==='ranged'?'远程':'近战'}`}, taken:false });
        log(`武器「${weapon.name}」掉落在地，靠近即可拾取。`);
        state.pendingChestPos = null;
        finishWeaponChoice();
    }

    function bagEntries() {
        const entries = player.inventory.map(item => item.type==='weapon'?({...item,name:`[D] ${item.weapon.name}`,icon:item.weapon.icon,quality:item.weapon.quality,desc:`可在地牢使用 · ${item.weapon.label} · ${item.weapon.damage}伤害 · ${item.weapon.type === 'ranged' ? `远程武器 · ${ammoName(item.weapon.ammoType||'mana')}` : '近战武器'}`,count:item.count||1}):({...item,name:item.item.name,icon:item.item.icon,quality:item.item.quality,desc:item.item.desc,count:item.count||1}));
        if (!cloud.ready&&player.potions > 0) entries.push({uid:'stack-potion',type:'potion',name:'地牢药剂',icon:'✚',quality:'fine',desc:`恢复 ${player.potionPower} 点生命`,count:player.potions});
        if (!cloud.ready&&player.keys > 0) entries.push({uid:'stack-key',type:'key',name:'黄铜钥匙',icon:'⌘',quality:'rare',desc:'用于开启上锁武器箱',count:player.keys});
        if (!cloud.ready&&player.shards > 0) entries.push({uid:'stack-shard',type:'shard',name:'遗迹碎片',icon:'◇',quality:'epic',desc:'地牢内的强化材料',count:player.shards});
        return entries;
    }

    function warehouseEntries() {
        return state.warehouseItems.map(item => {
            if (item.type==='weapon') return {...item,name:item.weapon.name,icon:item.weapon.icon,quality:item.weapon.quality,desc:`${item.weapon.label} · ${item.weapon.damage}伤害 · ${item.weapon.type==='ranged'?'远程武器':'近战武器'}`,count:1};
            if (item.type==='item') return {...item,name:item.item.name,icon:item.item.icon,quality:item.item.quality,desc:item.item.desc,count:1};
            const info={potion:{name:'地牢药剂',icon:'✚',quality:'fine',desc:`恢复 ${player.potionPower} 点生命`},key:{name:'黄铜钥匙',icon:'⌘',quality:'rare',desc:'用于开启上锁武器箱'},shard:{name:'遗迹碎片',icon:'◇',quality:'epic',desc:'地牢内的强化材料'}}[item.type];
            return {...item,...info};
        });
    }

    function renderBag() {
        const entries = bagEntries().slice(0, 21);
        syncBagOrder(entries);
        const byId = new Map(entries.map(item => [item.uid, item]));
        const grid = $('dungeonBagGrid');
        grid.innerHTML = Array.from({length:21}, (_, index) => {
            const item = byId.get(player.bagOrder[index]);
            if (!item) return `<div class="bag-slot empty" data-container="bag" data-slot="${index}" aria-label="空格"></div>`;
            const equipped = item.type === 'weapon' && item.uid === player.equippedUid ? '<span class="equipped">装备中</span>' : '';
            const count = item.count > 1 ? `<em>${item.count}</em>` : '';
            return `<button class="bag-slot ${item.quality} ${player.selectedBagId===item.uid?'selected':''}" draggable="true" data-container="bag" data-slot="${index}" data-uid="${item.uid}">${equipped}<i>${item.icon}</i><b>${item.name}</b>${count}</button>`;
        }).join('');
        grid.querySelectorAll('[data-uid]').forEach(button => button.addEventListener('click', () => {
            const uid = button.dataset.uid;
            const inWarehouseMode = $('bagModal').classList.contains('warehouse-mode');
            if (inWarehouseMode) {
                // 仓库模式：选中背包物品，显示放入仓库按钮
                player.selectedBagId = uid;
                state.selectedWarehouseId = '';
                renderBag();
                return;
            }
            if (touchAutoFacing) {
                const entry = bagEntries().find(e => e.uid === uid);
                if (entry && (entry.type === 'weapon' || entry.type === 'armor')) {
                    player.selectedBagId = uid;
                    equipBagItem();
                    return;
                }
            }
            selectBagItem(uid);
        }));
        renderArmorSlots();
        renderWarehouse();
        bindStorageDragEvents();
        refreshBagDetail();
        renderHotbar();
    }

    function renderArmorSlots(){
        const labels={head:'头部',chest:'胸甲',hands:'护手',legs:'腿甲'};
        $('armorSlots').innerHTML=Object.keys(labels).map(slot=>{const armor=player.armor[slot];return `<button type="button" class="armor-slot${armor?' equipped':''}" data-armor-slot="${slot}"><i>${armor?.icon||'▦'}</i><b>${armor?.name||labels[slot]}</b><small>${armor?`${armor.value} / ${armor.maxArmor} 护甲`:'未穿戴'}</small></button>`}).join('');
        $('armorSlots').querySelectorAll('[data-armor-slot]').forEach(button=>button.addEventListener('click',()=>{if(player.armor[button.dataset.armorSlot])unequipArmor(button.dataset.armorSlot)}));
        const mobileEquip=$('mobileEquip');
        if(mobileEquip){
            const eqLabels={head:'头',chest:'胸',hands:'手',legs:'腿'};
            mobileEquip.innerHTML=Object.keys(eqLabels).map(slot=>{
                const armor=player.armor[slot];
                return `<div class="mobile-equip-slot${armor?' equipped':''}"><span class="eq-label">${eqLabels[slot]}</span>${armor?`<i>${armor.icon||'▦'}</i><span class="eq-armor">${armor.value}</span>`:'<i>▦</i>'}</div>`;
            }).join('');
        }
    }

    let hotbarSignature='';
    function renderHotbar(){
        const slots=Array.from({length:7},(_,index)=>player.inventory.find(item=>item.slot===index)||null);
        const signature=slots.map(item=>`${item?.uid||''}:${item?.count||0}`).join('|')+`#${player.equippedUid}`;
        if(signature===hotbarSignature)return;hotbarSignature=signature;
        $('hotbar').innerHTML=slots.map((item,index)=>`<button type="button" class="hotbar-slot${item?.uid===player.equippedUid?' active':''}" data-hotbar-slot="${index}"><span>${index+1}</span>${item?`<i>${item.type==='weapon'?item.weapon.icon:item.item.icon}</i><b>${item.type==='weapon'?item.weapon.name:item.item.name}</b>`:''}</button>`).join('');
        $('hotbar').querySelectorAll('[data-hotbar-slot]').forEach(button=>button.addEventListener('click',()=>quickEquip(Number(button.dataset.hotbarSlot))));
    }

    function quickEquip(slotIndex){
        if(state.paused||state.ended)return;
        const item=player.inventory.find(entry=>entry.slot===slotIndex);
        if(!item||item.type!=='weapon'){log(`快捷栏 ${slotIndex+1} 不是武器。`);return;}
        player.weapon={...item.weapon};player.equippedUid=item.uid;renderHotbar();updateUi();saveCloudState();
    }

    function renderWarehouse() {
        const entries=warehouseEntries();
        const byId=new Map(entries.map(item=>[item.uid,item]));
        const grid=$('warehouseGrid');
        grid.innerHTML=Array.from({length:21},(_,index)=>{const item=byId.get(state.warehouseOrder[index]);if(!item)return `<div class="bag-slot empty" data-container="warehouse" data-slot="${index}" aria-label="仓库空格"></div>`;const count=item.count>1?`<em>${item.count}</em>`:'';const selected=state.selectedWarehouseId===item.uid?' selected':'';return `<button class="bag-slot ${item.quality}${selected}" draggable="true" data-container="warehouse" data-slot="${index}" data-uid="${item.uid}"><i>${item.icon}</i><b>${item.name}</b>${count}</button>`}).join('');
        grid.querySelectorAll('[data-uid]').forEach(button => button.addEventListener('click', () => {
            const inWarehouseMode = $('bagModal').classList.contains('warehouse-mode');
            if (inWarehouseMode) {
                state.selectedWarehouseId = button.dataset.uid;
                player.selectedBagId = '';
                refreshBagDetail();
                return;
            }
        }));
    }

    function syncBagOrder(entries = bagEntries()) {
        const validIds = new Set(entries.map(item => item.uid));
        const seen = new Set();
        const current = Array.from({length:21}, (_, index) => {
            const uid = player.bagOrder?.[index] || '';
            if (!uid || !validIds.has(uid) || seen.has(uid)) return '';
            seen.add(uid);
            return uid;
        });
        entries.forEach(item => {
            if (seen.has(item.uid)) return;
            const emptyIndex = current.indexOf('');
            if (emptyIndex >= 0) { current[emptyIndex] = item.uid; seen.add(item.uid); }
        });
        player.bagOrder = current;
    }

    function bindStorageDragEvents() {
        const slots = document.querySelectorAll('#dungeonBagGrid [data-slot],#warehouseGrid [data-slot]');
        slots.forEach(slot => {
            slot.addEventListener('dragstart', event => {
                if (!slot.dataset.uid) { event.preventDefault(); return; }
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', JSON.stringify({container:slot.dataset.container,slot:Number(slot.dataset.slot),uid:slot.dataset.uid}));
                requestAnimationFrame(() => slot.classList.add('dragging'));
            });
            slot.addEventListener('dragend', () => slots.forEach(item => item.classList.remove('dragging','drag-over')));
            slot.addEventListener('dragover', event => { event.preventDefault(); event.dataTransfer.dropEffect = 'move'; slot.classList.add('drag-over'); });
            slot.addEventListener('dragleave', () => slot.classList.remove('drag-over'));
            slot.addEventListener('drop', event => {
                event.preventDefault();
                let source;
                try { source=JSON.parse(event.dataTransfer.getData('text/plain')); } catch { return; }
                const to = Number(slot.dataset.slot);
                if (!Number.isInteger(source.slot) || !Number.isInteger(to)) return;
                if(source.slot===to&&source.container===slot.dataset.container)return;
                moveCloudStorage(source.container,slot.dataset.container,source.slot,to);
            });
        });
    }

    function removeBagEntry(item) {
        if(item.type==='weapon'||item.type==='item'){
            player.inventory=player.inventory.filter(entry=>entry.uid!==item.uid);
            if(player.equippedUid===item.uid){const replacement=player.inventory.find(entry=>entry.type==='weapon');player.weapon=replacement?{...replacement.weapon}:{...unarmedWeapon};player.equippedUid=replacement?.uid||''}
        } else if(item.type==='potion') player.potions=0;
        else if(item.type==='key') player.keys=0;
        else if(item.type==='shard') player.shards=0;
        player.bagOrder=player.bagOrder.map(uid=>uid===item.uid?'':uid);
    }

    function moveBagToWarehouse(uid,to) {
        const item=bagEntries().find(entry=>entry.uid===uid);
        if(!item||state.warehouseOrder[to])return;
        const stored=item.type==='weapon'||item.type==='item'?player.inventory.find(entry=>entry.uid===uid):{uid:`warehouse-${item.type}-${Date.now()}-${ri(10,99)}`,type:item.type,count:item.count};
        removeBagEntry(item);state.warehouseItems.push(stored);state.warehouseOrder[to]=stored.uid;syncBagOrder();log(`将「${item.name}」存入了主城仓库。`);updateUi();
    }

    function moveWarehouseToBag(uid,to) {
        const stored=state.warehouseItems.find(item=>item.uid===uid);
        if(!stored||player.bagOrder[to])return;
        const stackUid={potion:'stack-potion',key:'stack-key',shard:'stack-shard'}[stored.type];
        const needsSlot=stored.type==='weapon'||stored.type==='item'||!bagEntries().some(item=>item.uid===stackUid);
        if(needsSlot&&bagEntries().length>=21)return;
        state.warehouseItems=state.warehouseItems.filter(item=>item.uid!==uid);state.warehouseOrder=state.warehouseOrder.map(value=>value===uid?'':value);
        let bagUid=stored.uid;
        if(stored.type==='weapon'||stored.type==='item')player.inventory.push(stored);
        else if(stored.type==='potion'){player.potions+=stored.count;bagUid='stack-potion'}
        else if(stored.type==='key'){player.keys+=stored.count;bagUid='stack-key'}
        else if(stored.type==='shard'){player.shards+=stored.count;bagUid='stack-shard'}
        syncBagOrder();const current=player.bagOrder.indexOf(bagUid);if(current>=0&&current!==to)[player.bagOrder[current],player.bagOrder[to]]=[player.bagOrder[to],player.bagOrder[current]];log('从主城仓库取回了物品。');updateUi();
    }

    function selectBagItem(uid) {
        player.selectedBagId = uid;
        renderBag();
    }

    function selectedBagEntry() {
        return bagEntries().find(item => item.uid === player.selectedBagId) || null;
    }

    function refreshBagDetail() {
        const inWarehouseMode = $('bagModal').classList.contains('warehouse-mode');
        const bagItem = selectedBagEntry();
        const whItem = inWarehouseMode ? warehouseEntries().find(e => e.uid === state.selectedWarehouseId) : null;
        const item = whItem || bagItem;

        $('bagItemName').textContent = item ? `${item.name}${item.count>1?` × ${item.count}`:''}` : '选择一个物品';
        $('bagItemDesc').textContent = item ? item.desc : (inWarehouseMode ? '点击背包物品放入仓库，点击仓库物品取出。' : '官网背包共有21个格子，带 [D] 标签的物品可以在地牢使用。');

        if (inWarehouseMode) {
            // 仓库模式：隐藏装备/丢弃，显示放入/取出
            $('bagEquipBtn').style.display = 'none';
            $('bagDiscardBtn').style.display = 'none';
            const toWhBtn = $('toWarehouseBtn');
            const fromWhBtn = $('fromWarehouseBtn');
            toWhBtn.style.display = '';
            fromWhBtn.style.display = '';
            toWhBtn.disabled = !bagItem;
            fromWhBtn.disabled = !whItem;
        } else {
            // 普通模式：显示装备/丢弃，隐藏放入/取出
            $('bagEquipBtn').style.display = '';
            $('bagDiscardBtn').style.display = '';
            $('toWarehouseBtn').style.display = 'none';
            $('fromWarehouseBtn').style.display = 'none';
            $('bagEquipBtn').textContent=bagItem?.type==='armor'?'穿戴护甲':'装备';
            $('bagEquipBtn').disabled = !bagItem || !['weapon','armor'].includes(bagItem.type) || (bagItem.type==='weapon'&&bagItem.uid === player.equippedUid);
            $('bagDiscardBtn').disabled = !bagItem;
        }
    }

    async function equipBagItem() {
        const item = selectedBagEntry();
        if (!item || !['weapon','armor'].includes(item.type)) return;
        if(item.type==='armor'){
            try{const data=await dungeonApi('equipDungeonArmor',{inventorySlot:item.slot});hydrateCloud(data,true);renderBag();updateUi();log(`穿戴了「${item.armor.name}」，获得 ${item.armor.maxArmor} 点护甲。`)}
            catch(error){log('护甲穿戴失败，请重新打开背包后再试。')}return;
        }
        player.weapon = {...item.weapon};
        player.equippedUid = item.uid;
        log(`从地牢背包装备了「${item.weapon.name}」。`);
        renderBag();
        updateUi();
        saveCloudState();
    }

    async function unequipArmor(slot){
        try{const data=await dungeonApi('unequipDungeonArmor',{equipmentSlot:slot});hydrateCloud(data,true);renderBag();updateUi();log('已卸下护甲。')}
        catch(error){log('护甲卸下失败，请稍后重试。')}
    }

    // 以掉落物形式把物品丢到玩家身边（面向方向前方30像素，需要走近一步才能拾回）
    function dropPickupNearPlayer(itemData) {
        const dropX = player.x + player.facing.x * 30;
        const dropY = player.y + player.facing.y * 30;
        state.pickups.push({ x:dropX, y:dropY, type:'item', item:itemData, taken:false });
    }

    // 手机端丢弃按钮：丢弃手上拿着的武器，以掉落物形式丢到身边
    async function discardHeldWeapon() {
        if (state.paused || state.ended) return;
        if (!player.equippedUid || player.weapon.id === 'unarmed') { log('当前没有手持的地牢武器。'); return; }
        const entry = player.inventory.find(item => item.uid === player.equippedUid && item.type === 'weapon');
        if (!entry) { log('当前没有手持的地牢武器。'); return; }
        const weapon = { ...entry.weapon };
        if (Number.isInteger(entry.slot)) {
            try {
                const data = await dungeonApi('discardDungeonItem', { slot: entry.slot });
                hydrateCloud(data, true);
            } catch (error) { log('丢弃失败，武器还握在手中。'); return; }
        } else {
            player.inventory = player.inventory.filter(item => item.uid !== entry.uid);
        }
        dropPickupNearPlayer({ ...weapon, desc:`${weapon.label} · ${weapon.damage}伤害 · ${weapon.type === 'ranged' ? '远程' : '近战'}` });
        const replacement = player.inventory.find(item => item.type === 'weapon');
        player.weapon = replacement ? { ...replacement.weapon } : { ...unarmedWeapon };
        player.equippedUid = replacement?.uid || '';
        log(`丢弃了手持武器「${weapon.name}」，掉落在身旁，靠近即可拾回。`);
        updateUi();
        saveCloudState();
    }

    // 把背包物品转成可重新拾取的掉落物数据；无法重新发放的物品返回 null
    function bagItemToDrop(item) {
        if (item.type === 'weapon' && item.weapon) {
            const weapon = { ...item.weapon };
            return { ...weapon, desc:`${weapon.label} · ${weapon.damage}伤害 · ${weapon.type === 'ranged' ? '远程' : '近战'}` };
        }
        const source = item.item;
        if (source && item.officialItem && String(item.officialItem.id).startsWith('d_')) {
            return { ...source, id:String(item.officialItem.id).slice(2) };
        }
        return null;
    }

    async function discardBagItem() {
        const item = selectedBagEntry();
        if (!item) return;
        if(Number.isInteger(item.slot)) {
            try {
                const dropData = bagItemToDrop(item);
                const data=await dungeonApi('discardDungeonItem',{slot:item.slot}); hydrateCloud(data); player.selectedBagId='';
                if (dropData) { dropPickupNearPlayer(dropData); log(`「${item.name}」掉落在身旁，靠近即可拾回。`); }
                else log(`已从官网背包丢弃「${item.name}」。`);
                renderBag(); updateUi();
            }
            catch(error){log('丢弃失败，官网背包没有发生变化。');}
            return;
        }
        if (item.type === 'potion') player.potions = Math.max(0, player.potions - 1);
        else if (item.type === 'key') player.keys = Math.max(0, player.keys - 1);
        else if (item.type === 'shard') player.shards = Math.max(0, player.shards - 1);
        else if (item.type === 'weapon'||item.type==='item') {
            const dropData = bagItemToDrop(item);
            player.inventory = player.inventory.filter(entry => entry.uid !== item.uid);
            if (player.equippedUid === item.uid) {
                const replacement = player.inventory.find(entry => entry.type === 'weapon');
                player.weapon = replacement ? {...replacement.weapon} : {...unarmedWeapon};
                player.equippedUid = replacement?.uid || '';
            }
            if (dropData) { dropPickupNearPlayer(dropData); log(`「${item.name}」掉落在身旁，靠近即可拾回。`); }
        }
        log(`丢弃了「${item.name}」。`);
        player.selectedBagId = '';
        renderBag();
        updateUi();
    }

    async function openBag(warehouseMode = false) {
        if (state.ended || (state.paused && !$('bagModal').classList.contains('show'))) return;
        clearInputs();
        state.paused = true;
        player.selectedBagId = '';
        state.selectedWarehouseId = '';
        try {
            const data=await dungeonApi('getDungeonState');
            hydrateCloud(data,true);
        } catch(error) {
            state.paused=false;
            log('官网背包加载失败，请检查网络后重试。');
            return;
        }
        warehouseMode=warehouseMode&&state.mode==='town';
        $('bagModal').classList.toggle('warehouse-mode',warehouseMode);
        $('bagModalTitle').textContent=warehouseMode?'官网背包与云端仓库':'官网随身背包';
        $('bagModalCopy').textContent=warehouseMode?`当前为 ${cloud.warehouseNo} 号云端仓库。拖拽后立即同步。`:'这是官网统一背包；地牢内无法打开任何仓库，只有 [D] 物品可使用。';
        renderBag();
        $('bagModal').classList.add('show');
    }

    function closeBag() {
        if (!$('bagModal').classList.contains('show')) return;
        $('bagModal').classList.remove('show');
        state.paused = false;
        player.selectedBagId = '';
    }

    function toggleBag() {
        if ($('bagModal').classList.contains('show')) closeBag(); else openBag();
    }

    function showUpgrade() {
        state.paused = true;
        const choices = [...upgrades].sort(() => rand() - .5).slice(0, 3);
        $('upgradeChoices').innerHTML = choices.map((upgrade,index) => `<button class="choice" data-index="${index}"><i>${upgrade.icon}</i><b>${upgrade.name}</b><small>${upgrade.desc}</small></button>`).join('');
        $('upgradeChoices').querySelectorAll('.choice').forEach(button => button.addEventListener('click', () => {
            const upgrade = choices[Number(button.dataset.index)];
            upgrade.apply(player);
            log(`获得本局祝福「${upgrade.name}」。`);
            $('upgradeModal').classList.remove('show');
            state.paused = false;
            updateUi();
        }, {once:true}));
        $('upgradeModal').classList.add('show');
    }

    function updateEffects(dt) {
        state.effects.forEach(effect => effect.life -= dt);
        // 毒雾减速：玩家站在毒雾中时移动速度降低40%
        let inPoison = false;
        state.effects.forEach(effect => {
            if (effect.type === 'poison' && Math.hypot(effect.x - player.x, effect.y - player.y) < (effect.radius || 22) + player.radius) {
                inPoison = true;
            }
        });
        player.poisonSlow = inPoison ? 0.6 : 0;
        state.effects = state.effects.filter(effect => effect.life > 0);
    }

    function updateUi() {
        $('hpText').textContent = `${Math.max(0,Math.ceil(player.hp))} / ${player.maxHp}`;
        $('hpBar').style.width = `${clamp(player.hp/player.maxHp*100,0,100)}%`;
        const armorNow=Object.values(player.armor).reduce((sum,armor)=>sum+(armor?.value||0),0),armorMax=Object.values(player.armor).reduce((sum,armor)=>sum+(armor?.maxArmor||0),0);
        $('armorText').textContent=`${armorNow} / ${armorMax}`;$('armorBar').style.width=`${armorMax?clamp(armorNow/armorMax*100,0,100):0}%`;
        $('mobileHpText').textContent=`${Math.max(0,Math.ceil(player.hp))} / ${player.maxHp}`;$('mobileHpBar').style.width=`${clamp(player.hp/player.maxHp*100,0,100)}%`;
        $('mobileArmorText').textContent=`${armorNow} / ${armorMax}`;$('mobileArmorBar').style.width=`${armorMax?clamp(armorNow/armorMax*100,0,100):0}%`;
        const meSig=Object.entries(player.armor).map(([k,v])=>`${k}:${v?.value||0}`).join(',');
        if(state._meSig!==meSig){state._meSig=meSig;renderArmorSlots();}
        $('attackText').textContent = Math.round(player.weapon.damage * player.damageMultiplier * (1 + player.shards * player.shardPower));
        $('depthText').textContent = state.mode==='town'?'城':player.floor;
        $('goldText').textContent = player.wingCoins;
        if ($('officialGoldText')) $('officialGoldText').textContent = player.officialGold;
        $('killText').textContent = player.kills;
        $('potionCount').textContent = player.potions;
        const mobileAtk=$('mobileAttackText');if(mobileAtk)mobileAtk.textContent=String(Math.round(player.weapon.damage*player.damageMultiplier*(1+player.shards*player.shardPower)));
        const mobileKey=$('mobileKeyText');if(mobileKey)mobileKey.textContent=String(player.keys);
        const mobileWing=$('mobileWingText');if(mobileWing)mobileWing.textContent=String(player.wingCoins||0);
        $('keyCount').textContent = player.keys;
        $('shardCount').textContent = player.shards;
        $('floorText').textContent = state.mode==='town'?'CITY':String(player.floor).padStart(2,'0');
        $('weaponIcon').textContent = player.weapon.icon;
        $('weaponName').textContent = player.weapon.name;
        $('weaponStats').textContent = `${player.weapon.label} · ${player.weapon.damage}伤害 · ${player.weapon.type === 'ranged' ? `弹容量 ${weaponAmmoCapacity(player.weapon)} · ${ammoName()}库存 ${sharedAmmoCount()}` : '近战'}`;
        $('weaponLevel').textContent = ['I','II','III','IV','V'][qualityRank[player.weapon.quality]];
        const ranged=player.weapon.type==='ranged';
        $('ammoHud').hidden=!ranged;
        if(ranged){const ammoNow=sharedAmmoCount(),capacity=weaponAmmoCapacity(player.weapon);state.ammoMaxSeen=Math.max(capacity,1);$('ammoHudName').textContent=`${ammoName()} · 容量 ${capacity}`;$('ammoHudCount').textContent=`库存 ${ammoNow}`;$('ammoHudBarFill').style.width=`${clamp(Math.min(ammoNow,capacity)/Math.max(1,capacity)*100,0,100)}%`;}
        renderHotbar();
        const dashReady = 1 - player.dashCooldown/player.dashCooldownMax;
        $('dashBar').style.width = `${clamp(dashReady*100,0,100)}%`;
        $('dashText').textContent = player.dashCooldown <= 0 ? '准备就绪' : `${player.dashCooldown.toFixed(1)}秒`;
        const touchDashBarFill = $('touchDashBarFill');
        if (touchDashBarFill) touchDashBarFill.style.width = `${clamp(dashReady*100,0,100)}%`;
        const touchDashBtn=$('touchDashBtn');if(touchDashBtn)touchDashBtn.classList.toggle('ready',player.dashCooldown<=0);
        const touchPotionBtn=$('touchPotionBtn');if(touchPotionBtn)touchPotionBtn.classList.toggle('disabled',player.potions<1);
        const touchPotionCount=$('touchPotionCount');if(touchPotionCount){touchPotionCount.textContent=String(player.potions);touchPotionCount.style.display=player.potions>0?'':'none';}
        // 血量低于 10% 时给游戏画布加红色危险滤镜（边缘红色警告提示）
        const canvasWrap = document.querySelector('.canvas-wrap');
        if (canvasWrap) canvasWrap.classList.toggle('hp-critical', player.hp > 0 && player.hp / player.maxHp <= 0.1);
        const floorTiles = state.map.flat().filter(Boolean).length || 1;
        let exploredTiles = 0;
        state.explored.forEach((row,y) => row.forEach((seen,x) => { if (seen && state.map[y][x]) exploredTiles++; }));
        const {item:nearest,distance:nearestDistance}=nearestToPlayer(state.interactables,item => !item.used && !item.hidden);
        const interactionNames={chest:'打开武器宝箱',hidden:'打开隐藏武器箱',camp:'使用恢复营火',shrine:'触碰遗迹祭坛',merchant:'与无灯商人交易',armsDealer:'与军火商杰克交易',crate:'打开补给木箱',townDoor:'进入地牢',warehouse:'打开个人仓库',bank:'进入银行兑换货币',synthesis:'使用品质重铸机',repair:'使用护甲修复机器',portal:'进入紫色传送门'};
        // 互动距离：靠近可互动物品 2 格内（TILE*2=80 像素，覆盖正交两格和对角两格）
        const interactThreshold = TILE * 2;
        // 传送门也作为可互动物品（解锁后 2 格内走互动按钮）
        let portalInteractable = null;
        let portalInteractDist = Infinity;
        if (state.mode === 'dungeon' && portalUnlocked()) {
            portalInteractDist = distance(state.exit, player);
            if (portalInteractDist < interactThreshold) portalInteractable = { type: 'portal' };
        }
        // 合并候选：普通互动物品 vs 传送门，取最近的
        let effectiveNearest = nearest;
        let effectiveNearestDist = nearestDistance;
        if (portalInteractable && portalInteractDist < effectiveNearestDist) {
            effectiveNearest = portalInteractable;
            effectiveNearestDist = portalInteractDist;
        }
        // 显示互动按钮/提示的前置条件：当前房间没有活着的普通怪物（主城、走廊、起始房、清完怪的普通房都允许）
        let roomHasEnemies = false;
        if (state.mode === 'dungeon') {
            if (state.activeRoom) {
                roomHasEnemies = state.enemies.some(enemy => !enemy.dead && !enemy.elite && enemy.room && enemy.room.x === state.activeRoom.x && enemy.room.y === state.activeRoom.y);
            } else {
                const room = state.rooms.find(r => !r.isBossRoom && !r.isTown && insideRoom(player, r, player.radius));
                if (room) roomHasEnemies = state.enemies.some(enemy => !enemy.dead && !enemy.elite && enemy.room && enemy.room.x === room.x && enemy.room.y === room.y);
            }
        }
        const executable=state.enemies.find(enemy=>enemy.executeReady&&!enemy.dead&&distance(enemy,player)<=78);
        $('touchExecuteBtn').hidden=!executable;
        // 丢弃按钮：只有在手持地牢武器时才显示
        const touchDiscard=$('touchDiscardBtn');if(touchDiscard)touchDiscard.hidden=!player.equippedUid||player.weapon.id==='unarmed';
        state.nearestInteractable = (!roomHasEnemies && !executable && effectiveNearest && effectiveNearestDist < interactThreshold) ? effectiveNearest : null;
        const touchAttackBtn = $('touchAttackBtn');
        if (touchAttackBtn) {
            const inInteractMode = !!state.nearestInteractable;
            touchAttackBtn.textContent = inInteractMode ? '互动' : '攻击';
            touchAttackBtn.classList.toggle('interact-mode', inInteractMode);
        }
        if(executable)$('objective').textContent=touchAutoFacing?'点击斩杀按钮踹飞残血怪物':'按 F 踹飞并斩杀残血怪物';
        else if(roomHasEnemies)$('objective').textContent='房间被封锁 · 清理完房间里的怪物才能互动';
        else if(effectiveNearest&&effectiveNearestDist<interactThreshold)$('objective').textContent=touchAutoFacing?`靠近${interactionNames[effectiveNearest.type]||'互动'}`:`按 E ${interactionNames[effectiveNearest.type]||'互动'}`;
        else if(state.mode==='town')$('objective').textContent='主城安全区 · 东侧地牢大门 · 西侧仓库 · 东北侧银行';
        else if(state.bossFightActive&&bossAlive())$('objective').textContent='Boss 房已封锁 · 击败看守者才能离开';
        else $('objective').textContent=`探索进度 ${Math.round(exploredTiles/floorTiles*100)}% · ${state.enemies.length} 个敌人存活`;
        $('extractBtn').disabled=state.mode!=='town';$('newMapBtn').disabled=true;$('newMapBtn').textContent=state.mode==='town'?'从大门进入地牢':'每5层可选择撤离';
    }

    function qualityColor(quality) {
        return {common:'#aaa99f',fine:'#76a17e',rare:'#6b9dcc',epic:'#b27ad0',legendary:'#d46356'}[quality] || '#efd19a';
    }

    function draw() {
        const worldW = COLS*TILE, worldH = ROWS*TILE;
        const targetX = clamp(player.x-canvas.width/2,0,Math.max(0,worldW-canvas.width));
        const targetY = clamp(player.y-canvas.height/2,0,Math.max(0,worldH-canvas.height));
        state.camera.x += (targetX-state.camera.x)*.16;
        state.camera.y += (targetY-state.camera.y)*.16;
        const sx = x => x-state.camera.x;
        const sy = y => y-state.camera.y;
        ctx.fillStyle = '#050605';
        ctx.fillRect(0,0,canvas.width,canvas.height);
        // 管理员设置的地牢背景图（覆盖黑色底，但仅在地牢模式下）
        if (state.mode === 'dungeon' && cloud.dungeonBackgroundImage) {
            const img = cloud.dungeonBackgroundImage;
            const cw = canvas.width, ch = canvas.height;
            const ir = img.width / img.height, cr = cw / ch;
            let dw, dh, dx, dy;
            if (ir > cr) { dh = ch; dw = ch * ir; dx = (cw - dw) / 2; dy = 0; }
            else { dw = cw; dh = cw / ir; dx = 0; dy = (ch - dh) / 2; }
            ctx.globalAlpha = 0.55;
            ctx.drawImage(img, dx, dy, dw, dh);
            ctx.globalAlpha = 1;
            // 半透明黑色遮罩，保证游戏元素可读性
            ctx.fillStyle = 'rgba(5,6,5,.35)';
            ctx.fillRect(0,0,cw,ch);
        }
        const playerTileX = tileOf(player.x), playerTileY = tileOf(player.y);

        for (let y=0;y<ROWS;y++) for (let x=0;x<COLS;x++) {
            if (!state.explored[y][x]) continue;
            const px=sx(x*TILE),py=sy(y*TILE);
            if(px+TILE<0||py+TILE<0||px>canvas.width||py>canvas.height)continue;
            const vision=currentVision(),visible=Math.hypot(x-playerTileX,y-playerTileY)<=vision+.6;
            const bridge=state.bridges[y][x];
            // 默认颜色
            let baseColor = state.map[y][x]?(bridge?(visible?'#2d2921':'#14130f'):(visible?'#292a22':'#12130f')):(visible?'#0a0b09':'#060706');
            if(state.map[y][x]&&visible){const rt=roomTypeAt(x,y),tex=cloud.floorTextures[rt],customColor=cloud.floorColors[rt];if(customColor&&!(tex&&tex.img))baseColor=customColor}
            ctx.fillStyle=baseColor;
            ctx.fillRect(px,py,TILE,TILE);
            // 管理员设置的房间地板纹理
            if (state.map[y][x] && visible) {
                const rt = roomTypeAt(x,y);
                const tex = cloud.floorTextures[rt];
                if (tex && tex.img) {
                    ctx.globalAlpha = 0.7;
                    ctx.drawImage(tex.img, px, py, TILE, TILE);
                    ctx.globalAlpha = 1;
                }
            }
            if(bridge){ctx.strokeStyle=visible?'rgba(166,137,91,.14)':'rgba(100,82,53,.06)';ctx.strokeRect(px+2.5,py+2.5,TILE-5,TILE-5);if(visible){ctx.strokeStyle='rgba(204,174,124,.05)';for(let offset=10;offset<TILE;offset+=10){ctx.beginPath();ctx.moveTo(px+3,py+offset+.5);ctx.lineTo(px+TILE-3,py+offset+.5);ctx.stroke()}}}
            else if(state.map[y][x]){ctx.strokeStyle=visible?'rgba(232,223,204,.05)':'rgba(232,223,204,.015)';ctx.strokeRect(px+.5,py+.5,TILE-1,TILE-1)}
        }

        const visibleEntity = entity => Math.hypot(tileOf(entity.x)-playerTileX,tileOf(entity.y)-playerTileY)<=currentVision()+.8;
        state.obstacles.filter(ob => visibleEntity(ob)).forEach(ob => {
            const ox=sx(ob.x),oy=sy(ob.y),r=ob.radius;
            ctx.save();
            // 石柱底座（方形阴影）
            ctx.fillStyle='rgba(0,0,0,.35)';
            ctx.beginPath();ctx.ellipse(ox+3,oy+4,r*1.1,r*0.55,0,0,Math.PI*2);ctx.fill();
            // 柱身（方形石质）
            ctx.fillStyle='#3a3832';
            ctx.fillRect(ox-r,oy-r,r*2,r*2);
            // 石柱边缘高光
            ctx.strokeStyle='rgba(232,223,204,.22)';ctx.lineWidth=2;
            ctx.strokeRect(ox-r,oy-r,r*2,r*2);
            // 顶部柱帽（略大方形）
            ctx.fillStyle='#4a4740';
            ctx.fillRect(ox-r-3,oy-r-3,r*2+6,6);
            ctx.strokeStyle='rgba(232,223,204,.18)';
            ctx.strokeRect(ox-r-3,oy-r-3,r*2+6,6);
            // 底部基座
            ctx.fillStyle='#2e2c28';
            ctx.fillRect(ox-r-3,oy+r-3,r*2+6,6);
            // 柱身裂纹/纹理
            ctx.strokeStyle='rgba(0,0,0,.2)';ctx.lineWidth=1;
            ctx.beginPath();ctx.moveTo(ox-r+3,oy);ctx.lineTo(ox+r-3,oy);ctx.stroke();
            ctx.beginPath();ctx.moveTo(ox,oy-r+3);ctx.lineTo(ox,oy+r-3);ctx.stroke();
            // 左上方高光
            ctx.fillStyle='rgba(255,255,255,.06)';
            ctx.fillRect(ox-r+2,oy-r+2,r-2,r-2);
            ctx.restore();
        });
        state.traps.filter(trap => !trap.used && visibleEntity(trap)).forEach(trap => {ctx.strokeStyle='rgba(181,76,65,.48)';ctx.beginPath();ctx.moveTo(sx(trap.x)-9,sy(trap.y)+8);ctx.lineTo(sx(trap.x),sy(trap.y)-9);ctx.lineTo(sx(trap.x)+9,sy(trap.y)+8);ctx.stroke()});
        state.pickups.filter(item => !item.taken && visibleEntity(item)).forEach(item => drawPickup(item,sx(item.x),sy(item.y)));
        state.interactables.filter(item => !item.used && !item.hidden && visibleEntity(item)).forEach(item => drawInteractable(item,sx(item.x),sy(item.y)));
        // 无钥匙开锁箱：红色提示浮现于箱子上方3秒
        if (state.keyWarning) {
            const remain = state.keyWarning.until - performance.now();
            if (remain <= 0) state.keyWarning = null;
            else {
                ctx.save();
                ctx.font='bold 13px Microsoft YaHei';ctx.textAlign='center';ctx.textBaseline='middle';
                const wx=sx(state.keyWarning.x),wy=sy(state.keyWarning.y)-38,tw=ctx.measureText('该箱子需要钥匙').width+14;
                ctx.globalAlpha=remain<500?remain/500:1;
                ctx.fillStyle='rgba(5,6,5,.85)';ctx.fillRect(wx-tw/2,wy-11,tw,22);
                ctx.fillStyle='#ff5548';ctx.shadowColor='#ff5548';ctx.shadowBlur=8;
                ctx.fillText('该箱子需要钥匙',wx,wy);
                ctx.restore();
            }
        }

        if (portalUnlocked() && visibleEntity(state.exit)) {
            ctx.fillStyle='rgba(170,81,198,.22)';ctx.beginPath();ctx.arc(sx(state.exit.x),sy(state.exit.y),26,0,Math.PI*2);ctx.fill();ctx.strokeStyle='#bd6bd4';ctx.lineWidth=3;ctx.strokeRect(sx(state.exit.x)-15,sy(state.exit.y)-15,30,30);ctx.strokeRect(sx(state.exit.x)-8,sy(state.exit.y)-8,16,16);ctx.lineWidth=1;if(distance(state.exit,player)<110)drawLabel(touchAutoFacing?'紫色传送门 · 靠近互动':'紫色传送门 · 按 E 进入',sx(state.exit.x),sy(state.exit.y)-27,'#cb80df');
        }

        state.enemies.filter(visibleEntity).forEach(enemy => drawEnemy(enemy,sx(enemy.x),sy(enemy.y)));
        state.projectiles.filter(visibleEntity).forEach(projectile => {
            const px=sx(projectile.x), py=sy(projectile.y);
            if(projectile.homing){
                // 追踪导弹：暗红色菱形 + 尾焰 + 外发光
                const angle=Math.atan2(projectile.vy,projectile.vx);
                const r=projectile.radius;
                ctx.save();
                ctx.translate(px,py);
                ctx.rotate(angle);
                // 外发光
                ctx.shadowColor='#ff3020';ctx.shadowBlur=14;
                // 导弹主体（菱形）
                ctx.fillStyle='#cc2820';
                ctx.beginPath();ctx.moveTo(r*1.6,0);ctx.lineTo(0,r);ctx.lineTo(-r*0.8,0);ctx.lineTo(0,-r);ctx.closePath();ctx.fill();
                // 内部高亮
                ctx.shadowBlur=0;
                ctx.fillStyle='#ff6040';
                ctx.beginPath();ctx.moveTo(r*1.2,0);ctx.lineTo(0,r*0.5);ctx.lineTo(-r*0.4,0);ctx.lineTo(0,-r*0.5);ctx.closePath();ctx.fill();
                // 尾焰
                const flameLen=r*(1.5+Math.sin(performance.now()/40)*0.5);
                const fg=ctx.createLinearGradient(-r*0.8,0,-r*0.8-flameLen,0);
                fg.addColorStop(0,'rgba(255,180,60,.9)');fg.addColorStop(0.5,'rgba(255,80,30,.5)');fg.addColorStop(1,'rgba(255,40,20,0)');
                ctx.fillStyle=fg;
                ctx.beginPath();ctx.moveTo(-r*0.8,-r*0.5);ctx.lineTo(-r*0.8-flameLen,0);ctx.lineTo(-r*0.8,r*0.5);ctx.closePath();ctx.fill();
                ctx.restore();
            }else{
                ctx.fillStyle=projectile.color;ctx.shadowColor=projectile.color;ctx.shadowBlur=9;
                ctx.beginPath();ctx.arc(px,py,projectile.radius,0,Math.PI*2);ctx.fill();ctx.shadowBlur=0;
            }
        });
        drawEffects(sx,sy);
        drawPlayer(sx(player.x),sy(player.y));
        drawMinimap();
        drawBossBar();
        const potion=state.potionEffect;
        if(potion){
            const elapsed=performance.now()-potion.startedAt;
            const fade=elapsed>potion.duration ? clamp(1-(elapsed-potion.duration)/(potion.filterDuration-potion.duration),0,1) : 1;
            ctx.save();
            const cx=canvas.width/2,cy=canvas.height/2,radius=Math.hypot(canvas.width,canvas.height)*.58;
            const vignette=ctx.createRadialGradient(cx,cy,Math.min(canvas.width,canvas.height)*.22,cx,cy,radius);
            vignette.addColorStop(0,'rgba(75,205,96,0)');
            vignette.addColorStop(.48,'rgba(75,205,96,0)');
            vignette.addColorStop(1,`rgba(75,205,96,${(0.3*fade).toFixed(3)})`);
            ctx.fillStyle=vignette;
            ctx.fillRect(0,0,canvas.width,canvas.height);
            ctx.restore();
        }
    }

    function drawPickup(item,x,y) {
        if(item.type==='ammo'){
            const style={icon:'▰',color:'#d8bd78'};
            ctx.save();ctx.shadowColor=style.color;ctx.shadowBlur=10;ctx.fillStyle='#17140f';ctx.strokeStyle=style.color;ctx.lineWidth=2;ctx.fillRect(x-11,y-9,22,18);ctx.strokeRect(x-10.5,y-8.5,21,17);ctx.shadowBlur=0;ctx.fillStyle=style.color;ctx.font='bold 13px Georgia';ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillText(style.icon,x,y);ctx.restore();
            drawLabel(`${item.ammoType==='modern'?'现代弹药':'通用弹药'} × ${item.count}`,x,y-22,style.color);
            return;
        }
        const color=item.type==='gold'?'#d5aa5d':item.type==='shard'?'#8fc0d1':item.type==='item'?qualityColor(item.item.quality):'#79a4b7';
        ctx.fillStyle=color;ctx.shadowColor=color;ctx.shadowBlur=12;ctx.beginPath();ctx.arc(x,y,item.type==='item'?9:7,0,Math.PI*2);ctx.fill();ctx.shadowBlur=0;
        if(distance(item,player)<90&&item.type==='item')drawLabel(item.item.name,x,y-18,qualityColor(item.item.quality));
        if(distance(item,player)<90&&item.type==='gold')drawLabel(`翼币 × ${item.amount||1}`,x,y-18,'#d5aa5d');
    }

    function drawPlayer(x,y) {
        const radius=15;
        ctx.save();ctx.shadowColor=player.invulnerable>0?'#8dd69b':'#efd19a';ctx.shadowBlur=14;ctx.beginPath();ctx.arc(x,y,radius,0,Math.PI*2);ctx.clip();
        if(cloud.avatarImage){ctx.drawImage(cloud.avatarImage,x-radius,y-radius,radius*2,radius*2);}
        else{ctx.fillStyle=cloud.avatar?.color||'#8f2730';ctx.fillRect(x-radius,y-radius,radius*2,radius*2);ctx.fillStyle='#fff8e8';ctx.font='bold 15px Microsoft YaHei';ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillText(cloud.avatar?.text||Array.from(cloud.username)[0]||'旅',x,y);}
        ctx.restore();ctx.shadowBlur=0;ctx.strokeStyle=player.invulnerable>0?'#8dd69b':'#efd19a';ctx.lineWidth=2;ctx.beginPath();ctx.arc(x,y,radius+.5,0,Math.PI*2);ctx.stroke();ctx.lineWidth=1;
        drawLabel(cloud.username,x,y-radius-15,'#fff4df');
        if(player.weapon.type==='ranged'){
            const ammoNow=sharedAmmoCount();
            state.ammoMaxSeen=Math.max(state.ammoMaxSeen,ammoNow,1);
            const capacity=weaponAmmoCapacity(player.weapon),barW=clamp(14+capacity*.5,16,30),barH=4,bx=x-barW/2,by=y-radius-30;
            ctx.fillStyle='rgba(5,6,5,.8)';ctx.fillRect(bx-1,by-1,barW+2,barH+2);
            ctx.fillStyle='#3a2a14';ctx.fillRect(bx,by,barW,barH);
            if(ammoNow>0){ctx.fillStyle=ammoNow<=5?'#e06050':'#f2c173';ctx.fillRect(bx,by,barW*clamp(Math.min(ammoNow,capacity)/Math.max(1,capacity),0,1),barH);}
        }
        ctx.strokeStyle='#efd19a';ctx.beginPath();ctx.moveTo(x,y);ctx.lineTo(x+player.facing.x*24,y+player.facing.y*24);ctx.stroke();
    }

    function drawInteractable(item,x,y){
        const symbols={chest:'▣',hidden:'◆',camp:'♨',shrine:'✦',merchant:'¤',crate:'□',townDoor:'▥',warehouse:'▤',bank:'币',synthesis:'⚒',repair:'♜'};
        const colors={chest:'#d0a454',hidden:'#b884d0',camp:'#d98251',shrine:'#9d80c3',merchant:'#82a879',crate:'#a47c4b',townDoor:'#b47bdd',warehouse:'#d0a454',bank:'#d8bd78',synthesis:'#c779d8',repair:'#78a9c8'};
        if(item.type==='bank'){ctx.fillStyle='#25261f';ctx.fillRect(x-28,y-20,56,42);ctx.fillStyle='#d8bd78';ctx.beginPath();ctx.moveTo(x-34,y-20);ctx.lineTo(x,y-40);ctx.lineTo(x+34,y-20);ctx.closePath();ctx.fill();ctx.strokeStyle='#796b48';ctx.strokeRect(x-28,y-20,56,42)}
        ctx.fillStyle=colors[item.type]||'#aaa';ctx.font='bold 24px Georgia';ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillText(symbols[item.type]||'?',x,y);
        if(item.locked){ctx.font='10px Microsoft YaHei';ctx.fillStyle='#ead4a3';ctx.fillText('锁',x,y+20)}
        const names={chest:item.locked?'上锁武器箱':'武器宝箱',hidden:'隐藏武器箱',camp:'恢复营火',shrine:'遗迹祭坛',merchant:'无灯商人',crate:'补给木箱',townDoor:'地牢大门',warehouse:item.locked?`${item.warehouseNo}号仓库（未购买）`:`${item.warehouseNo}号云端仓库`,bank:'主城银行',synthesis:'品质重铸机',repair:'护甲修复机器'};
        if(distance(item,player)<110)drawLabel(`${names[item.type]||'场景物品'} · ${touchAutoFacing?'靠近互动':'E互动'}`,x,y-25,colors[item.type]||'#ddd');
    }

    function enemyDisplayName(enemy) {
        const nameMap = {
            crawler: '穴行兽', archer: '弩箭守卫', shotgunner: '霰弹守卫',
            brute: '重甲看守', bomber: '自爆怪', juggernaut: '石甲巨像'
        };
        const base = enemy.customName||nameMap[enemy.kind] || '未知生物';
        return enemy.champion ? `精英${base}` : base;
    }

    function drawEnemy(enemy,x,y){
        // 自爆怪引信倒计时：红色脉冲警告圈
        if(enemy.kind==='bomber' && enemy.fuseTime>0){
            const fuseRatio=enemy.fuseTime/1.2;
            const pulse=1+Math.sin(performance.now()/60)*(1-fuseRatio)*0.3;
            ctx.save();
            ctx.strokeStyle=`rgba(255,${Math.round(80+100*(1-fuseRatio))},40,${0.5+0.5*(1-fuseRatio)})`;
            ctx.lineWidth=2+(1-fuseRatio)*3;
            ctx.beginPath();ctx.arc(x,y,(enemy.radius+10)*pulse,0,Math.PI*2);ctx.stroke();
            ctx.fillStyle=`rgba(255,60,20,${0.15*(1-fuseRatio)})`;
            ctx.beginPath();ctx.arc(x,y,70*(1-fuseRatio*0.5),0,Math.PI*2);ctx.fill();
            ctx.restore();
            drawLabel('自爆！',x,y-enemy.radius-22,'#ff6040');
        }
        if(enemy.windup>0){ctx.fillStyle=enemy.elite?'rgba(143,85,189,.14)':enemy.champion?'rgba(212,74,58,.2)':'rgba(180,55,47,.12)';ctx.strokeStyle=enemy.elite?'rgba(190,126,224,.8)':enemy.champion?'rgba(255,80,60,.9)':'rgba(219,86,73,.72)';ctx.beginPath();ctx.arc(x,y,enemy.kind==='juggernaut'?95:enemy.kind==='brute'?84:enemy.kind==='boss'?105:enemy.kind==='archer'?34:enemy.kind==='shotgunner'?44:enemy.kind==='bomber'?50:58,0,Math.PI*2);ctx.fill();ctx.stroke();const skillNames={dash:enemy.champion?(enemy.dashTrueDamage?'死冲锋刺':'精英冲刺'):'冲刺',charge:'紫晶冲锋',nova:'虚空弹幕',volley:'三重魔弹',summon:'召唤守卫',melee:'蓄力重击',shot:'瞄准射击',shotgun:'霰弹轰击',selfdestruct:'自爆',homing:'追踪导弹',slam:'巨力砸地'};drawLabel(skillNames[enemy.attackMode]||'准备攻击',x,y-enemy.radius-22,enemy.elite?'#d59aef':enemy.champion?'#ff7060':enemy.kind==='juggernaut'?'#e0a060':'#ef9b8d')}
        // 精英怪外圈光晕
        if(enemy.champion){ctx.save();ctx.strokeStyle='rgba(255,70,50,.35)';ctx.lineWidth=3;ctx.beginPath();ctx.arc(x,y,enemy.radius+5,0,Math.PI*2);ctx.stroke();ctx.restore();}
        if(enemy.heldWeapon){ctx.save();ctx.strokeStyle=qualityColor(enemy.heldWeapon.quality);ctx.lineWidth=3;const angle=Math.atan2(player.y-enemy.y,player.x-enemy.x);ctx.beginPath();ctx.moveTo(x,y);ctx.lineTo(x+Math.cos(angle)*(enemy.radius+12),y+Math.sin(angle)*(enemy.radius+12));ctx.stroke();ctx.fillStyle=qualityColor(enemy.heldWeapon.quality);ctx.font='bold 11px Georgia';ctx.fillText(enemy.heldWeapon.icon||'†',x+Math.cos(angle)*(enemy.radius+15),y+Math.sin(angle)*(enemy.radius+15));ctx.restore();}
        // 自爆怪：橙色圆形 + 内部引信火花
        if(enemy.kind==='bomber'){
            ctx.fillStyle=enemy.color;ctx.beginPath();ctx.arc(x,y,enemy.radius,0,Math.PI*2);ctx.fill();
            // 内部引信火花
            const spark=0.5+Math.sin(performance.now()/100)*0.5;
            ctx.fillStyle=`rgba(255,${Math.round(180+60*spark)},60,${0.6+0.4*spark})`;
            ctx.beginPath();ctx.arc(x,y,enemy.radius*0.4,0,Math.PI*2);ctx.fill();
        }else if(enemy.kind==='juggernaut'){
            // 重装巨像：六边形厚重装甲 + 内部核心 + 砸地蓄力时发光
            const slamCharge = enemy.attackMode==='slam' && enemy.windup>0 ? (1 - enemy.windup/1.15) : 0;
            ctx.save();
            // 外层光晕（蓄力时增强）
            if(slamCharge>0){
                const glow=ctx.createRadialGradient(x,y,enemy.radius*0.5,x,y,enemy.radius*2);
                glow.addColorStop(0,`rgba(224,160,96,${slamCharge*0.4})`);
                glow.addColorStop(1,'rgba(224,160,96,0)');
                ctx.fillStyle=glow;ctx.beginPath();ctx.arc(x,y,enemy.radius*2,0,Math.PI*2);ctx.fill();
            }
            // 六边形主体
            ctx.fillStyle=enemy.color;
            ctx.beginPath();
            for(let i=0;i<6;i++){
                const a=Math.PI/3*i-Math.PI/6;
                const px=x+Math.cos(a)*enemy.radius;
                const py=y+Math.sin(a)*enemy.radius;
                if(i===0)ctx.moveTo(px,py);else ctx.lineTo(px,py);
            }
            ctx.closePath();ctx.fill();
            // 装甲板描边
            ctx.strokeStyle=slamCharge>0?`rgba(224,160,96,${0.5+slamCharge*0.5})`:'#6a5048';
            ctx.lineWidth=2.5;ctx.stroke();
            // 内部装甲纹理（三条线）
            ctx.strokeStyle='rgba(0,0,0,.25)';ctx.lineWidth=1.5;
            for(let i=0;i<3;i++){
                const a=Math.PI/3*i;
                ctx.beginPath();ctx.moveTo(x+Math.cos(a)*enemy.radius*0.3,y+Math.sin(a)*enemy.radius*0.3);
                ctx.lineTo(x+Math.cos(a)*enemy.radius*0.85,y+Math.sin(a)*enemy.radius*0.85);ctx.stroke();
            }
            // 核心光点
            const corePulse=0.6+Math.sin(performance.now()/300)*0.4;
            ctx.fillStyle=`rgba(224,160,96,${corePulse*(0.5+slamCharge*0.5)})`;
            ctx.beginPath();ctx.arc(x,y,enemy.radius*0.22,0,Math.PI*2);ctx.fill();
            ctx.restore();
        }else{
            ctx.fillStyle=enemy.color;ctx.beginPath();if(enemy.kind==='archer'||enemy.kind==='shotgunner'){ctx.rect(x-enemy.radius,y-enemy.radius,enemy.radius*2,enemy.radius*2)}else{ctx.moveTo(x,y-enemy.radius);ctx.lineTo(x+enemy.radius,y+enemy.radius);ctx.lineTo(x-enemy.radius,y+enemy.radius);ctx.closePath()}ctx.fill();
        }
        const monsterImage=cloud.monsterImages[enemy.kind];if(monsterImage){ctx.save();ctx.beginPath();ctx.arc(x,y,enemy.radius+2,0,Math.PI*2);ctx.clip();ctx.drawImage(monsterImage,x-enemy.radius-2,y-enemy.radius-2,(enemy.radius+2)*2,(enemy.radius+2)*2);ctx.restore();}
        // 精英怪描边
        if(enemy.champion){ctx.strokeStyle='#ff5040';ctx.lineWidth=2;ctx.beginPath();if(enemy.kind==='archer'||enemy.kind==='shotgunner'){ctx.rect(x-enemy.radius,y-enemy.radius,enemy.radius*2,enemy.radius*2)}else if(enemy.kind==='bomber'){ctx.arc(x,y,enemy.radius,0,Math.PI*2)}else if(enemy.kind==='juggernaut'){for(let i=0;i<6;i++){const a=Math.PI/3*i-Math.PI/6;const px=x+Math.cos(a)*enemy.radius,py=y+Math.sin(a)*enemy.radius;if(i===0)ctx.moveTo(px,py);else ctx.lineTo(px,py);}ctx.closePath()}else{ctx.moveTo(x,y-enemy.radius);ctx.lineTo(x+enemy.radius,y+enemy.radius);ctx.lineTo(x-enemy.radius,y+enemy.radius);ctx.closePath()}ctx.stroke();ctx.lineWidth=1;}
        ctx.fillStyle=enemy.elite?'#281435':'#4a1d1a';ctx.fillRect(x-enemy.radius,y-enemy.radius-8,enemy.radius*2,4);ctx.fillStyle=enemy.elite?'#bd7be3':enemy.champion?'#ff5040':'#d96357';ctx.fillRect(x-enemy.radius,y-enemy.radius-8,enemy.radius*2*(enemy.hp/enemy.maxHp),4);
        if(!enemy.elite){ctx.save();ctx.font='bold 10px Microsoft YaHei';ctx.textAlign='center';ctx.textBaseline='bottom';const nameTxt=enemyDisplayName(enemy);const tw=ctx.measureText(nameTxt).width;ctx.fillStyle='rgba(5,6,5,.7)';ctx.fillRect(x-tw/2-4,y-enemy.radius-20,tw+8,13);ctx.fillStyle=enemy.champion?'#ffb0a0':'#e8c8a0';ctx.fillText(nameTxt,x,y-enemy.radius-8);ctx.restore();}
        if(enemy.executeReady)drawLabel(touchAutoFacing?'点击斩杀':'按 F 踹飞斩杀',x,y-enemy.radius-36,'#f0d29b');
    }

    function drawLabel(text,x,y,color='#e8dfcc'){
        ctx.save();ctx.font='bold 10px Microsoft YaHei';ctx.textAlign='center';ctx.textBaseline='middle';const width=ctx.measureText(text).width+10;ctx.fillStyle='rgba(5,6,5,.78)';ctx.fillRect(x-width/2,y-8,width,16);ctx.fillStyle=color;ctx.fillText(text,x,y);ctx.restore();
    }

    function drawEffects(sx,sy){
        state.effects.forEach(effect=>{
            const alpha=clamp(effect.life/(effect.maxLife||effect.life),0,1);
            ctx.globalAlpha=alpha;
            if(effect.type==='text'){
                ctx.fillStyle=effect.color;ctx.font='bold 16px Microsoft YaHei';ctx.textAlign='center';
                ctx.fillText(effect.text,sx(effect.x),sy(effect.y)-20*(1-alpha));
            }else if(effect.type==='slash'){
                ctx.strokeStyle=effect.color;ctx.lineWidth=5;
                ctx.beginPath();ctx.arc(sx(effect.x),sy(effect.y),effect.range,effect.angle-.65,effect.angle+.65);ctx.stroke();ctx.lineWidth=1;
            }else if(effect.type==='ring'||effect.type==='burst'){
                ctx.strokeStyle=effect.color;ctx.beginPath();
                ctx.arc(sx(effect.x),sy(effect.y),(1-alpha)*42+8,0,Math.PI*2);ctx.stroke();
            }else if(effect.type==='poison'){
                // 毒雾：绿色半透明云团，边缘虚散
                const r=effect.radius||22;
                const pulse=1+Math.sin(performance.now()/300+effect.x)*0.12;
                const grad=ctx.createRadialGradient(sx(effect.x),sy(effect.y),0,sx(effect.x),sy(effect.y),r*pulse);
                grad.addColorStop(0,'rgba(120,200,90,.28)');
                grad.addColorStop(0.6,'rgba(80,160,60,.16)');
                grad.addColorStop(1,'rgba(60,120,40,0)');
                ctx.fillStyle=grad;ctx.beginPath();ctx.arc(sx(effect.x),sy(effect.y),r*pulse,0,Math.PI*2);ctx.fill();
                // 内部小气泡
                for(let i=0;i<4;i++){
                    const a=(performance.now()/800+i*1.7)%(Math.PI*2);
                    const br=r*0.5*pulse;
                    ctx.fillStyle='rgba(160,220,130,.15)';
                    ctx.beginPath();ctx.arc(sx(effect.x)+Math.cos(a)*br,sy(effect.y)+Math.sin(a)*br,3+Math.sin(performance.now()/200+i)*1.5,0,Math.PI*2);ctx.fill();
                }
            }else if(effect.type==='explosion'){
                // 自爆爆炸：橙红色扩散圆环 + 中心闪光
                const r=(1-alpha)*(effect.radius||80);
                const grad=ctx.createRadialGradient(sx(effect.x),sy(effect.y),0,sx(effect.x),sy(effect.y),Math.max(r,1));
                grad.addColorStop(0,`rgba(255,200,80,${alpha*0.9})`);
                grad.addColorStop(0.4,`rgba(240,100,40,${alpha*0.6})`);
                grad.addColorStop(1,'rgba(180,40,20,0)');
                ctx.fillStyle=grad;ctx.beginPath();ctx.arc(sx(effect.x),sy(effect.y),Math.max(r,1),0,Math.PI*2);ctx.fill();
                ctx.strokeStyle=`rgba(255,180,80,${alpha})`;ctx.lineWidth=3;
                ctx.beginPath();ctx.arc(sx(effect.x),sy(effect.y),Math.max(r,1),0,Math.PI*2);ctx.stroke();ctx.lineWidth=1;
            }else if(effect.type==='shockwave'){
                // 重击者震波：地面波纹
                ctx.strokeStyle=`rgba(180,130,80,${alpha*0.7})`;ctx.lineWidth=4*(1-alpha)+1;
                ctx.beginPath();ctx.arc(sx(effect.x),sy(effect.y),(1-alpha)*(effect.radius||60)+10,0,Math.PI*2);ctx.stroke();ctx.lineWidth=1;
            }else if(effect.type==='screen'){
                ctx.fillStyle=effect.color;ctx.fillRect(0,0,canvas.width,canvas.height);
            }
            ctx.globalAlpha=1;
        });
    }

    function drawMinimap(){
        const scale=3,w=COLS*scale,h=ROWS*scale,left=canvas.width-w-16,top=16,now=performance.now();
        if(now-(state.minimapUpdatedAt||0)>=150||minimapCache.width!==w+14){
            minimapCache.width=w+14;minimapCache.height=h+14;
            minimapCtx.fillStyle='rgba(5,6,5,.84)';minimapCtx.fillRect(0,0,w+14,h+14);
            minimapCtx.strokeStyle='rgba(199,157,89,.35)';minimapCtx.strokeRect(.5,.5,w+13,h+13);
            for(let y=0;y<ROWS;y++)for(let x=0;x<COLS;x++)if(state.explored[y][x]&&state.map[y][x]){
                minimapCtx.fillStyle=state.bridges[y][x]?'#635b49':'#55564a';minimapCtx.fillRect(7+x*scale,7+y*scale,scale,scale);
            }
            const ex=tileOf(state.exit.x),ey=tileOf(state.exit.y);
            if(state.explored[ey]?.[ex]){minimapCtx.fillStyle='#e6ba6a';minimapCtx.fillRect(6+ex*scale,6+ey*scale,scale+2,scale+2)}
            state.minimapUpdatedAt=now;
        }
        ctx.drawImage(minimapCache,left-7,top-7);
        ctx.fillStyle='#f5e4c1';ctx.fillRect(left+tileOf(player.x)*scale-1,top+tileOf(player.y)*scale-1,scale+2,scale+2);
    }

    function drawBossBar(){
        const boss=state.enemies.find(enemy=>enemy.elite&&!enemy.dead);
        if(!state.bossFightActive||!boss||!boss.room)return;
        const width=Math.min(580,canvas.width-220),height=16,left=(canvas.width-width)/2,top=20;
        ctx.fillStyle='rgba(5,6,5,.9)';ctx.fillRect(left-14,top-13,width+28,48);ctx.strokeStyle='rgba(157,91,195,.58)';ctx.strokeRect(left-14.5,top-13.5,width+29,49);
        ctx.fillStyle='#f0dfcc';ctx.font='bold 13px Microsoft YaHei';ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillText(boss.majorBoss?'深层看守者':'传送门Boss',canvas.width/2,top-3);
        ctx.fillStyle='#24132e';ctx.fillRect(left,top+9,width,height);ctx.fillStyle='#9454bc';ctx.fillRect(left,top+9,width*clamp(boss.hp/boss.maxHp,0,1),height);ctx.strokeStyle='#c783e8';ctx.strokeRect(left+.5,top+9.5,width-1,height-1);
        ctx.fillStyle='#ead8f4';ctx.font='bold 10px Georgia';ctx.fillText(`${Math.max(0,Math.ceil(boss.hp))} / ${boss.maxHp}`,canvas.width/2,top+17);
    }

    function loop(timestamp){
        const dt=Math.min(.033,(timestamp-state.lastTime)/1000||0);state.lastTime=timestamp;
        if(!state.paused&&!state.ended){updatePotionEffect(timestamp);updatePlayer(dt);updateEnemies(dt);updateProjectiles(dt);updateEffects(dt);if(timestamp-state.lastUiUpdate>=100){updateUi();state.lastUiUpdate=timestamp}}
        // 地牢聊天轮询：每2秒获取新消息
        if(cloud.ready&&timestamp-state.chat.lastPoll>=2000){state.chat.lastPoll=timestamp;pollDungeonChat();}
        draw();requestAnimationFrame(loop);
    }

    function loseCarriedItems(){
        const lost=bagEntries().length;
        const lostWingCoins=Math.max(0,Number(player.wingCoins||0));
        player.wingCoins=0;
        dungeonApi('clearDungeonCarriedItems',{}).then(data=>{player.inventory=(data.inventory||[]).map(officialEntry).filter(Boolean);player.bagOrder=Array(21).fill('');player.wingCoins=Number(data.wingCoins||0);}).catch(()=>log('死亡物品与翼币结算失败，请重新进入游戏核对。'));
        player.inventory=[];player.bagOrder=Array(21).fill('');player.potions=0;player.keys=0;player.shards=0;player.ammo={ammo:0,modern:0,arrow:0,bolt:0,mana:0};player.weapon={...unarmedWeapon};player.equippedUid='';player.armor={head:null,chest:null,hands:null,legs:null};player.selectedBagId='';
        return { lost, lostWingCoins };
    }

    function endRun(success){
        const floor=player.floor,shards=player.shards;
        const loss=!success&&state.mode==='dungeon'?loseCarriedItems():{lost:0,lostWingCoins:0};
        state.ended=true;state.paused=true;clearInputs();$('modalSeal').textContent=success?'✓':'☠';$('modalTitle').textContent=success?'成功撤离':'倒在遗迹中';$('modalCopy').textContent=success?'随身战利品已经安全带回主城。':`你失去了身上的 ${loss.lost} 格装备与物品，以及 ${loss.lostWingCoins} 翼币；主城仓库中的物品全部安全。`;$('sumFloor').textContent=floor;$('sumGold').textContent=success?player.wingCoins:loss.lostWingCoins;$('sumShards').textContent=success?shards:0;$('restartBtn').textContent='返回大厅';$('modal').classList.add('show')
    }

    function leaveDeathScreen(){$('modal').classList.remove('show');returnToTown()}

    function resetGame(){
        Object.assign(player,{x:0,y:0,hp:100,maxHp:100,speed:175,floor:1,wingCoins:player.wingCoins||0,officialGold:player.officialGold||0,kills:0,potions:0,potionPower:35,keys:0,shards:0,weapon:{...unarmedWeapon},equippedUid:'',armor:player.armor||{head:null,chest:null,hands:null,legs:null},inventory:player.inventory||[],bagOrder:player.bagOrder||Array(21).fill(''),selectedBagId:'',facing:{x:1,y:0},attackCooldown:0,dashCooldown:0,dashCooldownMax:1.25,dashTime:0,invulnerable:0,damageMultiplier:1,attackSpeed:1,shardPower:0,vision:2,poisonSlow:0,ammo:player.ammo||{ammo:0,modern:0,arrow:0,bolt:0,mana:0}});
        state.potionEffect=null;
        state.paused=false;state.ended=false;state.camera={x:0,y:0};state.warehouseItems=[];state.warehouseOrder=Array(21).fill('');state.ammoMaxSeen=0;$('modal').classList.remove('show');$('log').innerHTML='<p><b>记录：</b>旅人抵达了遗迹主城。</p>';generateTown();
    }

    function clearInputs(){Object.keys(input).forEach(key=>input[key]=false);touchMove.x=0;touchMove.y=0;const stick=$('joystickStick');if(stick)stick.style.transform='translate(-50%,-50%)'}
    function keyName(eventKey){const key=eventKey.toLowerCase();return{arrowup:'w',arrowdown:'s',arrowleft:'a',arrowright:'d'}[key]||key}
    // 聊天输入框：Enter发送，Escape取消。其他按键正常打字，由 window 处理器在 inputOpen 时屏蔽游戏操作
    $('chatInput')?.addEventListener('keydown',event=>{
        if(event.key==='Enter'){event.preventDefault();closeChatInput(true);}
        else if(event.key==='Escape'){event.preventDefault();closeChatInput(false);}
    });
    window.addEventListener('keydown',event=>{
        const key=keyName(event.key);
        // 聊天输入打开时：不处理任何游戏按键，也不阻止默认行为（让字符正常输入到文本框）
        if(state.chat.inputOpen){
            return;
        }
        // Enter 打开聊天
        if(key==='enter'){event.preventDefault();openChatInput();return;}
        if(['w','a','s','d',' ','shift','e','q','f','b','tab','escape','1','2','3','4','5','6','7'].includes(key))event.preventDefault();if(['w','a','s','d'].includes(key)&&!state.paused)input[key]=true;if(event.repeat)return;if(/^[1-7]$/.test(key))quickEquip(Number(key)-1);else if(key===' ')attack();else if(key==='shift')dash();else if(key==='e')interact();else if(key==='q')usePotion();else if(key==='f')executeEnemy();else if(key==='b'||key==='tab')toggleBag();else if(key==='escape'){if($('synthesisModal').classList.contains('show'))closeSynthesisMachine();else if($('bankModal').classList.contains('show'))closeBank();else closeBag()}});
    window.addEventListener('keyup',event=>{const key=keyName(event.key);if(['w','a','s','d'].includes(key))input[key]=false});window.addEventListener('blur',clearInputs);
    document.querySelectorAll('.move').forEach(button=>{const key=button.dataset.key;button.addEventListener('pointerdown',event=>{event.preventDefault();button.setPointerCapture?.(event.pointerId);input[key]=true});['pointerup','pointercancel','pointerleave'].forEach(type=>button.addEventListener(type,()=>input[key]=false))});
    const joystick=$('touchJoystick'),joystickStick=$('joystickStick');
    if(joystick&&joystickStick){let joystickPointer=null;const updateJoystick=event=>{const rect=joystick.getBoundingClientRect(),radius=rect.width*.31,dx=event.clientX-(rect.left+rect.width/2),dy=event.clientY-(rect.top+rect.height/2),distance=Math.hypot(dx,dy),scale=distance>radius?radius/distance:1;touchMove.x=(dx*scale)/radius;touchMove.y=(dy*scale)/radius;joystickStick.style.transform=`translate(calc(-50% + ${dx*scale}px),calc(-50% + ${dy*scale}px))`;};const releaseJoystick=event=>{if(joystickPointer!==null&&(!event||event.pointerId===joystickPointer)){joystickPointer=null;clearInputs();}};joystick.addEventListener('pointerdown',event=>{event.preventDefault();event.stopPropagation();joystickPointer=event.pointerId;joystick.setPointerCapture?.(event.pointerId);updateJoystick(event);});joystick.addEventListener('pointermove',event=>{if(event.pointerId===joystickPointer)updateJoystick(event);});joystick.addEventListener('pointerup',releaseJoystick);joystick.addEventListener('pointercancel',releaseJoystick);}
    $('touchAttackBtn')?.addEventListener('pointerdown',event=>{event.preventDefault();event.stopPropagation();if(state.nearestInteractable)interact();else attack();});
    $('touchPotionBtn')?.addEventListener('pointerdown',event=>{event.preventDefault();event.stopPropagation();usePotion();});
    $('touchBagBtn')?.addEventListener('pointerdown',event=>{event.preventDefault();event.stopPropagation();toggleBag();});
    $('touchDashBtn')?.addEventListener('pointerdown',event=>{event.preventDefault();event.stopPropagation();dash();});
    $('touchExecuteBtn')?.addEventListener('pointerdown',event=>{event.preventDefault();event.stopPropagation();executeEnemy();});
    $('touchDiscardBtn')?.addEventListener('pointerdown',event=>{event.preventDefault();event.stopPropagation();discardHeldWeapon();});
    $('touchChatBtn')?.addEventListener('click',event=>{event.preventDefault();event.stopPropagation();toggleChatInput();});
    [canvas,...document.querySelectorAll('.canvas-wrap button')].forEach(element=>element.addEventListener('dblclick',event=>event.preventDefault()));
    // 点击画布：聊天打开时关闭聊天，否则攻击
    canvas.addEventListener('pointerdown', event => {
        if (state.chat.inputOpen) { closeChatInput(false); return; }
        if (event.button === 0) attack();
    });
    let lastGameTouchAt=0;
    document.querySelector('.canvas-wrap')?.addEventListener('touchend',event=>{const now=Date.now();if(now-lastGameTouchAt<320)event.preventDefault();lastGameTouchAt=now;},{passive:false});
    $('attackBtn').addEventListener('pointerdown',event=>{event.preventDefault();attack()});$('dashBtn').addEventListener('pointerdown',event=>{event.preventDefault();dash()});$('interactBtn').addEventListener('click',interact);$('healBtn').addEventListener('click',usePotion);$('executeBtn').addEventListener('click',executeEnemy);$('bagBtn').addEventListener('click',toggleBag);$('bagCloseBtn').addEventListener('click',closeBag);$('bagEquipBtn').addEventListener('click',equipBagItem);$('bagDiscardBtn').addEventListener('click',discardBagItem);$('toWarehouseBtn').addEventListener('click',()=>{const uid=player.selectedBagId;if(!uid)return;const emptyIdx=state.warehouseOrder.findIndex(v=>!v);if(emptyIdx<0){log('仓库已满，无法存入。');return;}moveBagToWarehouse(uid,emptyIdx);player.selectedBagId='';renderBag();});$('fromWarehouseBtn').addEventListener('click',()=>{const uid=state.selectedWarehouseId;if(!uid)return;const emptyIdx=player.bagOrder.findIndex(v=>!v);if(emptyIdx<0&&bagEntries().length>=21){log('背包已满，无法取出。');return;}const targetSlot=emptyIdx>=0?emptyIdx:bagEntries().length;moveWarehouseToBag(uid,targetSlot);state.selectedWarehouseId='';renderBag();});$('extractBtn').addEventListener('click',()=>openBag(true));$('restartBtn').addEventListener('click',leaveDeathScreen);$('equipWeaponBtn').addEventListener('click',()=>claimPendingWeapon(true));$('storeWeaponBtn').addEventListener('click',()=>claimPendingWeapon(false));$('leaveWeaponBtn').addEventListener('click',declinePendingWeapon);$('confirmExtractBtn').addEventListener('click',returnToTown);$('continueDungeonBtn').addEventListener('click',continueDungeon);
    $('leaveMerchantBtn').addEventListener('click',()=>{$('merchantModal').classList.remove('show');state.activeMerchant=null;state.paused=false});
    $('goldToWingBtn').addEventListener('click',()=>exchangeBankCurrency('gold_to_wing'));
    $('wingToGoldBtn').addEventListener('click',()=>exchangeBankCurrency('wing_to_gold'));
    $('bankCloseBtn').addEventListener('click',closeBank);
    $('synthesizeBtn').addEventListener('click',synthesizeDungeonItems);
    $('synthesisCloseBtn').addEventListener('click',closeSynthesisMachine);
    $('repairCloseBtn').addEventListener('click',closeRepairMachine);
    $('returnToOfficialBagBtn').addEventListener('click',()=>{location.href='bagdemo.html';});
    $('acceptDungeonRiskBtn').addEventListener('click',()=>acceptDungeonRisk(false));
    $('neverWarnDungeonBtn').addEventListener('click',()=>acceptDungeonRisk(true));

    if (location.protocol === 'file:') {
        window.__dungeonDemo = {
            openWeaponChest: () => { state.paused = true; startWeaponReel(); },
            openUpgrade: showUpgrade,
            openMerchant: () => showMerchant({ used:false }),
            grantMerchantGold: amount => { player.wingCoins += Math.max(0, Number(amount) || 0); renderMerchant(); updateUi(); },
            merchantStock: () => state.activeMerchant?.stock.map(item => ({id:item.id,name:item.name,price:item.price,sold:item.sold,type:item.type})) || [],
            spawnTarget: () => {
                const enemy = makeEnemy('crawler', tileOf(player.x), tileOf(player.y));
                enemy.x = player.x + player.facing.x * 42;
                enemy.y = player.y + player.facing.y * 42;
                enemy.speed = 0;
                enemy.cooldown = 99;
                state.enemies = [enemy];
                return enemy.hp;
            },
            targetHp: () => state.enemies[0]?.hp ?? 0,
            snapshot: () => { const boss=state.enemies.find(enemy=>enemy.elite&&!enemy.dead); return { mode:state.mode, floor:player.floor, content:{weapons:weaponPool.length,items:itemPool.length}, difficulty:floorDifficulty(), mapSize:{cols:COLS,rows:ROWS}, roomCount:state.rooms.length, bridgeTiles:state.bridges.flat().filter(Boolean).length, bagItems:bagEntries().length, warehouseItems:state.warehouseItems.length, ammo:{...player.ammo},projectiles:state.projectiles.filter(item=>item.owner==='player').length,enemyProjectiles:state.projectiles.filter(item=>item.owner==='enemy').length,pickups:state.pickups.filter(item=>!item.taken).length,activeRoom:state.activeRoom?{...state.activeRoom}:null, player:{x:player.x,y:player.y}, boss:boss?{x:boss.x,y:boss.y,hp:boss.hp,damage:boss.damage,speed:boss.speed,cooldownScale:boss.cooldownScale,color:boss.color}:null, bossFightActive:state.bossFightActive, bosses:state.enemies.filter(enemy=>enemy.elite&&!enemy.dead).length, inventory:player.inventory.length, enemies:state.enemies.length, weapon:player.weapon.name, equippedUid:player.equippedUid, portal:{...state.exit}, portalUnlocked:portalUnlocked(), bossRoom:state.rooms.find(room=>room.isBossRoom)||null }; },
            setFloor: floor => { player.floor=Math.max(1,Math.floor(Number(floor)||1));generateFloor(); },
            approachTownDoor: () => { const door=state.interactables.find(item=>item.type==='townDoor');player.x=door.x-30;player.y=door.y; },
            equipTestRanged: ammo => {player.weapon={...weaponPool.find(item=>item.id==='short_bow')};player.ammo.arrow=Math.max(0,Number(ammo)||0);player.attackCooldown=0;},
            grantTestItem: () => {const item=itemPool[0],uid=`test-${Date.now()}`;player.inventory.push({uid,type:'item',item:{...item}});syncBagOrder();return uid;},
            damageForTest: amount => {player.invulnerable=0;damagePlayer(Number(amount)||0);},
            prepareExecution: () => {const enemy=state.enemies.find(item=>!item.elite);player.x=enemy.x-35;player.y=enemy.y;player.facing={x:1,y:0};enemy.hp=Math.max(1,Math.floor(enemy.maxHp*.2));enemy.executeRolled=true;enemy.executeReady=true;return state.enemies.length;},
            enterCombatRoom: () => {const enemy=state.enemies.find(item=>!item.elite);player.x=tileCenter(enemy.room.cx);player.y=tileCenter(enemy.room.cy);},
            enemyPosition: () => {const enemy=state.enemies.find(item=>!item.elite);return enemy?{x:enemy.x,y:enemy.y}:null},
            waitOutsideEnemyRoom: () => {const enemy=state.enemies.find(item=>!item.elite);player.x=enemy.room.x*TILE-player.radius-3;player.y=enemy.y;state.activeRoom=null;},
            forceOutsideActiveRoom: () => {if(state.activeRoom){player.x=state.activeRoom.x*TILE-50;player.y=tileCenter(state.activeRoom.cy)}},
            clearActiveRoom: () => {if(state.activeRoom)state.enemies.filter(enemy=>enemy.room&&enemy.room.x===state.activeRoom.x&&enemy.room.y===state.activeRoom.y).forEach(enemy=>enemy.dead=true)},
            triggerBossSkill: mode => {const boss=state.enemies.find(enemy=>enemy.elite);state.bossFightActive=true;player.x=boss.x-150;player.y=boss.y;boss.targetX=player.x;boss.targetY=player.y;boss.attackMode=mode;resolveEnemyAttack(boss);},
            triggerMonsterDash: () => {const enemy=state.enemies.find(item=>!item.elite&&item.kind!=='archer');state.enemies.forEach(item=>item.testDash=false);enemy.testDash=true;player.x=enemy.x+140;player.y=enemy.y;state.activeRoom={...enemy.room};enemy.targetX=player.x;enemy.targetY=player.y;enemy.attackMode='dash';resolveEnemyAttack(enemy);return{kind:enemy.kind,x:enemy.x,y:enemy.y}},
            dashMonsterPosition: () => {const enemy=state.enemies.find(item=>item.testDash);return{x:enemy.x,y:enemy.y}},
            enemiesInsideRooms: () => state.enemies.every(enemy=>!enemy.room||(enemy.x>=enemy.room.x*TILE+enemy.radius-.1&&enemy.x<=(enemy.room.x+enemy.room.w)*TILE-enemy.radius+.1&&enemy.y>=enemy.room.y*TILE+enemy.radius-.1&&enemy.y<=(enemy.room.y+enemy.room.h)*TILE-enemy.radius+.1)),
            defeatPortalBoss: () => { state.enemies.filter(enemy=>enemy.elite).forEach(enemy=>enemy.dead=true); state.enemies=state.enemies.filter(enemy=>!enemy.dead); },
            approachPortal: () => { player.x=state.exit.x-20;player.y=state.exit.y; },
            enterBossRoom: () => { const room=bossRoom();player.x=tileCenter(room.cx);player.y=room.y*TILE+player.radius+2; },
            forceOutsideBossRoom: () => { const room=bossRoom();player.x=room.x*TILE-player.radius-40;player.y=tileCenter(room.cy); },
            showPortalRoom: () => {
                state.explored = state.explored.map((row,y)=>row.map((_,x)=>state.map[y][x]===1));
                player.x = state.exit.x - TILE * 2;
                player.y = state.exit.y;
                state.camera.x = clamp(player.x-canvas.width/2,0,COLS*TILE-canvas.width);
                state.camera.y = clamp(player.y-canvas.height/2,0,ROWS*TILE-canvas.height);
            },
            regenerate: generateFloor,
            validateMap: () => {
                const start = { x:tileOf(player.x), y:tileOf(player.y) };
                const goal = { x:tileOf(state.exit.x), y:tileOf(state.exit.y) };
                const queue = [start];
                const seen = new Set([`${start.x},${start.y}`]);
                while (queue.length) {
                    const current = queue.shift();
                    for (const [dx,dy] of [[1,0],[-1,0],[0,1],[0,-1]]) {
                        const x=current.x+dx,y=current.y+dy,id=`${x},${y}`;
                        if (x>=0&&y>=0&&x<COLS&&y<ROWS&&state.map[y][x]===1&&!seen.has(id)) { seen.add(id); queue.push({x,y}); }
                    }
                }
                return seen.has(`${goal.x},${goal.y}`);
            }
        };
    }

    async function initializeGame() {
        try {
            const data=await dungeonApi('getDungeonState');
            hydrateCloud(data);
            const saved=data.state||{};
            if(saved.scene==='dungeon'){
                const refreshDeath = await handleDungeonRefresh(saved);
                if (refreshDeath) {
                    generateTown();
                    cloud.ready=true;
                    await saveCloudState();
                    log('本局已结束，地牢物品和翼币已掉落，已返回主城。');
                    requestAnimationFrame(loop);
                    return;
                }
                player.floor=Math.max(1,Number(saved.floor_no||1));
                generateFloor();
            }else generateTown();
            const savedX=Number(saved.pos_x),savedY=Number(saved.pos_y);
            if(Number.isFinite(savedX)&&Number.isFinite(savedY)&&walkable(savedX,savedY,player.radius)){player.x=savedX;player.y=savedY;}
            player.hp=Math.max(1,Math.min(player.maxHp,Number(saved.hp||player.maxHp)));
            cloud.ready=true;
            log(`已连接官网账号 ${data.username||data.userId}，背包、坐标与仓库均使用云端数据。`);
        } catch(error) {
            state.paused=true;
            $('objective').textContent=error.message==='unauthorized'?'请先登录官网后再进入地牢':'云端数据加载失败，请刷新重试';
            $('log').innerHTML=`<p><b>系统：</b>${$('objective').textContent}</p>`;
        }
        requestAnimationFrame(loop);
    }
    setInterval(saveCloudState,2000);
    window.addEventListener('pagehide',saveCloudStateOnExit);
    document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='hidden')saveCloudStateOnExit();});
    async function startAuthenticatedDungeon() {
        const auth = window.JourneyAuth ? await JourneyAuth.ready : { authenticated:null, offline:true };
        if (!auth || auth.authenticated !== true || !auth.user) {
            const title = $('dungeonLoginTitle');
            const copy = $('dungeonLoginCopy');
            if (title) title.textContent = auth?.offline ? '无法验证登录状态' : '需要登录账号';
            if (copy && auth?.offline) copy.textContent = '暂时无法验证登录状态，请检查网络后重新打开页面。未验证身份时不能进入黑暗地牢。';
            $('dungeonLoginRequired')?.classList.add('show');
            state.paused = true;
            return;
        }
        $('dungeonLoginRequired')?.classList.remove('show');
        await initializeGame();
        refreshDungeonOnline();
        setInterval(refreshDungeonOnline,5000);
    }
    startAuthenticatedDungeon();
})();
