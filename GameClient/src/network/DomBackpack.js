// 游戏内DOM背包 - 完全复用网页背包HTML5原生拖拽，稳定可靠
import { backpackManager, MAIN_SIZE, HOTBAR_SIZE } from './BackpackManager.js?v=58';

const TOTAL_SIZE = MAIN_SIZE + HOTBAR_SIZE;
let _bagUI = null;
let _hotbarUI = null;
let _bagGrid = null;
let _hotbarGrid = null;
let _selectedHotbar = 0;
let _visible = false;
let _dragSource = null;
let _hoveredSlot = null;
let _onHotbarSelect = null;
let _onDropItem = null;
let _onMessage = null;
let _isMobile = false;
let _mobileActionMenu = null;
let _mobileActionSlot = null;
let _unsubscribeUpdate = null;

const QUALITY_LABELS = {
    common: '普通',
    uncommon: '精良',
    rare: '稀有',
    epic: '史诗',
    legendary: '传说'
};

function getItemName(item) {
    if (!item) return '空槽位';
    if (item.customName) return item.customName;
    return item.name || item.id || '未知道具';
}

function getItemDesc(item) {
    if (!item) return '';
    if (item.description) return item.description;
    const q = item.quality || 'common';
    if (q === 'common') return '旅途中收集的小物品。';
    if (q === 'uncommon') return '带有特殊气息的道具。';
    if (q === 'rare') return '稀有的收藏品。';
    if (q === 'epic') return '史诗级珍贵物品。';
    if (q === 'legendary') return '传说级别的宝物。';
    return '';
}

function hideMobileActions() {
    _mobileActionSlot = null;
    if (_mobileActionMenu) _mobileActionMenu.classList.remove('visible');
}

function ensureMobileActionMenu() {
    if (_mobileActionMenu) return _mobileActionMenu;
    const menu = document.createElement('div');
    menu.className = 'mobile-item-actions';
    menu.innerHTML = `
        <div class="mobile-item-action-name"></div>
        <div class="mobile-item-action-buttons">
            <button type="button" data-action="hotbar">放到快捷栏</button>
            <button type="button" data-action="drop" class="danger">丢弃</button>
            <button type="button" data-action="cancel">取消</button>
        </div>
    `;
    menu.addEventListener('click', async (event) => {
        event.stopPropagation();
        const button = event.target.closest('button[data-action]');
        if (!button || _mobileActionSlot === null) return;
        const action = button.dataset.action;
        const slotIndex = _mobileActionSlot;
        if (action === 'cancel') {
            hideMobileActions();
            return;
        }
        button.disabled = true;
        try {
            if (action === 'drop') {
                hideMobileActions();
                if (_onDropItem) await _onDropItem(slotIndex);
                return;
            }
            const emptyHotbar = Array.from({ length: HOTBAR_SIZE }, (_, index) => index)
                .find(index => !backpackManager.getHotbarSlot(index));
            if (emptyHotbar === undefined) {
                if (_onMessage) _onMessage('快捷栏已满，请先腾出一个位置');
                return;
            }
            const moved = await backpackManager.moveSlot(slotIndex, MAIN_SIZE + emptyHotbar);
            if (moved) {
                _selectedHotbar = emptyHotbar;
                if (_onHotbarSelect) _onHotbarSelect(emptyHotbar);
                hideMobileActions();
            } else if (_onMessage) {
                _onMessage('物品移动失败，请重试');
            }
        } finally {
            button.disabled = false;
        }
    });
    document.getElementById('game-container')?.appendChild(menu);
    _mobileActionMenu = menu;
    return menu;
}

function showMobileActions(slotIndex, isHotbar) {
    const item = backpackManager.getSlot(slotIndex);
    if (!item) return;
    const menu = ensureMobileActionMenu();
    _mobileActionSlot = slotIndex;
    menu.querySelector('.mobile-item-action-name').textContent = getItemName(item);
    menu.querySelector('[data-action="hotbar"]').style.display = isHotbar ? 'none' : '';
    menu.classList.add('visible');
}

function createSlotElement(slotIndex, isHotbar) {
    const slot = document.createElement('div');
    slot.className = 'slot empty';
    slot.dataset.slotIndex = String(slotIndex);
    slot.dataset.isHotbar = isHotbar ? '1' : '0';
    slot.draggable = false;

    // 槽位编号（主背包不显示，快捷栏显示1-7）
    if (isHotbar) {
        const key = document.createElement('div');
        key.className = 'hotbar-key';
        key.textContent = String(slotIndex - MAIN_SIZE + 1);
        slot.appendChild(key);
    }

    // 拖拽事件 - 和网页背包完全一致的HTML5原生拖拽
    slot.addEventListener('dragstart', (e) => {
        const item = backpackManager.getSlot(slotIndex);
        if (!item) {
            e.preventDefault();
            return;
        }
        _dragSource = slotIndex;
        slot.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        // 设置拖拽数据（兼容性需要设置text）
        try {
            e.dataTransfer.setData('text/plain', String(slotIndex));
            e.dataTransfer.setData('application/x-journey-slot', String(slotIndex));
        } catch (err) {}
        // 自定义拖拽图像
        try {
            const ghost = document.createElement('div');
            ghost.style.cssText = `
                position:fixed;left:-9999px;top:-9999px;width:60px;height:60px;
                background:rgba(0,0,0,0.8);border:2px solid #ffd93d;border-radius:6px;
                display:grid;place-items:center;font-size:30px;
            `;
            ghost.textContent = item.icon || '📦';
            document.body.appendChild(ghost);
            e.dataTransfer.setDragImage(ghost, 30, 30);
            setTimeout(() => document.body.removeChild(ghost), 0);
        } catch (err) {}
    });

    slot.addEventListener('dragend', () => {
        slot.classList.remove('dragging');
        document.querySelectorAll('.slot.drag-over').forEach(s => s.classList.remove('drag-over'));
        _dragSource = null;
    });

    slot.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        slot.classList.add('drag-over');
    });

    slot.addEventListener('dragleave', () => {
        slot.classList.remove('drag-over');
    });

    slot.addEventListener('mouseenter', () => {
        _hoveredSlot = backpackManager.getSlot(slotIndex) ? slotIndex : null;
    });
    slot.addEventListener('mouseleave', () => {
        if (_hoveredSlot === slotIndex) _hoveredSlot = null;
    });

    slot.addEventListener('drop', async (e) => {
        e.preventDefault();
        e.stopPropagation();
        slot.classList.remove('drag-over');
        
        let fromSlot = _dragSource;
        if (fromSlot === null) {
            // 尝试从dataTransfer获取（兼容性）
            try {
                fromSlot = parseInt(e.dataTransfer.getData('application/x-journey-slot') || e.dataTransfer.getData('text/plain'), 10);
            } catch (err) {}
        }
        
        if (fromSlot === null || isNaN(fromSlot) || fromSlot === slotIndex) {
            return;
        }

        console.log('[DOM背包] 移动槽位', fromSlot, '→', slotIndex);
        await backpackManager.moveSlot(fromSlot, slotIndex);
    });

    slot.addEventListener('click', () => {
        const item = backpackManager.getSlot(slotIndex);
        if (isHotbar) {
            _selectedHotbar = slotIndex - MAIN_SIZE;
            render();
            if (_onHotbarSelect) _onHotbarSelect(_selectedHotbar);
        }
        if (_isMobile && item) showMobileActions(slotIndex, isHotbar);
    });

    return slot;
}

function renderSlot(slotEl, slotIndex, isHotbar) {
    const item = backpackManager.getSlot(slotIndex);
    
    // 清空现有内容（保留key标签）
    while (slotEl.children.length > (isHotbar ? 1 : 0)) {
        slotEl.removeChild(slotEl.lastChild);
    }

    slotEl.className = 'slot';
    if (isHotbar && slotIndex - MAIN_SIZE === _selectedHotbar) {
        slotEl.classList.add('selected');
    }

    if (!item) {
        if (_hoveredSlot === slotIndex) _hoveredSlot = null;
        slotEl.classList.add('empty');
        slotEl.draggable = false;
        return;
    }

    slotEl.draggable = true;
    const quality = item.quality || 'common';
    slotEl.classList.add(`quality-${quality}`);

    // 图标
    const icon = document.createElement('div');
    icon.className = 'slot-icon';
    icon.textContent = item.icon || '📦';
    slotEl.appendChild(icon);

    // 数量
    const qty = item.quantity || item.count || 1;
    if (qty > 1) {
        const count = document.createElement('div');
        count.className = 'slot-count';
        count.textContent = String(qty);
        slotEl.appendChild(count);
    }

    // Tooltip
    const tooltip = document.createElement('div');
    tooltip.className = 'slot-tooltip';
    const qLabel = QUALITY_LABELS[quality] || '普通';
    tooltip.innerHTML = `
        <strong>${getItemName(item)}</strong>
        <small>${qLabel} · ${qty > 1 ? '×' + qty + '个' : '1个'} · Q 丢弃</small>
        <p style="margin-top:5px;font-size:11px;line-height:1.4;">${getItemDesc(item)}</p>
    `;
    slotEl.appendChild(tooltip);
}

function render() {
    if (!_bagGrid || !_hotbarGrid) return;

    // 渲染主背包
    for (let i = 0; i < MAIN_SIZE; i++) {
        const slot = _bagGrid.children[i];
        if (slot) renderSlot(slot, i, false);
    }

    // 渲染快捷栏
    for (let i = 0; i < HOTBAR_SIZE; i++) {
        const slotIndex = MAIN_SIZE + i;
        const slot = _hotbarGrid.children[i];
        if (slot) renderSlot(slot, slotIndex, true);
    }
}

export function initDomBackpack(options = {}) {
    _onHotbarSelect = options.onHotbarSelect || null;
    _onDropItem = options.onDropItem || null;
    _onMessage = options.onMessage || null;
    _isMobile = Boolean(options.isMobile);

    _bagUI = document.getElementById('gameBagUI');
    _hotbarUI = document.getElementById('gameHotbar');
    _bagGrid = document.getElementById('bagGridDom');
    _hotbarGrid = document.getElementById('hotbarGridDom');

    if (!_bagGrid || !_hotbarGrid) {
        console.error('[DOM背包] 找不到DOM元素');
        return;
    }

    // 创建主背包槽位
    _bagGrid.innerHTML = '';
    for (let i = 0; i < MAIN_SIZE; i++) {
        const slot = createSlotElement(i, false);
        _bagGrid.appendChild(slot);
    }

    // 创建快捷栏槽位
    _hotbarGrid.innerHTML = '';
    for (let i = 0; i < HOTBAR_SIZE; i++) {
        const slotIndex = MAIN_SIZE + i;
        const slot = createSlotElement(slotIndex, true);
        _hotbarGrid.appendChild(slot);
    }

    // 关闭按钮
    const closeBtn = document.getElementById('bagCloseBtn');
    if (closeBtn) {
        closeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            hideBag();
        });
    }

    // 监听背包变化，自动渲染
    if (_unsubscribeUpdate) _unsubscribeUpdate();
    _unsubscribeUpdate = backpackManager.onUpdate(() => render());

    // 初始渲染
    render();

    // 快捷栏默认显示
    setHotbarVisible(true);
}

export function setBagVisible(visible) {
    _visible = visible;
    if (_bagUI) {
        _bagUI.classList.toggle('visible', visible);
    }
    if (!visible) hideMobileActions();
}

export function toggleBag() {
    setBagVisible(!_visible);
    return _visible;
}

export function isBagVisible() {
    return _visible;
}

export function setHotbarVisible(visible) {
    if (_hotbarUI) {
        _hotbarUI.style.display = visible ? 'block' : 'none';
    }
}

export function getSelectedHotbar() {
    return _selectedHotbar;
}

export function getHoveredSlot() {
    return _hoveredSlot;
}

export function setSelectedHotbar(index) {
    if (index >= 0 && index < HOTBAR_SIZE) {
        _selectedHotbar = index;
        render();
    }
}

export function positionBagUI(canvasRect) {
    if (!_bagUI) return;
    // 背包居中显示在画布上方
    _bagUI.style.left = '50%';
    _bagUI.style.top = '50%';
    _bagUI.style.transform = 'translate(-50%, -50%)';
}

export function hideBag() {
    setBagVisible(false);
}

export function renderAll() {
    render();
}

export function destroy() {
    setBagVisible(false);
    setHotbarVisible(false);
    _onHotbarSelect = null;
    _onDropItem = null;
    _onMessage = null;
    _hoveredSlot = null;
    hideMobileActions();
    if (_mobileActionMenu?.parentNode) _mobileActionMenu.parentNode.removeChild(_mobileActionMenu);
    _mobileActionMenu = null;
    if (_unsubscribeUpdate) _unsubscribeUpdate();
    _unsubscribeUpdate = null;
}

export function isPointOverUI(x, y) {
    if (_bagUI && _visible) {
        const r = _bagUI.getBoundingClientRect();
        if (x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) return true;
    }
    if (_hotbarUI) {
        const r = _hotbarUI.getBoundingClientRect();
        if (x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) return true;
    }
    return false;
}
