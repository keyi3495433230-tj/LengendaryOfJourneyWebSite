import { GameClient } from '../network/GameClient.js?v=58';
import { backpackManager, MAIN_SIZE } from '../network/BackpackManager.js?v=58';
import * as DomBag from '../network/DomBackpack.js?v=58';

export class GameScene extends Phaser.Scene {
    constructor() {
        super('GameScene');
    }

    init(data) {
        this._roomId = data.roomId || 'lobby';
        this._roomName = data.roomName || this._roomId;
        this._playerName = data.playerName || '游客';
        this._pendingLevel = data.level;
        this._pendingTitle = data.title;
    }

    create() {
        const { width, height } = this.scale;

        // 直接使用LobbyScene传过来的玩家信息，不需要重复请求
        this._myLevel = this._pendingLevel || 1;
        this._myTitle = this._pendingTitle || '';

        this._players = new Map();
        this._playerSprites = new Map();
        this._chatMessages = [];
        this._chatBubbleContainers = new Map();
        this._remotePlayers = new Map();
        this._droppedItems = new Map();
        this._pickupInFlight = new Set();
        this._pickupNoticeAt = 0;
        this._dropInFlight = false;
        this._hoveredSlot = null;
        this._kicked = false;
        this._moveTarget = null;
        this._chatInputVisible = false;
        this._isMobile = this._checkMobile();
        this._joystickActive = false;
        this._joystickVector = { x: 0, y: 0 };
        this._joined = false;
        this._gameClientHandlers = null;

        try {
            // 先立即创建世界和玩家，让用户看到界面
            this._createWorld();
            this._createPlayer();
            this._createChatUI();
            this._createHUD();
            this._createMoveMarker();
            if (this._isMobile) {
                this._createJoystick();
                this._createMobileTabBar();
            }
            this._setupInput();
            this._setupLeaveDetection();

            // 初始化DOM背包（HTML原生拖拽，稳定可靠）
            DomBag.initDomBackpack({
                isMobile: this._isMobile,
                onHotbarSelect: (idx) => this._showHotbar(idx),
                onDropItem: (slotIndex) => this._dropSlot(slotIndex),
                onMessage: (message) => this._addSystemMessage(message, '#ffb86b')
            });
            this._backpackUpdateHandler = () => this._refreshHeldItem();
            backpackManager.onUpdate(this._backpackUpdateHandler);
            this._inventorySyncErrorHandler = () => {
                this._addSystemMessage('背包同步失败，已重新读取云端数据', '#ff6b6b');
            };
            window.addEventListener('journey-inventory-sync-error', this._inventorySyncErrorHandler);
            this._selectedHotbar = 0;
            this._refreshHeldItem();

            // 显示加载提示
            this._loadingText = this.add.text(width / 2, height / 2 - 100, '正在连接服务器...', {
                fontSize: '20px', color: '#4ecdc4', fontFamily: 'Microsoft YaHei'
            }).setOrigin(0.5).setDepth(1000);

            // 注册事件，清理时统一解绑，避免场景重进后重复触发
            this._gameClientHandlers = {
                room_state: (d) => this._onRoomState(d),
                room_drops: (d) => this._onRoomDrops(d),
                player_state: (d) => this._onPlayerState(d),
                player_left: (d) => this._onPlayerLeft(d),
                player_joined: (d) => this._onPlayerJoined(d),
                chat_message: (d) => this._onChatMessage(d),
                item_dropped: (d) => this._onItemDropped(d),
                item_picked: (d) => this._onItemPicked(d),
                disconnected: () => this._onServerDisconnect(),
                room_joined: (d) => this._onRoomJoined(d)
            };
            Object.entries(this._gameClientHandlers).forEach(([type, handler]) => {
                GameClient.on(type, handler);
            });

            this.events.on('shutdown', () => this._cleanup());
            this._lastSendTime = 0;
            this._lastSendPos = { x: this._playerSprite.x, y: this._playerSprite.y };

            // 异步加入游戏，不阻塞渲染
            this.time.delayedCall(100, () => this._joinGame());
        } catch (e) {
            console.error('[GameScene] Create error:', e);
            this.add.text(width / 2, height / 2, '加载出错: ' + e.message, {
                fontSize: '20px', color: '#ff6b6b', fontFamily: 'Microsoft YaHei'
            }).setOrigin(0.5);
        }
    }

    async _joinGame() {
        const { width, height } = this.scale;
        try {
            const joinSuccess = await GameClient.joinRoom(this._roomId, this._playerName, this._myLevel, this._myTitle);
            if (this._loadingText) this._loadingText.destroy();
            
            if (!joinSuccess) {
                this.add.text(width / 2, height / 2, '加入游戏失败，请刷新重试', {
                    fontSize: '24px', color: '#ff6b6b', fontFamily: 'Microsoft YaHei'
                }).setOrigin(0.5).setDepth(1000);
                this.add.text(width / 2, height / 2 + 50, '点击刷新', {
                    fontSize: '18px', color: '#4ecdc4', fontFamily: 'Microsoft YaHei'
                }).setOrigin(0.5).setInteractive({ useHandCursor: true }).setDepth(1000)
                    .on('pointerdown', () => window.location.reload());
            }
        } catch (e) {
            if (this._loadingText) this._loadingText.destroy();
            console.error('[GameScene] Join error:', e);
            this.add.text(width / 2, height / 2, '连接失败: ' + e.message, {
                fontSize: '20px', color: '#ff6b6b', fontFamily: 'Microsoft YaHei'
            }).setOrigin(0.5).setDepth(1000);
        }
    }

    _loadPlayerProfile() {
        return { level: 1, title: '' };
    }

    _checkMobile() {
        return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) 
            || (window.matchMedia && window.matchMedia('(max-width: 900px)').matches);
    }

    _createWorld() {
        const { width, height } = this.scale;
        // 纯色背景
        const bg = this.add.graphics();
        bg.fillStyle(0x2d5a27, 1);
        bg.fillRect(0, 0, width, height);
        this._playerLayer = this.add.layer();
        this._dropLayer = this.add.layer();
        this._uiLayer = this.add.layer();
    }

    _createTree(x, y) {
        const t = this.add.container(x, y);
        t.add([
            this.add.ellipse(0, 12, 30, 8, 0x000000, 0.3),
            this.add.rectangle(0, 0, 8, 14, 0x6b4423),
            this.add.circle(0, -14, 20, 0x2d6a4f),
            this.add.circle(-8, -10, 14, 0x40916c),
            this.add.circle(8, -10, 14, 0x52b788)
        ]);
    }

    _createFlower(x, y, color) {
        const f = this.add.container(x, y);
        f.add([
            this.add.circle(0, -5, 5, color), this.add.circle(-5, 0, 5, color),
            this.add.circle(5, 0, 5, color), this.add.circle(0, 5, 5, color),
            this.add.circle(0, 0, 4, 0xffd93d)
        ]);
    }

    _createCapsuleSprite(name, bodyColor, isSelf = false, level = 1, title = '') {
        const c = this.add.container(0, 0);
        const body = this.add.container(0, 0);
        body.add([
            this.add.ellipse(0, 20, 32, 10, 0x000000, 0.25),
            (() => { const g = this.add.graphics(); g.fillStyle(bodyColor, 1); g.fillRoundedRect(-16, -8, 32, 32, 16); return g; })(),
            (() => { const g = this.add.graphics(); g.fillStyle(0xffffff, 0.2); g.fillRoundedRect(-10, -6, 8, 18, 4); return g; })(),
            this.add.circle(-5, 0, 3.5, 0x000000),
            this.add.circle(5, 0, 3.5, 0x000000),
            this.add.circle(-4, -1, 1, 0xffffff),
            this.add.circle(6, -1, 1, 0xffffff),
            this.add.arc(0, 7, 10, 0, Math.PI, false, 0x000000)
        ]);
        c.add(body);

        const titleText = title ? ` ${title}` : '';
        const lvText = this.add.text(0, -68, `Lv.${level}${titleText}`, {
            fontSize: '12px', color: this._getTitleColor(level), fontFamily: 'Microsoft YaHei', fontStyle: 'bold',
            backgroundColor: '#00000099', padding: { x: 5, y: 2 }
        }).setOrigin(0.5);
        c.add(lvText);

        const displayName = name || GameClient.playerName || '玩家';
        const nameText = this.add.text(0, -48, displayName, {
            fontSize: '14px', color: '#ffffff', fontFamily: 'Microsoft YaHei', fontStyle: 'bold',
            backgroundColor: '#00000099', padding: { x: 6, y: 2 }
        }).setOrigin(0.5);
        c.add(nameText);

        const heldItemText = this.add.text(22, 9, '', {
            fontSize: '22px', fontFamily: 'Microsoft YaHei'
        }).setOrigin(0.5).setVisible(false);
        c.add(heldItemText);

        c.setSize(40, 56);
        c.setData('nameText', nameText);
        c.setData('lvText', lvText);
        c.setData('body', body);
        c.setData('heldItemText', heldItemText);
        c.setData('facing', 1);
        c.setData('level', level);
        c.setData('title', title);

        if (isSelf) {
            const ring = this.add.arc(0, 20, 22, 0, Math.PI * 2, false, 0x64b5f6, 0.6);
            c.add(ring);
            this._selectionRing = ring;
        }
        return c;
    }

    _setSpriteFacing(sprite, facing) {
        if (!sprite) return;
        const normalized = facing < 0 ? -1 : 1;
        const body = sprite.getData('body');
        const held = sprite.getData('heldItemText');
        if (body) body.setScale(normalized, 1);
        if (held) held.setX(normalized * 23);
        sprite.setData('facing', normalized);
    }

    _setHeldItemVisual(sprite, item) {
        if (!sprite) return;
        const held = sprite.getData('heldItemText');
        if (!held) return;
        held.setText(item?.icon || '').setVisible(Boolean(item));
    }

    _refreshHeldItem() {
        const item = backpackManager.getHotbarSlot(this._selectedHotbar || 0);
        this._setHeldItemVisual(this._playerSprite, item);
        GameClient.setHeldItem(item);
    }

    _updateNameTag(id, name, level, title) {
        const sprite = id === GameClient.playerId ? this._playerSprite : this._playerSprites.get(id);
        if (!sprite) return;
        const nameText = sprite.getData('nameText');
        const lvText = sprite.getData('lvText');
        if (nameText) nameText.setText(name || '玩家');
        if (lvText) {
            lvText.setText(`Lv.${level}${title ? ` ${title}` : ''}`);
            lvText.setColor(this._getTitleColor(level));
        }
    }

    _getTitleColor(level) {
        const numericLevel = Math.max(1, Number(level) || 1);
        if (numericLevel <= 10) return '#ffffff';

        // 10级以上由白色平滑过渡到红色，等级越高红色越明显。
        const ratio = Math.min(1, (numericLevel - 10) / 90);
        const greenBlue = Math.round(255 * (1 - ratio));
        return `rgb(255, ${greenBlue}, ${greenBlue})`;
    }

    _savePlayerPosition() {
        try {
            if (!this._playerSprite) return;
            GameClient.savePosition(this._playerSprite.x, this._playerSprite.y);
        } catch (e) {
            console.warn('[GameScene] Failed to save position:', e);
        }
    }

    _loadPlayerPosition() {
        return null;
    }

    _createPlayer() {
        const { width, height } = this.scale;
        // 出生点由服务器在 join 时统一下发，先在中心做占位，避免本地缓存分叉
        const sx = width / 2;
        const sy = height / 2;
        const playerName = this._playerName || GameClient.playerName || '玩家';
        
        this._playerSprite = this._createCapsuleSprite(playerName, 0x4ecdc4, true, this._myLevel, this._myTitle);
        this._playerSprite.setPosition(sx, sy);
        this._playerLayer.add(this._playerSprite);
        this._facing = 1;
        this._lastSendPos = { x: sx, y: sy };
        
        // 进入游戏后立即发送位置
        this._lastSendTime = 0;
    }

    _createMoveMarker() {
        this._moveMarker = this.add.container(0, 0);
        this._moveMarker.setScrollFactor(0);
        const ring = this.add.arc(0, 0, 12, 0, Math.PI * 2, false, 0xffffff, 0.6);
        const inner = this.add.arc(0, 0, 4, 0, Math.PI * 2, false, 0xffffff, 0.9);
        this._moveMarker.add([ring, inner]);
        this._moveMarker.setVisible(false);
        this._moveMarker.setDepth(100);
        this._uiLayer.add(this._moveMarker);
    }

    _createJoystick() {
        const { width, height } = this.scale;
        const joyX = 110, joyY = height - 170, joyRadius = 70;
        
        this._joystick = this.add.container(joyX, joyY);
        this._joystick.setScrollFactor(0);
        this._joystick.setDepth(200);
        
        // 底座
        const baseBg = this.add.graphics();
        baseBg.fillStyle(0x000000, 0.4);
        baseBg.fillCircle(0, 0, joyRadius);
        baseBg.lineStyle(3, 0x4ecdc4, 0.6);
        baseBg.strokeCircle(0, 0, joyRadius);
        
        // 摇杆头
        const stickBg = this.add.graphics();
        stickBg.fillStyle(0x4ecdc4, 0.8);
        stickBg.fillCircle(0, 0, 30);
        stickBg.lineStyle(2, 0xffffff, 0.6);
        stickBg.strokeCircle(0, 0, 30);
        
        this._joystick.add([baseBg, stickBg]);
        this._uiLayer.add(this._joystick);
        
        this._joystickStick = stickBg;
        this._joystickBasePos = { x: joyX, y: joyY };
        this._joystickActive = false;
        this._joystickVector = { x: 0, y: 0 };
        this._joystickPointerId = null;
        
        // 摇杆交互：全屏pointerdown，检测距离摇杆底座的距离
        // 只有在摇杆底座附近(120px内)按下才激活摇杆，否则正常点击移动
        
        this.input.on('pointerdown', (pointer) => {
            if (document.activeElement === this._chatInput) return;
            if (DomBag.isBagVisible()) return;
            
            // 检测是否在摇杆激活范围内（底座周围120px半径）
            const dx = pointer.x - joyX;
            const dy = pointer.y - joyY;
            const dist = Math.sqrt(dx*dx + dy*dy);
            
            if (dist < 120) {
                this._joystickActive = true;
                this._joystickPointerId = pointer.id;
                // 取消点击移动
                this._moveTarget = null;
                this._moveMarker.setVisible(false);
                
                // 阻止事件继续传播，避免触发点击移动
                if (pointer.event) pointer.event.stopPropagation();
                
                if (dist > 0) {
                    const clampedDist = Math.min(dist, joyRadius - 15);
                    const nx = dx / dist, ny = dy / dist;
                    this._joystickVector = { x: nx, y: ny };
                    this._joystickStick.setPosition(nx * clampedDist, ny * clampedDist);
                }
            }
        });
        
        this.input.on('pointermove', (pointer) => {
            if (!this._joystickActive || pointer.id !== this._joystickPointerId) return;
            const dx = pointer.x - joyX;
            const dy = pointer.y - joyY;
            const dist = Math.sqrt(dx*dx + dy*dy);
            if (dist > 0) {
                const clampedDist = Math.min(dist, joyRadius - 15);
                const nx = dx / dist, ny = dy / dist;
                this._joystickVector = { x: nx, y: ny };
                this._joystickStick.setPosition(nx * clampedDist, ny * clampedDist);
            }
        });
        
        const endJoy = (pointer) => {
            if (this._joystickPointerId !== null && pointer.id !== this._joystickPointerId) return;
            this._joystickActive = false;
            this._joystickPointerId = null;
            this._joystickVector = { x: 0, y: 0 };
            this._joystickStick.setPosition(0, 0);
        };
        this.input.on('pointerup', endJoy);
        this.input.on('pointerupoutside', endJoy);
    }

    _createMobileTabBar() {
        this._mobileTabBar = document.createElement('div');
        this._mobileTabBar.className = 'mobile-game-tabs';
        const chatButton = document.createElement('button');
        chatButton.type = 'button';
        chatButton.textContent = '聊天';
        chatButton.setAttribute('aria-label', '打开聊天');
        chatButton.addEventListener('click', (event) => {
            event.stopPropagation();
            if (this._chatInputVisible) {
                this._sendChat();
            } else {
                this._showChatInput();
            }
        });
        const bagButton = document.createElement('button');
        bagButton.type = 'button';
        bagButton.textContent = '背包';
        bagButton.setAttribute('aria-label', '打开或关闭背包');
        bagButton.addEventListener('click', (event) => {
            event.stopPropagation();
            this._toggleBackpack();
            bagButton.classList.toggle('active', DomBag.isBagVisible());
        });
        this._mobileTabBar.append(chatButton, bagButton);
        document.getElementById('game-container')?.appendChild(this._mobileTabBar);
    }

    _setupInput() {
        this._keys = this.input.keyboard.addKeys('W,A,S,D,UP,DOWN,LEFT,RIGHT,Q,ONE,TWO,THREE,FOUR,FIVE,SIX,SEVEN,ENTER,SPACE');

        this.input.keyboard.on('keydown', (event) => {
            const code = event.code;

            if (document.activeElement === this._chatInput) {
                return;
            }

            if (code === 'Enter' || code === 'NumpadEnter') {
                event.preventDefault();
                if (this._chatInputVisible) {
                    this._sendChat();
                } else {
                    this._showChatInput();
                }
                return;
            }

            if (code === 'Escape') {
                if (this._chatInputVisible) {
                    this._hideChatInput();
                    return;
                }
                if (DomBag.isBagVisible()) {
                    DomBag.hideBag();
                }
                return;
            }

            if (code === 'KeyQ') {
                event.preventDefault();
                if (!event.repeat) {
                    const hoveredSlot = DomBag.getHoveredSlot();
                    const canUseHoveredSlot = hoveredSlot !== null
                        && (DomBag.isBagVisible() || hoveredSlot >= MAIN_SIZE);
                    if (canUseHoveredSlot) this._dropSlot(hoveredSlot);
                    else if (!DomBag.isBagVisible()) this._dropSlot(MAIN_SIZE + this._selectedHotbar);
                }
                return;
            }

            if (code === 'Tab') {
                event.preventDefault();
                this._toggleBackpack();
            } else if (code === 'KeyE' || code === 'KeyB' || code === 'KeyI') {
                this._toggleBackpack();
            } else if (DomBag.isBagVisible()) {
                return;
            } else if (code === 'Digit1' || code === 'One') {
                this._showHotbar(0);
            } else if (code === 'Digit2' || code === 'Two') {
                this._showHotbar(1);
            } else if (code === 'Digit3' || code === 'Three') {
                this._showHotbar(2);
            } else if (code === 'Digit4' || code === 'Four') {
                this._showHotbar(3);
            } else if (code === 'Digit5' || code === 'Five') {
                this._showHotbar(4);
            } else if (code === 'Digit6' || code === 'Six') {
                this._showHotbar(5);
            } else if (code === 'Digit7' || code === 'Seven') {
                this._showHotbar(6);
            }
        });

        this.input.on('wheel', (pointer, gameObjects, deltaX, deltaY) => {
            if (document.activeElement === this._chatInput) return;
            if (DomBag.isBagVisible()) return;
            backpackManager.scrollHotbar(deltaY);
            this._showHotbar(backpackManager.getSelectedHotbar());
        });

        this.input.on('pointermove', (pointer) => {
            if (this._isMobile || DomBag.isBagVisible() || !this._playerSprite) return;
            this._facing = pointer.worldX < this._playerSprite.x ? -1 : 1;
            this._setSpriteFacing(this._playerSprite, this._facing);
        });

        this.input.on('pointerdown', (pointer, gameObjects) => {
            if (document.activeElement === this._chatInput) return;
            if (DomBag.isBagVisible()) return;
            if (pointer.rightButtonDown()) return;
            if (this._joystickActive) return;

            const { width, height } = this.scale;

            const clickedUI = gameObjects.some(go => {
                if (go === this._moveMarker) return true;
                if (this._isMobile && this._joystick) {
                    const joyX = 110, joyY = height - 170;
                    const dx = pointer.x - joyX;
                    const dy = pointer.y - joyY;
                    if (Math.sqrt(dx*dx + dy*dy) < 120) return true;
                }
                return false;
            });

            const clickedDrop = Array.from(this._droppedItems.values()).some(d => {
                const b = d.sprite.getBounds();
                return pointer.x >= b.x - 20 && pointer.x <= b.x + b.width + 20 &&
                       pointer.y >= b.y - 20 && pointer.y <= b.y + b.height + 20;
            });

            if (!clickedUI && !clickedDrop && !this._isDragging) {
                const tx = Phaser.Math.Clamp(pointer.x, 30, width - 30);
                const ty = Phaser.Math.Clamp(pointer.y, 55, height - 30);
                this._moveTarget = { x: tx, y: ty };
                this._moveMarker.setPosition(tx, ty);
                this._moveMarker.setVisible(true);
                this.tweens.killTweensOf(this._moveMarker);
                this._moveMarker.setAlpha(1);
                this._moveMarker.setScale(1);
                this.tweens.add({
                    targets: this._moveMarker, scale: 1.3, alpha: 0.3, duration: 600, yoyo: true, repeat: -1
                });
            }
        });
    }

    _createChatUI() {
        const { width, height } = this.scale;

        this._chatPanel = this.add.container(15, 50);
        this._chatPanel.setScrollFactor(0);
        this._chatPanel.setDepth(50);
        this._uiLayer.add(this._chatPanel);
        this._chatPanel.setVisible(true);

        this._chatInput = document.createElement('input');
        this._chatInput.type = 'text';
        this._chatInput.placeholder = '按 Enter 输入消息...';
        this._chatInput.maxLength = 100;
        this._chatInput.style.cssText = `
            position: fixed; width: 280px; height: 34px;
            background: rgba(10, 10, 26, 0.92); border: 2px solid rgba(78, 205, 196, 0.5);
            border-radius: 8px; padding: 4px 12px; color: white; font-size: 14px;
            font-family: 'Segoe UI', sans-serif; outline: none; z-index: 9999; display: none;
            box-sizing: border-box; backdrop-filter: blur(4px);
        `;
        document.body.appendChild(this._chatInput);
        this._chatInput.addEventListener('keydown', (e) => {
            e.stopPropagation();
            if (e.key === 'Enter') {
                e.preventDefault();
                this._sendChat();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                this._hideChatInput();
            }
        });
        this._chatInput.addEventListener('blur', () => {
            if (this._chatInput.value.trim() === '') {
                this._hideChatInput();
            }
        });

        this._updateChatHtmlPosition();
        this.scale.on('resize', () => this._updateChatHtmlPosition());
        window.addEventListener('resize', () => this._updateChatHtmlPosition());

        this.events.on('shutdown', () => {
            if (this._chatInput && this._chatInput.parentNode) this._chatInput.parentNode.removeChild(this._chatInput);
            window.removeEventListener('resize', this._updateChatHtmlPosition);
        });
    }

    _updateChatHtmlPosition() {
        const canvas = this.sys.game.canvas;
        if (!canvas) return;
        const rect = canvas.getBoundingClientRect();
        const gameW = this.scale.game.config.width;
        const gameH = this.scale.game.config.height;
        const scaleX = rect.width / gameW;
        const scaleY = rect.height / gameH;
        const inputW = 280, inputH = 34;
        const margin = 15;
        const inputX = rect.left + margin * scaleX;
        const inputY = rect.top + (gameH - margin - inputH) * scaleY;
        this._chatInput.style.left = `${inputX}px`;
        this._chatInput.style.top = `${inputY}px`;
        this._chatInput.style.bottom = 'auto';
        this._chatInput.style.width = `${inputW * scaleX}px`;
        this._chatInput.style.height = `${inputH * scaleY}px`;
        this._chatInput.style.fontSize = `${14 * Math.min(scaleX, scaleY)}px`;
    }

    _showChatInput() {
        this._chatInputVisible = true;
        this._chatInput.style.display = 'block';
        this._chatInput.placeholder = '输入消息后按 Enter 发送...';
        this._chatInput.focus();
    }

    _hideChatInput() {
        this._chatInputVisible = false;
        this._chatInput.style.display = 'none';
        this._chatInput.value = '';
        this._chatInput.placeholder = '按 Enter 输入消息...';
    }

    async _sendChat() {
        const msg = this._chatInput.value.trim();
        if (msg) {
            await GameClient.chat(msg);
        }
        this._hideChatInput();
    }

    _addChatMessage(name, message) {
        this._chatMessages.push({ name, message, time: Date.now() });
        if (this._chatMessages.length > 30) this._chatMessages.shift();
        this._renderChatDisplay();
    }

    _addSystemMessage(message, color = '#ffd93d') {
        this._chatMessages.push({ name: '系统', message, time: Date.now(), system: true, sysColor: color });
        if (this._chatMessages.length > 30) this._chatMessages.shift();
        this._renderChatDisplay();
    }

    _renderChatDisplay() {
        this._chatPanel.removeAll(true);
        const maxShow = 12;
        const start = Math.max(0, this._chatMessages.length - maxShow);
        const recent = this._chatMessages.slice(start);
        const lineH = 22;
        const bgH = recent.length * lineH + 12;
        const maxTextW = 320;

        const bg = this.add.graphics();
        bg.fillStyle(0x000000, 0.55);
        bg.fillRoundedRect(0, 0, maxTextW + 16, bgH, 8);
        this._chatPanel.add(bg);

        recent.forEach((msg, i) => {
            const isSystem = !!msg.system;
            const color = isSystem ? (msg.sysColor || '#ffd93d') : (msg.name === this._playerName ? '#4ecdc4' : '#ffffff');
            const prefix = isSystem ? '[系统] ' : `${msg.name}: `;
            const text = this.add.text(8, 6 + i * lineH, prefix + msg.message, {
                fontSize: '13px', color, fontFamily: 'Microsoft YaHei, Segoe UI',
                fontStyle: isSystem ? 'bold' : 'normal',
                wordWrap: { width: maxTextW }
            });
            this._chatPanel.add(text);
        });
    }

    _showChatBubble(id, name, message) {
        const sprite = id === GameClient.playerId ? this._playerSprite : this._playerSprites.get(id);
        if (!sprite || !message || !message.trim()) return;
        
        // 清除该玩家之前的气泡
        const oldBubble = this._chatBubbleContainers.get(id);
        if (oldBubble) {
            oldBubble.destroy();
            this._chatBubbleContainers.delete(id);
        }
        
        const c = this.add.container(sprite.x, sprite.y - 70);
        const text = this.add.text(0, 0, message, {
            fontSize: '14px', color: '#ffffff', fontFamily: 'Microsoft YaHei, Segoe UI',
            backgroundColor: '#000000dd', padding: { x: 10, y: 5 }, wordWrap: { width: 180 }
        }).setOrigin(0.5);
        const w = (text.width || 80) + 8, h = (text.height || 20) + 8;
        const bg = this.add.graphics();
        bg.fillStyle(0x000000, 0.9); bg.fillRoundedRect(-w / 2, -h / 2, w, h, 10);
        c.add([bg, text]);
        this._playerLayer.add(c);
        
        this._chatBubbleContainers.set(id, { container: c, sprite, expireTime: this.time.now + 3000 });
        
        // 3秒后淡出
        this.time.delayedCall(2800, () => {
            if (this._chatBubbleContainers.get(id)?.container === c) {
                this.tweens.add({
                    targets: c, alpha: 0, duration: 200,
                    onComplete: () => {
                        if (this._chatBubbleContainers.get(id)?.container === c) {
                            c.destroy();
                            this._chatBubbleContainers.delete(id);
                        }
                    }
                });
            }
        });
    }

    _createHUD() {
        const { width } = this.scale;
        this._topBar = this.add.container(0, 0);
        this._topBar.setScrollFactor(0);
        const bg = this.add.graphics();
        bg.fillStyle(0x000000, 0.5);
        bg.fillRoundedRect(0, 0, width, 44, { tl: 0, tr: 0, bl: 10, br: 10 });
        this._topBar.add([
            bg,
            this.add.text(20, 22, this._roomName, { fontSize: '16px', color: '#fff', fontFamily: 'Segoe UI', fontStyle: 'bold' }).setOrigin(0, 0.5)
        ]);
        this._playerCountText = this.add.text(width / 2, 22, '1 人在线', { fontSize: '15px', color: '#4ecdc4', fontFamily: 'Segoe UI' }).setOrigin(0.5);
        this._topBar.add(this._playerCountText);
        this._uiLayer.add(this._topBar);
    }

    _toggleBackpack() {
        const nowOpen = DomBag.toggleBag();
        if (nowOpen) {
            this._moveTarget = null;
            this._moveMarker.setVisible(false);
            this._joystickActive = false;
            this._joystickVector = { x: 0, y: 0 };
            if (this._joystickStick) this._joystickStick.setPosition(0, 0);
        }
    }

    _showHotbar(index) {
        this._selectedHotbar = index;
        backpackManager.setSelectedHotbar(index);
        DomBag.setSelectedHotbar(index);
        this._refreshHeldItem();
    }

    _refreshBackpackUI() {
        DomBag.renderAll();
    }

    _onRoomState(data) {
        const existingIds = new Set(this._playerSprites.keys());
        existingIds.add(GameClient.playerId);
        const newIds = new Set();
        for (const player of data.players) {
            if (player.id === GameClient.playerId) continue;
            newIds.add(player.id);
            const level = player.level || 1, title = player.title || '';
            // 直接使用服务器返回的实际位置作为目标，不使用targetX做预测避免弹动
            const serverX = player.x !== undefined ? player.x : 960;
            const serverY = player.y !== undefined ? player.y : 540;
            
            if (!existingIds.has(player.id)) {
                this._addPlayerSprite(player.id, player.name, serverX, serverY, level, title);
                this._remotePlayers.set(player.id, {
                    targetX: serverX,
                    targetY: serverY,
                    facing: player.direction === 'left' ? -1 : 1
                });
            } else {
                this._updateNameTag(player.id, player.name, level, title);
                const sprite = this._playerSprites.get(player.id);
                if (sprite) {
                    // 只有距离非常远（>300像素）才瞬移，正常移动平滑插值
                    const dist = Phaser.Math.Distance.Between(sprite.x, sprite.y, serverX, serverY);
                    if (dist > 300) {
                        sprite.x = serverX;
                        sprite.y = serverY;
                    }
                }
                // 更新目标位置为服务器最新位置（保留当前sprite位置继续插值）
                const remote = this._remotePlayers.get(player.id);
                if (remote) {
                    remote.targetX = serverX;
                    remote.targetY = serverY;
                    remote.facing = player.direction === 'left' ? -1 : 1;
                } else {
                    this._remotePlayers.set(player.id, {
                        targetX: serverX,
                        targetY: serverY,
                        facing: player.direction === 'left' ? -1 : 1
                    });
                }
            }
            const playerSprite = this._playerSprites.get(player.id);
            if (playerSprite) {
                this._setSpriteFacing(playerSprite, player.direction === 'left' ? -1 : 1);
                this._setHeldItemVisual(playerSprite, player.heldItem || null);
            }
            const info = this._players.get(player.id);
            if (info) { info.name = player.name; info.x = serverX; info.y = serverY; info.level = level; info.title = title; }
            else { this._players.set(player.id, { name: player.name, x: serverX, y: serverY, level, title }); }
            
            if (player.bubble && (!player.bubbleTime || player.bubbleTime >= (GameClient._joinedAt || 0))) {
                this._showChatBubble(player.id, player.name, player.bubble, true);
            }
        }
        for (const id of existingIds) {
            if (!newIds.has(id) && id !== GameClient.playerId) this._removePlayerSprite(id);
        }
        this._playerCountText.setText(`${newIds.size + 1} 人在线`);
    }

    _onItemDropped(data) {
        this._createDroppedItemSprite(data);
    }

    _createDroppedItemSprite(drop) {
        if (this._droppedItems.has(drop.id)) return;
        const c = this.add.container(drop.x, drop.y);
        c.setDepth(5);

        const shadow = this.add.ellipse(0, 14, 22, 7, 0x000000, 0.3);
        const bg = this.add.graphics();
        bg.fillStyle(drop.color || 0x4ecdc4, 0.9);
        bg.fillCircle(0, 0, 16);
        bg.lineStyle(2, 0xffffff, 0.4);
        bg.strokeCircle(0, 0, 16);
        const icon = this.add.text(0, 0, drop.icon || '📦', { fontSize: '20px' }).setOrigin(0.5);
        const itemName = drop.name || drop.itemName || drop.itemId || '物品';
        const nameText = this.add.text(0, -32, itemName, {
            fontSize: '13px',
            color: this._getQualityColor(drop.quality),
            fontFamily: 'Microsoft YaHei',
            fontStyle: 'bold',
            backgroundColor: '#000000aa',
            padding: { x: 5, y: 2 }
        }).setOrigin(0.5);

        const inner = this.add.container(0, 0);
        inner.add([bg, icon]);
        c.add([shadow, inner, nameText]);
        this._dropLayer.add(c);

        this.tweens.add({ targets: inner, y: -5, duration: 1200, yoyo: true, repeat: -1, ease: 'Sine.inOut' });

        c.setSize(36, 36);
        c.setInteractive(new Phaser.Geom.Circle(0, 0, 22), Phaser.Geom.Circle.Contains);
        c.on('pointerover', () => { c.setScale(1.2); });
        c.on('pointerout', () => { c.setScale(1); });
        c.on('pointerdown', (pointer) => {
            if (pointer.event) pointer.event.stopPropagation();
            this._moveTarget = null;
            this._moveMarker.setVisible(false);
            this._pickupDrop(drop.id, drop);
        });

        this._droppedItems.set(drop.id, { sprite: c, inner, data: drop, createdAt: Date.now() });
    }

    _getQualityColor(quality) {
        return {
            common: '#c6cbd1',
            uncommon: '#5ed184',
            rare: '#63a8ff',
            epic: '#c07aff',
            legendary: '#ff5f70'
        }[quality] || '#c6cbd1';
    }

    async _pickupDrop(dropId, dropData) {
        if (this._pickupInFlight.has(dropId)) return;
        this._pickupInFlight.add(dropId);
        try {
            await backpackManager.waitForMainSync();
            const result = await GameClient.pickupItem(dropId);
            if (result?.code === 'ok') {
                backpackManager.applyServerInventory(result);
                this._refreshBackpackUI();
                this._refreshHeldItem();
                const destinationName = result.destination === 'hotbar' ? '快捷栏' : '背包';
                this._addSystemMessage(`已捡起「${dropData?.name || dropData?.itemName || '物品'}」，放入${destinationName}`, '#5ed184');
            } else if (result?.code === 'inventory_full') {
                const now = Date.now();
                if (now - this._pickupNoticeAt > 2000) {
                    this._addSystemMessage('快捷栏和背包都已满，无法捡起物品', '#ff6b6b');
                    this._pickupNoticeAt = now;
                }
            } else if (result?.code === 'drop_not_found') {
                this._removeDroppedItem(dropId, false);
            } else {
                this._addSystemMessage('拾取失败，请检查网络后重试', '#ff6b6b');
            }
        } catch (error) {
            console.error('[GameScene] Pickup item failed:', error);
            this._addSystemMessage('拾取失败，请检查网络后重试', '#ff6b6b');
        } finally {
            this._pickupInFlight.delete(dropId);
        }
    }

    async _dropHoveredItem() {
        const slotIndex = DomBag.getHoveredSlot();
        if (slotIndex !== null) await this._dropSlot(slotIndex);
    }

    async _dropSlot(slotIndex) {
        const item = Number.isInteger(slotIndex) ? backpackManager.getSlot(slotIndex) : null;
        if (!item || this._dropInFlight) return;
        this._dropInFlight = true;
        try {
            await backpackManager.waitForMainSync();
            const result = await GameClient.dropItem({
                slotIndex,
                x: this._playerSprite.x + (this._facing >= 0 ? 55 : -55),
                y: this._playerSprite.y + 15
            });
            if (result?.code === 'ok') {
                backpackManager.applyServerInventory(result);
                this._refreshBackpackUI();
                this._refreshHeldItem();
                this._addSystemMessage(`已丢出「${result.drop?.name || item.name || item.id}」`, '#c6cbd1');
                return;
            }
            this._addSystemMessage('物品丢弃失败，请重试', '#ff6b6b');
        } catch (error) {
            console.error('[GameScene] Drop item failed:', error);
            this._addSystemMessage('物品丢弃失败，请检查网络后重试', '#ff6b6b');
        } finally {
            this._dropInFlight = false;
        }
    }

    _onItemPicked(data) {
        this._removeDroppedItem(data.dropId, true);
    }

    _onRoomDrops(data) {
        const activeIds = new Set((data?.dropIds || []).map(String));
        this._droppedItems.forEach((drop, dropId) => {
            // 忽略可能早于本地丢弃响应返回的在途轮询结果。
            if (!activeIds.has(String(dropId)) && Date.now() - (drop.createdAt || 0) > 500) {
                this._removeDroppedItem(dropId, true);
            }
        });
    }

    _removeDroppedItem(dropId, animate = true) {
        const drop = this._droppedItems.get(dropId);
        if (!drop) return;
        this._droppedItems.delete(dropId);
        this._pickupInFlight.delete(dropId);
        if (!animate) {
            drop.sprite.destroy();
            return;
        }
        this.tweens.add({
            targets: drop.sprite, scaleX: 0, scaleY: 0, alpha: 0, duration: 200,
            onComplete: () => drop.sprite.destroy()
        });
    }

    _addPlayerSprite(id, name, x, y, level = 1, title = '') {
        if (id === GameClient.playerId) {
            this._players.set(id, { name, x, y, level, title });
            return;
        }
        const color = Phaser.Display.Color.RandomRGB(100, 200).color;
        const c = this._createCapsuleSprite(name, color, false, level, title);
        c.setPosition(x, y);
        this._playerLayer.add(c);
        this._playerSprites.set(id, c);
        this._players.set(id, { name, x, y, level, title });
        this._remotePlayers.set(id, { targetX: x, targetY: y, lastUpdate: 0 });
    }

    _removePlayerSprite(id) {
        const s = this._playerSprites.get(id);
        if (s) {
            this.tweens.add({ targets: s, alpha: 0, duration: 300, onComplete: () => s.destroy() });
            this._playerSprites.delete(id);
        }
        this._players.delete(id);
        this._remotePlayers.delete(id);
        // 清理该玩家的气泡
        const bubble = this._chatBubbleContainers.get(id);
        if (bubble && bubble.container) {
            bubble.container.destroy();
            this._chatBubbleContainers.delete(id);
        }
    }

    _onPlayerState(data) {
        const remote = this._remotePlayers.get(data.id);
        if (remote) {
            remote.targetX = data.x;
            remote.targetY = data.y;
            remote.facing = data.direction === 'left' ? -1 : 1;
            remote.lastUpdate = this.time.now;
        }
        const level = data.level || 1, title = data.title || '';
        const info = this._players.get(data.id);
        if (info) { info.name = data.name; info.x = data.x; info.y = data.y; info.level = level; info.title = title; }
        else { this._players.set(data.id, { name: data.name, x: data.x, y: data.y, level, title }); }
        this._updateNameTag(data.id, data.name, level, title);
        const sprite = this._playerSprites.get(data.id);
        this._setSpriteFacing(sprite, data.direction === 'left' ? -1 : 1);
        this._setHeldItemVisual(sprite, data.heldItem || null);
    }

    _onPlayerLeft(data) {
        const name = data.playerName || (this._players.get(data.playerId) || {}).name || '玩家';
        this._addSystemMessage(`${name} 离开了游戏`);
        this._removePlayerSprite(data.playerId);
    }

    _onPlayerJoined(data) {
        const pName = data.name || data.playerName || '玩家';
        this._addSystemMessage(`${pName} 进入了游戏`);
    }

    _onChatMessage(data) {
        if (!data) return;
        const name = data.name || data.playerName || '';
        const content = data.content || data.message || '';
        const fromId = data.from || data.playerId;
        // 没有内容就跳过，不显示undefined
        if (!content && !data.system) return;
        if (data.system || fromId === 'SYSTEM') {
            const msg = content || '系统消息';
            this._addSystemMessage(msg, data.system ? '#4ecdc4' : '#88aa88');
        } else {
            const displayName = name || '玩家';
            this._addChatMessage(displayName, content);
            if (fromId !== GameClient.playerId) this._showChatBubble(fromId, displayName, content);
        }
    }

    _onRoomJoined(data) {
        console.log('[GameScene] Room joined:', data);
        GameClient.playerId = data.playerId;
        this._playerId = data.playerId;
        this._playerName = data?.playerName || this._playerName || GameClient.playerName || '玩家';
        const joinName = this._playerName;
        if (data.x !== undefined && data.y !== undefined && this._playerSprite) {
            this._playerSprite.x = data.x;
            this._playerSprite.y = data.y;
            GameClient.move(data.x, data.y);
        }
        if (this._playerSprite) {
            this._players.set(data.playerId, {
                name: joinName, x: this._playerSprite.x, y: this._playerSprite.y,
                level: this._myLevel, title: this._myTitle
            });
            this._updateNameTag(data.playerId, joinName, this._myLevel, this._myTitle);
        }
        this._joined = true;
        this._lastSendPos = { x: this._playerSprite.x, y: this._playerSprite.y };
        const joinText = `${joinName}（ID: ${data.playerId}）加入了游戏`;
        this._addSystemMessage(joinText, '#4ecdc4');
    }

    _onServerDisconnect() {
        if (this._kicked) return;
        this._kicked = true;
        this._savePlayerPosition();
        this._addSystemMessage('⚠ 与服务器断开连接，正在返回...', '#ff6b6b');
        this.time.delayedCall(2000, () => {
            this.scene.start('LobbyScene', {
                playerName: this._playerName,
                level: this._myLevel,
                title: this._myTitle
            });
        });
    }

    _setupLeaveDetection() {
        this._leaveHandler = () => {
            this._savePlayerPosition();
            if (GameClient.isConnected()) {
                GameClient.leaveRoom();
            }
        };
        this._visibilityHandler = () => {
            if (document.hidden) {
                this._savePlayerPosition();
                if (GameClient.isConnected()) {
                    GameClient.leaveRoom();
                }
            }
        };
        window.addEventListener('beforeunload', this._leaveHandler);
        window.addEventListener('pagehide', this._leaveHandler);
        document.addEventListener('visibilitychange', this._visibilityHandler);
        
        // 每10秒自动保存一次位置
        this._positionSaveTimer = this.time.addEvent({
            delay: 10000,
            callback: () => this._savePlayerPosition(),
            loop: true
        });
    }

    update(_, delta) {
        const dt = delta / 1000, speed = 300;
        const { width, height } = this.scale;

        const bpOpen = DomBag.isBagVisible();
        const inputFocused = document.activeElement === this._chatInput;

        let vx = 0, vy = 0, kb = false;
        let joyInput = false;

        if (!bpOpen && !inputFocused) {
            if (this._keys.A.isDown || this._keys.LEFT.isDown) { vx = -1; kb = true; }
            if (this._keys.D.isDown || this._keys.RIGHT.isDown) { vx = 1; kb = true; }
            if (this._keys.W.isDown || this._keys.UP.isDown) { vy = -1; kb = true; }
            if (this._keys.S.isDown || this._keys.DOWN.isDown) { vy = 1; kb = true; }
        }

        // 摇杆输入
        if (!bpOpen && !inputFocused && this._joystickActive && (this._joystickVector.x !== 0 || this._joystickVector.y !== 0)) {
            joyInput = true;
            vx = this._joystickVector.x;
            vy = this._joystickVector.y;
            kb = true; // 摇杆输入也视为键盘移动，取消点击移动
        }

        if (kb) {
            this._moveTarget = null;
            this._moveMarker.setVisible(false);
        }

        if (kb) {
            if (vx || vy) { const l = Math.sqrt(vx * vx + vy * vy); vx /= l; vy /= l; }
            vx *= speed; vy *= speed;
            if (this._isMobile && vx) this._facing = vx > 0 ? 1 : -1;
        } else if (!bpOpen && this._moveTarget) {
            const dx = this._moveTarget.x - this._playerSprite.x;
            const dy = this._moveTarget.y - this._playerSprite.y;
            const dist = Math.sqrt(dx * dx + dy * dy);
            if (dist < 5) {
                this._moveTarget = null;
                this._moveMarker.setVisible(false);
            } else {
                vx = (dx / dist) * speed;
                vy = (dy / dist) * speed;
                if (this._isMobile && vx) this._facing = vx > 0 ? 1 : -1;
            }
        }

        // 背包打开时不移动
        if (bpOpen) {
            vx = 0;
            vy = 0;
        }

        const nx = Phaser.Math.Clamp(this._playerSprite.x + vx * dt, 30, width - 30);
        const ny = Phaser.Math.Clamp(this._playerSprite.y + vy * dt, 55, height - 30);
        this._playerSprite.x = nx; this._playerSprite.y = ny;

        if (!this._isMobile && this.input.activePointer) {
            this._facing = this.input.activePointer.worldX < nx ? -1 : 1;
        }
        this._setSpriteFacing(this._playerSprite, this._facing);
        
        // 同步方向和移动状态到GameClient
        GameClient.setDirection(this._facing < 0 ? 'left' : 'right');
        GameClient.setMoving(Math.abs(vx) > 0.1 || Math.abs(vy) > 0.1);

        // 更新聊天气泡位置，跟随玩家
        this._chatBubbleContainers.forEach((bubble, id) => {
            if (bubble.container && bubble.sprite && bubble.sprite.active) {
                bubble.container.setPosition(bubble.sprite.x, bubble.sprite.y - 70);
            }
        });

        this._updateRemotePlayers(dt);
        if (this._selectionRing) this._selectionRing.setAlpha((kb || this._moveTarget) && !bpOpen ? 0.8 : 0.3);

        // 位置变化时更新GameClient（客户端本地记录位置，定时上报由GameClient自己处理）
        GameClient.move(nx, ny);

        this._droppedItems.forEach((drop, dropId) => {
            const d = Phaser.Math.Distance.Between(nx, ny, drop.sprite.x, drop.sprite.y);
            if (d < 40) {
                this._pickupDrop(dropId, drop.data);
            }
        });
    }

    _updateRemotePlayers(dt) {
        // 平滑插值：较低的lerp系数避免弹动，每帧移动10%*dt*60距离
        const lerp = Math.min(1, 10 * dt);
        this._remotePlayers.forEach((remote, id) => {
            const s = this._playerSprites.get(id);
            if (!s) return;
            const dx = remote.targetX - s.x, dy = remote.targetY - s.y;
            const d = Math.sqrt(dx * dx + dy * dy);
            
            if (d > 1) {
                if (d < 2) {
                    s.x = remote.targetX;
                    s.y = remote.targetY;
                } else {
                    s.x += dx * lerp;
                    s.y += dy * lerp;
                }
                
            }
            this._setSpriteFacing(s, remote.facing || 1);
            const bubble = this._chatBubbleContainers.get(id);
            if (bubble && bubble.container && bubble.container.active) {
                bubble.container.setPosition(s.x, s.y - 70);
            }
        });
    }

    _cleanup() {
        this._savePlayerPosition();
        
        this._chatBubbleContainers.forEach((bubble) => {
            if (bubble.container) bubble.container.destroy();
        });
        this._chatBubbleContainers.clear();
        if (this._chatHideTimer) clearTimeout(this._chatHideTimer);
        if (this._positionSaveTimer) { this._positionSaveTimer.remove(); this._positionSaveTimer = null; }
        if (this._chatInput && this._chatInput.parentNode) this._chatInput.parentNode.removeChild(this._chatInput);
        if (this._mobileTabBar?.parentNode) this._mobileTabBar.parentNode.removeChild(this._mobileTabBar);
        if (this._gameClientHandlers) {
            Object.entries(this._gameClientHandlers).forEach(([type, handler]) => {
                GameClient.off(type, handler);
            });
            this._gameClientHandlers = null;
        }
        DomBag.destroy();
        window.removeEventListener('beforeunload', this._leaveHandler);
        window.removeEventListener('pagehide', this._leaveHandler);
        document.removeEventListener('visibilitychange', this._visibilityHandler);
        window.removeEventListener('resize', this._updateChatHtmlPosition);
        if (this._inventorySyncErrorHandler) {
            window.removeEventListener('journey-inventory-sync-error', this._inventorySyncErrorHandler);
        }
        if (this._backpackUpdateHandler) backpackManager.offUpdate(this._backpackUpdateHandler);
    }
}
