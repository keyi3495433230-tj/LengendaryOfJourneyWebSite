(function () {
    'use strict';

    const root = document.documentElement;
    const shellFile = (location.pathname.split('/').pop() || 'index.html').toLowerCase();
    root.classList.toggle('journey-single-navigation', shellFile !== 'index.html');
    const compactQuery = window.matchMedia('(max-width: 760px)');
    const touchQuery = window.matchMedia('(pointer: coarse)');

    function detectDevice() {
        const mobile = compactQuery.matches;
        root.dataset.device = mobile ? 'mobile' : 'desktop';
        root.classList.toggle('device-mobile', mobile);
        root.classList.toggle('device-desktop', !mobile);
        root.classList.toggle('device-touch', touchQuery.matches);
        window.dispatchEvent(new CustomEvent('devicechange', { detail: { device: root.dataset.device } }));
    }

    detectDevice();
    [compactQuery, touchQuery].forEach((query) => {
        if (query.addEventListener) query.addEventListener('change', detectDevice);
        else query.addListener(detectDevice);
    });

    const storageKey = 'journey_device_v1';
    let deviceId = localStorage.getItem(storageKey) || '';
    if (!/^[a-zA-Z0-9_-]{20,80}$/.test(deviceId)) {
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            const bytes = new Uint8Array(24);
            window.crypto.getRandomValues(bytes);
            deviceId = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
        } else {
            deviceId = (Date.now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2)).padEnd(24, '0').slice(0, 48);
        }
        localStorage.setItem(storageKey, deviceId);
    }

    function cookie(name) {
        const prefix = encodeURIComponent(name) + '=';
        const part = document.cookie.split('; ').find((entry) => entry.indexOf(prefix) === 0);
        return part ? decodeURIComponent(part.slice(prefix.length)) : '';
    }

    const nativeFetch = window.fetch.bind(window);
    const pendingReads = new Map();
    const catalogCacheKey = 'journey_item_catalog_v2';
    const mutationActions = new Set([
        'generateRedeemCode', 'redeemCode', 'updatePassword', 'updateActive', 'updateName',
        'add', 'like', 'votePoll', 'reply', 'replyLike', 'del', 'delReply', 'pin',
        'buyWarehouseExpansion', 'transferStorageItem', 'renameStorageItem', 'moveWarehouseItem',
        'discardWarehouseItem', 'drawLottery', 'discardLotteryItem', 'sellLotteryItemToSystem',
        'grantInventoryItem', 'saveInventory', 'discardInventoryItem', 'moveInventoryItem', 'sellInventoryToSystem',
        'batchSellInventoryToSystem', 'dailyCheckin', 'claimDailyTask', 'listMarketItem', 'delistMarketItem',
        'throwDriftBottle', 'pickDriftBottle', 'commentDriftBottle', 'resolveDriftItem',
        'buyMarketItem', 'createGoldTransfer', 'respondGoldTransfer', 'dismissNotification',
        'createItemGift', 'respondItemGift', 'claimItemMail', 'synthesizeItems',
        'updateProfile', 'addFriend', 'respondFriendRequest',
        'updateFriendRemark', 'sendMessage', 'sendWorldMessage', 'adminUpdateUser',
        'adminResetPassword', 'adminDeletePost', 'adminDeleteUserPosts', 'adminUpdateSettings',
        'useWishingWell', 'buyContactOffer',
        'createRpsRoom', 'joinRpsRoom', 'cancelRpsRoom', 'lockRpsStake', 'chooseRpsMove', 'surrenderRpsRoom', 'dismissRpsResult',
        'adminSaveItem', 'adminDeleteItem', 'adminSendMail', 'adminDeleteDriftBottle', 'adminDeleteDriftComment', 'adminSaveContact'
    ]);

    window.fetch = function (input, options) {
        const init = Object.assign({}, options || {});
        const requestUrl = typeof input === 'string' ? input : input.url;
        let fetchInput = input;
        let url;
        try { url = new URL(requestUrl, location.href); } catch (error) { return nativeFetch(input, init); }
        const requestAction = url.searchParams.get('action') || '';

        if (url.origin === location.origin && /(?:^|\/)board\.php$/i.test(url.pathname)) {
            const headers = new Headers(init.headers || (typeof input !== 'string' ? input.headers : undefined));
            const requestMethod = String(init.method || (typeof input !== 'string' ? input.method : 'GET') || 'GET').toUpperCase();
            const action = requestAction;
            if (requestMethod === 'GET' && mutationActions.has(action)) {
                init.method = 'POST';
                init.body = new URLSearchParams(url.searchParams);
                headers.set('Content-Type', 'application/x-www-form-urlencoded;charset=UTF-8');
                url.search = '';
                fetchInput = url.toString();
            }
            headers.set('X-Requested-With', 'JourneyWeb');
            headers.set('X-Journey-Device', deviceId);
            const csrf = cookie('journey_csrf');
            if (csrf) headers.set('X-CSRF-Token', csrf);
            init.headers = headers;
            init.credentials = 'same-origin';
        }

        const isBoardRead = url.origin === location.origin
            && /(?:^|\/)board\.php$/i.test(url.pathname)
            && String(init.method || 'GET').toUpperCase() === 'GET';
        const action = requestAction;
        if (isBoardRead && action === 'getItemCatalog') {
            try {
                const cached = JSON.parse(sessionStorage.getItem(catalogCacheKey) || 'null');
                if (cached && cached.expiresAt > Date.now() && typeof cached.body === 'string') {
                    return Promise.resolve(new Response(cached.body, {
                        status: 200,
                        headers: { 'Content-Type': 'application/json;charset=UTF-8', 'X-Journey-Cache': 'session' }
                    }));
                }
            } catch (error) {
                sessionStorage.removeItem(catalogCacheKey);
            }
        }

        const execute = () => nativeFetch(fetchInput, init).then((response) => {
            if (url.origin === location.origin && response.status === 401) {
                window.dispatchEvent(new CustomEvent('journey:auth-required'));
            }
            if (isBoardRead && action === 'getItemCatalog' && response.ok) {
                response.clone().text().then((body) => {
                    try { sessionStorage.setItem(catalogCacheKey, JSON.stringify({ body, expiresAt: Date.now() + 300000 })); }
                    catch (error) { /* Storage can be unavailable in privacy mode. */ }
                }).catch(() => {});
            }
            if (!isBoardRead && (action === 'adminSaveItem' || action === 'adminDeleteItem') && response.ok) {
                try { sessionStorage.removeItem(catalogCacheKey); } catch (error) {}
            }
            return response;
        });

        if (!isBoardRead) return execute();
        const readKey = url.toString();
        if (!pendingReads.has(readKey)) {
            const pending = execute();
            pendingReads.set(readKey, pending);
            pending.finally(() => pendingReads.delete(readKey)).catch(() => {});
        }
        return pendingReads.get(readKey).then((response) => response.clone());
    };

    let resolveAuth;
    const authReady = new Promise((resolve) => { resolveAuth = resolve; });
    window.JourneyAuth = {
        ready: authReady,
        current: null,
        deviceId,
        async refresh() {
            try {
                const response = await window.fetch('board.php?action=me', { cache: 'no-store' });
                const data = response.ok ? await response.json() : { authenticated: false };
                this.current = data && data.authenticated ? data.user : null;
                if (this.current) {
                    localStorage.setItem('forum_user', JSON.stringify({
                        userId: this.current.userId,
                        username: this.current.user
                    }));
                } else if (response.ok) {
                    localStorage.removeItem('forum_user');
                }
                window.dispatchEvent(new CustomEvent('journey:auth-changed', { detail: data }));
                return data;
            } catch (error) {
                return { authenticated: null, offline: true };
            }
        },
        async logout() {
            try {
                await window.fetch('board.php?action=logout', { method: 'POST' });
            } finally {
                localStorage.removeItem('forum_user');
                this.current = null;
                location.href = 'index.html';
            }
        }
    };

    window.JourneyAuth.refresh().then(resolveAuth);

    function markCurrentNavigation() {
        const currentFile = (location.pathname.split('/').pop() || 'index.html').toLowerCase();
        const navigationItems = document.querySelectorAll(
            '.topbar > .nav > a, .topbar > .nav > button, ' +
            '.site-header .nav-links > a, .site-header .nav-links > button, ' +
            '.container > .header > a, .container > .header > button'
        );
        navigationItems.forEach((item) => {
            let target = item.getAttribute('href') || '';
            if (!target) {
                const onclick = item.getAttribute('onclick') || '';
                const match = onclick.match(/location\.href\s*=\s*['\"]([^'\"]+)/i);
                target = match ? match[1] : '';
            }
            if (!target || target.startsWith('#') || /^javascript:/i.test(target)) return;
            try {
                const targetFile = (new URL(target, location.href).pathname.split('/').pop() || 'index.html').toLowerCase();
                if (targetFile === currentFile) {
                    item.classList.add('is-current');
                    item.setAttribute('aria-current', 'page');
                }
            } catch (error) {}
        });
    }

    const journeyPages = [
        { href: 'index.html', label: '首页', hint: '旅途入口' },
        { href: 'game.html', label: '世界观', hint: '设定资料' },
        { href: 'dungeon-wasd-demo.html', label: '黑暗地牢', hint: '探索与撤离' },
        { href: 'rps.html', label: '竞技场', hint: '多人对决' },
        { href: 'bagdemo.html', label: '背包', hint: '物品与任务' },
        { href: 'bagdemo.html#tasks', label: '任务', hint: '每日与地牢委托' },
        { href: 'bagdemo.html#messages', label: '消息', hint: '通知与礼物', messageEntry: true },
        { href: 'wishing.html', label: '许愿井', hint: '每日回声' },
        { href: 'contacts.html', label: '联络人', hint: '可翼黑市' },
        { href: 'lottery.html', label: '抽奖', hint: '获取物品' },
        { href: 'market.html', label: '市场', hint: '交易与行情' },
        { href: 'bottle.html', label: '漂流瓶', hint: '潮汐来信' },
        { href: 'forum.html', label: '论坛', hint: '玩家社区' },
        { href: 'friends.html', label: '好友', hint: '好友与私信' },
        { href: 'leaderboard.html', label: '排行', hint: '旅人榜单' },
        { href: 'catalog.html', label: '图鉴', hint: '物品资料' },
        { href: 'profile.html', label: '资料', hint: '个人信息' }
    ];

    function installPageSwitcher() {
        if (!document.body || document.getElementById('journeyPageSwitcher')) return;
        const currentFile = (location.pathname.split('/').pop() || 'index.html').toLowerCase();
        const root = document.createElement('div');
        root.id = 'journeyPageSwitcher';
        root.className = 'journey-page-switcher';

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'journey-switcher-trigger';
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-controls', 'journeySwitcherPanel');
        trigger.innerHTML = '<span class="journey-switcher-lines" aria-hidden="true"></span><span>页面</span>';
        const triggerBadge = document.createElement('span');
        triggerBadge.className = 'journey-global-message-badge';
        triggerBadge.hidden = true;
        trigger.appendChild(triggerBadge);

        const backdrop = document.createElement('button');
        backdrop.type = 'button';
        backdrop.className = 'journey-switcher-backdrop';
        backdrop.setAttribute('aria-label', '关闭页面导航');
        backdrop.tabIndex = -1;

        const panel = document.createElement('aside');
        panel.id = 'journeySwitcherPanel';
        panel.className = 'journey-switcher-panel';
        panel.setAttribute('aria-label', '页面导航');
        panel.setAttribute('aria-hidden', 'true');

        const heading = document.createElement('div');
        heading.className = 'journey-switcher-head';
        heading.innerHTML = '<div><strong>旅途导航</strong><span>在各个功能之间快速切换</span></div>';
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'journey-switcher-close';
        close.setAttribute('aria-label', '关闭页面导航');
        close.textContent = '关闭';
        heading.appendChild(close);

        const navigation = document.createElement('nav');
        navigation.className = 'journey-switcher-grid';
        journeyPages.forEach((page, index) => {
            const link = document.createElement('a');
            link.href = page.href;
            link.innerHTML = `<span class="journey-switcher-index">${String(index + 1).padStart(2, '0')}</span><strong>${page.label}</strong><small>${page.hint}</small>`;
            const targetUrl = new URL(page.href, location.href);
            const targetFile = (targetUrl.pathname.split('/').pop() || 'index.html').toLowerCase();
            const hashMatches = !targetUrl.hash || targetUrl.hash === location.hash;
            if (targetFile === currentFile && hashMatches) {
                link.classList.add('is-current');
                link.setAttribute('aria-current', 'page');
            }
            if (page.messageEntry) {
                link.classList.add('journey-message-entry');
                const badge = document.createElement('span');
                badge.className = 'journey-global-message-badge';
                badge.hidden = true;
                link.appendChild(badge);
            }
            navigation.appendChild(link);
        });
        if (currentFile === 'admin.html') {
            const adminLink = document.createElement('a');
            adminLink.href = 'admin.html';
            adminLink.className = 'is-current';
            adminLink.setAttribute('aria-current', 'page');
            adminLink.innerHTML = '<span class="journey-switcher-index">12</span><strong>管理</strong><small>后台控制台</small>';
            navigation.appendChild(adminLink);
        }

        panel.append(heading, navigation);
        root.append(trigger, backdrop, panel);
        document.body.appendChild(root);

        const setOpen = (open) => {
            root.classList.toggle('is-open', open);
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            panel.setAttribute('aria-hidden', open ? 'false' : 'true');
            document.documentElement.classList.toggle('journey-switcher-open', open);
            if (open) close.focus({ preventScroll: true });
            else if (document.activeElement === close) trigger.focus({ preventScroll: true });
        };
        trigger.addEventListener('click', () => setOpen(!root.classList.contains('is-open')));
        close.addEventListener('click', () => setOpen(false));
        backdrop.addEventListener('click', () => setOpen(false));
        window.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && root.classList.contains('is-open')) setOpen(false);
        });

        const renderMessageCount = (value) => {
            const count = Math.max(0, Math.floor(Number(value) || 0));
            root.querySelectorAll('.journey-global-message-badge').forEach((badge) => {
                badge.textContent = count > 99 ? '99+' : String(count);
                badge.hidden = count < 1;
                badge.setAttribute('aria-label', count > 0 ? `${count} 条未读消息` : '没有未读消息');
            });
            trigger.classList.toggle('has-unread', count > 0);
        };

        let messageRequestRunning = false;
        const refreshMessageCount = async () => {
            if (messageRequestRunning || document.hidden) return;
            const auth = await window.JourneyAuth.ready;
            if (!auth || !auth.authenticated || !auth.user || !auth.user.userId) {
                renderMessageCount(0);
                return;
            }
            messageRequestRunning = true;
            try {
                const response = await window.fetch(`board.php?action=getMyMessages&userId=${encodeURIComponent(auth.user.userId)}`, { cache: 'no-store' });
                const data = response.ok ? await response.json() : null;
                if (data && data.code === 'ok') renderMessageCount(data.pendingCount);
            } catch (error) {
                // Keep the last known count during a temporary network failure.
            } finally {
                messageRequestRunning = false;
            }
        };
        window.addEventListener('journey:messages-updated', (event) => {
            if (event.detail && Object.prototype.hasOwnProperty.call(event.detail, 'count')) {
                renderMessageCount(event.detail.count);
            } else {
                refreshMessageCount();
            }
        });
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) refreshMessageCount();
        });
        refreshMessageCount();
        window.setInterval(refreshMessageCount, 30000);
    }

    function initializeSharedUi() {
        markCurrentNavigation();
        installPageSwitcher();
        installDungeonPurchaseGuard();
    }

    function showDungeonPurchaseBlocked() {
        const existing = document.getElementById('journeyDungeonPurchaseBlocked');
        if (existing) existing.remove();
        const notice = document.createElement('div');
        notice.id = 'journeyDungeonPurchaseBlocked';
        notice.textContent = '无法在地牢中随意购买物品';
        Object.assign(notice.style, {
            position: 'fixed', zIndex: '99999', left: '50%', top: '18px', transform: 'translateX(-50%)',
            padding: '12px 18px', border: '1px solid rgba(214,169,93,.55)', borderRadius: '6px',
            background: '#211a15', color: '#f3eee4', font: '700 14px "Microsoft YaHei",sans-serif',
            boxShadow: '0 16px 46px rgba(0,0,0,.45)'
        });
        document.body.appendChild(notice);
        window.setTimeout(() => notice.remove(), 2600);
    }

    function installDungeonPurchaseGuard() {
        let checking = false;
        document.addEventListener('click', async (event) => {
            const link = event.target.closest('a[href]');
            if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const target = new URL(link.href, location.href);
            const targetFile = (target.pathname.split('/').pop() || '').toLowerCase();
            const currentFile = (location.pathname.split('/').pop() || '').toLowerCase();
            if (currentFile === 'dungeon-wasd-demo.html' && targetFile !== currentFile && typeof window.journeyDungeonLeaveGuard === 'function') {
                event.preventDefault();
                window.journeyDungeonLeaveGuard(target.href);
                return;
            }
            if (!['contacts.html', 'market.html'].includes(targetFile)) return;
            event.preventDefault();
            if (checking) return;
            checking = true;
            try {
                const auth = await window.JourneyAuth.ready;
                if (!auth || !auth.authenticated) { location.href = target.href; return; }
                const response = await window.fetch('board.php?action=getDungeonState', { cache: 'no-store' });
                const state = response.ok ? await response.json() : null;
                if (state && state.code === 'ok' && state.state && state.state.scene === 'dungeon') {
                    showDungeonPurchaseBlocked();
                    return;
                }
                location.href = target.href;
            } catch (error) {
                // Do not block normal navigation if the state check is temporarily unavailable.
                location.href = target.href;
            } finally {
                checking = false;
            }
        }, true);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeSharedUi);
    else initializeSharedUi();
})();
