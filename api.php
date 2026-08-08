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
 *
 * 要求：PHP curl 扩展、allow_url_fopen 或 curl 均可。
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// ---------- 配置 ----------
define('SESSION_DIR', __DIR__ . '/data/sessions');
define('DEVICES_FILE', __DIR__ . '/data/devices.json');
define('SMS_SEEN_FILE', __DIR__ . '/data/sms_seen.json');
define('SESSION_TTL', 1800); // 会话缓存 30 分钟
define('REQUEST_TIMEOUT', 15);

// ---------- 工具 ----------
function json_out($status, $message, $data = null) {
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
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
    foreach ($chars as $c) {
        if ($len >= $max) break;
        $out .= $c;
        $len++;
    }
    return $out;
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
    if (!is_dir(SESSION_DIR)) {
        @mkdir(SESSION_DIR, 0755, true);
    }
    $file = session_file($url);
    file_put_contents($file, json_encode([
        'cookie' => $cookie,
        'expires' => time() + SESSION_TTL,
    ]), LOCK_EX);
    @chmod($file, 0600); // 会话含敏感 Cookie，仅属主可读写
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
 * 转发设备 API 请求
 */
function forward_device($url, $path, $method, $body = null) {
    $cookie = get_session_cookie($url);
    if (!$cookie) {
        throw new Exception('会话未建立，请先登录该设备');
    }
    $headers = ['Cookie: ' . $cookie];
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
    return [$status, $respBody];
}

// ---------- 路由 ----------
$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
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

        case 'health':
            $sessions = is_dir(SESSION_DIR) ? count(glob(SESSION_DIR . '/*.json') ?: []) : 0;
            json_out('ok', 'SimAdmin proxy running', ['sessions' => $sessions]);
            break;

        case 'devices':
            // GET：读取服务器端设备配置（跨设备同步）
            // POST：保存设备配置到服务器（覆盖式）
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                if (!file_exists(DEVICES_FILE)) {
                    json_out('ok', '无设备配置', []);
                }
                $data = json_decode(file_get_contents(DEVICES_FILE), true);
                json_out('ok', '设备配置', is_array($data) ? $data : []);
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
                    'password' => isset($d['password']) ? (string) $d['password'] : '',
                ];
            }
            if (!is_dir(dirname(DEVICES_FILE))) {
                @mkdir(dirname(DEVICES_FILE), 0755, true);
            }
            file_put_contents(DEVICES_FILE, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
            @chmod(DEVICES_FILE, 0600);
            json_out('ok', '已保存 ' . count($clean) . ' 台设备', ['count' => count($clean)]);
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
            if (!is_dir(dirname(SMS_SEEN_FILE))) {
                @mkdir(dirname(SMS_SEEN_FILE), 0755, true);
            }
            // 合并写入（取较大值，避免旧端覆盖新端）
            $existing = [];
            if (file_exists(SMS_SEEN_FILE)) {
                $existing = json_decode(file_get_contents(SMS_SEEN_FILE), true);
                if (!is_array($existing)) $existing = [];
            }
            foreach ($clean as $devId => $maxId) {
                $existing[$devId] = max(isset($existing[$devId]) ? (int) $existing[$devId] : 0, $maxId);
            }
            file_put_contents(SMS_SEEN_FILE, json_encode($existing, JSON_UNESCAPED_UNICODE), LOCK_EX);
            @chmod(SMS_SEEN_FILE, 0600);
            json_out('ok', '已保存已读记录', ['count' => count($existing)]);
            break;

        default:
            json_out('error', '未知操作: ' . $action);
    }
} catch (Exception $e) {
    json_out('error', $e->getMessage());
}
