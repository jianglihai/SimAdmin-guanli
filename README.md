# 📡 SimAdmin 多设备管理（PHP 版）

一个运行在 **宝塔面板 / 任意 PHP 环境** 的多设备管理控制台，用于**聚合管理多台部署了 SimAdmin 的设备**。

## 功能特性

- **多设备聚合监控**：一台页面管理所有 SimAdmin 设备，卡片展示：
  - 固件版本、型号 / IMEI、SIM 卡、运营商
  - 信号强度、注册状态（中文）、数据连接、漫游 / 飞行模式
  - **VoLTE 状态**（成功=绿 / 启动中=黄 / 失败=红 / 固件不支持=灰）
  - 运行时长、温度、CPU / 内存
  - **OTA 待更新横幅**（有更新时提示）
- **多端同步**：设备配置存服务器 `data/devices.json`，任何浏览器/手机访问同一页面看到同一份配置，增删改实时同步
- **短信中心**：
  - 每台设备「💬 短信」入口，聊天式气泡视图（最新在底部）
  - **未读提醒**：有新短信时按钮变红显示数字，点击查看自动已读（已读状态跨端同步）
  - **回复短信**：直接发送，Enter 快捷发送
  - 时间智能显示（今天→`17:31`，昨天→`昨天 09:00`）
- **会话缓存**：代理自动登录设备（HttpOnly Cookie 缓存 30 分钟），前端无需处理认证
- **主密码保护**：首次部署设置访问密码，所有接口鉴权；错误密码拒绝进入且弹窗锁定；未设置时设备列表自动隐藏密码字段
- **自动刷新**：设备状态每 30 秒自动刷新（页面隐藏时暂停，静默局部更新不闪屏），离线设备及时发现；短信每 10 秒检测未读
- **会话自动重登**：设备会话缺失/过期时自动用配置密码重登，免手动刷新
- **安全存储**：主密码以 bcrypt 哈希存储（`data/config.json`），设备密码与会话 Cookie 文件权限 0600，`data/` 目录 gitignore 排除
- **白天/黑夜主题**：顶栏一键切换（localStorage 记忆），0.4s 平滑过渡，尊重系统「减少动画」偏好
- **网站图标**：内联 SVG 信号塔 favicon（无需额外文件）

## 为什么需要代理

SimAdmin 后端认证仅支持 **HttpOnly Cookie**（`SameSite=Lax`），浏览器前端 JS 无法读取/携带跨域 Cookie，纯前端直连设备 API 会因未认证被拒。

本项目通过 **PHP 代理**（`api.php`）中转：
1. 接收前端请求（设备地址 + 密码）
2. 用 cURL 向设备 `POST /api/auth/login`，捕获 `Set-Cookie`
3. 会话 Cookie 缓存到 `data/sessions/`（30 分钟有效）
4. 后续设备 API 请求自动附带 Cookie 转发

## 架构

```
浏览器 (index.html)
      │  同域请求
      ▼
宝塔 PHP 网站 (api.php 代理)
      │  curl + 会话 Cookie
      ▼
各设备 SimAdmin API (http://设备IP:3000)
```

## 目录结构

```
wifiguanli/
├── index.html      # 前端控制台（设备卡片、状态、VoLTE、短信、主题切换）
├── api.php         # PHP 代理接口（登录、转发、会话缓存、配置存储）
├── data/           # 运行时数据（自动创建，勿提交到 git）
│   ├── config.json     # 主密码（bcrypt 哈希）
│   ├── devices.json    # 设备配置（多端同步）
│   ├── sms_seen.json   # 短信已读记录（跨端同步）
│   └── sessions/       # 设备会话 Cookie 缓存
└── README.md
```

## 部署（宝塔面板）

### 1. 上传文件
将 `index.html`、`api.php` 上传到宝塔网站根目录（如 `/www/wwwroot/simadmin-manager/`）。

### 2. 创建 data 目录
```bash
cd /www/wwwroot/simadmin-manager
mkdir -p data/sessions
chmod -R 755 data
```
> ⚠️ 需确保 PHP 进程对 `data/sessions/` 有**写权限**（www 用户）。

### 3. 配置站点
- 宝塔 → 网站 → 添加站点（或使用已有站点）
- 网站目录指向 `simadmin-manager/`
- PHP 版本 ≥ 7.4（推荐 8.x），需启用 **curl 扩展**（宝塔默认开启）
- 伪静态无需特殊配置（纯 PHP + 静态文件）

### 4. 访问
浏览器打开 `http://你的域名或IP/` 即可。

## 一键部署（脚本）

无需宝塔，使用官方一键脚本即可在任意 Debian / Ubuntu（含其他 systemd Linux）服务器上一键拉起服务。脚本会自动：安装 PHP + curl 扩展 → 拉取源码（本地或最新 release）→ 创建 `data/` 与 `data/sessions/` 并收紧权限 → 用 systemd 托管（或 php 内置服务器后台运行）→ 启动后自检。

### 方式 A：已克隆仓库
```bash
bash deploy.sh                 # 默认端口 5000，安装到 /opt/simadmin-guanli
bash deploy.sh --port 9000 --dir /www/simadmin-guanli   # 自定义端口与目录
```

### 方式 B：GitHub 一键（无需克隆）
```bash
curl -fsSL https://raw.githubusercontent.com/jianglihai/SimAdmin-guanli/main/deploy.sh | bash
```

部署完成后打开 `http://<服务器IP>:<端口>/`，首次访问会要求设置主密码（8–64 位，含两类字符）。

> 可选参数：`--port <端口>`、`--dir <安装目录>`、`--no-systemd`（改用 php 内置服务器后台运行）。

## 使用

### 首次部署：设置主密码
打开页面后弹出「首次设置访问密码」——设置后所有接口需要此密码鉴权（sessionStorage 保存，关闭标签页后需重新输入）。

### 添加设备
1. 点击「＋ 添加设备」
2. 输入：
   - **设备名称**：如 `客厅随身WiFi`
   - **设备 IP**：如 `192.168.68.1`（自动补全为 `http://192.168.68.1:3000`，可带端口）
   - **管理员密码**：SimAdmin 的管理员密码
3. 点击「添加」，自动连接并拉取状态

> 设备信息（含密码）保存在**服务器** `data/devices.json`，多端访问共享同一份配置。

### 短信与未读提醒
- 设备卡片「💬 短信」按钮 → 打开短信面板（聊天视图，最新在底部）
- **未读提醒**：设备收到新短信 → 按钮变红显示数字（每 10 秒自动检测；已读状态跨端同步）
- 回复：输入号码 + 内容 → 发送（Enter 快捷发送）
- 打开短信面板即视为已读

### 主题切换
顶栏「🌙 夜间 / ☀️ 白天」按钮一键切换主题，选择保存在浏览器 localStorage，下次打开自动恢复。

### 模拟新短信（体验用）
访问 `index.html?reset=1` 可清空已读记录，用于体验未读提醒效果（不影响真实数据）。

## API 接口

| 接口 | 方法 | 参数 | 说明 |
|---|---|---|---|
| `api.php?action=setup` | POST | `{access_password}` | 首次设置主密码（仅未设置时可用） |
| `api.php?action=login` | POST | `{url, password}` | 登录设备，缓存会话 |
| `api.php?action=logout` | POST | `{url}` | 清除会话缓存 |
| `api.php?action=status` | GET | `url, path` | 转发设备 GET 请求（如 `/api/health`） |
| `api.php?action=action` | POST | `{url, path, body}` | 转发设备 POST 请求（如 `/api/sms/send`） |
| `api.php?action=devices` | GET/POST | — | 读取 / 保存设备配置（服务器端同步） |
| `api.php?action=sms-seen` | GET/POST | — | 读取 / 合并保存短信已读记录 |
| `api.php?action=health` | GET | — | 代理自身健康检查 |

> 除 `setup` / `health` 外，所有接口需携带 `access_password` 主密码参数（GET 用查询参数，POST 用 body 字段）。

> `path` 参数仅允许 `/api/` 开头的路径；`url` 仅允许 `http://` / `https://` 开头（防 SSRF）。

## 安全说明

- **主密码保护**：所有接口需携带 `access_password`（除 setup/health）；错误密码拒绝进入，主密码弹窗锁定（Esc/点遮罩/页面按钮均无效）
- 设备密码存于服务器 `data/devices.json`（文件权限 0600，仅属主可读写）；`data/` 目录已被 gitignore 排除，不会随代码推送
- 会话 Cookie 缓存于服务器 `data/sessions/`（文件权限 0600，仅属主可读写）
- 主密码以 bcrypt 哈希存储（`data/config.json`），文件泄露也无法还原明文
- **SSRF 防护**：`api.php` 校验设备地址协议（仅 http/https），拒绝 file://、ftp:// 等
- **路径注入防护**：转发路径仅允许 `/api/` 开头
- **会话自动重登**：会话缺失/过期时自动用设备配置密码重登（不依赖手动刷新）

## 注意事项

- 设备 SimAdmin 需已设置管理员密码（`/api/auth/setup` 或 CLI）
- 信号强度为百分比（0-100），来自 `/api/network` 的 `signal_strength`
- VoLTE 状态来自 `/api/volte/control`（新版 SimAdmin 才有，旧版显示"固件不支持"）
- 若设备端口非 3000，在 IP 后加端口即可：`192.168.68.1:8080`
