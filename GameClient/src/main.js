import { BootScene } from './scenes/BootScene.js?v=58';
import { LobbyScene } from './scenes/LobbyScene.js?v=58';
import { GameScene } from './scenes/GameScene.js?v=58';
import { GameClient } from './network/GameClient.js?v=58';
import { backpackManager } from './network/BackpackManager.js?v=58';

const GAME_WORLD_ENABLED = false;

function resolveApiUrl() {
    if (window.BOARD_PHP_URL) return window.BOARD_PHP_URL;
    // GameClient在/GameClient/目录下，board.php在网站根目录
    return '../board.php';
}

function startGame() {
    try {
        if (!GAME_WORLD_ENABLED) {
            const loading = document.getElementById('loading-status');
            const message = document.getElementById('load-msg');
            if (loading) loading.style.display = '';
            if (message) {
                message.textContent = '游戏世界暂停开放，请返回网站使用背包与社区功能。';
                message.style.color = '#c79a55';
            }
            return;
        }
        const apiUrl = resolveApiUrl();
        window.BOARD_PHP_URL = apiUrl;
        window.GameClient = GameClient;
        window.backpackManager = backpackManager;
        
        console.log('[Game] API URL:', apiUrl);
        
        // 只初始化API地址，不提前请求，由BootScene控制流程
        GameClient.init(apiUrl);

        const config = {
            type: Phaser.CANVAS,
            width: 1920,
            height: 1080,
            parent: 'game-canvas',
            backgroundColor: '#0f0f2e',
            scene: [BootScene, LobbyScene, GameScene],
            physics: { default: 'arcade', arcade: { debug: false, gravity: { y: 0 } } },
            scale: { mode: Phaser.Scale.FIT, autoCenter: Phaser.Scale.CENTER_BOTH }
        };

        new Phaser.Game(config);

        // 隐藏加载提示
        const loading = document.getElementById('loading-status');
        if (loading) loading.style.display = 'none';

        console.log('[Game] Started (HTTP mode)');
    } catch (e) {
        console.error('[Game] Start failed:', e);
        const msg = document.getElementById('load-msg');
        if (msg) {
            msg.textContent = '启动失败: ' + e.message;
            msg.style.color = '#ff6b6b';
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startGame);
} else {
    startGame();
}
