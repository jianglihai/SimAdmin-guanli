<?php
/**
 * SimAdmin 多设备管理 - PHP 代理接口（宝塔/PHP 环境）
 *
 * 为什么需要代理：
 * SimAdmin 后端认证仅支持 HttpOnly Cookie（SameSite=Lax），前端 JS 无法读取/携带
 * 跨域 Cookie。此 PHP 代理作为中间层：
 *   1. 接收前端 /api/login 请求（设备地址 + 密码）
 *   2. curl 向设备 POST /api/auth/login，捕获 Set-Cookie
 *   3. 缓存会话 Cookie 到本地文件（data/sessions/）
 *   4. 转发设备 API 响应回前端
 *
 * 接口：
 *   POST api.php?action=login    { url, password }        登录设备（缓存会话）
 *   POST api.php?action=logout   { url }                  清除会话缓存
 *   GET  api.php?action=status   { url, path }            读设备接口（GET）
 *   POST api.php?action=action   { url, path, body }      设备操作（POST）
 *   GET  api.php?action=health                            代理自身健康检查
 *   GET  api.php?action=devices                             读取设备列表（数据库）
 *   POST api.php?action=devices  { devices:[...] }        保存设备列表（数据库，密码可空）
 *   POST api.php?action=refresh                            抓取全部设备数据并写入数据库
 *   POST api.php?action=data     { ttl }                  低延迟读取缓存（过期则回源；ttl=0 仅读缓存）
 *
 * 要求：PHP curl 扩展、allow_url_fopen 或 curl 均可。
 */

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

// ---------- 配置 ----------
define('SESSION_DIR', __DIR__ . '/data/sessions');
define('DEVICES_FILE', __DIR__ . '/data/devices.json');
define('SMS_SEEN_FILE', __DIR__ . '/data/sms_seen.json');
define('CONFIG_FILE', __DIR__ . '/data/config.json');
define('DB_FILE', __DIR__ . '/data/simadmin.db');
define('SESSION_TTL', 1800); // 会话缓存 30 分钟
define('REQUEST_TIMEOUT', 15);

// ---------- 数据库（SQLite） ----------
// 设备配置 + API 抓取缓存统一存库，前端读库即可拿到低延迟数据，
// 无需每次轮询都回源打设备。
function db() {
    static $pdo = null;
    if ($pdo === null) {
        if (!extension_loaded('pdo_sqlite') && !extension_loaded('sqlite3')) {
            throw new Exception('PHP 缺少 SQLite 扩展（pdo_sqlite / sqlite3），无法启用数据库缓存；请在服务器安装 php-sqlite3');
        }
        ensure_data_dir(dirname(DB_FILE));
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        init_db_schema($pdo);
        migrate_devices_from_json();
        @chmod(DB_FILE, 0600);
    }
    return $pdo;
}

function init_db_schema($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS devices (
        id        TEXT PRIMARY KEY,
        name      TEXT NOT NULL,
        url       TEXT NOT NULL,
        password  TEXT,
        no_auth   INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS device_cache (
        device_id  TEXT PRIMARY KEY,
        data_json  TEXT NOT NULL,
        updated_at INTEGER NOT NULL
    )");
}

/** 读取全部设备（含密码明文，供后端使用） */
function db_get_devices() {
    $rows = db()->query("SELECT id, name, url, password, no_auth FROM devices ORDER BY created_at ASC")->fetchAll();
    return array_map(function ($r) {
        return [
            'id'       => $r['id'],
            'name'     => $r['name'],
            'url'      => $r['url'],
            'password' => $r['password'] ?? '',
            'no_auth'  => (int) $r['no_auth'],
        ];
    }, $rows);
}

/**
 * 覆盖式保存设备列表。
 * 若某设备未携带密码（开放模式前端拿不到密码），则保留库中已有密码，避免误清空。
 */
function db_save_devices($list) {
    $pdo = db();
    $existing = [];
    foreach ($pdo->query("SELECT id, password FROM devices")->fetchAll() as $r) {
        $existing[$r['id']] = $r['password'] ?? '';
    }
    $pdo->beginTransaction();
    $pdo->exec("DELETE FROM devices");
    $stmt = $pdo->prepare("INSERT INTO devices (id, name, url, password, no_auth, created_at) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($list as $d) {
        $pw = isset($d['password']) ? (string) $d['password'] : '';
        if ($pw === '' && isset($existing[$d['id']]) && $existing[$d['id']] !== '') {
            $pw = $existing[$d['id']]; // 前端未提供密码时沿用库内已有密码
        }
        $noAuth = ($pw === '') ? 1 : 0;
        $stmt->execute([$d['id'], $d['name'], $d['url'], $pw, $noAuth, time()]);
    }
    $pdo->commit();
    // 清理已删除设备的孤儿缓存：device_cache 不随 devices 表级联删除，
    // 反复删除+重加会产生大量无效缓存行，且可能干扰前端读取（见前端按 url 查找修复）
    $pdo->exec("DELETE FROM device_cache WHERE device_id NOT IN (SELECT id FROM devices)");
}

/** 按设备 URL 查密码（用于代理自动重登 / 判断是否需要鉴权） */
function db_find_password($url) {
    $norm = rtrim($url, '/');
    $stmt = db()->prepare("SELECT password FROM devices WHERE url = ?");
    $stmt->execute([$norm]);
    $row = $stmt->fetch();
    return $row ? ($row['password'] ?? '') : null;
}

/**
 * 首次启动兼容：若数据库 devices 表为空，但旧版 data/devices.json 存在，
 * 则把其中的设备一次性导入数据库（保留密码与 URL）。导入后旧文件仍保留，不删除。
 */
function migrate_devices_from_json() {
    $count = (int) db()->query("SELECT COUNT(*) FROM devices")->fetchColumn();
    if ($count > 0) return;
    if (!file_exists(DEVICES_FILE)) return;
    $list = json_decode(file_get_contents(DEVICES_FILE), true);
    if (!is_array($list) || empty($list)) return;
    $stmt = db()->prepare("INSERT OR IGNORE INTO devices (id, name, url, password, no_auth, created_at) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($list as $d) {
        if (!is_array($d)) continue;
        $url = rtrim(trim($d['url'] ?? ''), '/');
        $name = trim($d['name'] ?? '');
        if ($url === '' || $name === '') continue;
        $pw = isset($d['password']) ? (string) $d['password'] : '';
        $noAuth = ($pw === '') ? 1 : 0;
        $stmt->execute([$d['id'] ?? md5($url), $name, $url, $pw, $noAuth, time()]);
    }
}

/** 读取设备缓存（来自数据库，低延迟） */
function db_get_cache($deviceId) {
    $stmt = db()->prepare("SELECT data_json, updated_at FROM device_cache WHERE device_id = ?");
    $stmt->execute([$deviceId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $data = json_decode($row['data_json'], true);
    return ['data' => is_array($data) ? $data : null, 'updated_at' => (int) $row['updated_at']];
}

/** 写入设备缓存（API 抓取结果落库） */
function db_set_cache($deviceId, $data) {
    $stmt = db()->prepare("INSERT OR REPLACE INTO device_cache (device_id, data_json, updated_at) VALUES (?, ?, ?)");
    $stmt->execute([$deviceId, json_encode($data, JSON_UNESCAPED_UNICODE), time()]);
}

/** 删除设备缓存（设备离线时调用，库内不再残留离线设备数据 —— 方案 B） */
function db_delete_cache($deviceId) {
    $stmt = db()->prepare("DELETE FROM device_cache WHERE device_id = ?");
    $stmt->execute([$deviceId]);
}

// ---------- 主密码保护 ----------
// 首次访问时若未设置主密码，允许 setup 接口设置；设置后所有接口需带主密码
function get_access_password() {
    if (!file_exists(CONFIG_FILE)) return '';
    $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
    return isset($cfg['access_password']) ? $cfg['access_password'] : '';
}

function access_password_set() {
    return get_access_password() !== '';
}

function check_access($provided) {
    $stored = get_access_password();
    if ($stored === '') return true; // 未设置主密码，暂不校验（首次部署）
    // 兼容旧明文：若存储的是明文（非哈希）则直接比较，否则用 password_verify
    if (is_string($provided)) {
        if (strpos($stored, '$2y$') === 0 || strpos($stored, '$argon2') === 0) {
            return password_verify($provided, $stored);
        }
        return hash_equals($stored, $provided);
    }
    return false;
}

// 从请求中提取主密码：POST body 的 access_password 或 GET 的 access_password
function extract_access_password() {
    if (isset($_POST['access_password'])) return $_POST['access_password'];
    if (isset($_GET['access_password'])) return $_GET['access_password'];
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    return is_array($json) && isset($json['access_password']) ? $json['access_password'] : null;
}

// 从设备列表响应中剔除密码字段（防止泄露）
function strip_passwords($devices) {
    return array_map(function ($d) {
        unset($d['password']);
        return $d;
    }, $devices);
}

// ---------- 工具 ----------
function json_out($status, $message, $data = null) {
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** 确保 data 目录存在（返回目录路径） */
function ensure_data_dir($dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/** 写 JSON 文件（自动建目录 + 权限收紧） */
function write_json_file($file, $data, $flags = 0) {
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0755, true);
    }
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | $flags), LOCK_EX);
    @chmod($file, 0600); // 含敏感数据，仅属主可读写
}

function read_body() {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

/**
 * 校验设备地址：仅允许 http/https，防止代理被用作 SSRF 跳板
 */
function validate_url($url) {
    $url = rtrim(trim($url), '/');
    if ($url === '') return '';
    if (!preg_match('#^https?://#i', $url)) {
        throw new Exception('设备地址需以 http:// 或 https:// 开头');
    }
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        throw new Exception('设备地址格式不正确');
    }
    return $url;
}

/**
 * 多字节安全截断（不依赖 mbstring 扩展）
 */
function mb_cut($str, $max) {
    if (function_exists('mb_substr')) {
        return mb_substr($str, 0, $max, 'UTF-8');
    }
    $len = 0;
    $out = '';
    $chars = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chars)) return mb_substr_fallback($str, $max); // 非法 UTF-8 兜底
    foreach ($chars as $c) {
        if ($len >= $max) break;
        $out .= $c;
        $len++;
    }
    return $out;
}

/**
 * 非法 UTF-8 时的字节级截断兜底
 */
function mb_substr_fallback($str, $max) {
    return strlen($str) > $max ? substr($str, 0, $max) : $str;
}

function session_file($url) {
    return SESSION_DIR . '/' . md5($url) . '.json';
}

function get_session_cookie($url) {
    $file = session_file($url);
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    if (!$data || time() > $data['expires']) {
        @unlink($file);
        return null;
    }
    return $data['cookie'];
}

function save_session_cookie($url, $cookie) {
    ensure_data_dir(SESSION_DIR);
    $file = session_file($url);
    write_json_file($file, [
        'cookie' => $cookie,
        'expires' => time() + SESSION_TTL,
    ]);
}

function clear_session($url) {
    $file = session_file($url);
    if (file_exists($file)) @unlink($file);
}

/**
 * 向设备发起 HTTP 请求，返回 [status, headers, body]
 * 优先 curl 扩展，缺失时回退 file_get_contents（需 allow_url_fopen）
 */
function device_request($url, $method = 'GET', $headers = [], $body = null) {
    if (function_exists('curl_init')) {
        return device_request_curl($url, $method, $headers, $body);
    }
    return device_request_stream($url, $method, $headers, $body);
}

function device_request_curl($url, $method, $headers, $body) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => REQUEST_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADER => true,          // 需要捕获 Set-Cookie
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('连接设备失败: ' . $err);
    }
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerText = substr($raw, 0, $headerSize);
    $respBody = substr($raw, $headerSize);
    return parse_response($headerText, $respBody);
}

function device_request_stream($url, $method, $headers, $body) {
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body !== null ? $body : '',
            'timeout' => REQUEST_TIMEOUT,
            'ignore_errors' => true,
            'follow_location' => true,
        ],
    ]);
    $respBody = @file_get_contents($url, false, $ctx);
    if ($respBody === false) {
        throw new Exception('连接设备失败: 无法访问 ' . $url);
    }
    $headerText = '';
    if (isset($http_response_header) && is_array($http_response_header)) {
        $headerText = implode("\r\n", $http_response_header);
    }
    return parse_response($headerText, $respBody);
}

function parse_response($headerText, $respBody) {
    $headers = [];
    foreach (explode("\r\n", $headerText) as $line) {
        if (strpos($line, ':') === false) continue;
        list($k, $v) = explode(':', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strtolower($k) === 'set-cookie') {
            $headers['Set-Cookie'][] = $v;
        } else {
            $headers[$k] = $v;
        }
    }
    $statusCode = 0;
    if (preg_match('#HTTP/\S+\s+(\d+)#', $headerText, $m)) {
        $statusCode = (int) $m[1];
    }
    return [$statusCode, $headers, $respBody];
}

/**
 * 登录设备：POST /api/auth/login，捕获会话 Cookie 并缓存
 */
function login_device($url, $password) {
    // 已有有效会话则直接返回
    if (get_session_cookie($url)) {
        return ['ok' => true, 'cached' => true];
    }

    $payload = json_encode(['password' => $password], JSON_UNESCAPED_UNICODE);
    list($status, $headers, $body) = device_request(
        $url . '/api/auth/login',
        'POST',
        ['Content-Type: application/json'],
        $payload
    );

    $parsed = json_decode($body, true);
    if ($status !== 200 || !$parsed || ($parsed['status'] ?? '') !== 'ok') {
        $msg = isset($parsed['message']) ? $parsed['message'] : ("登录失败 (HTTP " . $status . ")");
        throw new Exception($msg);
    }

    // 提取会话 Cookie（SimAdmin 的会话 Cookie 名为 simadmin_session）
    $cookieParts = [];
    $setCookies = isset($headers['Set-Cookie']) ? $headers['Set-Cookie'] : [];
    foreach ($setCookies as $sc) {
        $pair = explode(';', $sc)[0];
        if (strpos($pair, '=') !== false) {
            $name = explode('=', $pair)[0];
            if ($name === 'simadmin_session' || strpos($name, 'session') !== false) {
                $cookieParts[] = $pair;
            }
        }
    }
    if (!$cookieParts) {
        throw new Exception('登录响应未包含会话 Cookie');
    }
    save_session_cookie($url, implode('; ', $cookieParts));
    return ['ok' => true, 'cached' => false];
}

/**
 * 从设备配置（数据库）中查找该设备的密码（用于会话自动重登 / 判断是否需要鉴权）
 * 返回字符串：非空=需要密码验证；空字符串=该设备未开启密码验证（no_auth）
 */
function find_device_password($url) {
    try {
        return db_find_password($url);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * 转发设备 API 请求。
 * - 设备配置了密码：用会话 Cookie 鉴权；缺失/失效时自动重登一次。
 * - 设备未配置密码（no_auth）：直接请求，不带 Cookie；若设备实际要求鉴权(401/403)
 *   则给出清晰提示，不报"会话未建立"。
 */
function forward_device($url, $path, $method, $body = null) {
    $password = find_device_password($url); // string（可能为空）或 null
    $needsAuth = is_string($password) && $password !== '';

    $cookie = null;
    if ($needsAuth) {
        $cookie = get_session_cookie($url);
        if (!$cookie) {
            try {
                login_device($url, $password);
                $cookie = get_session_cookie($url);
            } catch (Exception $e) { $cookie = null; }
        }
    }

    $headers = [];
    if ($cookie) $headers[] = 'Cookie: ' . $cookie;
    $payload = null;
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
    }

    list($status, $_unused, $respBody) = device_request(
        $url . $path,
        $method,
        $headers,
        $payload
    );

    // 未开启密码验证的设备：若实际返回 401/403，说明该设备其实需要密码
    if (!$needsAuth) {
        if ($status === 401 || $status === 403) {
            throw new Exception('该设备返回 401，需要密码验证，请在「管理设备」中补充设备密码');
        }
        return [$status, $respBody];
    }

    // 需要密码的设备：会话过期时自动重登一次
    if (($status === 401 || $status === 403) && $method === 'GET') {
        try {
            clear_session($url);
            login_device($url, $password);
            $newCookie = get_session_cookie($url);
            if ($newCookie) {
                list($status, $_unused, $respBody) = device_request(
                    $url . $path,
                    $method,
                    ['Cookie: ' . $newCookie] + $headers,
                    $payload
                );
            }
        } catch (Exception $e) { /* 重登失败则返回原始 401 */ }
    }
    return [$status, $respBody];
}

/**
 * 从单台设备抓取全部状态接口（同前端 fetchDevice 的字段集合），结果直接落库。
 * 任一接口失败不影响其余；整体不可达时抛异常由调用方记录。
 */
function fetch_device_full($url) {
    $get = function ($path) use ($url) {
        list($status, $body) = forward_device($url, $path, 'GET');
        $parsed = json_decode($body, true);
        return ($status === 200 && is_array($parsed)) ? $parsed : null;
    };
    // SimAdmin 设备每个接口返回 {status,message,data:{...}}；
    // 前端按扁平结构读取（network.signal_strength / sim.present / device.model / stats.cpu_load ...），
    // 故此处抽取各分区的 .data 落库（health 无 .data 包裹，保持原样以便取 version）。
    $flat = function ($x) {
        return (is_array($x) && isset($x['data']) && is_array($x['data'])) ? $x['data'] : $x;
    };
    $health   = $get('/api/health');
    $device   = $flat($get('/api/device'));
    $sim      = $flat($get('/api/sim'));
    $network  = $flat($get('/api/network'));
    $stats    = $flat($get('/api/stats'));
    $volte    = null; try { $volte    = $flat($get('/api/volte/control')); }    catch (Exception $e) {}
    $dataConn = null; try { $dataConn = $flat($get('/api/data')); }             catch (Exception $e) {}
    $roaming  = null; try { $roaming  = $flat($get('/api/roaming')); }          catch (Exception $e) {}
    $airplane = null; try { $airplane = $flat($get('/api/airplane-mode')); }    catch (Exception $e) {}
    $ota      = null; try { $ota      = $flat($get('/api/ota/status')); }       catch (Exception $e) {}
    // 短信列表：/api/sms/list 返回 {status,message,data:{messages:[...]}}；
    // 抽 messages 存入缓存，前端即可低延迟读取（发送逻辑不在此处）。
    $smsRaw = null;
    try { $smsRaw = $get('/api/sms/list?limit=50&offset=0'); } catch (Exception $e) {}
    $smsMsgs = [];
    if (is_array($smsRaw)) {
        $cand = (isset($smsRaw['data']) && is_array($smsRaw['data'])) ? $smsRaw['data'] : $smsRaw;
        if (isset($cand['messages']) && is_array($cand['messages'])) {
            $smsMsgs = $cand['messages'];
        } elseif (isset($smsRaw['messages']) && is_array($smsRaw['messages'])) {
            $smsMsgs = $smsRaw['messages'];
        } elseif (!empty($cand) && array_keys($cand) === range(0, count($cand) - 1)) {
            $smsMsgs = $cand; // $cand 本身就是消息数组
        }
    }
    return [
        'health'   => $health,
        'device'   => $device,
        'sim'      => $sim,
        'network'  => $network,
        'stats'    => $stats,
        'volte'    => $volte,
        'dataConn' => $dataConn,
        'roaming'  => $roaming,
        'airplane' => $airplane,
        'ota'      => $ota,
        'sms'      => $smsMsgs,
        'version'  => ($health && isset($health['version'])) ? $health['version'] : null,
    ];
}

// ---------- Web UI 反向代理 ----------
// 浏览器只需能访问面板；面板服务端去抓设备 Web 页面/资产/API 再回传，
// 从而规避「设备与用户浏览器不在同一网段、直连打不开」的问题。
// 设备 API 复用 forward_device（自带会话 Cookie + 自动重登），故代理后自动鉴权。

/** 将 HTML 中的资源/脚本路径改写为走面板代理（web 动作），并把 /api 基址改写为 webapi 代理 */
function proxy_web_html_rewrite($html, $encUrl, $accessPw) {
    $q = $accessPw !== '' ? '&access_password=' . rawurlencode($accessPw) : '';
    // src="/..." / href="/..." -> web 代理（跳过已是 api.php 的地址，避免二次改写）
    $html = preg_replace_callback(
        '#(src|href)\s*=\s*"(\/(?!api\.php)[^"]*)"#i',
        function ($m) use ($encUrl, $q) {
            $path = $m[2];
            return $m[1] . '="/api.php?action=web&url=' . $encUrl . $q . '&p=' . rawurlencode($path) . '"';
        },
        $html
    );
    // 内联/外链脚本里的 "/api 基址 -> webapi 代理（含 access_password，供 SPA 运行时调用）
    $html = proxy_web_asset_rewrite($html, $encUrl, $accessPw);
    return $html;
}

/** 将 JS/CSS 中的绝对 "/api 基址改写为 webapi 代理（保留后续路径） */
function proxy_web_asset_rewrite($code, $encUrl, $accessPw) {
    $q = $accessPw !== '' ? '&access_password=' . rawurlencode($accessPw) : '';
    return preg_replace(
        '#"/api(?=/|")#',
        '"/api.php?action=webapi&url=' . $encUrl . $q . '&p=/api',
        $code
    );
}

/** 代理设备 Web 页面/资产：抓取后改写路径再回传 */
function proxy_web($deviceUrl, $path, $accessPw) {
    $deviceUrl = validate_url($deviceUrl);
    // 仅允许代理「已管理的设备」，防止被当作通用 SSRF 跳板
    if (find_device_password($deviceUrl) === null) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(403);
        echo '该设备不在管理列表中，禁止代理';
        exit;
    }
    $cookie = get_session_cookie($deviceUrl);
    $headers = [];
    if ($cookie) $headers[] = 'Cookie: ' . $cookie;
    list($status, $respHeaders, $body) = device_request($deviceUrl . $path, 'GET', $headers, null);
    // 捕获设备下发的会话 Cookie，供后续 webapi（forward_device）复用
    if (!empty($respHeaders['Set-Cookie'])) {
        $parts = [];
        foreach ((array) $respHeaders['Set-Cookie'] as $sc) {
            $pair = explode(';', $sc)[0];
            if (strpos($pair, '=') !== false) {
                $n = explode('=', $pair)[0];
                if ($n === 'simadmin_session' || stripos($n, 'session') !== false) $parts[] = $pair;
            }
        }
        if ($parts) save_session_cookie($deviceUrl, implode('; ', $parts));
    }
    $ct = isset($respHeaders['Content-Type']) ? $respHeaders['Content-Type'] : 'application/octet-stream';
    $encUrl = rawurlencode($deviceUrl);
    if (stripos($ct, 'text/html') !== false) {
        $body = proxy_web_html_rewrite($body, $encUrl, $accessPw);
    } elseif (preg_match('#(javascript|css)#i', $ct)) {
        $body = proxy_web_asset_rewrite($body, $encUrl, $accessPw);
    }
    http_response_code($status);
    header('Content-Type: ' . $ct);
    header('Cache-Control: no-store');
    echo $body;
    exit;
}


// ---------- 路由（仅 Web 请求执行；CLI 被 poller.php require 时跳过） ----------
if (PHP_SAPI !== 'cli') {
$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    // setup：首次设置主密码（仅在未设置时可用）
    if ($action === 'setup') {
        $body = read_body();
        $password = isset($body['access_password']) ? (string) $body['access_password'] : '';
        if (access_password_set()) {
            json_out('error', '主密码已设置，如需修改请直接编辑 data/config.json');
        }
        if (strlen($password) < 4) {
            json_out('error', '主密码至少 4 位');
        }
        // 哈希存储（password_hash），文件泄露也无法还原明文
        write_json_file(CONFIG_FILE, ['access_password' => password_hash($password, PASSWORD_DEFAULT)]);
        json_out('ok', '主密码已设置');
    }

    // 鉴权：除 setup / health / version / data 外，所有接口需通过主密码校验
    // version 免鉴权：供前端缓存强刷校验（无需密码即可拿到部署版本号）
    // data 免鉴权：只读数据库缓存，不回源、不含敏感写入，供首屏秒显
    $isOpen = in_array($action, ['setup', 'health', 'version', 'data']);
    if (!$isOpen && !check_access(extract_access_password())) {
        json_out('error', '需要主密码（access_password）', ['need_auth' => true]);
    }

    switch ($action) {

        case 'login':
            $body = read_body();
            $url = validate_url(isset($body['url']) ? $body['url'] : '');
            $password = isset($body['password']) ? $body['password'] : '';
            if ($url === '' || $password === '') {
                json_out('error', 'url 和 password 必填');
            }
            $r = login_device($url, $password);
            json_out('ok', '登录成功', ['cached' => $r['cached']]);
            break;

        case 'logout':
            $body = read_body();
            $url = validate_url(isset($body['url']) ? $body['url'] : '');
            if ($url !== '') clear_session($url);
            json_out('ok', '已登出');
            break;

        case 'status':
            $url = validate_url(isset($_GET['url']) ? $_GET['url'] : '');
            $path = isset($_GET['path']) ? $_GET['path'] : '/api/health';
            if ($url === '') json_out('error', '缺少 url 参数');
            // 防止 path 注入：只允许 /api/ 开头
            if (strpos($path, '/api/') !== 0) json_out('error', '非法 path');
            list($status, $respBody) = forward_device($url, $path, 'GET');
            http_response_code($status);
            echo $respBody;
            exit;

        case 'action':
            $body = read_body();
            $url = validate_url(isset($body['url']) ? $body['url'] : '');
            $path = isset($body['path']) ? $body['path'] : '';
            $payload = isset($body['body']) ? $body['body'] : null;
            if ($url === '' || $path === '') json_out('error', '缺少 url 或 path');
            if (strpos($path, '/api/') !== 0) json_out('error', '非法 path');
            list($status, $respBody) = forward_device($url, $path, 'POST', $payload);
            http_response_code($status);
            echo $respBody;
            exit;

        case 'web':
            // 代理设备 Web UI 页面（SPA）：浏览器无需直连设备。
            // url 取自有管理列表的设备；access_password 用于放行并透传给 webapi 改写。
            $url = validate_url(isset($_GET['url']) ? $_GET['url'] : '');
            if ($url === '') json_out('error', '缺少 url 参数');
            $path = isset($_GET['p']) ? $_GET['p'] : '/';
            $accessPw = extract_access_password();
            proxy_web($url, $path, $accessPw);
            break;

        case 'webapi':
            // 代理设备 Web UI 的 API 调用（SPA 运行时发起），复用 forward_device 自动鉴权。
            $url = validate_url(isset($_GET['url']) ? $_GET['url'] : '');
            $path = isset($_GET['p']) ? $_GET['p'] : '';
            if ($url === '' || $path === '') json_out('error', '缺少 url 或 p');
            // 仅允许代理「已管理的设备」
            if (find_device_password($url) === null) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                echo '该设备不在管理列表中，禁止代理';
                exit;
            }
            $method = $_SERVER['REQUEST_METHOD'];
            $body = null;
            if ($method !== 'GET' && $method !== 'HEAD') {
                $raw = file_get_contents('php://input');
                $decoded = json_decode($raw, true);
                $body = is_array($decoded) ? $decoded : null;
            }
            list($status, $respBody) = forward_device($url, $path, $method, $body);
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo $respBody;
            exit;

        case 'health':
            $sessions = is_dir(SESSION_DIR) ? count(glob(SESSION_DIR . '/*.json') ?: []) : 0;
            json_out('ok', 'SimAdmin proxy running', [
                'sessions' => $sessions,
                'access_protected' => access_password_set(),
            ]);
            break;

        case 'version':
            // 版本号 = index.html 的文件修改时间。每次上传新 HTML，mtime 变化 → 版本号变化 →
            // 前端检测到 localStorage 记录与服务端不一致即自动强刷。无需手动改版本号，永不与前端漂移。
            $ver = 'build-' . @filemtime(__DIR__ . '/index.html');
            json_out('ok', 'version', ['version' => $ver]);
            break;

        case 'devices':
            // GET：读取服务器端设备配置（数据库，跨设备同步）
            //      已设置主密码（调用者已鉴权）→ 返回完整配置（含密码，多端登录需要）
            //      未设置主密码（开放状态）→ 隐藏密码兜底
            // POST：保存设备配置到数据库（覆盖式）
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                try {
                    $data = db_get_devices();
                } catch (Exception $e) {
                    $data = [];
                }
                if (!access_password_set()) {
                    $data = strip_passwords($data);
                }
                json_out('ok', '设备配置', $data);
                break;
            }
            $body = read_body();
            $list = isset($body['devices']) ? $body['devices'] : null;
            if (!is_array($list)) json_out('error', 'devices 必须为数组');
            // 清洗：只保留白名单字段，跳过非法条目（防止注入脏数据）
            $clean = [];
            foreach ($list as $d) {
                if (!is_array($d)) continue;
                try {
                    $url = validate_url(isset($d['url']) ? $d['url'] : '');
                } catch (Exception $e) {
                    continue; // 非法地址跳过该条，保留其余合法条目
                }
                $name = trim(isset($d['name']) ? (string) $d['name'] : '');
                if ($url === '' || $name === '') continue;
                $clean[] = [
                    'id' => substr(preg_replace('/[^a-zA-Z0-9_-]/', '', isset($d['id']) ? (string) $d['id'] : ''), 0, 32),
                    'name' => mb_cut($name, 50),
                    'url' => $url,
                    // 密码可空：空字符串表示设备未开启密码验证（no_auth）
                    'password' => isset($d['password']) ? (string) $d['password'] : '',
                ];
            }
            try {
                db_save_devices($clean);
            } catch (Exception $e) {
                json_out('error', '保存设备失败: ' . $e->getMessage());
            }
            json_out('ok', '已保存 ' . count($clean) . ' 台设备', ['count' => count($clean)]);
            break;

        case 'refresh':
            // 主动抓取所有设备最新数据并写入数据库（前端"手动刷新"走这里）
            $devs = [];
            try { $devs = db_get_devices(); } catch (Exception $e) {}
            $result = [];
            foreach ($devs as $d) {
                try {
                    $data = fetch_device_full($d['url']);
                    db_set_cache($d['id'], $data);
                    $result[$d['id']] = [
                        'ok'         => true,
                        'data'       => $data,
                        'updated_at' => time(),
                    ];
                } catch (Exception $e) {
                    $result[$d['id']] = ['ok' => false, 'error' => $e->getMessage()];
                }
            }
            json_out('ok', '已刷新 ' . count($devs) . ' 台设备', $result);
            break;

        case 'data':
            // 只读数据库缓存，绝不回源。
            // 回源抓取由 poller.php 常驻守护（每 5 秒）完成；前端手动刷新走 action=refresh。
            // 这样无论设备是否在线 / 响应多慢，action=data 都瞬间返回，彻底避免前端加载卡死。
            $devs = [];
            try { $devs = db_get_devices(); } catch (Exception $e) {}
            $result = [];
            foreach ($devs as $d) {
                $cached = null;
                try { $cached = db_get_cache($d['id']); } catch (Exception $e) {}
                if ($cached && $cached['data'] !== null) {
                    $result[$d['id']] = [
                        'ok'         => true,
                        'data'       => $cached['data'],
                        'updated_at' => $cached['updated_at'],
                        'from_cache' => true,
                    ];
                } else {
                    // 缓存已不存在：poller 判定设备离线并已清理其缓存数据（方案 B）。
                    // 返回 offline 标记，前端据此正确显示为「离线」，而非用旧缓存误判「在线」。
                    $result[$d['id']] = [
                        'ok'      => false,
                        'error'   => '设备离线',
                        'offline' => true,
                    ];
                }
            }
            json_out('ok', '数据', $result);
            break;

        case 'sms-seen':
            // 已读短信记录：GET 读取 / POST 合并保存（跨设备共享已读状态）
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                if (!file_exists(SMS_SEEN_FILE)) {
                    json_out('ok', '无已读记录', []);
                }
                $data = json_decode(file_get_contents(SMS_SEEN_FILE), true);
                json_out('ok', '已读记录', is_array($data) ? $data : []);
                break;
            }
            $body = read_body();
            $seen = isset($body['seen']) ? $body['seen'] : null;
            if (!is_array($seen)) json_out('error', 'seen 必须为对象');
            // 清洗：key 限 32 字符字母数字，value 限非负整数
            $clean = [];
            foreach ($seen as $devId => $maxId) {
                $devId = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $devId), 0, 32);
                $maxId = (int) $maxId;
                if ($devId === '' || $maxId < 0) continue;
                $clean[$devId] = $maxId;
            }
            ensure_data_dir(dirname(SMS_SEEN_FILE));
            // 合并写入（取较大值，避免旧端覆盖新端）；flock 保证读-改-写原子性
            $existing = [];
            $fp = fopen(SMS_SEEN_FILE, 'c+');
            if ($fp !== false) {
                flock($fp, LOCK_EX);
                $raw = stream_get_contents($fp);
                if ($raw !== false && $raw !== '') {
                    $tmp = json_decode($raw, true);
                    if (is_array($tmp)) $existing = $tmp;
                }
                foreach ($clean as $devId => $maxId) {
                    $existing[$devId] = max(isset($existing[$devId]) ? (int) $existing[$devId] : 0, $maxId);
                }
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($existing, JSON_UNESCAPED_UNICODE));
                fflush($fp);
                flock($fp, LOCK_UN);
                fclose($fp);
            } else {
                // 降级：直接写
                foreach ($clean as $devId => $maxId) {
                    $existing[$devId] = max(isset($existing[$devId]) ? (int) $existing[$devId] : 0, $maxId);
                }
                file_put_contents(SMS_SEEN_FILE, json_encode($existing, JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
            @chmod(SMS_SEEN_FILE, 0600);
            json_out('ok', '已保存已读记录', ['count' => count($existing)]);
            break;

        default:
            json_out('error', '未知操作: ' . $action);
    }
} catch (Exception $e) {
    json_out('error', $e->getMessage());
}
} // end if (PHP_SAPI !== 'cli')
