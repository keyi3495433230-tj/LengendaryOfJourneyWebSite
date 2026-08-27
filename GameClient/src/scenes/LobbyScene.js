import { GameClient } from '../network/GameClient.js?v=58';
import { backpackManager } from '../network/BackpackManager.js?v=58';

export class LobbyScene extends Phaser.Scene {
    constructor() { super('LobbyScene'); }

    init(data) {
        this._playerName = data.playerName || '冒险者';
        this._level = data.level || 1;
        this._title = data.title || '';
        this._enterEnabled = false;
    }

    create() {
        const w = this.cameras.main.width;
        const h = this.cameras.main.height;
        const cx = w / 2;
        const cy = h / 2;

        // 背景
        this.cameras.main.setBackgroundColor('#0f0f25');
        this.add.rectangle(0, 0, w, h, 0x0f0f25).setOrigin(0);

        // 标题
        this.add.text(cx, 100, '旅途冒险家新区', {
            fontSize: '48px', color: '#4ecdc4', fontFamily: 'Microsoft YaHei', fontStyle: 'bold'
        }).setOrigin(0.5);
        this.add.text(cx, 150, 'Journey Realm', {
            fontSize: '20px', color: '#666688', fontFamily: 'Segoe UI', letterSpacing: 6
        }).setOrigin(0.5);

        // 欢迎信息
        this.add.text(cx, 200, `欢迎回来，${this._playerName}${this._title ? ` [${this._title}]` : ''}  Lv.${this._level}`, {
            fontSize: '16px', color: '#9999bb', fontFamily: 'Microsoft YaHei'
        }).setOrigin(0.5);

        // 服务器卡片背景
        const cardBg = this.add.rectangle(cx, cy, 520, 130, 0x1a1a35, 1)
            .setStrokeStyle(2, 0x4ecdc4, 0.5);

        // 服务器图标和信息
        this.add.circle(cx - 200, cy, 35, 0x4ecdc4, 0.2);
        this.add.text(cx - 200, cy, '⚔️', { fontSize: '36px' }).setOrigin(0.5);
        this.add.text(cx - 130, cy - 30, '旅途新区', {
            fontSize: '24px', color: '#ffffff', fontFamily: 'Microsoft YaHei', fontStyle: 'bold'
        }).setOrigin(0, 0.5);
        this.add.text(cx - 130, cy + 5, '全新冒险大陆', {
            fontSize: '14px', color: '#8888aa', fontFamily: 'Microsoft YaHei'
        }).setOrigin(0, 0.5);
        this._onlineText = this.add.text(cx - 130, cy + 35, '🟢 正在连接...', {
            fontSize: '14px', color: '#8888aa', fontFamily: 'Microsoft YaHei'
        }).setOrigin(0, 0.5);

        // 进入按钮
        const enterBtn = this.add.rectangle(cx + 150, cy, 120, 50, 0x4ecdc4, 0.9);
        this._enterText = this.add.text(cx + 150, cy, '进入游戏', {
            fontSize: '20px', color: '#000000', fontFamily: 'Microsoft YaHei', fontStyle: 'bold'
        }).setOrigin(0.5);

        // 整个卡片区域都可点击
        const hitArea = this.add.rectangle(cx, cy, 520, 130, 0xffffff, 0)
            .setInteractive({ useHandCursor: true });

        hitArea.on('pointerover', () => {
            cardBg.setFillStyle(0x222245, 1);
            cardBg.setStrokeStyle(2, 0x4ecdc4, 1);
            enterBtn.setFillStyle(0x6ee7de, 1);
        });
        hitArea.on('pointerout', () => {
            cardBg.setFillStyle(0x1a1a35, 1);
            cardBg.setStrokeStyle(2, 0x4ecdc4, 0.5);
            enterBtn.setFillStyle(0x4ecdc4, 0.9);
        });

        hitArea.on('pointerdown', () => {
            this._tryEnter();
        });

        // 状态文字
        this._statusText = this.add.text(cx, h - 140, '准备就绪，点击进入游戏', {
            fontSize: '15px', color: '#66aa88', fontFamily: 'Microsoft YaHei'
        }).setOrigin(0.5);

        // Enter键
        this._enterKey = this.input.keyboard.addKey(Phaser.Input.Keyboard.KeyCodes.ENTER);

        // HTTP模式直接可以进入
        this._enterEnabled = true;
    }

    _tryEnter() {
        if (!this._enterEnabled) {
            this._statusText.setText('⚠ 请等待服务器连接成功');
            this._statusText.setColor('#ff8c42');
            return;
        }
        this._statusText.setText('正在进入旅途新区...');
        this._statusText.setColor('#ffd93d');
        // 直接跳转，不延迟
        this.scene.start('GameScene', {
            roomId: 'journey_new',
            roomName: '旅途新区',
            playerName: this._playerName,
            level: this._level,
            title: this._title
        });
    }

    async _refreshOnline() {
        try {
            const players = await GameClient.getOnlinePlayers();
            const count = players ? players.length : 0;
            this._onlineText.setText(`🟢 ${count} 人在线`);
            this._onlineText.setColor(count > 0 ? '#66aa88' : '#8888aa');
        } catch (e) {
            this._onlineText.setText('🟡 人数加载中');
        }
    }

    update() {
        if (Phaser.Input.Keyboard.JustDown(this._enterKey)) {
            this._tryEnter();
        }
    }

    shutdown() {
        if (this._connHandler) GameClient.offConnectionChange(this._connHandler);
        if (this._onlineTimer) this._onlineTimer.remove();
    }
}
