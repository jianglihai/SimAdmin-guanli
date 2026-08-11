#!/usr/bin/env php
<?php
/**
 * SimAdmin-guanli 后台轮询器（常驻守护）
 *
 * 每 15 秒（可用环境变量 POLLER_INTERVAL 覆盖，单位秒，最小 1）抓取所有设备的
 * API 状态（health/device/sim/network/stats/volte/dataConn/roaming/airplane/ota/sms）
 * 并写入 SQLite 缓存（device_cache 表）。
 *
 * 这样前端无论是否打开页面，读取的始终是「最近 ≤15 秒」的缓存数据，实现真正低延迟；
 * 同时轮询过程会预建立设备会话（Cookie），前端直连也更快。
 * 15s 间隔在「数据新鲜度」与「减少对设备 simadmin 接口的压力」之间取平衡。
 *
 * 注意：本脚本只【读取】设备数据，不负责任何【发送】（发短信等发送逻辑在 api.php / 前端）。
 *
 * 运行方式（推荐 systemd，见 deploy.sh 生成的 simadmin-poller.service）：
 *   php poller.php
 * 后台（无 systemd 时）：
 *   nohup php poller.php > data/poller.log 2>&1 &
 *
 * 复用 api.php 里的全部函数（fetch_device_full / db_get_devices / db_set_cache 等）。
 * api.php 已用 PHP_SAPI !== 'cli' 包裹 Web 路由，require 时不会执行 Web 分支。
 */

require_once __DIR__ . '/api.php';

// 轮询间隔（秒），可用环境变量覆盖
$interval = (int) (getenv('POLLER_INTERVAL') ?: 15);
if ($interval < 1) $interval = 1;

// 防重复实例：文件锁（进程退出时 OS 自动释放）
ensure_data_dir(__DIR__ . '/data');
$lockFile = __DIR__ . '/data/poller.pid';
$fp = @fopen($lockFile, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "另一个 poller 实例已在运行（pid 见 data/poller.pid），退出。\n");
    exit(1);
}
fwrite($fp, (string) getmypid());
fflush($fp);

/**
 * 执行一轮抓取：遍历全部设备，逐台 fetch_device_full 写库。
 * 单台异常不影响其他设备，仅记到 STDERR。
 */
function poller_run_once() {
    $devs = [];
    try {
        $devs = db_get_devices();
    } catch (Exception $e) {
        fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] 读取设备列表失败: " . $e->getMessage() . "\n");
        return;
    }
    foreach ($devs as $d) {
        try {
            $data = fetch_device_full($d['url']);
            db_set_cache($d['id'], $data);
        } catch (Exception $e) {
            fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] 设备 {$d['name']} ({$d['url']}) 抓取失败: " . $e->getMessage() . "\n");
        }
    }
}

$devCount = count(db_get_devices());
fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] poller 启动 (间隔 {$interval}s)，监控 {$devCount} 台设备\n");

// 启动立即抓一次，避免首屏空白
poller_run_once();

while (true) {
    sleep($interval);
    poller_run_once();
}
