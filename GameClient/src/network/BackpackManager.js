const MAIN_SIZE = 21;
const HOTBAR_SIZE = 7;
const TOTAL_SIZE = MAIN_SIZE + HOTBAR_SIZE;

class BackpackManager {
    constructor() {
        this.MAIN_SIZE = MAIN_SIZE;
        this.HOTBAR_SIZE = HOTBAR_SIZE;
        this.TOTAL_SIZE = TOTAL_SIZE;
        this._slots = new Array(TOTAL_SIZE).fill(null);
        this._baseUrl = (typeof window !== 'undefined' && window.BOARD_PHP_URL) || '../board.php';
        this._serverUser = null;
        this._initialized = false;
        this._listeners = new Set();
        this._pendingPickups = [];
        this._pickupInFlight = false;
        this._selectedHotbar = 0;
        this._inventorySyncQueue = Promise.resolve();
        this._mainSyncTimer = null;
        this._pendingMainSyncSnapshot = null;
        this._mainSyncDelay = 120;
    }

    onChange(callback) {
        this._listeners.add(callback);
        return () => this._listeners.delete(callback);
    }
    onUpdate(cb) { return this.onChange(cb); }
    offUpdate(cb) { this._listeners.delete(cb); }
    
    _emitChange() {
        const snapshot = this._slots.slice();
        this._listeners.forEach(cb => { try { cb(snapshot); } catch (e) { console.error(e); } });
    }

    _serializeMainInventory() {
        return this._slots.slice(0, MAIN_SIZE).map(item => {
            if (!item) return null;
            const saved = {
                id: item.id,
                count: Math.max(1, item.quantity || item.count || 1)
            };
            if (item.customName) saved.customName = item.customName;
            return saved;
        });
    }

    _serializeHotbar() {
        return this._slots.slice(MAIN_SIZE, TOTAL_SIZE).map(item => {
            if (!item) return null;
            const saved = {
                id: item.id,
                count: Math.max(1, item.quantity || item.count || 1)
            };
            if (item.customName) saved.customName = item.customName;
            return saved;
        });
    }

    setServerUser(user) {
        this._serverUser = user;
    }

    waitForMainSync() {
        if (this._mainSyncTimer) {
            clearTimeout(this._mainSyncTimer);
            this._mainSyncTimer = null;
            this._flushPendingMainSync();
        }
        return this._inventorySyncQueue;
    }

    getMainInventorySignature() {
        return JSON.stringify(this._serializeMainInventory());
    }

    _flushPendingMainSync() {
        if (!this._pendingMainSyncSnapshot) return;
        const snapshot = this._pendingMainSyncSnapshot;
        this._pendingMainSyncSnapshot = null;
        this._inventorySyncQueue = this._inventorySyncQueue
            .then(() => this._moveMainOnServer(snapshot))
            .catch((e) => {
                console.error('[BackpackManager] main sync error', e);
                return false;
            });
    }

    _scheduleMainSync(snapshot) {
        const pending = this._pendingMainSyncSnapshot;
        if (snapshot && typeof snapshot === 'object' && !Array.isArray(snapshot)) {
            const next = (pending && typeof pending === 'object' && !Array.isArray(pending)) ? { ...pending } : {};
            if (Array.isArray(pending)) next.inventory = pending;
            if (Object.prototype.hasOwnProperty.call(snapshot, 'inventory')) {
                next.inventory = Array.isArray(snapshot.inventory) ? snapshot.inventory : this._serializeMainInventory();
            } else if (!Object.prototype.hasOwnProperty.call(next, 'inventory') && Array.isArray(pending)) {
                next.inventory = pending;
            }
            if (Object.prototype.hasOwnProperty.call(snapshot, 'hotbar')) {
                next.hotbar = Array.isArray(snapshot.hotbar) ? snapshot.hotbar : this._serializeHotbar();
            } else if (!Object.prototype.hasOwnProperty.call(next, 'hotbar') && pending && typeof pending === 'object' && !Array.isArray(pending) && Object.prototype.hasOwnProperty.call(pending, 'hotbar')) {
                next.hotbar = pending.hotbar;
            }
            this._pendingMainSyncSnapshot = next;
        } else {
            if (pending && typeof pending === 'object' && !Array.isArray(pending)) {
                this._pendingMainSyncSnapshot = { ...pending, inventory: Array.isArray(snapshot) ? snapshot : this._serializeMainInventory() };
            } else {
                this._pendingMainSyncSnapshot = Array.isArray(snapshot) ? snapshot : this._serializeMainInventory();
            }
        }
        if (this._mainSyncTimer) clearTimeout(this._mainSyncTimer);
        this._mainSyncTimer = null;
        this._flushPendingMainSync();
    }

    async _fetchWithTimeout(url, options = {}, timeout = 5000) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);
        try {
            return await fetch(url, { ...options, signal: controller.signal });
        } finally {
            clearTimeout(timeoutId);
        }
    }

    getServerUser() {
        return this._serverUser;
    }

    getSlot(index) {
        if (index < 0 || index >= TOTAL_SIZE) return null;
        return this._slots[index];
    }
    
    getSlots() {
        return this._slots.slice();
    }
    
    getAllSlots() { return this.getSlots(); }
    
    getSelectedHotbar() {
        return this._selectedHotbar;
    }
    
    setSelectedHotbar(index) {
        if (index >= 0 && index < HOTBAR_SIZE) {
            this._selectedHotbar = index;
            this._emitChange();
        }
    }
    
    isMainSlot(index) {
        return index >= 0 && index < MAIN_SIZE;
    }
    
    isHotbarSlot(index) {
        return index >= MAIN_SIZE && index < TOTAL_SIZE;
    }
    
    hasEmptySlot() {
        return this._slots.some(s => s === null);
    }
    
    scrollHotbar(delta) {
        if (delta > 0) {
            this._selectedHotbar = (this._selectedHotbar + 1) % HOTBAR_SIZE;
        } else if (delta < 0) {
            this._selectedHotbar = (this._selectedHotbar - 1 + HOTBAR_SIZE) % HOTBAR_SIZE;
        }
        this._emitChange();
    }

    _applyMainSlots(mainSlots) {
        if (!Array.isArray(mainSlots)) return;
        for (let i = 0; i < MAIN_SIZE; i++) {
            const it = mainSlots[i];
            this._slots[i] = it && it.id ? {
                id: it.id,
                name: it.name || it.id,
                icon: it.icon || '📦',
                color: it.color || 0xaaaaaa,
                quantity: Math.max(1, it.quantity || it.count || 1),
                count: Math.max(1, it.quantity || it.count || 1),
                description: it.description || '',
                quality: it.quality || 'common',
                customName: it.customName || null,
                slot: i
            } : null;
        }
        this._emitChange();
    }

    _applyHotbarSlots(hotbarSlots) {
        if (!Array.isArray(hotbarSlots)) return;
        for (let i = 0; i < HOTBAR_SIZE; i++) {
            const it = hotbarSlots[i];
            const slotIndex = MAIN_SIZE + i;
            this._slots[slotIndex] = it && it.id ? {
                id: it.id,
                name: it.name || it.id,
                icon: it.icon || '📦',
                color: it.color || 0xaaaaaa,
                quantity: Math.max(1, it.quantity || it.count || 1),
                count: Math.max(1, it.quantity || it.count || 1),
                description: it.description || '',
                quality: it.quality || 'common',
                customName: it.customName || null,
                slot: slotIndex
            } : null;
        }
        this._emitChange();
    }

    applyServerInventory(data) {
        if (!data || typeof data !== 'object') return;
        if (Array.isArray(data.inventory)) this._applyMainSlots(data.inventory);
        if (Array.isArray(data.hotbar)) this._applyHotbarSlots(data.hotbar);
    }

    async fetchFromServer() {
        try {
            await this.waitForMainSync();
            const res = await this._fetchWithTimeout(`${this._baseUrl}?action=getGameProfile&t=${Date.now()}`, { 
                credentials: 'include',
                cache: 'no-store'
            });
            const data = await res.json();
            if (data.authenticated && data.user) {
                this._serverUser = {
                    userId: data.user.userId,
                    username: data.user.username,
                    displayName: data.user.displayName || data.user.username,
                    level: data.user.level || 1,
                    title: data.user.title || ''
                };
            }
            if (data.inventory) {
                this._applyMainSlots(data.inventory);
            }
            if (data.hotbar) {
                this._applyHotbarSlots(data.hotbar);
            }
            return data;
        } catch (e) {
            console.error('[BackpackManager] fetch failed', e);
            return { authenticated: false };
        }
    }

    async _moveMainOnServer(inventorySnapshot) {
        try {
            const payload = (inventorySnapshot && typeof inventorySnapshot === 'object' && !Array.isArray(inventorySnapshot))
                ? inventorySnapshot
                : { inventory: Array.isArray(inventorySnapshot) ? inventorySnapshot : this._serializeMainInventory() };
            const form = new FormData();
            // 与网页背包使用同一个保存接口，确保槽位顺序完全一致。
            form.append('action', 'saveInventory');
            form.append('userId', this._serverUser?.userId || '');
            form.append('inventory', JSON.stringify(Array.isArray(payload.inventory) ? payload.inventory : this._serializeMainInventory()));
            if (Object.prototype.hasOwnProperty.call(payload, 'hotbar')) {
                form.append('hotbar', JSON.stringify(Array.isArray(payload.hotbar) ? payload.hotbar : this._serializeHotbar()));
            }
            const res = await this._fetchWithTimeout(this._baseUrl, {
                method: 'POST',
                credentials: 'include',
                keepalive: true,
                body: form
            });
            const data = await res.json();
            if (data.code === 'ok') return true;
            console.warn('[BackpackManager] saveInventory failed:', data.code);
            return false;
        } catch (e) {
            console.error('[BackpackManager] move error', e);
            return false;
        }
    }

    async _moveSlotOnServer(fromIndex, toIndex) {
        const form = new FormData();
        form.append('action', 'moveGameInventorySlot');
        form.append('from', String(fromIndex));
        form.append('to', String(toIndex));
        const res = await this._fetchWithTimeout(this._baseUrl, {
            method: 'POST',
            credentials: 'include',
            keepalive: true,
            body: form
        });
        const data = await res.json();
        if (data.code !== 'ok') {
            throw new Error(data.code || 'save_failed');
        }
        return data;
    }

    async discardSlot(index) {
        if (index < 0 || index >= TOTAL_SIZE || !this._slots[index]) return false;
        const wasHotbar = this.isHotbarSlot(index);
        // 立即本地删除
        this._slots[index] = null;
        this._emitChange();
        
        if (wasHotbar) {
            // 快捷栏本地删除，不需要同步
            return true;
        }
        const snapshot = this._serializeMainInventory();
        this._scheduleMainSync(snapshot);
        return true;
    }
    
    async discardItem(index) {
        return this.discardSlot(index);
    }

    moveSlot(fromIndex, toIndex) {
        if (fromIndex === toIndex) return Promise.resolve(true);
        if (fromIndex < 0 || fromIndex >= TOTAL_SIZE || toIndex < 0 || toIndex >= TOTAL_SIZE) return Promise.resolve(false);
        const fromItem = this._slots[fromIndex];
        if (!fromItem) return Promise.resolve(false);

        // 【关键】立即本地交换，UI秒响应
        const toItem = this._slots[toIndex];
        this._slots[fromIndex] = toItem || null;
        this._slots[toIndex] = fromItem;
        if (this._slots[toIndex]) this._slots[toIndex].slot = toIndex;
        if (this._slots[fromIndex]) this._slots[fromIndex].slot = fromIndex;
        this._emitChange();

        // 每次拖放按顺序提交源/目标槽位，服务器基于最新云端数据完成交换。
        const operation = this._inventorySyncQueue.then(() => this._moveSlotOnServer(fromIndex, toIndex));
        this._inventorySyncQueue = operation.catch((error) => {
            console.error('[BackpackManager] slot sync failed', error);
            window.dispatchEvent(new CustomEvent('journey-inventory-sync-error'));
            // 当前队列结束后再重新读取云端，避免失败恢复等待自身队列。
            setTimeout(() => this.fetchFromServer(), 0);
            return false;
        });
        return operation.then(() => true).catch(() => false);
    }
    
    addItemToLocal(itemId, name, icon, color, quantity, description, quality, slotIndex = -1) {
        const item = {
            id: itemId,
            name: name || itemId,
            icon: icon || '📦',
            color: color || 0xaaaaaa,
            quantity: quantity || 1,
            count: quantity || 1,
            description: description || '',
            quality: quality || 'common'
        };
        
        if (slotIndex >= 0 && slotIndex < TOTAL_SIZE && !this._slots[slotIndex]) {
            this._slots[slotIndex] = item;
            this._emitChange();
            if (this.isMainSlot(slotIndex)) {
                this._scheduleMainSync(this._serializeMainInventory());
            } else if (this.isHotbarSlot(slotIndex)) {
                this._scheduleMainSync({ hotbar: this._serializeHotbar() });
            }
            return slotIndex;
        }
        
        for (let i = 0; i < TOTAL_SIZE; i++) {
            if (!this._slots[i]) {
                this._slots[i] = item;
                this._emitChange();
                if (this.isMainSlot(i)) {
                    this._scheduleMainSync(this._serializeMainInventory());
                } else if (this.isHotbarSlot(i)) {
                    this._scheduleMainSync({ hotbar: this._serializeHotbar() });
                }
                return i;
            }
        }
        return -1;
    }

    async addPickup(pickup) {
        this._pendingPickups.push(pickup);
        this._processPickupQueue();
    }
    
    async _processPickupQueue() {
        if (this._pickupInFlight || this._pendingPickups.length === 0) return;
        this._pickupInFlight = true;
        while (this._pendingPickups.length > 0) {
            const pickup = this._pendingPickups.shift();
            const ok = await this.addItemToServer(pickup.itemId, pickup.count || 1, pickup.customName);
            if (pickup.onComplete) {
                try { pickup.onComplete(ok); } catch (e) {}
            }
        }
        this._pickupInFlight = false;
    }
    
    async addItemToServer(itemId, count, customName) {
        try {
            await this.waitForMainSync();
            const form = new FormData();
            form.append('action', 'gameAddItem');
            form.append('itemId', itemId);
            form.append('count', String(count || 1));
            if (customName) form.append('customName', customName);
            const res = await this._fetchWithTimeout(this._baseUrl, {
                method: 'POST',
                credentials: 'include',
                body: form
            });
            const data = await res.json();
            if (data.code === 'ok' && data.inventory) {
                this._applyMainSlots(data.inventory);
                if (data.hotbar) {
                    this._applyHotbarSlots(data.hotbar);
                }
                return true;
            }
        } catch (e) {
            console.error('[BackpackManager] addItem error', e);
        }
        return false;
    }

    getHotbarSlot(hotbarIndex) {
        const idx = MAIN_SIZE + hotbarIndex;
        return idx >= MAIN_SIZE && idx < TOTAL_SIZE ? this._slots[idx] : null;
    }
    
    async useHotbarSlot(hotbarIndex) {
        const idx = MAIN_SIZE + hotbarIndex;
        if (idx < MAIN_SIZE || idx >= TOTAL_SIZE || !this._slots[idx]) return false;
        const item = this._slots[idx];
        const qty = item.quantity || item.count || 1;
        if (qty > 1) {
            item.quantity = qty - 1;
            item.count = qty - 1;
        } else {
            this._slots[idx] = null;
        }
        this._emitChange();
        return true;
    }
}

export const backpackManager = new BackpackManager();
export { MAIN_SIZE, HOTBAR_SIZE, TOTAL_SIZE };
export default backpackManager;
