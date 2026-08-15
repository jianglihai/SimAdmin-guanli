#!/usr/bin/env bash
#
# SimAdmin-guanli 一键部署脚本 (PHP 版多设备 SimAdmin 管理面板)
#
# 用法:
#   1) 已克隆仓库后，本地部署:
#        bash deploy.sh [--port 5000] [--dir /opt/simadmin-guanli]
#   2) GitHub 一键拉取并部署 (无需克隆):
#        curl -fsSL https://raw.githubusercontent.com/jianglihai/SimAdmin-guanli/main/deploy.sh | bash
#
# 参数:
#   --port <整数>      监听端口，默认 5000
#   --dir  <路径>      安装目录，默认 /opt/simadmin-guanli
#   --no-systemd       不使用 systemd，改用 php 内置服务器后台运行
#   -h | --help        显示本帮助
#
# 说明:
#   - 自动检测并安装 PHP (>=7.4) 与 curl 扩展
#   - 自动创建 data/ 与 data/sessions/ 并收紧权限 (700)，敏感文件 0600
#   - 优先用 systemd 托管，方便开机自启与崩溃重启
#   - 部署完成后访问 http://<本机IP>:<PORT>/
#
set -euo pipefail

PORT=5000
INSTALL_DIR="/opt/simadmin-guanli"
USE_SYSTEMD=true
REPO="jianglihai/SimAdmin-guanli"
ASSET="simadmin-guanli.tar.gz"
APP_FILES=(index.html api.php poller.php)
# 当前发布版本号（构建 release 时使用；发布时打对应 vX.Y.Z tag 并上传 simadmin-guanli.tar.gz 资产）
VERSION="1.0.8"

# ----- 参数解析 -----
while [ $# -gt 0 ]; do
  case "$1" in
    --port)       PORT="$2"; shift 2;;
    --dir)        INSTALL_DIR="$2"; shift 2;;
    --no-systemd) USE_SYSTEMD=false; shift;;
    -h|--help)    sed -n '2,28p' "$0" | sed 's/^# \{0,1\}//'; exit 0;;
    *)            echo "未知参数: $1" >&2; exit 1;;
  esac
done

# 颜色输出
if [ -t 1 ]; then C_G='\033[32m'; C_R='\033[31m'; C_Y='\033[33m'; C_N='\033[0m'; else C_G=''; C_R=''; C_Y=''; C_N=''; fi
log(){ printf "${C_G}[deploy]${C_N} %s\n" "$*"; }
warn(){ printf "${C_Y}[warn]${C_N} %s\n" "$*" >&2; }
err(){ printf "${C_R}[error]${C_N} %s\n" "$*" >&2; exit 1; }

# root 则不加 sudo
if [ "$(id -u)" = "0" ]; then SUDO=""; else SUDO="sudo"; fi

# ----- 1) 定位源码 -----
log "部署版本: v${VERSION}"
SRC=""
if [ -f "${APP_FILES[0]}" ] && [ -f "${APP_FILES[1]}" ]; then
  SRC="$(pwd)"
  log "使用本地源码: $SRC"
else
  log "本地未找到应用文件，拉取最新发布版本 ..."
  TMP="$(mktemp -d)"
  SRC_ZIP=0
  # 1) 优先用 release 资产重定向地址（不依赖 GitHub REST API，避免未认证限流 403）
  if curl -fsSL -L -o "$TMP/src.tar.gz" "https://github.com/${REPO}/releases/latest/download/${ASSET}"; then
    log "已从最新 release 下载资产: ${ASSET}"
  # 2) 兜底：REST API 的 tarball（仍可能被限流）
  elif URL="$(curl -fsSL "https://api.github.com/repos/${REPO}/releases/latest" | grep -m1 '"tarball_url"' | sed -E 's/.*"tarball_url": *"([^"]+)".*/\1/')" && [ -n "${URL:-}" ]; then
    curl -fsSL -L -o "$TMP/src.tar.gz" "$URL"
    log "已从 REST API 下载最新 release tarball"
  # 3) 最终兜底：main 分支源码包
  else
    warn "无法获取 release，改用 main 分支源码包"
    curl -fsSL -L -o "$TMP/src.zip" "https://codeload.github.com/${REPO}/zip/refs/heads/main"
    SRC_ZIP=1
  fi
  command -v unzip >/dev/null 2>&1 || $SUDO apt-get install -y unzip >/dev/null 2>&1 || true
  if [ "$SRC_ZIP" = "1" ]; then
    unzip -o -q "$TMP/src.zip" -d "$TMP"
  else
    tar -xzmf "$TMP/src.tar.gz" -C "$TMP"
  fi
  SRC="$(find "$TMP" -maxdepth 2 -name index.html | head -1 | xargs dirname)"
  [ -n "$SRC" ] || err "解压后未找到 index.html"
  log "已下载到: $SRC"
fi

# ----- 2) 确保 PHP + curl -----
if ! command -v php >/dev/null 2>&1; then
  log "未检测到 PHP，开始安装 ..."
  if command -v apt-get >/dev/null 2>&1; then
    $SUDO apt-get update -y
    $SUDO apt-get install -y php-cli php-curl php-sqlite3 unzip
  elif command -v dnf >/dev/null 2>&1; then
    $SUDO dnf install -y php-cli php-common php-pdo php-sqlite3 unzip
  elif command -v yum >/dev/null 2>&1; then
    $SUDO yum install -y php-cli php-common php-pdo php-sqlite3 unzip
  elif command -v apk >/dev/null 2>&1; then
    $SUDO apk add php-cli php-curl php-sqlite3 unzip
  else
    err "无法自动安装 PHP，请手动安装 php-cli 及 curl/sqlite3 扩展"
  fi
fi
PHP_BIN="$(command -v php)"
log "PHP: $($PHP_BIN -v | head -1)"
if ! $PHP_BIN -m | grep -qi curl; then
  err "PHP 缺少 curl 扩展，请安装 php-curl 后重试"
fi
if ! $PHP_BIN -m | grep -qiE 'sqlite3|pdo_sqlite'; then
  err "PHP 缺少 SQLite 扩展，请安装 php-sqlite3 后重试"
fi

# ----- 3) 安装到目标目录 -----
log "安装到 $INSTALL_DIR"
$SUDO mkdir -p "$INSTALL_DIR"
$SUDO cp -r "$SRC"/. "$INSTALL_DIR"/
$SUDO mkdir -p "$INSTALL_DIR/data/sessions"
$SUDO chmod -R 755 "$INSTALL_DIR"
$SUDO chmod 700 "$INSTALL_DIR/data"
$SUDO chmod 700 "$INSTALL_DIR/data/sessions"
# 敏感文件若已存在则收紧权限
[ -f "$INSTALL_DIR/data/config.json" ] && $SUDO chmod 600 "$INSTALL_DIR/data/config.json" || true
[ -f "$INSTALL_DIR/data/devices.json" ] && $SUDO chmod 600 "$INSTALL_DIR/data/devices.json" || true

# ----- 4) 启动服务 -----
if $USE_SYSTEMD && command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ]; then
  UNIT="/etc/systemd/system/simadmin-guanli.service"
  log "写入 systemd 单元: $UNIT"
  cat > "$UNIT" <<EOF
[Unit]
Description=SimAdmin-guanli PHP Manager
After=network.target

[Service]
Type=simple
WorkingDirectory=${INSTALL_DIR}
ExecStart=${PHP_BIN} -S 0.0.0.0:${PORT} -t ${INSTALL_DIR}
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
  $SUDO systemctl daemon-reload
  $SUDO systemctl enable --now simadmin-guanli
  log "已通过 systemd 启动 (端口 ${PORT})"
  # 后台轮询器：每 5 秒刷新缓存，保证前端低延迟（不依赖页面是否打开）
  POLLER_UNIT="/etc/systemd/system/simadmin-poller.service"
  cat > "$POLLER_UNIT" <<EOF
[Unit]
Description=SimAdmin-guanli Background Poller (5s cache refresh)
After=network.target

[Service]
Type=simple
WorkingDirectory=${INSTALL_DIR}
ExecStart=${PHP_BIN} ${INSTALL_DIR}/poller.php
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
  $SUDO systemctl daemon-reload
  $SUDO systemctl enable --now simadmin-poller 2>/dev/null || true
  $SUDO systemctl restart simadmin-poller 2>/dev/null || true
  log "已启动后台轮询器 simadmin-poller (每 5 秒刷新缓存)"
else
  warn "未使用 systemd，改用 php 内置服务器后台运行"
  nohup "$PHP_BIN" -S "0.0.0.0:${PORT}" -t "$INSTALL_DIR" >/tmp/simadmin-guanli.log 2>&1 &
  echo $! > /tmp/simadmin-guanli.pid
  log "已在后台启动 (PID $(cat /tmp/simadmin-guanli.pid)), 日志 /tmp/simadmin-guanli.log"
  # 后台轮询器（无 systemd 时）
  nohup "$PHP_BIN" "$INSTALL_DIR/poller.php" >"$INSTALL_DIR/data/poller.log" 2>&1 &
  echo $! > /tmp/simadmin-poller.pid
  log "已后台启动轮询器 (PID $(cat /tmp/simadmin-poller.pid)), 日志 $INSTALL_DIR/data/poller.log"
fi

# ----- 5) 自检 -----
sleep 2
if curl -fsS -m 5 "http://127.0.0.1:${PORT}/" -o /dev/null; then
  log "部署成功! 访问: http://<本机IP>:${PORT}/"
  log "首次打开会要求设置主密码 (8-64 位, 含两类字符)"
else
  err "自检失败，请检查日志: journalctl -u simadmin-guanli 或 /tmp/simadmin-guanli.log"
fi
