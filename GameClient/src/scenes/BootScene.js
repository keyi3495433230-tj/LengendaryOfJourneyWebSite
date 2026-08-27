import { backpackManager } from '../network/BackpackManager.js?v=58';

export class BootScene extends Phaser.Scene {
    constructor() {
        super('BootScene');
    }

    create() {
        const width = this.cameras.main.width;
        const height = this.cameras.main.height;
        this._w = width;
        this._h = height;

        this.add.rectangle(0, 0, width, height, 0x0f0f2e).setOrigin(0);

        const title = this.add.text(width / 2, height / 2 - 50, '游戏世界暂停开放', {
            fontSize: '48px', color: '#c084fc', fontFamily: 'Microsoft YaHei', fontStyle: 'bold'
        }).setOrigin(0.5);

        this.tweens.add({
            targets: title, alpha: { from: 1, to: 0.6 }, duration: 1000, yoyo: true, repeat: -1
        });

        this._statusText = this.add.text(width / 2, height / 2 + 20, '当前无法进入游戏场景', {
            fontSize: '16px', color: '#aaaaaa', fontFamily: 'Microsoft YaHei'
        }).setOrigin(0.5);

        this.add.text(width / 2, height / 2 + 80, '返回我的背包', {
            fontSize: '18px', color: '#1a140b', fontFamily: 'Microsoft YaHei',
            backgroundColor: '#c79a55', padding: { x: 30, y: 12 }
        }).setOrigin(0.5).setInteractive({ useHandCursor: true })
            .on('pointerdown', () => { window.location.replace('../bagdemo.html'); });
    }

    async _checkLogin() {
        const apiUrl = window.BOARD_PHP_URL || '../board.php';
        const width = this._w;
        const height = this._h;

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 5000);

            const res = await fetch(`${apiUrl}?action=getGameProfile&t=${Date.now()}`, {
                credentials: 'include',
                cache: 'no-store',
                signal: controller.signal
            });

            clearTimeout(timeoutId);

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }

            const data = await res.json();

            if (data.authenticated && data.user) {
                this._statusText.setText('登录成功，正在进入...');
                this._statusText.setColor('#c084fc');

                // 初始化背包
                if (data.inventory) {
                    backpackManager._applyMainSlots(data.inventory);
                }
                if (data.hotbar) {
                    backpackManager._applyHotbarSlots(data.hotbar);
                }
                backpackManager.setServerUser({
                    userId: data.user.userId,
                    username: data.user.username,
                    displayName: data.user.displayName || data.user.username,
                    level: data.user.level || 1,
                    title: data.user.title || ''
                });

                this.scene.start('LobbyScene', {
                    playerName: data.user.displayName || data.user.username,
                    level: data.user.level || 1,
                    title: data.user.title || ''
                });
            } else {
                this._statusText.setText('请先登录');
                this._statusText.setColor('#ff6b6b');

                this.add.text(width / 2, height / 2 + 80, '点击前往登录', {
                    fontSize: '18px', color: '#c084fc', fontFamily: 'Microsoft YaHei',
                    backgroundColor: '#1a1a3e', padding: { x: 30, y: 12 }
                }).setOrigin(0.5).setInteractive({ useHandCursor: true })
                    .on('pointerdown', () => { window.location.href = '../auth.html'; });
            }
        } catch (e) {
            console.error('[Boot] Login check failed:', e);
            this._statusText.setText('连接服务器失败: ' + e.message);
            this._statusText.setColor('#ff6b6b');

            this.add.text(width / 2, height / 2 + 80, '点击重试', {
                fontSize: '18px', color: '#c084fc', fontFamily: 'Microsoft YaHei',
                backgroundColor: '#1a1a3e', padding: { x: 30, y: 12 }
            }).setOrigin(0.5).setInteractive({ useHandCursor: true })
                .on('pointerdown', () => { this.scene.restart(); });
        }
    }
}
