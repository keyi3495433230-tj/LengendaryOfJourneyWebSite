export const GameClient = {
    playerId: null,
    playerName: null,
    roomId: null,
    listeners: {},
    _connectionListeners: [],
    _baseUrl: (typeof window !== 'undefined' && window.BOARD_PHP_URL) || '../board.php',
    _connected: false,
    _failed: false,
    _pollTimer: null,
    _updateTimer: null,
    _pollInterval: 150, // 150ms轮询一次状态（其他玩家/聊天/掉落）
    _updateInterval: 100, // 100ms上报一次位置
    _lastChatTime: 0,
    _lastDropTime: 0,
    _seenChatIds: new Set(),
    _currentRoomPlayers: new Map(),
    _playerState: {
        x: 960,
        y: 540,
        targetX: 960,
        targetY: 540,
        direction: 'right',
        isMoving: false,
        bubble: null,
        heldItem: null
    },
    _pendingUpdates: false,
    _heldItemDirty: true,

    init(baseUrl) {
        if (baseUrl) this._baseUrl = baseUrl;
        // HTTP模式不需要init连接，等joinRoom
        this._notifyConnectionChange(false);
    },

    // 带超时的fetch封装
    async _fetchWithTimeout(url, options = {}, timeout = 5000) {
        const controller = new AbortController();
        const id = setTimeout(() => controller.abort(), timeout);
        try {
            const res = await fetch(url, { ...options, signal: controller.signal });
            clearTimeout(id);
            return res;
        } catch (e) {
            clearTimeout(id);
            throw e;
        }
    },

    connect(userId = null) {
        // HTTP模式下connect不做什么，等joinRoom
        this._notifyConnectionChange(true);
        this._emit('connected');
    },

    onConnectionChange(handler) {
        this._connectionListeners.push(handler);
        handler(this.isConnected());
    },

    offConnectionChange(handler) {
        this._connectionListeners = this._connectionListeners.filter(h => h !== handler);
    },

    _notifyConnectionChange(connected) {
        this._connected = connected;
        this._connectionListeners.forEach(h => {
            try { h(connected); } catch (e) { console.error(e); }
        });
    },

    async getOnlinePlayers() {
        return Array.from(this._currentRoomPlayers.values());
    },

    _startPolling() {
        this._stopPolling();
        this._pollLoop();
        // 启动定时上报
        this._updateTimer = setInterval(() => this._updateLoop(), this._updateInterval);
    },

    _stopPolling() {
        if (this._pollTimer) {
            clearTimeout(this._pollTimer);
            this._pollTimer = null;
        }
        if (this._updateTimer) {
            clearInterval(this._updateTimer);
            this._updateTimer = null;
        }
    },

    async _pollLoop() {
        if (!this._connected || !this.roomId) return;
        
        try {
            const params = new URLSearchParams({
                action: 'game_get_state',
                lastChatTime: String(this._lastChatTime),
                lastDropTime: String(this._lastDropTime)
            });
            const res = await this._fetchWithTimeout(`${this._baseUrl}?${params}`, { credentials: 'include' }, 3000);
            const data = await res.json();
            
            if (data.code === 'ok') {
                // 更新其他玩家
                this._currentRoomPlayers.clear();
                if (data.players && Array.isArray(data.players)) {
                    data.players.forEach(p => {
                        const normalizedId = String(p.id || '');
                        if (normalizedId && normalizedId !== String(this.playerId || '')) {
                            p.id = normalizedId;
                            p.name = p.name || `玩家${normalizedId}`;
                            this._currentRoomPlayers.set(normalizedId, p);
                        }
                    });
                    // 加入自己
                    if (this.playerId) {
                        this._currentRoomPlayers.set(this.playerId, {
                            id: this.playerId,
                            name: this.playerName,
                            x: this._playerState.x,
                            y: this._playerState.y,
                            ...this._playerState
                        });
                    }
                }
                this._emit('room_state', { players: data.players || [] });
                this._emit('room_drops', { dropIds: Array.isArray(data.dropIds) ? data.dropIds : [] });
                
                // 处理聊天（验证字段完整性）
                if (data.chat && Array.isArray(data.chat)) {
                    let newestChatTime = this._lastChatTime;
                    data.chat.forEach(msg => {
                        if (!msg || (msg.name === undefined && msg.content === undefined)) return;
                        const chatId = msg.id || `${msg.from || ''}|${msg.time || ''}|${msg.content || ''}`;
                        if (this._seenChatIds.has(chatId)) return;
                        this._seenChatIds.add(chatId);
                        this._emit('chat_message', msg);
                        const msgTime = Number(msg.time || 0);
                        if (msgTime > newestChatTime) {
                            newestChatTime = msgTime;
                        }
                    });
                    this._lastChatTime = newestChatTime;
                }
                
                // 处理掉落物
                if (data.drops && Array.isArray(data.drops)) {
                    let newestDropTime = this._lastDropTime;
                    data.drops.forEach(drop => {
                        this._emit('item_dropped', drop);
                        const dropTime = Number(drop?.time || 0);
                        if (dropTime > newestDropTime) {
                            newestDropTime = dropTime;
                        }
                    });
                    this._lastDropTime = newestDropTime;
                }
            }
        } catch (e) {
            // 出错不中断，继续轮询
            console.warn('[GameClient] Poll error:', e.message);
        }
        
        this._pollTimer = setTimeout(() => this._pollLoop(), this._pollInterval);
    },

    async _updateLoop() {
        if (!this._connected || !this.roomId) return;
        
        try {
            const form = new FormData();
            form.append('action', 'game_update');
            form.append('x', String(this._playerState.x));
            form.append('y', String(this._playerState.y));
            form.append('targetX', String(this._playerState.targetX));
            form.append('targetY', String(this._playerState.targetY));
            form.append('direction', this._playerState.direction);
            form.append('isMoving', this._playerState.isMoving ? 'true' : 'false');
            const heldItemSnapshot = this._heldItemDirty ? JSON.stringify(this._playerState.heldItem) : null;
            if (heldItemSnapshot !== null) form.append('heldItem', heldItemSnapshot);
            if (this._playerState.bubble) {
                form.append('bubble', this._playerState.bubble);
            }
            
            const response = await this._fetchWithTimeout(this._baseUrl, {
                method: 'POST',
                credentials: 'include',
                body: form
            });
            if (response.ok && heldItemSnapshot !== null && heldItemSnapshot === JSON.stringify(this._playerState.heldItem)) {
                this._heldItemDirty = false;
            }
        } catch (e) {
            // 忽略更新错误
        }
    },

    _handleMessage(type, data) {
        // 兼容旧WebSocket事件
        this._emit(type, data);
    },

    _emit(type, data) {
        if (this.listeners[type]) {
            this.listeners[type].forEach(cb => { try { cb(data); } catch (e) { console.error(e); } });
        }
        if (this.listeners['*']) {
            this.listeners['*'].forEach(cb => { try { cb(type, data); } catch (e) { console.error(e); } });
        }
    },

    on(type, callback) {
        if (!this.listeners[type]) {
            this.listeners[type] = [];
        }
        this.listeners[type].push(callback);
    },

    rememberChatMessage(message) {
        if (!message) return null;
        const chatId = message.id || `${message.from || ''}|${message.time || ''}|${message.content || ''}`;
        if (!chatId) return null;
        this._seenChatIds.add(chatId);
        return chatId;
    },

    off(type, callback) {
        if (!this.listeners[type]) return;
        this.listeners[type] = this.listeners[type].filter(cb => cb !== callback);
    },

    async joinRoom(roomId, playerName, level, title) {
        try {
            console.log('[GameClient] Joining room...');
            const form = new FormData();
            form.append('action', 'game_join');
            const res = await this._fetchWithTimeout(this._baseUrl, {
                method: 'POST',
                credentials: 'include',
                body: form
            }, 3000);
            console.log('[GameClient] Join response status:', res.status);
            const data = await res.json();
            console.log('[GameClient] Join response:', data);
            
            if (data.code === 'ok') {
                this.playerId = data.playerId;
                this.playerName = data.playerName || playerName;
                this.roomId = data.roomId || roomId;
                this._playerState.x = data.x;
                this._playerState.y = data.y;
                this._playerState.targetX = data.x;
                this._playerState.targetY = data.y;
                this._joinedAt = data.serverTime || Math.floor(Date.now() / 1000);
                this._lastChatTime = data.chatCursor || this._joinedAt;
                this._lastDropTime = 0;
                this._seenChatIds.clear();
                this._heldItemDirty = true;
                
                this._currentRoomPlayers.clear();
                this._notifyConnectionChange(true);
                this._emit('connected');
                if (data.joinNotice) {
                    this.rememberChatMessage(data.joinNotice);
                }
                this._emit('room_joined', data);
                
                this._startPolling();
                
                return true;
            } else {
                console.error('[GameClient] Join failed:', data.code, data.message);
            }
        } catch (e) {
            console.error('[GameClient] Join room failed:', e.message, e);
        }
        return false;
    },

    async leaveRoom() {
        this._stopPolling();
        
        try {
            const form = new FormData();
            form.append('action', 'game_leave');
            form.append('x', String(this._playerState.x));
            form.append('y', String(this._playerState.y));
            await this._fetchWithTimeout(this._baseUrl, {
                method: 'POST',
                credentials: 'include',
                body: form
            });
        } catch (e) {
            // 忽略
        }
        
        this._currentRoomPlayers.clear();
        this._lastChatTime = 0;
        this._lastDropTime = 0;
        this._seenChatIds.clear();
        this._notifyConnectionChange(false);
        this._emit('disconnected');
    },

    move(x, y) {
        this._playerState.x = x;
        this._playerState.y = y;
        // 移动时同步更新target，避免target停留在初始点导致其他玩家看到弹动
        this._playerState.targetX = x;
        this._playerState.targetY = y;
    },
    
    setTarget(x, y) {
        this._playerState.targetX = x;
        this._playerState.targetY = y;
        this._playerState.isMoving = true;
    },
    
    setDirection(direction) {
        this._playerState.direction = direction === 'left' ? 'left' : 'right';
    },

    setHeldItem(item) {
        const nextItem = item ? {
            id: String(item.id || ''),
            name: String(item.customName || item.name || item.id || '物品'),
            icon: String(item.icon || '?'),
            quality: String(item.quality || 'common')
        } : null;
        if (JSON.stringify(nextItem) !== JSON.stringify(this._playerState.heldItem)) {
            this._playerState.heldItem = nextItem;
            this._heldItemDirty = true;
        }
    },
    
    setMoving(isMoving) {
        this._playerState.isMoving = isMoving;
    },
    
    setBubble(text) {
        this._playerState.bubble = text;
    },
    
    getPosition() {
        return { x: this._playerState.x, y: this._playerState.y };
    },

    async chat(message) {
        try {
            const form = new FormData();
            form.append('action', 'game_chat');
            form.append('message', message);
            const res = await this._fetchWithTimeout(this._baseUrl, {
                method: 'POST',
                credentials: 'include',
                body: form
            });
            const data = await res.json();
            if (data.code === 'ok') {
                // 由状态轮询统一回显，避免发送响应和轮询各显示一次
                this.setBubble(message);
                setTimeout(() => this.setBubble(null), 3000);
            }
        } catch (e) {
            console.error('[GameClient] Chat failed:', e);
        }
    },

    async dropItem(itemData) {
        try {
            const form = new FormData();
            form.append('action', 'game_drop');
              form.append('x', String(itemData.x || this._playerState.x));
              form.append('y', String(itemData.y || this._playerState.y));
              form.append('slotIndex', String(itemData.slotIndex !== undefined ? itemData.slotIndex : -1));
            const res = await this._fetchWithTimeout(this._baseUrl, {
                method: 'POST',
                credentials: 'include',
                body: form
            });
            const data = await res.json();
              if (data.code === 'ok') {
                  this._emit('item_dropped', data.drop);
                  return data;
              }
              return data;
        } catch (e) {
            console.error('[GameClient] Drop item failed:', e);
        }
          return null;
    },

    async pickupItem(dropId) {
        try {
            const form = new FormData();
            form.append('action', 'game_pickup');
            form.append('dropId', dropId);
            const res = await this._fetchWithTimeout(this._baseUrl, {
                method: 'POST',
                credentials: 'include',
                body: form
            });
            const data = await res.json();
              if (data.code === 'ok') {
                  this._emit('item_picked', { dropId: data.dropId });
                  return data;
              }
              return data;
        } catch (e) {
            console.error('[GameClient] Pickup failed:', e);
        }
          return null;
    },

    requestRoomList() {
        // HTTP模式只有一个主世界
        this._emit('room_list', { rooms: [{ id: 'main_world', name: '主世界', players: this._currentRoomPlayers.size }] });
    },

    isConnected() {
        return this._connected;
    },

    isFailed() {
        return this._failed;
    },

    getStatusText() {
        if (this.isConnected()) return '已连接';
        return '连接中...';
    },

    async savePosition(x, y) {
        try {
            const form = new FormData();
            form.append('action', 'game_save_position');
            form.append('x', String(x));
            form.append('y', String(y));
            await this._fetchWithTimeout(this._baseUrl, {
                method: 'POST',
                credentials: 'include',
                body: form
            });
        } catch (e) {}
    },

    disconnect() {
        this.leaveRoom();
    }
};
