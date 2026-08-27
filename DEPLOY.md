# 旅途之界 游戏部署指南
## 阿里云轻量服务器 + 宝塔面板

---

## 一、你需要知道的基本信息

| 项目 | 内容 |
|------|------|
| 服务器IP | 47.245.57.193 |
| 域名 | georgehive.com |
| 操作系统 | Ubuntu/Debian |
| 面板 | 宝塔面板 |
| 网站根目录 | `/www/wwwroot/georgehive.com/` |
| 游戏服务器端口 | 5000 |

---

## 二、需要上传的文件

### 用 WinSCP 上传（推荐）

| 本地路径 | 远程路径 | 说明 |
|---------|---------|------|
| `d:\旅途传说\网页设计\GameClient\` | `/www/wwwroot/georgehive.com/GameClient/` | 整个目录 |
| `d:\旅途传说\网页设计\board.php` | `/www/wwwroot/georgehive.com/board.php` | 覆盖旧版 |
| `d:\旅途传说\网页设计\index.html` | `/www/wwwroot/georgehive.com/index.html` | 覆盖（新增游戏按钮） |
| `d:\旅途传说\网页设计\GameServer\publish\` 里所有文件 | `/opt/gameserver/` | 游戏服务器 |

### 不要上传的文件

- `.journey-data/` ← 你的数据库，**千万别覆盖！**
- `lib/` ← 已在服务器上
- `journey-config.php` ← 服务器配置
- `auth.html`、`profile.html` 等 ← 未修改

---

## 三、宝塔面板安装 .NET 8

### 第1步：打开宝塔终端

宝塔面板 → 左侧菜单 → **终端**

### 第2步：复制粘贴以下命令

```bash
# 安装 .NET 8 运行时
wget https://dot.net/v1/dotnet-install.sh -O dotnet-install.sh
chmod +x dotnet-install.sh
bash dotnet-install.sh --channel 8.0 --install-dir /opt/dotnet
rm dotnet-install.sh

# 配置环境变量
echo 'DOTNET_ROOT=/opt/dotnet' >> /etc/environment
echo 'export PATH=/opt/dotnet:$PATH' >> /etc/profile
export PATH=/opt/dotnet:$PATH

# 验证安装
dotnet --list-runtimes
# 应该显示 8.0.x
```

### 第3步：安装进程守护 Supervisor

```bash
apt-get update
apt-get install -y supervisor
```

---

## 四、配置游戏服务器

### 第1步：上传 GameServer 文件

用 WinSCP 将 `GameServer\publish\` 里的**所有文件**上传到 `/opt/gameserver/`

包括：`GameServer.dll`、`GameServer.deps.json`、`GameServer.runtimeconfig.json` 等

### 第2步：创建 Supervisor 配置

在宝塔终端执行：

```bash
mkdir -p /opt/gameserver
mkdir -p /opt/gameserver/App_Data

cat > /etc/supervisor/conf.d/gameserver.conf << 'EOF'
[program:gameserver]
command=/opt/dotnet/dotnet /opt/gameserver/GameServer.dll
directory=/opt/gameserver
autostart=true
autorestart=true
restartsecs=5
stderr_logfile=/var/log/gameserver_err.log
stdout_logfile=/var/log/gameserver.log
environment=ASPNETCORE_ENVIRONMENT="Production",DOTNET_ROOT="/opt/dotnet"
EOF

# 启动服务
supervisorctl reread
supervisorctl update
supervisorctl start gameserver
```

### 第3步：检查服务状态

```bash
supervisorctl status gameserver
# 应该显示 RUNNING
```

如果出错，查看日志：
```bash
tail -50 /var/log/gameserver_err.log
```

---

## 五、快速测试（先跳过Nginx！）

**在配置Nginx之前，先测试游戏服务器是否正常运行。**

### 第1步：开放5000端口

**阿里云控制台** → 轻量应用服务器 → 防火墙 → 添加规则：
- 协议：TCP
- 端口：5000
- 放行所有IP

**宝塔面板** → 安全 → 添加端口 5000

### 第2步：直接访问测试

浏览器访问：
```
http://georgehive.com/GameClient/index.html?ws=ws://georgehive.com:5000/ws
```

**带 `?ws=` 参数**会跳过Nginx代理，直接连接游戏服务器。

### 第3步：查看结果

如果能正常进入游戏 → 游戏服务器没问题，继续配置Nginx
如果显示连接错误 → 游戏服务器没启动好，回去检查第4步

---

## 六、配置宝塔 Nginx 反向代理（正式配置）

确认游戏服务器正常后，再配置Nginx代理，让用户无需加`?ws=`参数。

### 第1步：打开网站配置

宝塔面板 → **网站** → 找到 `georgehive.com` → 点击 **设置**

### 第2步：找到配置文件

点击左侧 **配置文件** 标签

### 第3步：添加反向代理配置

在 `server { ... }` 块内，在 `}` 闭合括号**之前**，添加：

```nginx
    # 游戏 WebSocket 反向代理
    location /ws {
        proxy_pass http://127.0.0.1:5000/ws;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 86400;
    }
```

### 第4步：保存并重载

点击 **保存**，然后在宝塔终端执行：
```bash
nginx -t
```

如果显示 `syntax is ok` 和 `test is successful`，再执行：
```bash
nginx -s reload
```

### 第5步：正式测试

现在可以不加参数直接访问：
```
http://georgehive.com/GameClient/index.html
```

### ⚠️ 注意

如果宝塔配置文件里已经有 `location /ws` 相关配置，**替换**而不是重复添加！

---

## 八、测试

### 第1步：登录

浏览器访问：`http://georgehive.com/auth.html`

登录你的账号

### 第2步：访问游戏

浏览器访问：`http://georgehive.com/GameClient/index.html`

### 第3步：查看诊断信息

启动后，屏幕上会显示调试信息，类似：

```
📡 检测环境...
WebSocket: ws://georgehive.com/ws
API: ../board.php
HTTP状态: 200 OK
code: ok  authenticated: true
✓ 已登录: 用户名 Lv.1
```

如果显示红色错误，把那几行内容告诉我。

---

## 九、常见问题

### Q: 界面一直卡在"加载中..."
可能原因：
1. PHP session 过期 → 先去 auth.html 重新登录
2. 浏览器缓存 → 按 Ctrl+F5 强制刷新
3. 用了HTTPS但WebSocket是ws:// → 改成 `https://` 访问，会自动用 `wss://`

### Q: 显示"API无响应"
检查：
1. board.php 是否已上传到网站根目录
2. PHP 是否正常：访问 `georgehive.com/board.php?action=me` 看是否返回JSON

### Q: 显示"连接断开"
检查：
1. 游戏服务器是否运行：`supervisorctl status gameserver`
2. Nginx 反向代理是否配置正确
3. 防火墙是否开放

### Q: WebSocket 连接超时
如果Nginx代理配置有问题，可以用URL参数直接连游戏服务器：
```
georgehive.com/GameClient/index.html?ws=ws://georgehive.com:5000/ws
```
（需要开放5000端口）

### Q: 如何重启游戏服务器
```bash
supervisorctl restart gameserver
```

### Q: 如何查看游戏服务器日志
```bash
tail -f /var/log/gameserver.log
```

---

## 十、完整流程总结

```
用户访问 georgehive.com
       ↓
   点击"进入游戏"
       ↓
   georgehive.com/GameClient/index.html
       ↓
   BootScene 检查登录态 (fetch board.php?action=getGameProfile)
       ↓
   已登录 → LobbyScene (选择大区)
       ↓
   点击"进入" → GameScene
       ↓
   WebSocket 连接 ws://georgehive.com/ws (Nginx代理到5000)
       ↓
   游戏正常运行 🎮
```
