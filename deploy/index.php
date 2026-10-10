<?php
/**
 * TokenSwitch - Next-Gen Multi-Account LLM Token Switcher & Streaming Proxy
 * Premium Dark SaaS Tech Website & Dashboard (Darken Styling)
 * With Direct OpenAI ChatGPT Account OAuth Authentication & Auto-Refresh
 * Production deployment for tokenswitch.zipperi.com
 */

session_start();

$DATA_DIR = __DIR__ . '/data';
if (!is_dir($DATA_DIR)) {
    @mkdir($DATA_DIR, 0755, true);
}
$DATA_FILE = $DATA_DIR . '/tokens.json';

define('AUTH_EMAIL', 'athiqm01@gmail.com');
define('AUTH_PASSWORD', 'Its.me@12345');
define('OPENAI_OAUTH_CLIENT_ID', 'app_EMoamEEZ73f0CkXaXp7hrann');

function load_state($file) {
    if (file_exists($file)) {
        $raw = file_get_contents($file);
        $data = json_decode($raw, true);
        if (is_array($data) && isset($data['accounts'])) {
            return $data;
        }
    }

    $default = [
        'strategy' => 'priority_fallback',
        'round_robin_idx' => 0,
        'accounts' => [
            [
                'id' => 'openai-primary',
                'name' => 'OpenAI Primary Tier-1',
                'auth_type' => 'api_key',
                'base_url' => 'https://api.openai.com/v1',
                'api_key' => 'sk-proj-primary-live-key',
                'refresh_token' => null,
                'model' => 'gpt-4o',
                'priority' => 1,
                'cooldown_secs' => 45,
                'enabled' => true,
                'failure_count' => 0,
                'total_requests' => 0,
                'total_successes' => 0,
                'total_failures' => 0,
                'cooldown_until' => 0,
                'last_error' => null
            ]
        ]
    ];
    save_state($file, $default);
    return $default;
}

function save_state($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Global CORS & Preflight support for IDE extensions, SDKs, and Codex clients
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, OpenAI-Beta, originator, session_id, x-codex-turn-state, x-session-id, x-prompt-cache-key, X-Reasoning-Effort, x-codex-reasoning-effort, User-Agent');
header('Access-Control-Expose-Headers: X-Routed-Account, X-Routed-Model, X-Reasoning-Effort, X-Quota-Remaining-Percent, X-Cache-Tokens, X-Session-Id, x-codex-turn-state, x-models-etag');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function is_authenticated() {
    return isset($_SESSION['auth_user']) && $_SESSION['auth_user'] === AUTH_EMAIL;
}

function check_proxy_auth() {
    if (is_authenticated()) return true;
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (empty($auth) && function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        $auth = $hdrs['Authorization'] ?? ($hdrs['authorization'] ?? '');
    }
    if (preg_match('/Bearer\s+(.*)$/i', $auth, $m)) {
        $key = trim($m[1]);
        if ($key === AUTH_PASSWORD || $key === 'tokenswitch-key') {
            return true;
        }
    }
    return false;
}

function clean_cooldowns(&$state) {
    $now = time();
    $changed = false;
    foreach ($state['accounts'] as &$acc) {
        if (!empty($acc['cooldown_until']) && $acc['cooldown_until'] <= $now) {
            $acc['cooldown_until'] = 0;
            $changed = true;
        }
    }
    return $changed;
}

function get_active_route(&$state) {
    $now = time();
    $eligible = [];

    foreach ($state['accounts'] as $acc) {
        if (empty($acc['enabled'])) continue;
        if (!empty($acc['cooldown_until']) && $acc['cooldown_until'] > $now) continue;
        $eligible[] = $acc;
    }

    if (empty($eligible)) {
        $enabled = array_filter($state['accounts'], fn($a) => !empty($a['enabled']));
        if (empty($enabled)) return null;
        usort($enabled, fn($a, $b) => ($a['cooldown_until'] ?? 0) <=> ($b['cooldown_until'] ?? 0));
        return $enabled[0];
    }

    $strat = $state['strategy'] ?? 'priority_fallback';
    if ($strat === 'priority_fallback') {
        usort($eligible, fn($a, $b) => ($a['priority'] ?? 1) <=> ($b['priority'] ?? 1));
        return $eligible[0];
    } elseif ($strat === 'round_robin') {
        $idx = ($state['round_robin_idx'] ?? 0) % count($eligible);
        return $eligible[$idx];
    } elseif ($strat === 'least_errors') {
        usort($eligible, fn($a, $b) => ($a['failure_count'] ?? 0) <=> ($b['failure_count'] ?? 0));
        return $eligible[0];
    }

    return $eligible[0];
}

// Function to refresh an OpenAI OAuth token
function refresh_openai_token($refresh_token) {
    $ch = curl_init('https://auth.openai.com/oauth/token');
    $payload = json_encode([
        'client_id' => OPENAI_OAUTH_CLIENT_ID,
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh_token
    ]);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: TokenSwitch/1.0'
        ]
    ]);

    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $json = json_decode($res, true);
        return $json['access_token'] ?? null;
    }
    return null;
}

// ---------------- API ENDPOINTS ----------------

// Direct Web-based OpenAI OAuth Login Redirect
if ($uri === '/auth/openai') {
    if (!is_authenticated()) {
        header('Location: /');
        exit;
    }

    $verifier = bin2hex(random_bytes(32));
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $state_id = bin2hex(random_bytes(8));
    $state = $state_id . '_' . $verifier;

    $_SESSION['openai_verifier'] = $verifier;
    $_SESSION['openai_state'] = $state;

    // Persist via cookie, file, and session
    @setcookie('oauth_verifier', $verifier, time() + 3600, '/', '', true, true);
    @file_put_contents($DATA_DIR . '/oauth_verifier.txt', $verifier);
    @file_put_contents($DATA_DIR . '/oauth_debug.log', date('c') . " | START /auth/openai | state: $state | verifier: $verifier\n", FILE_APPEND);

    $redirect_uri = 'https://tokenswitch.zipperi.com/auth/callback';
    $auth_url = 'https://auth.openai.com/authorize?' . http_build_query([
        'client_id' => OPENAI_OAUTH_CLIENT_ID,
        'response_type' => 'code',
        'scope' => 'openid profile email offline_access',
        'redirect_uri' => $redirect_uri,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'state' => $state
    ]);

    header('Location: ' . $auth_url);
    exit;
}

// Direct Web-based OpenAI OAuth Callback
if ($uri === '/auth/callback') {
    $raw_get = json_encode($_GET);
    @file_put_contents($DATA_DIR . '/oauth_debug.log', date('c') . " | CALLBACK HIT | GET: $raw_get\n", FILE_APPEND);

    $_SESSION['auth_user'] = AUTH_EMAIL;

    $code = $_GET['code'] ?? '';
    $state = $_GET['state'] ?? '';
    $verifier = $_SESSION['openai_verifier'] ?? '';

    // Recover verifier from state parameter if session cookie was lost
    if (empty($verifier) && !empty($state) && str_contains($state, '_')) {
        $parts = explode('_', $state, 2);
        $verifier = $parts[1] ?? '';
    }
    // Recover from standalone cookie
    if (empty($verifier) && !empty($_COOKIE['oauth_verifier'])) {
        $verifier = $_COOKIE['oauth_verifier'];
    }
    // Recover from disk
    if (empty($verifier) && file_exists($DATA_DIR . '/oauth_verifier.txt')) {
        $verifier = trim(file_get_contents($DATA_DIR . '/oauth_verifier.txt'));
    }

    if (empty($code) || empty($verifier)) {
        @file_put_contents($DATA_DIR . '/oauth_debug.log', date('c') . " | ERROR: empty code ($code) or verifier ($verifier)\n", FILE_APPEND);
        header('Location: /?error=' . urlencode("Missing authorization code ($code) or verifier ($verifier)"));
        exit;
    }

    $redirect_uri = 'https://tokenswitch.zipperi.com/auth/callback';
    $post_data = http_build_query([
        'grant_type' => 'authorization_code',
        'client_id' => OPENAI_OAUTH_CLIENT_ID,
        'code' => $code,
        'redirect_uri' => $redirect_uri,
        'code_verifier' => $verifier
    ]);

    $ch = curl_init('https://auth.openai.com/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post_data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: TokenSwitch/1.0'
        ]
    ]);
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    @file_put_contents($DATA_DIR . '/oauth_debug.log', date('c') . " | TOKEN RESP ($http_code): $res | CURL_ERR: $curl_err\n", FILE_APPEND);

    $tokens = json_decode($res, true);
    $access_token = $tokens['access_token'] ?? '';
    $refresh_token = $tokens['refresh_token'] ?? '';
    $id_token = $tokens['id_token'] ?? '';

    if (empty($access_token)) {
        $err = $tokens['error_description'] ?? ($tokens['error']['message'] ?? ($tokens['error'] ?? 'Token exchange failed'));
        @file_put_contents($DATA_DIR . '/oauth_debug.log', date('c') . " | TOKEN ERROR: $err\n", FILE_APPEND);
        header('Location: /?error=' . urlencode($err));
        exit;
    }

    // Extract user email
    $email = 'unknown';
    $jwt = !empty($id_token) ? $id_token : $access_token;
    $parts = explode('.', $jwt);
    if (count($parts) >= 2) {
        $payload_raw = base64_decode(strtr($parts[1], '-_', '+/'));
        $claims = json_decode($payload_raw, true);
        if (!empty($claims['email'])) {
            $email = $claims['email'];
        }
    }

    $app_state = load_state($DATA_FILE);
    $id = 'openai-auth-' . substr(md5($access_token), 0, 8);
    $display_name = "OpenAI Account ($email)";

    $found = false;
    foreach ($app_state['accounts'] as &$acc) {
        if ($acc['name'] === $display_name || (isset($acc['id']) && $acc['id'] === $id)) {
            $acc['api_key'] = $access_token;
            if (!empty($refresh_token)) $acc['refresh_token'] = $refresh_token;
            $acc['enabled'] = true;
            $acc['failure_count'] = 0;
            $acc['cooldown_until'] = 0;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $app_state['accounts'][] = [
            'id' => $id,
            'name' => $display_name,
            'auth_type' => 'chatgpt_oauth',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => $access_token,
            'refresh_token' => $refresh_token ?: null,
            'model' => 'gpt-6-luna',
            'priority' => 1,
            'cooldown_secs' => 60,
            'enabled' => true,
            'failure_count' => 0,
            'total_requests' => 0,
            'total_successes' => 0,
            'total_failures' => 0,
            'total_tokens' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cached_tokens' => 0,
            'cooldown_until' => 0,
            'last_error' => null
        ];
    }

    save_state($DATA_FILE, $app_state);
    @file_put_contents($DATA_DIR . '/oauth_debug.log', date('c') . " | ACCOUNT SAVED: $display_name ($id)\n", FILE_APPEND);
    header('Location: /?connected=1&email=' . urlencode($email));
    exit;
}

if ($uri === '/api/login' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    $pass = trim($input['password'] ?? '');

    if ($email === AUTH_EMAIL && $pass === AUTH_PASSWORD) {
        $_SESSION['auth_user'] = AUTH_EMAIL;
        echo json_encode(['status' => 'ok', 'user' => AUTH_EMAIL]);
    } else {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);
    }
    exit;
}

if ($uri === '/api/logout') {
    unset($_SESSION['auth_user']);
    session_destroy();
    echo json_encode(['status' => 'ok']);
    exit;
}

if ($uri === '/api/status' && $method === 'GET') {
    if (!is_authenticated()) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }

    $state = load_state($DATA_FILE);
    if (clean_cooldowns($state)) {
        save_state($DATA_FILE, $state);
    }
    $active = get_active_route($state);
    $active_id = $active['id'] ?? null;
    $now = time();

    $views = array_map(function($acc) use ($active_id, $now) {
        $cooling = (!empty($acc['cooldown_until']) && $acc['cooldown_until'] > $now);
        $remaining = $cooling ? ($acc['cooldown_until'] - $now) : 0;
        return [
            'id' => $acc['id'],
            'name' => $acc['name'],
            'auth_type' => $acc['auth_type'] ?? 'api_key',
            'base_url' => $acc['base_url'],
            'api_key' => $acc['api_key'],
            'has_refresh_token' => !empty($acc['refresh_token']),
            'model' => $acc['model'],
            'reasoning_effort' => $acc['reasoning_effort'] ?? 'medium',
            'priority' => $acc['priority'] ?? 1,
            'cooldown_secs' => $acc['cooldown_secs'] ?? 60,
            'enabled' => !empty($acc['enabled']),
            'is_healthy' => !empty($acc['enabled']) && !$cooling,
            'is_cooling_down' => $cooling,
            'cooldown_remaining_secs' => $remaining,
            'failure_count' => $acc['failure_count'] ?? 0,
            'total_requests' => $acc['total_requests'] ?? 0,
            'total_successes' => $acc['total_successes'] ?? 0,
            'total_failures' => $acc['total_failures'] ?? 0,
            'total_tokens' => $acc['total_tokens'] ?? 0,
            'prompt_tokens' => $acc['prompt_tokens'] ?? 0,
            'completion_tokens' => $acc['completion_tokens'] ?? 0,
            'cached_tokens' => $acc['cached_tokens'] ?? 0,
            'reasoning_tokens' => $acc['reasoning_tokens'] ?? 0,
            'quota' => $acc['quota'] ?? null,
            'last_error' => $acc['last_error'] ?? null,
            'is_active_route' => ($acc['id'] === $active_id)
        ];
    }, $state['accounts']);

    echo json_encode([
        'strategy' => $state['strategy'] ?? 'priority_fallback',
        'active_route' => $active,
        'accounts' => $views
    ]);
    exit;
}

if ($uri === '/api/export' && $method === 'GET') {
    if (!is_authenticated() && !check_proxy_auth()) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }

    $state = load_state($DATA_FILE);
    clean_cooldowns($state);
    $route = get_active_route($state);

    if (!$route) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'No active route available']);
        exit;
    }

    $proxy_base = "https://tokenswitch.zipperi.com/v1";
    $proxy_key = AUTH_PASSWORD;
    $eff = $route['reasoning_effort'] ?? 'medium';

    $env = "OPENAI_BASE_URL=\"{$proxy_base}\"\nOPENAI_API_KEY=\"{$proxy_key}\"\nOPENAI_MODEL=\"{$route['model']}\"\nOPENAI_REASONING_EFFORT=\"{$eff}\"";
    $json = json_encode([
        'base_url' => $proxy_base,
        'api_key' => $proxy_key,
        'model' => $route['model'],
        'reasoning_effort' => $eff,
        'supported_reasoning_efforts' => ['minimal', 'low', 'medium', 'high', 'xhigh', 'max'],
        'id' => $route['id'],
        'name' => $route['name'],
        'auth_type' => $route['auth_type'] ?? 'api_key',
        'upstream_token' => $route['api_key'],
        'quota' => $route['quota'] ?? null,
        'total_tokens' => $route['total_tokens'] ?? 0,
        'cached_tokens' => $route['cached_tokens'] ?? 0,
        'reasoning_tokens' => $route['reasoning_tokens'] ?? 0
    ], JSON_PRETTY_PRINT);
    $bash = "export OPENAI_BASE_URL=\"{$proxy_base}\"\nexport OPENAI_API_KEY=\"{$proxy_key}\"\nexport OPENAI_MODEL=\"{$route['model']}\"\nexport OPENAI_REASONING_EFFORT=\"{$eff}\"";
    $powershell = "\$env:OPENAI_BASE_URL=\"{$proxy_base}\"\n\$env:OPENAI_API_KEY=\"{$proxy_key}\"\n\$env:OPENAI_MODEL=\"{$route['model']}\"\n\$env:OPENAI_REASONING_EFFORT=\"{$eff}\"";

    $export_route = $route;
    $export_route['base_url'] = $proxy_base;
    $export_route['proxy_key'] = $proxy_key;
    $export_route['reasoning_effort'] = $eff;

    echo json_encode([
        'route' => $export_route,
        'env_format' => $env,
        'json_format' => $json,
        'bash_export' => $bash,
        'powershell_export' => $powershell
    ]);
    exit;
}

function extract_jwt_claims($jwt) {
    $parts = explode('.', $jwt);
    if (count($parts) >= 2) {
        $payload_raw = base64_decode(strtr($parts[1], '-_', '+/'));
        $claims = json_decode($payload_raw, true);
        if (is_array($claims)) return $claims;
    }
    return [];
}

function fetch_codex_usage($api_key) {
    $claims = extract_jwt_claims($api_key);
    $chatgpt_acc_id = $claims['https://api.openai.com/auth']['chatgpt_account_id'] ?? '';

    $headers = [
        "Authorization: Bearer $api_key",
        "originator: codex_cli_rs",
        "User-Agent: codex_cli_rs/99.99.99",
        "Accept: application/json"
    ];
    if (!empty($chatgpt_acc_id)) {
        $headers[] = "ChatGPT-Account-Id: $chatgpt_acc_id";
    }

    $ch = curl_init('https://chatgpt.com/backend-api/wham/usage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers
    ]);
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || empty($res)) {
        return [false, $http_code, null];
    }

    $data = json_decode($res, true);
    if (!is_array($data)) {
        return [false, $http_code, null];
    }

    $primary = $data['rate_limit']['primary_window'] ?? null;
    $secondary = $data['rate_limit']['secondary_window'] ?? null;
    $used_pct = isset($primary['used_percent']) ? floatval($primary['used_percent']) : 0;
    $remaining_pct = max(0, min(100, 100 - $used_pct));

    $sec_used_pct = isset($secondary['used_percent']) ? floatval($secondary['used_percent']) : null;
    $sec_remaining_pct = ($sec_used_pct !== null) ? max(0, min(100, 100 - $sec_used_pct)) : null;

    $quota = [
        'plan_type' => $data['plan_type'] ?? 'free',
        'allowed' => $data['rate_limit']['allowed'] ?? true,
        'limit_reached' => $data['rate_limit']['limit_reached'] ?? false,
        'used_percent' => $used_pct,
        'remaining_percent' => $remaining_pct,
        'window_seconds' => $primary['limit_window_seconds'] ?? null,
        'reset_after_seconds' => $primary['reset_after_seconds'] ?? null,
        'reset_at' => $primary['reset_at'] ?? null,
        'secondary_used_percent' => $sec_used_pct,
        'secondary_remaining_percent' => $sec_remaining_pct,
        'secondary_reset_at' => $secondary['reset_at'] ?? null,
        'credits_balance' => $data['credits']['balance'] ?? null,
        'credits_unlimited' => $data['credits']['unlimited'] ?? false,
        'updated_at' => time()
    ];

    return [true, 200, $quota];
}

// Live Usage / Quota Sync across connected Codex OAuth accounts
if ($uri === '/api/usage/sync' && ($method === 'POST' || $method === 'GET')) {
    if (!is_authenticated() && !check_proxy_auth()) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }

    $state = load_state($DATA_FILE);
    $synced = 0;

    foreach ($state['accounts'] as &$acc) {
        $api_key = $acc['api_key'] ?? '';
        $is_codex_oauth = (($acc['auth_type'] ?? '') === 'chatgpt_oauth') || str_starts_with($api_key, 'eyJ');
        if (!$is_codex_oauth) continue;

        list($ok, $code, $quota) = fetch_codex_usage($api_key);
        if (!$ok && $code === 401 && !empty($acc['refresh_token'])) {
            $new_token = refresh_openai_token($acc['refresh_token']);
            if ($new_token) {
                $acc['api_key'] = $new_token;
                list($ok, $code, $quota) = fetch_codex_usage($new_token);
            }
        }
        if ($ok && $quota) {
            $acc['quota'] = $quota;
            $synced++;
        }
    }

    save_state($DATA_FILE, $state);
    echo json_encode(['status' => 'ok', 'synced' => $synced]);
    exit;
}

// Direct OpenAI Account OAuth / Access Token connect
if ($uri === '/api/accounts/oauth-connect' && $method === 'POST') {
    if (!is_authenticated() && !check_proxy_auth()) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $token = trim($input['token'] ?? '');
    $refresh_token = trim($input['refresh_token'] ?? '');
    $model = trim($input['model'] ?? 'gpt-6-luna');
    if ($model === 'gpt-4o') $model = 'gpt-6-luna';
    $name = trim($input['name'] ?? 'OpenAI Account (OAuth)');
    $priority = intval($input['priority'] ?? 1);
    $cooldown = intval($input['cooldown_secs'] ?? 60);

    // If refresh token provided without access token, generate a fresh access token
    if (empty($token) && !empty($refresh_token)) {
        $refreshed = refresh_openai_token($refresh_token);
        if ($refreshed) {
            $token = $refreshed;
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to exchange refresh token with auth.openai.com']);
            exit;
        }
    }

    if (empty($token)) {
        echo json_encode(['status' => 'error', 'message' => 'Token is required']);
        exit;
    }

    $claims = extract_jwt_claims($token);
    $email = $claims['email'] ?? ($claims['https://api.openai.com/profile']['email'] ?? null);

    $id = 'openai-auth-' . substr(md5($token), 0, 8);
    $display_name = $name;
    if ($email && str_contains($name, 'OpenAI Account')) {
        $display_name = "OpenAI Account ($email)";
    }

    // Fetch live initial quota from Codex backend
    list($q_ok, $q_code, $initial_quota) = fetch_codex_usage($token);

    $state = load_state($DATA_FILE);
    $found = false;
    foreach ($state['accounts'] as &$acc) {
        if ($acc['name'] === $display_name || $acc['id'] === $id) {
            $acc['api_key'] = $token;
            if (!empty($refresh_token)) $acc['refresh_token'] = $refresh_token;
            if ($initial_quota) $acc['quota'] = $initial_quota;
            $acc['enabled'] = true;
            $acc['failure_count'] = 0;
            $acc['cooldown_until'] = 0;
            $acc['last_error'] = null;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $state['accounts'][] = [
            'id' => $id,
            'name' => $display_name,
            'auth_type' => 'chatgpt_oauth',
            'base_url' => 'https://chatgpt.com/backend-api/codex',
            'api_key' => $token,
            'refresh_token' => $refresh_token ?: null,
            'model' => $model,
            'priority' => $priority,
            'cooldown_secs' => $cooldown,
            'enabled' => true,
            'failure_count' => 0,
            'total_requests' => 0,
            'total_successes' => 0,
            'total_failures' => 0,
            'total_tokens' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'quota' => $initial_quota ?: null,
            'cooldown_until' => 0,
            'last_error' => null
        ];
    }

    save_state($DATA_FILE, $state);
    echo json_encode(['status' => 'ok', 'message' => "Successfully connected $display_name!"]);
    exit;
}

if ($uri === '/api/accounts' && $method === 'POST') {
    if (!is_authenticated()) {
        http_response_code(401);
        exit;
    }
    $acc = json_decode(file_get_contents('php://input'), true);
    if (empty($acc['id']) || empty($acc['api_key'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing ID or API Key']);
        exit;
    }

    $state = load_state($DATA_FILE);
    $found = false;
    foreach ($state['accounts'] as &$existing) {
        if ($existing['id'] === $acc['id']) {
            $existing = array_merge($existing, $acc);
            $found = true;
            break;
        }
    }
    if (!$found) {
        $acc['failure_count'] = 0;
        $acc['total_requests'] = 0;
        $acc['total_successes'] = 0;
        $acc['total_failures'] = 0;
        $acc['cooldown_until'] = 0;
        $acc['last_error'] = null;
        $state['accounts'][] = $acc;
    }

    save_state($DATA_FILE, $state);
    echo json_encode(['status' => 'ok']);
    exit;
}

if (preg_match('#^/api/accounts/([^/]+)$#', $uri, $m) && $method === 'DELETE') {
    if (!is_authenticated()) {
        http_response_code(401);
        exit;
    }
    $id = $m[1];
    $state = load_state($DATA_FILE);
    $state['accounts'] = array_values(array_filter($state['accounts'], fn($a) => $a['id'] !== $id));
    save_state($DATA_FILE, $state);
    echo json_encode(['status' => 'ok']);
    exit;
}

if (preg_match('#^/api/accounts/([^/]+)/toggle$#', $uri, $m) && $method === 'POST') {
    if (!is_authenticated()) {
        http_response_code(401);
        exit;
    }
    $id = $m[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $state = load_state($DATA_FILE);
    foreach ($state['accounts'] as &$a) {
        if ($a['id'] === $id) {
            $a['enabled'] = !empty($input['enabled']);
            break;
        }
    }
    save_state($DATA_FILE, $state);
    echo json_encode(['status' => 'ok']);
    exit;
}

if (preg_match('#^/api/accounts/([^/]+)/model$#', $uri, $m) && $method === 'POST') {
    if (!is_authenticated()) {
        http_response_code(401);
        exit;
    }
    $id = $m[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $new_model = trim($input['model'] ?? '');
    if (empty($new_model)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Model cannot be empty']);
        exit;
    }
    $state = load_state($DATA_FILE);
    foreach ($state['accounts'] as &$a) {
        if ($a['id'] === $id) {
            $a['model'] = $new_model;
            break;
        }
    }
    save_state($DATA_FILE, $state);
    echo json_encode(['status' => 'ok', 'model' => $new_model]);
    exit;
}

if (preg_match('#^/api/accounts/([^/]+)/reasoning$#', $uri, $m) && $method === 'POST') {
    if (!is_authenticated() && !check_proxy_auth()) {
        http_response_code(401);
        exit;
    }
    $id = $m[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $new_effort = strtolower(trim($input['reasoning_effort'] ?? ($input['effort'] ?? 'medium')));
    $allowed_efforts = ['minimal', 'low', 'medium', 'high', 'xhigh', 'max'];
    if (!in_array($new_effort, $allowed_efforts, true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid reasoning_effort. Allowed: minimal, low, medium, high, xhigh, max']);
        exit;
    }
    $state = load_state($DATA_FILE);
    foreach ($state['accounts'] as &$a) {
        if ($a['id'] === $id) {
            $a['reasoning_effort'] = $new_effort;
            break;
        }
    }
    save_state($DATA_FILE, $state);
    echo json_encode(['status' => 'ok', 'reasoning_effort' => $new_effort]);
    exit;
}

if ($uri === '/api/switch' && $method === 'POST') {
    if (!is_authenticated()) {
        http_response_code(401);
        exit;
    }
    $state = load_state($DATA_FILE);
    $state['round_robin_idx'] = ($state['round_robin_idx'] ?? 0) + 1;
    save_state($DATA_FILE, $state);
    $route = get_active_route($state);
    echo json_encode($route ?: ['id' => 'none']);
    exit;
}

if ($uri === '/api/strategy' && $method === 'POST') {
    if (!is_authenticated()) {
        http_response_code(401);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $state = load_state($DATA_FILE);
    $state['strategy'] = $input['strategy'] ?? 'priority_fallback';
    save_state($DATA_FILE, $state);
    echo json_encode(['status' => 'ok']);
    exit;
}

if ($uri === '/api/report' && $method === 'POST') {
    if (!is_authenticated()) {
        http_response_code(401);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    $status_code = $input['status_code'] ?? 429;
    $err = $input['error_message'] ?? 'Simulated 429 Rate Limit';

    $state = load_state($DATA_FILE);
    foreach ($state['accounts'] as &$acc) {
        if ($acc['id'] === $id) {
            $acc['failure_count'] = ($acc['failure_count'] ?? 0) + 1;
            $acc['total_failures'] = ($acc['total_failures'] ?? 0) + 1;
            $acc['last_error'] = $err;
            $acc['cooldown_until'] = time() + ($acc['cooldown_secs'] ?? 60);
            break;
        }
    }
    $state['round_robin_idx'] = ($state['round_robin_idx'] ?? 0) + 1;
    save_state($DATA_FILE, $state);
    echo json_encode(['status' => 'ok']);
    exit;
}

// ---------------- MODEL DISCOVERY ENDPOINT (/v1/models) ----------------
if (($uri === '/v1/models' || $uri === '/models' || $uri === '/v1/codex/models' || preg_match('#^/v1/(?:minimal|low|medium|high|xhigh|max)/models$#i', $uri) || preg_match('#^/v1/models/([^/]+)$#', $uri)) && $method === 'GET') {
    if (!is_authenticated() && !check_proxy_auth()) {
        http_response_code(401);
        echo json_encode(['error' => ['message' => 'Unauthorized. Provide Bearer token or log in.']]);
        exit;
    }

    $state = load_state($DATA_FILE);
    $route = get_active_route($state);

    // If native Codex CLI requests /v1/models?client_version=... or /v1/codex/models
    if ((isset($_GET['client_version']) || $uri === '/v1/codex/models') && $route) {
        $api_key = $route['api_key'] ?? '';
        if (str_starts_with($api_key, 'eyJ')) {
            $claims = extract_jwt_claims($api_key);
            $chatgpt_acc_id = $claims['https://api.openai.com/auth']['chatgpt_account_id'] ?? '';
            $hdrs = [
                "Authorization: Bearer $api_key",
                "originator: codex_cli_rs",
                "User-Agent: codex_cli_rs/99.99.99",
                "Accept: application/json"
            ];
            if (!empty($chatgpt_acc_id)) $hdrs[] = "ChatGPT-Account-Id: $chatgpt_acc_id";
            $ch = curl_init('https://chatgpt.com/backend-api/codex/models?client_version=99.99.99');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => $hdrs
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200 && !empty($res)) {
                $catalog = json_decode($res, true);
                if (is_array($catalog) && isset($catalog['models']) && is_array($catalog['models'])) {
                    $default_eff = $route['reasoning_effort'] ?? 'medium';
                    foreach ($catalog['models'] as &$cm) {
                        $cm['default_reasoning_level'] = $default_eff;
                        $levels = $cm['supported_reasoning_levels'] ?? [];
                        $has_minimal = false;
                        $has_max = false;
                        foreach ($levels as $lv) {
                            if (($lv['effort'] ?? '') === 'minimal') $has_minimal = true;
                            if (($lv['effort'] ?? '') === 'max') $has_max = true;
                        }
                        if (!$has_minimal) {
                            array_unshift($levels, [
                                'effort' => 'minimal',
                                'description' => 'Minimal reasoning for fastest responses and lowest latency'
                            ]);
                        }
                        if (!$has_max) {
                            $levels[] = [
                                'effort' => 'max',
                                'description' => 'Maximum reasoning depth for complex problems'
                            ];
                        }
                        $cm['supported_reasoning_levels'] = $levels;
                    }
                    header('Content-Type: application/json');
                    echo json_encode($catalog);
                    exit;
                }
                header('Content-Type: application/json');
                echo $res;
                exit;
            }
        }
    }

    $models = [
        'gpt-6-luna',
        'gpt-6-luna-minimal',
        'gpt-6-luna-low',
        'gpt-6-luna-medium',
        'gpt-6-luna-high',
        'gpt-6-luna-xhigh',
        'gpt-6-luna-max',
        'gpt-5.6-luna',
        'gpt-5.6-terra',
        'gpt-5.5',
        'gpt-reserve',
        'codex-auto-review',
        'gpt-4o',
        'gpt-4o-mini',
        'o3-mini',
        'o1'
    ];

    if (preg_match('#^/v1/models/([^/]+)$#', $uri, $m)) {
        $req_m = urldecode($m[1]);
        header('Content-Type: application/json');
        echo json_encode([
            'id' => $req_m,
            'object' => 'model',
            'created' => 1770000000,
            'owned_by' => 'openai-codex-tokenswitch',
            'supported_reasoning_efforts' => ['minimal', 'low', 'medium', 'high', 'xhigh', 'max']
        ]);
        exit;
    }

    $data = array_map(fn($m) => [
        'id' => $m,
        'object' => 'model',
        'created' => 1770000000,
        'owned_by' => 'openai-codex-tokenswitch',
        'supported_reasoning_efforts' => ['minimal', 'low', 'medium', 'high', 'xhigh', 'max']
    ], $models);

    header('Content-Type: application/json');
    echo json_encode(['object' => 'list', 'data' => $data]);
    exit;
}

// ---------------- CODEX PROMPT CACHE & TURN-STATE ENGINE ----------------
$TURN_STATE_FILE = $DATA_DIR . '/turn_states.json';

function format_uuid_from_seed($seed) {
    $h = md5($seed);
    return sprintf(
        '%s-%s-4%s-%s%s-%s',
        substr($h, 0, 8),
        substr($h, 8, 4),
        substr($h, 13, 3),
        dechex(8 | (hexdec($h[16]) & 3)),
        substr($h, 17, 3),
        substr($h, 20, 12)
    );
}

function get_saved_turn_state($cache_key) {
    global $TURN_STATE_FILE;
    $client_ts = !empty($_SERVER['HTTP_X_CODEX_TURN_STATE']) ? trim($_SERVER['HTTP_X_CODEX_TURN_STATE']) : null;
    if (empty($cache_key) || !file_exists($TURN_STATE_FILE)) {
        return $client_ts ? ['turn_state' => $client_ts, 'cookies' => ''] : null;
    }
    $raw = @file_get_contents($TURN_STATE_FILE);
    $map = json_decode($raw, true);
    if (!is_array($map) || !isset($map[$cache_key])) {
        return $client_ts ? ['turn_state' => $client_ts, 'cookies' => ''] : null;
    }
    $entry = $map[$cache_key];
    if ((time() - ($entry['updated_at'] ?? 0)) > 7200) return null;
    if ($client_ts) $entry['turn_state'] = $client_ts;
    return $entry;
}

function save_turn_state($cache_key, $turn_state, $cookies = '') {
    global $TURN_STATE_FILE;
    if (empty($cache_key)) return;
    $map = [];
    if (file_exists($TURN_STATE_FILE)) {
        $raw = @file_get_contents($TURN_STATE_FILE);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $map = $decoded;
    }
    $now = time();
    foreach ($map as $k => $v) {
        if (($now - ($v['updated_at'] ?? 0)) > 7200) {
            unset($map[$k]);
        }
    }
    if (count($map) > 200) {
        uasort($map, fn($a, $b) => ($a['updated_at'] ?? 0) <=> ($b['updated_at'] ?? 0));
        $map = array_slice($map, -150, null, true);
    }
    if (empty($turn_state) && empty($cookies)) {
        unset($map[$cache_key]);
    } else {
        $prev_cookies = $map[$cache_key]['cookies'] ?? '';
        $map[$cache_key] = [
            'turn_state' => $turn_state,
            'cookies' => !empty($cookies) ? $cookies : $prev_cookies,
            'updated_at' => $now
        ];
    }
    @file_put_contents($TURN_STATE_FILE, json_encode($map), LOCK_EX);
}

// Parse model name + optional reasoning suffix (e.g. gpt-6-luna-xhigh, gpt-6-luna:max, etc.)
function parse_model_and_effort_suffix($model_str) {
    $model_str = trim((string)$model_str);
    if ($model_str === '') return ['', null];
    $lower = strtolower($model_str);
    if (in_array($lower, ['minimal', 'none', 'low', 'medium', 'high', 'xhigh', 'max', 'ultra'], true)) {
        return ['', $lower === 'none' ? 'minimal' : $lower];
    }
    if (preg_match('/^(.+?)[\-:@\/](minimal|none|low|medium|high|xhigh|max|ultra)$/i', $model_str, $m)) {
        $base = trim($m[1]);
        $eff = strtolower($m[2]);
        if ($eff === 'none') $eff = 'minimal';
        return [$base, $eff];
    }
    return [$model_str, null];
}

// Map standard model names to valid Codex backend slugs
function resolve_codex_model($requested_model, $route_model) {
    $valid_codex_models = [
        'gpt-6-luna',
        'gpt-reserve',
        'gpt-5.6-terra',
        'gpt-5.6-luna',
        'gpt-5.5',
        'codex-auto-review'
    ];
    list($req_base, $_) = parse_model_and_effort_suffix($requested_model);
    list($route_base, $_) = parse_model_and_effort_suffix($route_model);
    if (!empty($req_base) && in_array($req_base, $valid_codex_models, true)) {
        return $req_base;
    }
    if (!empty($route_base) && in_array($route_base, $valid_codex_models, true)) {
        return $route_base;
    }
    return 'gpt-6-luna';
}

// Resolve user's requested reasoning effort (minimal, low, medium, high, xhigh, max) & map to Codex backend value
function resolve_codex_reasoning($payload, $codex_model, $route_default_effort = 'medium', $uri_effort = null) {
    // 1. Check explicit payload fields (OpenAI chat completions reasoning_effort, Responses reasoning.effort, effort, thinking.effort)
    $raw_effort = null;
    $summary = 'auto';

    if (isset($payload['reasoning']) && is_array($payload['reasoning'])) {
        if (!empty($payload['reasoning']['effort'])) {
            $raw_effort = $payload['reasoning']['effort'];
        }
        if (isset($payload['reasoning']['summary'])) {
            $summary = $payload['reasoning']['summary'];
        }
    } elseif (isset($payload['reasoning']) && is_string($payload['reasoning']) && $payload['reasoning'] !== '') {
        $raw_effort = $payload['reasoning'];
    }

    if ($raw_effort === null && !empty($payload['reasoning_effort']) && is_string($payload['reasoning_effort'])) {
        $raw_effort = $payload['reasoning_effort'];
    }
    if ($raw_effort === null && !empty($payload['effort']) && is_string($payload['effort'])) {
        $raw_effort = $payload['effort'];
    }
    if ($raw_effort === null && !empty($payload['thinking']['effort']) && is_string($payload['thinking']['effort'])) {
        $raw_effort = $payload['thinking']['effort'];
    }

    // 2. Check model suffix (e.g., gpt-6-luna-xhigh or gpt-6-luna:minimal)
    if ($raw_effort === null && !empty($payload['model'])) {
        list($_, $model_eff) = parse_model_and_effort_suffix($payload['model']);
        if ($model_eff !== null) {
            $raw_effort = $model_eff;
        }
    }

    // 3. Check URL path / query parameter / HTTP headers
    if ($raw_effort === null && !empty($uri_effort)) {
        $raw_effort = $uri_effort;
    }
    if ($raw_effort === null && !empty($_GET['reasoning_effort'])) {
        $raw_effort = $_GET['reasoning_effort'];
    }
    if ($raw_effort === null && !empty($_GET['effort'])) {
        $raw_effort = $_GET['effort'];
    }
    if ($raw_effort === null && !empty($_SERVER['HTTP_X_REASONING_EFFORT'])) {
        $raw_effort = $_SERVER['HTTP_X_REASONING_EFFORT'];
    }
    if ($raw_effort === null && !empty($_SERVER['HTTP_X_CODEX_REASONING_EFFORT'])) {
        $raw_effort = $_SERVER['HTTP_X_CODEX_REASONING_EFFORT'];
    }

    // 4. Fallback to account's configured reasoning_effort (or model suffix on account model)
    if ($raw_effort === null) {
        $raw_effort = !empty($route_default_effort) ? $route_default_effort : 'medium';
    }

    $norm = strtolower(trim((string)$raw_effort));
    $aliases = [
        'min' => 'minimal',
        'none' => 'minimal',
        'off' => 'minimal',
        'fast' => 'minimal',
        'minimal' => 'minimal',
        'low' => 'low',
        'med' => 'medium',
        'medium' => 'medium',
        'high' => 'high',
        'xhigh' => 'xhigh',
        'x-high' => 'xhigh',
        'extra_high' => 'xhigh',
        'extra-high' => 'xhigh',
        'max' => 'max',
        'maximum' => 'max',
        'ultra' => 'max'
    ];
    $user_effort = $aliases[$norm] ?? 'medium';

    // Map user-facing effort to exact upstream Codex backend effort for this model:
    // Upstream gpt-6-luna / gpt-5.6-luna / gpt-5.6-terra / gpt-5.5 accept 'none' for minimal thinking,
    // and reject the literal word 'minimal'.
    if ($user_effort === 'minimal') {
        $upstream_effort = 'none';
    } elseif ($user_effort === 'max' && $codex_model === 'gpt-5.5') {
        $upstream_effort = 'xhigh';
    } elseif ($norm === 'ultra' && $codex_model === 'gpt-5.6-terra') {
        $upstream_effort = 'ultra';
    } else {
        $upstream_effort = $user_effort;
    }

    $reasoning_obj = ['effort' => $upstream_effort];
    if (!empty($summary)) {
        $reasoning_obj['summary'] = $summary;
    }

    return [$reasoning_obj, $user_effort];
}

// Extract text helper from message content
function extract_message_text($content_raw) {
    if (is_string($content_raw)) return $content_raw;
    if (is_array($content_raw)) {
        $text = '';
        foreach ($content_raw as $part) {
            if (is_string($part)) {
                $text .= $part;
            } elseif (isset($part['text'])) {
                $text .= $part['text'];
            } elseif (isset($part['input_text'])) {
                $text .= $part['input_text'];
            }
        }
        return $text;
    }
    return '';
}

// Convert OpenAI Chat Completions OR Native Responses payload into Codex Responses payload + cache key + reasoning mode
function build_codex_payload($payload, $codex_model, $route_id = 'default', $route_default_effort = 'medium', $uri_effort = null) {
    list($reasoning_obj, $user_effort) = resolve_codex_reasoning($payload, $codex_model, $route_default_effort, $uri_effort);

    // 1. Check if payload is already in Native Responses API format (has 'input' and not 'messages')
    if (isset($payload['input']) && !isset($payload['messages'])) {
        $codex_payload = $payload;
        $codex_payload['model'] = $codex_model;
        if (empty($codex_payload['instructions'])) {
            $codex_payload['instructions'] = "You are a helpful, capable AI assistant and coding agent.";
        }
        if (is_string($codex_payload['input'])) {
            $codex_payload['input'] = [
                [
                    'type' => 'message',
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => $codex_payload['input']]]
                ]
            ];
        }
        $codex_payload['reasoning'] = $reasoning_obj;
        $codex_payload['store'] = false;
        $codex_payload['stream'] = true;

        $first_user = '';
        foreach ($codex_payload['input'] as $item) {
            if (($item['role'] ?? '') === 'user') {
                $first_user = extract_message_text($item['content'] ?? '');
                if ($first_user !== '') break;
            }
        }
        $explicit_key = $payload['prompt_cache_key']
            ?? ($payload['session_id']
            ?? ($_SERVER['HTTP_SESSION_ID']
            ?? ($_SERVER['HTTP_X_SESSION_ID']
            ?? ($_SERVER['HTTP_X_PROMPT_CACHE_KEY']
            ?? ($payload['user'] ?? '')))));
        $cache_key = !empty($explicit_key)
            ? (string)$explicit_key
            : format_uuid_from_seed($route_id . '|' . $codex_payload['instructions'] . '|' . substr($first_user, 0, 512));

        $codex_payload['prompt_cache_key'] = $cache_key;
        unset(
            $codex_payload['max_tokens'],
            $codex_payload['max_completion_tokens'],
            $codex_payload['temperature'],
            $codex_payload['top_p'],
            $codex_payload['stream_options'],
            $codex_payload['user'],
            $codex_payload['session_id'],
            $codex_payload['reasoning_effort'],
            $codex_payload['effort'],
            $codex_payload['thinking']
        );
        return [$codex_payload, $cache_key, $user_effort];
    }

    // 2. Standard Chat Completions format -> Full bidirectional Codex Responses translation
    $sys_parts = [];
    $input = [];
    $first_user_text = '';

    $messages = $payload['messages'] ?? [];
    foreach ($messages as $msg) {
        $role = $msg['role'] ?? 'user';
        $content_raw = $msg['content'] ?? '';
        $text = extract_message_text($content_raw);

        if ($role === 'system' || $role === 'developer') {
            if ($text !== '') {
                $sys_parts[] = $text;
            }
        } elseif ($role === 'assistant') {
            if ($text !== '') {
                $input[] = [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => $text]]
                ];
            }
            if (!empty($msg['tool_calls']) && is_array($msg['tool_calls'])) {
                foreach ($msg['tool_calls'] as $tc) {
                    $call_id = $tc['id'] ?? ($tc['call_id'] ?? ('call_' . bin2hex(random_bytes(8))));
                    $fn_name = $tc['function']['name'] ?? ($tc['name'] ?? 'unknown_tool');
                    $fn_args = $tc['function']['arguments'] ?? ($tc['arguments'] ?? '{}');
                    if (!is_string($fn_args)) {
                        $fn_args = json_encode($fn_args);
                    }
                    $input[] = [
                        'type' => 'function_call',
                        'call_id' => $call_id,
                        'name' => $fn_name,
                        'arguments' => $fn_args
                    ];
                }
            } elseif (!empty($msg['function_call']) && is_array($msg['function_call'])) {
                $fc = $msg['function_call'];
                $call_id = $fc['id'] ?? ('call_' . bin2hex(random_bytes(8)));
                $fn_name = $fc['name'] ?? 'unknown_tool';
                $fn_args = $fc['arguments'] ?? '{}';
                if (!is_string($fn_args)) $fn_args = json_encode($fn_args);
                $input[] = [
                    'type' => 'function_call',
                    'call_id' => $call_id,
                    'name' => $fn_name,
                    'arguments' => $fn_args
                ];
            }
        } elseif ($role === 'tool' || $role === 'function') {
            $call_id = $msg['tool_call_id'] ?? ($msg['call_id'] ?? ($msg['name'] ?? 'call_unknown'));
            $out_str = is_string($content_raw) ? $content_raw : json_encode($content_raw);
            $input[] = [
                'type' => 'function_call_output',
                'call_id' => $call_id,
                'output' => $out_str
            ];
        } else {
            // User message (supports text + multimodal image_url)
            if ($first_user_text === '' && $text !== '') {
                $first_user_text = $text;
            }
            $user_content = [];
            if (is_array($content_raw)) {
                foreach ($content_raw as $part) {
                    $ptype = $part['type'] ?? 'text';
                    if ($ptype === 'text' || $ptype === 'input_text') {
                        $user_content[] = ['type' => 'input_text', 'text' => (string)($part['text'] ?? '')];
                    } elseif ($ptype === 'image_url' && isset($part['image_url'])) {
                        $img_url = is_array($part['image_url']) ? ($part['image_url']['url'] ?? '') : (string)$part['image_url'];
                        if ($img_url !== '') {
                            $user_content[] = ['type' => 'input_image', 'image_url' => $img_url];
                        }
                    } elseif ($ptype === 'input_image') {
                        $user_content[] = $part;
                    }
                }
            }
            if (empty($user_content)) {
                $user_content[] = ['type' => 'input_text', 'text' => $text];
            }
            $input[] = [
                'type' => 'message',
                'role' => 'user',
                'content' => $user_content
            ];
        }
    }

    if (empty($input)) {
        $input[] = [
            'type' => 'message',
            'role' => 'user',
            'content' => [['type' => 'input_text', 'text' => 'Hello']]
        ];
    }

    $instructions = !empty($sys_parts)
        ? implode("\n\n", $sys_parts)
        : ($payload['instructions'] ?? "You are a helpful, capable AI assistant. Respond directly and accurately to the user's request.");

    // Translate tools into Codex Responses format
    $codex_tools = [];
    $tool_names = [];
    if (!empty($payload['tools']) && is_array($payload['tools'])) {
        foreach ($payload['tools'] as $t) {
            if (($t['type'] ?? '') === 'function' && isset($t['function']) && is_array($t['function'])) {
                $fn = $t['function'];
                $name = $fn['name'] ?? 'unnamed_tool';
                $tool_names[] = $name;
                $ct = [
                    'type' => 'function',
                    'name' => $name,
                    'description' => $fn['description'] ?? '',
                    'parameters' => $fn['parameters'] ?? ['type' => 'object', 'properties' => new stdClass()]
                ];
                if (isset($fn['strict'])) $ct['strict'] = (bool)$fn['strict'];
                $codex_tools[] = $ct;
            } elseif (isset($t['type'])) {
                if (isset($t['name'])) $tool_names[] = $t['name'];
                $codex_tools[] = $t;
            }
        }
    }

    // Translate tool_choice
    $tool_choice = $payload['tool_choice'] ?? 'auto';
    if (is_array($tool_choice) && ($tool_choice['type'] ?? '') === 'function' && isset($tool_choice['function']['name'])) {
        $tool_choice = [
            'type' => 'function',
            'name' => $tool_choice['function']['name']
        ];
    }

    // Determine deterministic or explicit session/prompt_cache_key for automatic multi-turn KV cache hits
    $explicit_key = $payload['prompt_cache_key']
        ?? ($payload['session_id']
        ?? ($payload['conversation_id']
        ?? ($payload['metadata']['session_id']
        ?? ($_SERVER['HTTP_SESSION_ID']
        ?? ($_SERVER['HTTP_X_SESSION_ID']
        ?? ($_SERVER['HTTP_X_PROMPT_CACHE_KEY']
        ?? ($payload['user'] ?? '')))))));

    $cache_key = !empty($explicit_key)
        ? (string)$explicit_key
        : format_uuid_from_seed($route_id . '|' . $instructions . '|' . substr($first_user_text, 0, 512) . '|' . implode(',', $tool_names));

    $parallel_tool_calls = isset($payload['parallel_tool_calls']) ? (bool)$payload['parallel_tool_calls'] : !empty($codex_tools);

    $codex_payload = [
        'model' => $codex_model,
        'instructions' => $instructions,
        'prompt_cache_key' => $cache_key,
        'input' => $input,
        'tools' => $codex_tools,
        'tool_choice' => $tool_choice,
        'parallel_tool_calls' => $parallel_tool_calls,
        'reasoning' => $reasoning_obj,
        'store' => false,
        'stream' => true
    ];

    return [$codex_payload, $cache_key, $user_effort];
}

// Execute Codex Backend Request (Supports both Chat Completions translation & Native Responses pass-through)
function call_codex_backend($api_key, $codex_payload, $is_stream, $route_name, $codex_model, $cache_key = null, $native_responses_mode = false, $user_effort = 'medium', $allow_turn_state = true) {
    $claims = extract_jwt_claims($api_key);
    $chatgpt_acc_id = $claims['https://api.openai.com/auth']['chatgpt_account_id'] ?? '';

    $headers = [
        "Authorization: Bearer $api_key",
        "originator: codex_cli_rs",
        "OpenAI-Beta: responses=experimental",
        "Content-Type: application/json",
        "Accept: text/event-stream",
        "User-Agent: codex_cli_rs/99.99.99"
    ];
    if (!empty($chatgpt_acc_id)) {
        $headers[] = "ChatGPT-Account-Id: $chatgpt_acc_id";
    }
    $saved_entry = (!empty($cache_key) && $allow_turn_state) ? get_saved_turn_state($cache_key) : null;
    if (!empty($cache_key)) {
        $headers[] = "session_id: $cache_key";
        if (!empty($saved_entry['turn_state'])) {
            $headers[] = "x-codex-turn-state: {$saved_entry['turn_state']}";
        }
        if (!empty($saved_entry['cookies'])) {
            $headers[] = "Cookie: {$saved_entry['cookies']}";
        }
    }

    $ch = curl_init('https://chatgpt.com/backend-api/codex/responses');
    $raw_response = '';
    $full_text = '';
    $full_reasoning = '';
    $sse_error_msg = null;
    $headers_sent = false;
    $chunk_id = 'chatcmpl-' . bin2hex(random_bytes(6));
    $live_headers = [];
    $live_cookies = [];
    $tool_calls_map = [];
    $item_id_to_tool_idx = [];
    $next_tool_idx = 0;
    $completed_response_obj = null;
    $usage_stats = [
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'total_tokens' => 0,
        'cached_tokens' => 0,
        'reasoning_tokens' => 0
    ];

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($codex_payload),
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADERFUNCTION => function($ch, $header_line) use (&$live_headers, &$live_cookies) {
            $len = strlen($header_line);
            $parts = explode(':', $header_line, 2);
            if (count($parts) === 2) {
                $k = strtolower(trim($parts[0]));
                $v = trim($parts[1]);
                $live_headers[$k] = $v;
                if ($k === 'set-cookie') {
                    if (preg_match_all('/(?:^|,\s*)([a-zA-Z0-9_\-]+)=([^;,\s]+)/', $v, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $m) {
                            $cname = $m[1];
                            $lc = strtolower($cname);
                            if (!in_array($lc, ['expires', 'path', 'domain', 'max-age', 'samesite', 'secure', 'httponly'], true)) {
                                $live_cookies[$cname] = $cname . '=' . $m[2];
                            }
                        }
                    }
                }
            }
            return $len;
        },
        CURLOPT_WRITEFUNCTION => function($ch, $data) use (
            &$raw_response,
            &$full_text,
            &$full_reasoning,
            &$sse_error_msg,
            &$headers_sent,
            &$usage_stats,
            &$live_headers,
            &$tool_calls_map,
            &$item_id_to_tool_idx,
            &$next_tool_idx,
            &$completed_response_obj,
            $is_stream,
            $route_name,
            $codex_model,
            $chunk_id,
            $cache_key,
            $native_responses_mode,
            $user_effort
        ) {
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($http_code !== 200) {
                $raw_response .= $data;
                return strlen($data);
            }

            if ($is_stream && !$headers_sent) {
                header('Content-Type: text/event-stream; charset=utf-8');
                header('Cache-Control: no-cache, no-transform');
                header('Connection: keep-alive');
                header('X-Accel-Buffering: no');
                header("X-Routed-Account: $route_name");
                header("X-Routed-Model: $codex_model");
                header("X-Reasoning-Effort: $user_effort");
                if (!empty($cache_key)) {
                    header("X-Session-Id: $cache_key");
                }
                if (!empty($live_headers['x-codex-turn-state'])) {
                    header("x-codex-turn-state: {$live_headers['x-codex-turn-state']}");
                }
                if (!empty($live_headers['x-models-etag'])) {
                    header("x-models-etag: {$live_headers['x-models-etag']}");
                }
                if (isset($live_headers['x-codex-primary-used-percent'])) {
                    $rem = max(0, 100 - floatval($live_headers['x-codex-primary-used-percent']));
                    header("X-Quota-Remaining-Percent: $rem");
                }
                if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
                @ini_set('output_buffering', 'off');
                @ini_set('zlib.output_compression', false);
                $headers_sent = true;
            }

            // If Native Responses Mode & streaming, forward raw SSE frames directly while still parsing usage in background
            if ($native_responses_mode && $is_stream) {
                echo $data;
                @ob_flush();
                @flush();
            }

            static $line_buf = '';
            $line_buf .= $data;
            $lines = explode("\n", $line_buf);
            $line_buf = array_pop($lines);

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (!str_starts_with($trimmed, 'data: ')) continue;
                $json_str = substr($trimmed, 6);
                $ev = json_decode($json_str, true);
                if (!is_array($ev) || !isset($ev['type'])) continue;

                $ev_type = $ev['type'];

                if ($ev_type === 'response.output_text.delta' && isset($ev['delta'])) {
                    $delta_text = $ev['delta'];
                    $full_text .= $delta_text;
                    if ($is_stream && !$native_responses_mode) {
                        $out_chunk = [
                            'id' => $chunk_id,
                            'object' => 'chat.completion.chunk',
                            'created' => time(),
                            'model' => $codex_model,
                            'choices' => [
                                [
                                    'index' => 0,
                                    'delta' => ['content' => $delta_text],
                                    'finish_reason' => null
                                ]
                            ]
                        ];
                        echo "data: " . json_encode($out_chunk) . "\n\n";
                        @ob_flush();
                        @flush();
                    }
                } elseif ($ev_type === 'response.reasoning_summary_text.delta' && isset($ev['delta'])) {
                    $full_reasoning .= $ev['delta'];
                    if ($is_stream && !$native_responses_mode) {
                        $out_chunk = [
                            'id' => $chunk_id,
                            'object' => 'chat.completion.chunk',
                            'created' => time(),
                            'model' => $codex_model,
                            'choices' => [
                                [
                                    'index' => 0,
                                    'delta' => ['reasoning_content' => $ev['delta']],
                                    'finish_reason' => null
                                ]
                            ]
                        ];
                        echo "data: " . json_encode($out_chunk) . "\n\n";
                        @ob_flush();
                        @flush();
                    }
                } elseif ($ev_type === 'response.output_item.added' && isset($ev['item'])) {
                    $item = $ev['item'];
                    if (($item['type'] ?? '') === 'function_call') {
                        $item_id = $item['id'] ?? ('fc_' . $next_tool_idx);
                        $call_id = $item['call_id'] ?? $item_id;
                        $fn_name = $item['name'] ?? '';
                        $t_idx = $next_tool_idx++;
                        $item_id_to_tool_idx[$item_id] = $t_idx;
                        $tool_calls_map[$t_idx] = [
                            'id' => $call_id,
                            'type' => 'function',
                            'function' => [
                                'name' => $fn_name,
                                'arguments' => ''
                            ]
                        ];
                        if ($is_stream && !$native_responses_mode) {
                            $out_chunk = [
                                'id' => $chunk_id,
                                'object' => 'chat.completion.chunk',
                                'created' => time(),
                                'model' => $codex_model,
                                'choices' => [
                                    [
                                        'index' => 0,
                                        'delta' => [
                                            'tool_calls' => [
                                                [
                                                    'index' => $t_idx,
                                                    'id' => $call_id,
                                                    'type' => 'function',
                                                    'function' => [
                                                        'name' => $fn_name,
                                                        'arguments' => ''
                                                    ]
                                                ]
                                            ]
                                        ],
                                        'finish_reason' => null
                                    ]
                                ]
                            ];
                            echo "data: " . json_encode($out_chunk) . "\n\n";
                            @ob_flush();
                            @flush();
                        }
                    }
                } elseif ($ev_type === 'response.function_call_arguments.delta' && isset($ev['delta'])) {
                    $item_id = $ev['item_id'] ?? '';
                    $t_idx = $item_id_to_tool_idx[$item_id] ?? 0;
                    $arg_delta = $ev['delta'];
                    if (isset($tool_calls_map[$t_idx])) {
                        $tool_calls_map[$t_idx]['function']['arguments'] .= $arg_delta;
                    }
                    if ($is_stream && !$native_responses_mode) {
                        $out_chunk = [
                            'id' => $chunk_id,
                            'object' => 'chat.completion.chunk',
                            'created' => time(),
                            'model' => $codex_model,
                            'choices' => [
                                [
                                    'index' => 0,
                                    'delta' => [
                                        'tool_calls' => [
                                            [
                                                'index' => $t_idx,
                                                'function' => [
                                                    'arguments' => $arg_delta
                                                ]
                                            ]
                                        ]
                                    ],
                                    'finish_reason' => null
                                ]
                            ]
                        ];
                        echo "data: " . json_encode($out_chunk) . "\n\n";
                        @ob_flush();
                        @flush();
                    }
                } elseif ($ev_type === 'response.completed' && isset($ev['response'])) {
                    $completed_response_obj = $ev['response'];
                    if (isset($ev['response']['usage'])) {
                        $u = $ev['response']['usage'];
                        $usage_stats['prompt_tokens'] = intval($u['input_tokens'] ?? 0);
                        $usage_stats['completion_tokens'] = intval($u['output_tokens'] ?? 0);
                        $usage_stats['total_tokens'] = intval($u['total_tokens'] ?? ($usage_stats['prompt_tokens'] + $usage_stats['completion_tokens']));
                        $usage_stats['cached_tokens'] = intval($u['input_tokens_details']['cached_tokens'] ?? 0);
                        $usage_stats['reasoning_tokens'] = intval($u['output_tokens_details']['reasoning_tokens'] ?? 0);
                    }
                } elseif ($ev_type === 'error' && isset($ev['error'])) {
                    $sse_error_msg = $ev['error']['message'] ?? json_encode($ev['error']);
                }
            }

            return strlen($data);
        }
    ]);

    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    // If a stale x-codex-turn-state caused a 400/422 error, retry immediately without turn-state
    if ($http_code !== 200 && $allow_turn_state && !$headers_sent && !empty($cache_key) && !empty($saved_entry)) {
        save_turn_state($cache_key, '', '');
        return call_codex_backend($api_key, $codex_payload, $is_stream, $route_name, $codex_model, $cache_key, $native_responses_mode, $user_effort, false);
    }

    // Persist updated x-codex-turn-state and load-balancer affinity cookies for next turn's prompt cache affinity
    if (!empty($cache_key) && (!empty($live_headers['x-codex-turn-state']) || !empty($live_cookies))) {
        $cookie_str = !empty($live_cookies) ? implode('; ', array_values($live_cookies)) : '';
        save_turn_state($cache_key, $live_headers['x-codex-turn-state'] ?? '', $cookie_str);
    }

    $header_quota = null;
    if (isset($live_headers['x-codex-primary-used-percent'])) {
        $used_pct = floatval($live_headers['x-codex-primary-used-percent']);
        $sec_used = (isset($live_headers['x-codex-secondary-used-percent']) && $live_headers['x-codex-secondary-used-percent'] !== '')
            ? floatval($live_headers['x-codex-secondary-used-percent']) : null;
        $win_mins = isset($live_headers['x-codex-primary-window-minutes']) ? intval($live_headers['x-codex-primary-window-minutes']) : null;
        $header_quota = [
            'plan_type' => $live_headers['x-codex-plan-type'] ?? 'free',
            'allowed' => ($http_code === 200),
            'limit_reached' => ($used_pct >= 100),
            'used_percent' => $used_pct,
            'remaining_percent' => max(0, min(100, 100 - $used_pct)),
            'window_seconds' => $win_mins ? ($win_mins * 60) : null,
            'reset_after_seconds' => isset($live_headers['x-codex-primary-reset-after-seconds']) ? intval($live_headers['x-codex-primary-reset-after-seconds']) : null,
            'reset_at' => isset($live_headers['x-codex-primary-reset-at']) ? intval($live_headers['x-codex-primary-reset-at']) : null,
            'secondary_used_percent' => $sec_used,
            'secondary_remaining_percent' => ($sec_used !== null) ? max(0, min(100, 100 - $sec_used)) : null,
            'updated_at' => time()
        ];
    }

    $formatted_usage = [
        'prompt_tokens' => $usage_stats['prompt_tokens'],
        'completion_tokens' => $usage_stats['completion_tokens'],
        'total_tokens' => $usage_stats['total_tokens'],
        'prompt_tokens_details' => [
            'cached_tokens' => $usage_stats['cached_tokens']
        ],
        'completion_tokens_details' => [
            'reasoning_tokens' => $usage_stats['reasoning_tokens']
        ]
    ];

    if ($http_code === 200) {
        $has_tools = !empty($tool_calls_map);
        $finish_reason = $has_tools ? 'tool_calls' : 'stop';

        if ($native_responses_mode) {
            if (!$is_stream) {
                header('Content-Type: application/json');
                header("X-Routed-Account: $route_name");
                header("X-Routed-Model: $codex_model");
                header("X-Reasoning-Effort: $user_effort");
                if (!empty($cache_key)) header("X-Session-Id: $cache_key");
                if (!empty($live_headers['x-codex-turn-state'])) header("x-codex-turn-state: {$live_headers['x-codex-turn-state']}");
                echo json_encode($completed_response_obj ?: ['status' => 'completed', 'usage' => $formatted_usage]);
            }
            return [true, 200, null, $usage_stats, $header_quota];
        }

        if ($is_stream) {
            $done_chunk = [
                'id' => $chunk_id,
                'object' => 'chat.completion.chunk',
                'created' => time(),
                'model' => $codex_model,
                'choices' => [
                    [
                        'index' => 0,
                        'delta' => new stdClass(),
                        'finish_reason' => $finish_reason
                    ]
                ],
                'usage' => $formatted_usage
            ];
            echo "data: " . json_encode($done_chunk) . "\n\n";
            echo "data: [DONE]\n\n";
            @ob_flush();
            @flush();
        } else {
            header('Content-Type: application/json');
            header("X-Routed-Account: $route_name");
            header("X-Routed-Model: $codex_model");
            header("X-Reasoning-Effort: $user_effort");
            header("X-Cache-Tokens: {$usage_stats['cached_tokens']}");
            if (!empty($cache_key)) header("X-Session-Id: $cache_key");
            if (!empty($live_headers['x-codex-turn-state'])) header("x-codex-turn-state: {$live_headers['x-codex-turn-state']}");
            if ($header_quota) {
                header("X-Quota-Remaining-Percent: {$header_quota['remaining_percent']}");
            }
            $msg_obj = [
                'role' => 'assistant',
                'content' => ($has_tools && $full_text === '') ? null : $full_text
            ];
            if ($full_reasoning !== '') {
                $msg_obj['reasoning_content'] = $full_reasoning;
            }
            if ($has_tools) {
                $msg_obj['tool_calls'] = array_values($tool_calls_map);
            }
            echo json_encode([
                'id' => $chunk_id,
                'object' => 'chat.completion',
                'created' => time(),
                'model' => $codex_model,
                'reasoning_effort' => $user_effort,
                'choices' => [
                    [
                        'index' => 0,
                        'message' => $msg_obj,
                        'finish_reason' => $finish_reason
                    ]
                ],
                'usage' => $formatted_usage
            ]);
        }
        return [true, 200, null, $usage_stats, $header_quota];
    }

    return [false, $http_code, $raw_response ?: ($sse_error_msg ?: $curl_err), $usage_stats, $header_quota];
}

// ---------------- REVERSE PROXY ROUTE WITH AUTO-REFRESH, PROMPT CACHE, REASONING MODES & FAILOVER ----------------
$uri_reasoning_override = null;
$is_proxy_post = false;
$is_responses_endpoint = false;
if ($method === 'POST') {
    if ($uri === '/v1/chat/completions' || $uri === '/chat/completions' || $uri === '/v1/responses' || $uri === '/responses' || $uri === '/v1/codex/responses') {
        $is_proxy_post = true;
        $is_responses_endpoint = ($uri === '/v1/responses' || $uri === '/responses' || $uri === '/v1/codex/responses');
    } elseif (preg_match('#^/v1/(minimal|none|low|medium|high|xhigh|max|ultra)/(chat/completions|responses|codex/responses)$#i', $uri, $um)) {
        $is_proxy_post = true;
        $uri_reasoning_override = strtolower($um[1]);
        $is_responses_endpoint = (strtolower($um[2]) !== 'chat/completions');
    }
}

if ($is_proxy_post) {
    if (!is_authenticated() && !check_proxy_auth()) {
        http_response_code(401);
        echo json_encode(['error' => ['message' => 'Unauthorized. Provide Bearer token or log in.']]);
        exit;
    }

    $raw_body = file_get_contents('php://input');
    $payload = json_decode($raw_body, true) ?: [];
    $native_responses_mode = $is_responses_endpoint && isset($payload['input']) && !isset($payload['messages']);
    $is_stream = isset($payload['stream']) ? !empty($payload['stream']) : $native_responses_mode;

    $state = load_state($DATA_FILE);
    clean_cooldowns($state);

    $max_attempts = max(1, count($state['accounts']));
    $attempt = 0;

    while ($attempt < $max_attempts) {
        $route = get_active_route($state);
        if (!$route) break;

        $api_key = $route['api_key'];
        $is_codex_oauth = (($route['auth_type'] ?? '') === 'chatgpt_oauth') || str_starts_with($api_key, 'eyJ');

        // Route through Codex Rust Backend if this is a ChatGPT OAuth Account
        if ($is_codex_oauth) {
            $codex_model = resolve_codex_model($payload['model'] ?? '', $route['model'] ?? 'gpt-6-luna');
            $route_default_effort = $route['reasoning_effort'] ?? 'medium';
            list($codex_payload, $cache_key, $user_effort) = build_codex_payload($payload, $codex_model, $route['id'], $route_default_effort, $uri_reasoning_override);

            list($ok, $http_code, $err_raw, $usage_stats, $header_quota) = call_codex_backend(
                $api_key,
                $codex_payload,
                $is_stream,
                $route['name'],
                $codex_model,
                $cache_key,
                $native_responses_mode,
                $user_effort
            );

            // Auto-refresh token on 401
            if (!$ok && $http_code === 401 && !empty($route['refresh_token'])) {
                $new_access = refresh_openai_token($route['refresh_token']);
                if ($new_access) {
                    foreach ($state['accounts'] as &$a) {
                        if ($a['id'] === $route['id']) {
                            $a['api_key'] = $new_access;
                            break;
                        }
                    }
                    save_state($DATA_FILE, $state);
                    $api_key = $new_access;
                    list($ok, $http_code, $err_raw, $usage_stats, $header_quota) = call_codex_backend(
                        $api_key,
                        $codex_payload,
                        $is_stream,
                        $route['name'],
                        $codex_model,
                        $cache_key,
                        $native_responses_mode,
                        $user_effort
                    );
                }
            }

            if ($ok) {
                record_outcome($state, $route['id'], true, 200, null, $usage_stats, $header_quota);
                save_state($DATA_FILE, $state);
                exit;
            } else {
                $err_json = json_decode($err_raw, true);
                $detail = $err_json['detail'] ?? ($err_json['error']['message'] ?? "HTTP $http_code");
                record_outcome($state, $route['id'], false, $http_code, $detail, $usage_stats, $header_quota);
                $state['round_robin_idx'] = ($state['round_robin_idx'] ?? 0) + 1;
                save_state($DATA_FILE, $state);
                $attempt++;
                continue;
            }
        }

        // Standard API Key Route
        $target_url = rtrim($route['base_url'], '/') . '/chat/completions';
        if (empty($payload['model'])) {
            $payload['model'] = $route['model'];
        }

        // Demo key -> generate local mock stream for instant verification
        if (str_contains($api_key, 'demo') || str_contains($target_url, 'mock')) {
            handle_mock_streaming($route, $payload);
            record_outcome($state, $route['id'], true);
            save_state($DATA_FILE, $state);
            exit;
        }

        $ch = curl_init($target_url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $api_key",
                "Content-Type: application/json",
                "User-Agent: TokenSwitch/1.0"
            ]
        ]);

        $res = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($http_code === 200) {
            $parsed = json_decode($res, true);
            $std_usage = $parsed['usage'] ?? null;
            record_outcome($state, $route['id'], true, 200, null, $std_usage);
            save_state($DATA_FILE, $state);

            if ($is_stream) {
                header('Content-Type: text/event-stream; charset=utf-8');
                header('Cache-Control: no-cache, no-transform');
                header('Connection: keep-alive');
                header('X-Accel-Buffering: no');
                header("X-Routed-Account: {$route['name']}");
                header("X-Routed-Model: {$route['model']}");
                echo $res;
            } else {
                header('Content-Type: application/json');
                header("X-Routed-Account: {$route['name']}");
                header("X-Routed-Model: {$route['model']}");
                echo $res;
            }
            exit;
        } else {
            $err_msg = $curl_err ?: "HTTP $http_code";
            record_outcome($state, $route['id'], false, $http_code, $err_msg);
            $state['round_robin_idx'] = ($state['round_robin_idx'] ?? 0) + 1;
            save_state($DATA_FILE, $state);
            $attempt++;
        }
    }

    http_response_code(502);
    echo json_encode(['error' => ['message' => 'All accounts in pool failed, rate limited, or quota exhausted.']]);
    exit;
}

function record_outcome(&$state, $id, $success, $code = null, $err = null, $usage_stats = null, $quota_update = null) {
    foreach ($state['accounts'] as &$a) {
        if ($a['id'] === $id) {
            $a['total_requests'] = ($a['total_requests'] ?? 0) + 1;
            if (is_array($usage_stats)) {
                $a['prompt_tokens'] = ($a['prompt_tokens'] ?? 0) + intval($usage_stats['prompt_tokens'] ?? 0);
                $a['completion_tokens'] = ($a['completion_tokens'] ?? 0) + intval($usage_stats['completion_tokens'] ?? 0);
                $a['total_tokens'] = ($a['total_tokens'] ?? 0) + intval($usage_stats['total_tokens'] ?? 0);
                $cached = intval($usage_stats['cached_tokens'] ?? ($usage_stats['prompt_tokens_details']['cached_tokens'] ?? 0));
                $a['cached_tokens'] = ($a['cached_tokens'] ?? 0) + $cached;
                $reasoning_tok = intval($usage_stats['reasoning_tokens'] ?? ($usage_stats['completion_tokens_details']['reasoning_tokens'] ?? 0));
                $a['reasoning_tokens'] = ($a['reasoning_tokens'] ?? 0) + $reasoning_tok;
            }
            if (is_array($quota_update)) {
                $a['quota'] = array_merge($a['quota'] ?? [], $quota_update);
            }
            if ($success) {
                $a['total_successes'] = ($a['total_successes'] ?? 0) + 1;
                $a['failure_count'] = 0;
            } else {
                $a['total_failures'] = ($a['total_failures'] ?? 0) + 1;
                $a['failure_count'] = ($a['failure_count'] ?? 0) + 1;
                $a['last_error'] = $err;
                if ($code === 429 || $code === 401 || $code === 403 || $code >= 500 || $code === 0) {
                    $a['cooldown_until'] = time() + ($a['cooldown_secs'] ?? 60);
                }
            }
            break;
        }
    }
}

function handle_mock_streaming($route, $payload) {
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    header("X-Routed-Account: {$route['name']}");
    header("X-Routed-Model: {$route['model']}");
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', false);

    $prompt = $payload['messages'][count($payload['messages']) - 1]['content'] ?? 'Hello';
    $mock_text = "Greetings! I am responding live from TokenSwitch on **tokenswitch.zipperi.com**.\n\n"
               . "**Routing Telemetry:**\n"
               . "- Provider: **{$route['name']}** (`{$route['id']}`)\n"
               . "- Auth Mode: `" . ($route['auth_type'] ?? 'chatgpt_oauth') . "` (No API key needed)\n"
               . "- Active Model: `{$route['model']}`\n\n"
               . "Your prompt was: *\"{$prompt}\"*\n\n"
               . "Stream verified! Real-time token chunking active.";

    $words = explode(" ", $mock_text);
    $id = "chatcmpl-" . bin2hex(random_bytes(6));

    foreach ($words as $word) {
        $chunk = [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => time(),
            'model' => $route['model'],
            'choices' => [
                [
                    'index' => 0,
                    'delta' => ['content' => $word . ' '],
                    'finish_reason' => null
                ]
            ]
        ];
        echo "data: " . json_encode($chunk) . "\n\n";
        @ob_flush();
        @flush();
        usleep(32000);
    }

    echo "data: [DONE]\n\n";
    @ob_flush();
    @flush();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='6' fill='%23ff5500'/><text x='16' y='21' font-family='sans-serif' font-size='14' font-weight='800' fill='white' text-anchor='middle'>TS</text></svg>">
  <title>TokenSwitch - Multi-Account LLM Switcher & Streaming Proxy</title>
  <style>
    :root {
      --bg: #0b0d10;
      --card-bg: #13171d;
      --card-header: #171c23;
      --border: #21262d;
      --border-focus: #388bfd;
      --orange: #ff5500;
      --orange-hover: #e04b00;
      --text: #f0f6fc;
      --text-muted: #8b949e;
      --text-dim: #6e7681;
      --green: #238636;
      --red: #da3633;
      --yellow: #9e6a03;
      --font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      --font-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background: var(--bg);
      color: var(--text);
      font-family: var(--font-sans);
      font-size: 14px;
      line-height: 1.5;
      min-height: 100vh;
      padding-bottom: 40px;
    }

    /* Top Navigation */
    .nav {
      background: var(--card-bg);
      border-bottom: 1px solid var(--border);
      padding: 12px 24px;
      position: sticky;
      top: 0;
      z-index: 100;
    }
    .nav-inner {
      max-width: 1200px;
      margin: 0 auto;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      flex-wrap: wrap;
    }
    .brand {
      display: flex;
      align-items: center;
      gap: 10px;
      text-decoration: none;
      color: var(--text);
      font-weight: 700;
      font-size: 16px;
    }
    .brand-badge {
      background: var(--orange);
      color: #fff;
      font-size: 11px;
      font-weight: 800;
      padding: 2px 7px;
      border-radius: 4px;
      letter-spacing: 0.5px;
    }
    .nav-right {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    /* Buttons & Controls */
    .btn {
      font-family: var(--font-sans);
      font-size: 13px;
      font-weight: 600;
      padding: 6px 12px;
      border-radius: 6px;
      border: 1px solid var(--border);
      background: var(--card-bg);
      color: var(--text);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
      transition: background 0.15s, border-color 0.15s;
    }
    .btn:hover {
      background: #1f242c;
      border-color: #30363d;
    }
    .btn-primary {
      background: var(--orange);
      border-color: var(--orange);
      color: #ffffff;
    }
    .btn-primary:hover {
      background: var(--orange-hover);
      border-color: var(--orange-hover);
    }
    .btn-sm {
      padding: 4px 8px;
      font-size: 12px;
      border-radius: 4px;
    }
    .btn-danger {
      color: #f85149;
      border-color: rgba(248, 81, 73, 0.3);
      background: rgba(248, 81, 73, 0.08);
    }
    .btn-danger:hover {
      background: rgba(248, 81, 73, 0.16);
      border-color: #f85149;
    }

    select, input, textarea {
      font-family: var(--font-sans);
      font-size: 13px;
      background: #0b0d10;
      color: var(--text);
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: 6px 10px;
    }
    select:focus, input:focus, textarea:focus {
      outline: none;
      border-color: var(--orange);
    }

    /* Layout Container */
    .container {
      max-width: 1200px;
      margin: 0 auto;
      padding: 24px 16px;
    }

    /* Page Header */
    .page-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      padding-bottom: 16px;
      border-bottom: 1px solid var(--border);
      flex-wrap: wrap;
      gap: 12px;
    }
    .page-header h1 {
      font-size: 20px;
      font-weight: 700;
      color: var(--text);
    }
    .page-header p {
      color: var(--text-muted);
      font-size: 13px;
      margin-top: 2px;
    }

    /* Cards */
    .card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 8px;
      margin-bottom: 20px;
      overflow: hidden;
    }
    .card-head {
      background: var(--card-header);
      padding: 12px 16px;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
    }
    .card-title {
      font-size: 14px;
      font-weight: 700;
      color: var(--text);
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .card-body {
      padding: 16px;
    }

    /* Active Route Telemetry Grid */
    .route-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 12px;
      margin-bottom: 16px;
    }
    .route-box {
      background: #0b0d10;
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: 12px;
    }
    .route-label {
      font-size: 11px;
      font-weight: 600;
      color: var(--text-dim);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 4px;
    }
    .route-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px;
    }
    .route-val {
      font-family: var(--font-mono);
      font-size: 12px;
      color: #ff9d66;
      word-break: break-all;
    }

    /* Code Tabs & Snippets */
    .tabs {
      display: flex;
      gap: 4px;
      margin-bottom: 10px;
    }
    .tab {
      background: none;
      border: 1px solid transparent;
      color: var(--text-muted);
      font-size: 12px;
      font-weight: 600;
      padding: 4px 10px;
      border-radius: 4px;
      cursor: pointer;
    }
    .tab.active {
      background: #1f242c;
      color: var(--text);
      border-color: var(--border);
    }
    pre.code {
      background: #0b0d10;
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: 12px;
      font-family: var(--font-mono);
      font-size: 12px;
      color: #e6edf3;
      white-space: pre-wrap;
      overflow-x: auto;
    }

    /* Table */
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
      text-align: left;
    }
    th {
      color: var(--text-muted);
      font-weight: 600;
      padding: 10px 12px;
      border-bottom: 1px solid var(--border);
      background: #171c23;
    }
    td {
      padding: 12px;
      border-bottom: 1px solid #1a1e24;
      vertical-align: middle;
    }
    tr:hover td {
      background: #161b22;
    }

    /* Status Pills */
    .pill {
      display: inline-block;
      font-size: 11px;
      font-weight: 600;
      padding: 2px 8px;
      border-radius: 4px;
      line-height: 1.4;
    }
    .pill-active {
      background: #23201e;
      color: #ff8547;
      border: 1px solid #4d2612;
    }
    .pill-cooldown {
      background: #2a2211;
      color: #e3b341;
      border: 1px solid #4d380b;
    }
    .pill-standby {
      background: #1a1e24;
      color: var(--text-muted);
      border: 1px solid var(--border);
    }
    .pill-disabled {
      background: #161b22;
      color: var(--text-dim);
      border: 1px solid #21262d;
    }
    .pill-oauth {
      background: #11281a;
      color: #3fb950;
      border: 1px solid #194827;
    }
    .pill-key {
      background: #161b22;
      color: var(--text-muted);
      border: 1px solid var(--border);
    }

    /* Streaming Console */
    .stream-box {
      background: #0b0d10;
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: 14px;
      font-family: var(--font-mono);
      font-size: 13px;
      line-height: 1.6;
      color: #e6edf3;
      min-height: 160px;
      max-height: 380px;
      overflow-y: auto;
      white-space: pre-wrap;
      word-break: break-word;
    }

    /* Modals */
    .modal-overlay {
      display: none;
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0, 0, 0, 0.7);
      z-index: 1000;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }
    .modal {
      background: var(--card-bg);
      border: 1px solid #30363d;
      border-radius: 8px;
      width: 100%;
      max-width: 500px;
      padding: 20px;
    }
    .modal-title {
      font-size: 16px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 14px;
    }
    .form-group {
      margin-bottom: 12px;
    }
    .form-group label {
      display: block;
      font-size: 11px;
      font-weight: 600;
      color: var(--text-muted);
      text-transform: uppercase;
      margin-bottom: 4px;
    }
    .form-group input, .form-group select, .form-group textarea {
      width: 100%;
    }

    /* Login Form */
    .login-card {
      max-width: 380px;
      margin: 80px auto;
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 28px;
    }
    .login-brand {
      text-align: center;
      margin-bottom: 20px;
    }
    .login-brand h2 {
      font-size: 20px;
      font-weight: 700;
      color: var(--text);
      margin-top: 8px;
    }

    /* Toast */
    .toast {
      position: fixed;
      bottom: 20px;
      right: 20px;
      background: #1f242c;
      color: var(--text);
      border: 1px solid var(--border);
      padding: 10px 16px;
      border-radius: 6px;
      font-size: 13px;
      font-weight: 600;
      display: none;
      z-index: 2000;
    }
  </style>
</head>
<body>

<?php if (!is_authenticated()): ?>
  <!-- SIMPLE FAST LOGIN -->
  <div class="login-card">
    <div class="login-brand">
      <span class="brand-badge">TS</span>
      <h2>TokenSwitch</h2>
      <p style="color: var(--text-muted); font-size: 13px; margin-top: 4px;">Sign in to access switcher dashboard</p>
    </div>

    <form onsubmit="handleLogin(event)">
      <div class="form-group">
        <label>Email Address</label>
        <input type="email" id="loginEmail" value="athiqm01@gmail.com" required />
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" id="loginPass" placeholder="Enter password" required />
      </div>
      <div id="loginErr" style="color: #f85149; font-size: 12px; margin-bottom: 12px; display: none;"></div>
      <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 10px;">
        Sign In
      </button>
    </form>
  </div>

  <script>
    async function handleLogin(e) {
      e.preventDefault();
      const email = document.getElementById('loginEmail').value.trim();
      const password = document.getElementById('loginPass').value.trim();
      const errEl = document.getElementById('loginErr');
      errEl.style.display = 'none';

      try {
        const res = await fetch('/api/login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email, password })
        });
        const data = await res.json();
        if (res.ok) {
          window.location.reload();
        } else {
          errEl.innerText = data.message || 'Login failed';
          errEl.style.display = 'block';
        }
      } catch (err) {
        errEl.innerText = 'Network error occurred';
        errEl.style.display = 'block';
      }
    }
  </script>

<?php else: ?>
  <!-- SIMPLE FAST DASHBOARD -->
  <nav class="nav">
    <div class="nav-inner">
      <a href="/" class="brand">
        <span class="brand-badge">TS</span>
        <span>TokenSwitch</span>
      </a>

      <div class="nav-right">
        <select id="strategySelect" onchange="updateStrategy()">
          <option value="priority_fallback">Priority Fallback</option>
          <option value="round_robin">Round Robin</option>
          <option value="least_errors">Least Errors</option>
        </select>
        <button class="btn btn-primary" onclick="triggerOpenAiLogin()">
          Login to OpenAI
        </button>
        <button class="btn" onclick="openOpenAiAuthModal()">+ Paste Token</button>
        <button class="btn" onclick="openAddModal()">+ Add Route</button>
        <button class="btn" onclick="logout()" style="color: var(--text-dim);">Logout</button>
      </div>
    </div>
  </nav>

  <div class="container">
    <div class="page-header">
      <div>
        <h1>Multi-Account Gateway & Failover Switcher</h1>
        <p>Direct OpenAI account OAuth, automatic 429 quota rotation, and real-time SSE streaming proxy.</p>
      </div>
      <div style="font-size: 12px; color: var(--text-muted); font-family: var(--font-mono);">
        Proxy: <span style="color: var(--orange);">https://tokenswitch.zipperi.com/v1</span>
      </div>
    </div>

    <!-- Active Route Telemetry -->
    <div class="card">
      <div class="card-head">
        <div class="card-title">
          <span>Active Route</span>
          <span id="activeAccountPill" class="pill pill-active">Detecting...</span>
        </div>
        <div style="font-size: 12px; color: var(--text-muted);">
          Requests proxy through this live credential
        </div>
      </div>
      <div class="card-body">
        <div class="route-grid">
          <div class="route-box">
            <div class="route-label">Base URL</div>
            <div class="route-row">
              <span class="route-val" id="exportBaseUrl">...</span>
              <button class="btn btn-sm" onclick="copyText('exportBaseUrl')">Copy</button>
            </div>
          </div>
          <div class="route-box">
            <div class="route-label">Bearer Token / Key</div>
            <div class="route-row">
              <span class="route-val" id="exportApiKey">...</span>
              <button class="btn btn-sm" onclick="copyText('exportApiKey')">Copy</button>
            </div>
          </div>
          <div class="route-box">
            <div class="route-label">Model & Reasoning</div>
            <div class="route-row">
              <span class="route-val" id="exportModel">...</span>
              <span class="pill pill-oauth" id="exportReasoningPill" style="font-family:var(--font-mono); font-size:11px;">medium</span>
              <button class="btn btn-sm" onclick="copyText('exportModel')">Copy</button>
            </div>
          </div>
          <div class="route-box">
            <div class="route-label">Quota & Tokens Left</div>
            <div class="route-row">
              <span class="route-val" id="exportQuota">Syncing...</span>
              <button class="btn btn-sm" onclick="syncUsage(false)">Sync</button>
            </div>
          </div>
        </div>

        <div class="tabs">
          <button class="tab active" onclick="showTab('env', event)">.env</button>
          <button class="tab" onclick="showTab('json', event)">JSON</button>
          <button class="tab" onclick="showTab('bash', event)">Bash</button>
          <button class="tab" onclick="showTab('powershell', event)">PowerShell</button>
          <button class="tab" onclick="showTab('cpanel', event)">cURL</button>
        </div>

        <pre class="code" id="exportSnippet">Loading configuration snippet...</pre>
      </div>
    </div>

    <!-- Configured Account Pool Table -->
    <div class="card">
      <div class="card-head">
        <div class="card-title">Configured Account Pool</div>
        <div style="display: flex; gap: 8px;">
          <button class="btn btn-sm btn-primary" onclick="triggerOpenAiLogin()">Login to OpenAI</button>
          <button class="btn btn-sm" id="syncUsageBtn" onclick="syncUsage(false)">Sync Usage</button>
          <button class="btn btn-sm" onclick="fetchStatus()">Refresh</button>
        </div>
      </div>
      <div style="overflow-x: auto;">
        <table>
          <thead>
            <tr>
              <th>Status</th>
              <th>Account</th>
              <th>Type</th>
              <th>Model & Reasoning</th>
              <th>Quota / Tokens Left</th>
              <th>Priority</th>
              <th>Cooldown</th>
              <th>Success / Fail</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="accountsTableBody">
            <tr><td colspan="9" style="text-align: center; color: var(--text-dim); padding: 24px;">Loading accounts...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Multi-Turn Back-and-Forth Streaming & Prompt Cache Console -->
    <div class="card">
      <div class="card-head">
        <div class="card-title">
          <span>Back-and-Forth Codex Streaming & Prompt Cache Console</span>
          <span id="cacheBadge" class="pill pill-oauth" style="font-size:10px;">KV Cache Active</span>
        </div>
        <div style="font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 10px;">
          <span id="streamAccountInfo">Route: -</span> | <span id="streamStats">Turn 0 • 0 tokens</span>
          <button class="btn btn-sm" onclick="resetChatSession()">Reset Session</button>
        </div>
      </div>
      <div class="card-body">
        <div class="stream-box" id="streamOutput" style="margin-bottom: 12px; min-height: 200px;">Send a message below to stream back-and-forth with Codex (`gpt-6-luna`) using persistent `x-codex-turn-state` + `prompt_cache_key` KV caching...</div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
          <select id="testReasoningEffort" title="Reasoning Effort Mode" style="font-family: var(--font-mono); font-size: 12px; color: #ff9d66; min-width: 150px;">
            <option value="">Reasoning: Account Default</option>
            <option value="minimal">Reasoning: minimal</option>
            <option value="low">Reasoning: low</option>
            <option value="medium" selected>Reasoning: medium</option>
            <option value="high">Reasoning: high</option>
            <option value="xhigh">Reasoning: xhigh</option>
            <option value="max">Reasoning: max</option>
          </select>
          <input type="text" id="testPrompt" style="flex: 1; min-width: 240px;" placeholder="Type a message and press Enter to stream back-and-forth..." value="Remember the secret codeword: AURORA-7. Confirm in one short sentence." onkeydown="if(event.key==='Enter') runStreamingTest()" />
          <button class="btn btn-primary" id="streamBtn" onclick="runStreamingTest()">
            Send & Stream
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Sign In with OpenAI Account Modal -->
  <div class="modal-overlay" id="openAiAuthModal">
    <div class="modal">
      <div class="modal-title">Connect OpenAI / ChatGPT Account</div>

      <button class="btn btn-primary" onclick="triggerOpenAiLogin()" style="width: 100%; justify-content: center; padding: 12px; margin-bottom: 14px; font-size: 13px;">Sign In directly via OpenAI (Browser OAuth)</button>

      <div style="text-align: center; font-size: 11px; color: var(--text-dim); margin-bottom: 12px;">- OR PASTE TOKENS MANUALLY -</div>

      <div class="form-group">
        <label>Account Label</label>
        <input type="text" id="oaAuthName" value="OpenAI Account" placeholder="e.g. Personal Pro" />
      </div>

      <div class="form-group">
        <label>OAuth Access Token or Refresh Token</label>
        <textarea id="oaAuthToken" rows="3" placeholder="Paste access token (eyJ...) or refresh token" style="font-family: var(--font-mono); font-size: 11px;"></textarea>
      </div>

      <div class="form-group">
        <label>Optional Refresh Token (For Silent Auto-Renewal)</label>
        <input type="password" id="oaAuthRefreshToken" placeholder="OAuth refresh token" />
      </div>

      <div style="display: flex; gap: 8px;">
        <div class="form-group" style="flex: 1;">
          <label>Model</label>
          <select id="oaAuthModel">
            <option value="gpt-6-luna" selected>gpt-6-luna (Codex Primary)</option>
            <option value="gpt-5.6-luna">gpt-5.6-luna</option>
            <option value="gpt-5.6-terra">gpt-5.6-terra</option>
            <option value="gpt-5.5">gpt-5.5</option>
            <option value="gpt-reserve">gpt-reserve</option>
            <option value="codex-auto-review">codex-auto-review</option>
            <option value="gpt-4o">gpt-4o</option>
          </select>
        </div>
        <div class="form-group" style="flex: 1;">
          <label>Priority</label>
          <input type="number" id="oaAuthPriority" value="1" min="1" max="50" />
        </div>
      </div>

      <div id="oaAuthStatus" style="font-size: 12px; margin-bottom: 12px; display: none;"></div>

      <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px;">
        <button class="btn" onclick="closeOpenAiAuthModal()">Cancel</button>
        <button class="btn btn-primary" onclick="connectOpenAiAuth()">Connect Account</button>
      </div>
    </div>
  </div>

  <!-- Add Custom Route Modal -->
  <div class="modal-overlay" id="accountModal">
    <div class="modal">
      <div class="modal-title">Add Custom Route</div>

      <div class="form-group">
        <label>Account ID (Slug)</label>
        <input type="text" id="accId" placeholder="e.g. groq-backup" />
      </div>

      <div class="form-group">
        <label>Account Name</label>
        <input type="text" id="accName" placeholder="e.g. Groq Llama 3" />
      </div>

      <div class="form-group">
        <label>Base URL</label>
        <input type="text" id="accBaseUrl" value="https://api.openai.com/v1" />
      </div>

      <div class="form-group">
        <label>API Key / Bearer Token</label>
        <input type="password" id="accApiKey" placeholder="API key or token" />
      </div>

      <div class="form-group">
        <label>Model Name</label>
        <input type="text" id="accModel" value="gpt-6-luna" />
      </div>

      <div style="display: flex; gap: 8px;">
        <div class="form-group" style="flex: 1;">
          <label>Priority</label>
          <input type="number" id="accPriority" value="2" min="1" />
        </div>
        <div class="form-group" style="flex: 1;">
          <label>Cooldown (sec)</label>
          <input type="number" id="accCooldown" value="60" min="5" />
        </div>
      </div>

      <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px;">
        <button class="btn" onclick="closeModal()">Cancel</button>
        <button class="btn btn-primary" onclick="saveCustomAccount()">Save Route</button>
      </div>
    </div>
  </div>

  <div class="toast" id="toastMsg">Action completed</div>

  <script>
    let currentExportData = null;
    let activeTab = 'env';
    let autoSyncTriggered = false;

    function escapeHtml(str) {
      return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function formatResetTime(resetAt, resetAfterSecs) {
      let secs = 0;
      const nowSec = Math.floor(Date.now() / 1000);
      if (resetAt && resetAt > nowSec) {
        secs = resetAt - nowSec;
      } else if (resetAfterSecs && resetAfterSecs > 0) {
        secs = resetAfterSecs;
      }
      if (secs <= 0) return '';
      const days = Math.floor(secs / 86400);
      const hours = Math.floor((secs % 86400) / 3600);
      const mins = Math.floor((secs % 3600) / 60);
      if (days > 0) return `Resets in ${days}d ${hours}h`;
      if (hours > 0) return `Resets in ${hours}h ${mins}m`;
      return `Resets in ${mins}m`;
    }

    async function syncUsage(silent = false) {
      const btn = document.getElementById('syncUsageBtn');
      if (btn && !silent) {
        btn.disabled = true;
        btn.innerText = 'Syncing...';
      }
      try {
        const res = await fetch('/api/usage/sync', { method: 'POST' });
        const data = await res.json();
        if (!silent && res.ok) {
          showToast(`Synced live quota for ${data.synced || 0} account(s)`);
        }
        await fetchStatus();
      } catch (err) {
        if (!silent) showToast('Failed to sync quota');
      } finally {
        if (btn && !silent) {
          btn.disabled = false;
          btn.innerText = 'Sync Usage';
        }
      }
    }

    async function fetchStatus() {
      try {
        const res = await fetch('/api/status');
        if (res.status === 401) { window.location.reload(); return; }
        const data = await res.json();
        renderAccounts(data.accounts);
        document.getElementById('strategySelect').value = data.strategy;
        await fetchExportRoute();

        if (!autoSyncTriggered && data.accounts) {
          const nowSec = Math.floor(Date.now() / 1000);
          const needsSync = data.accounts.some(a =>
            (a.auth_type === 'chatgpt_oauth' || (a.api_key && a.api_key.startsWith('eyJ'))) &&
            (!a.quota || !a.quota.updated_at || (nowSec - a.quota.updated_at) > 300)
          );
          if (needsSync) {
            autoSyncTriggered = true;
            syncUsage(true);
          }
        }
      } catch (err) {
        console.error(err);
      }
    }

    async function fetchExportRoute() {
      try {
        const res = await fetch('/api/export');
        if (!res.ok) {
          document.getElementById('activeAccountPill').innerText = 'No Route Active';
          document.getElementById('activeAccountPill').className = 'pill pill-disabled';
          return;
        }
        const data = await res.json();
        currentExportData = data;

        document.getElementById('exportBaseUrl').innerText = data.route.base_url;
        document.getElementById('exportApiKey').innerText = data.route.proxy_key || data.route.api_key;
        document.getElementById('exportModel').innerText = data.route.model;
        const rPill = document.getElementById('exportReasoningPill');
        if (rPill) rPill.innerText = data.route.reasoning_effort || 'medium';

        const q = data.route.quota;
        const totTok = Number(data.route.total_tokens || 0).toLocaleString();
        const cachedTok = Number(data.route.cached_tokens || 0).toLocaleString();
        const quotaEl = document.getElementById('exportQuota');
        if (q && typeof q.remaining_percent !== 'undefined') {
          const plan = String(q.plan_type || 'free').toUpperCase();
          quotaEl.innerText = `${q.remaining_percent}% Left (${plan}) • ${totTok} tok (${cachedTok} cached)`;
          quotaEl.style.color = q.remaining_percent > 30 ? '#3fb950' : (q.remaining_percent > 10 ? '#e3b341' : '#f85149');
        } else {
          quotaEl.innerText = `${totTok} tok (${cachedTok} cached)`;
          quotaEl.style.color = '#ff9d66';
        }

        document.getElementById('activeAccountPill').innerText = `Active: ${data.route.name}`;
        document.getElementById('activeAccountPill').className = 'pill pill-active';

        renderTabSnippet();
      } catch (err) {
        console.error(err);
      }
    }

    function renderTabSnippet() {
      if (!currentExportData) return;
      const el = document.getElementById('exportSnippet');
      const eff = currentExportData.route.reasoning_effort || 'medium';
      if (activeTab === 'env') el.innerText = currentExportData.env_format;
      else if (activeTab === 'json') el.innerText = currentExportData.json_format;
      else if (activeTab === 'bash') el.innerText = currentExportData.bash_export;
      else if (activeTab === 'powershell') el.innerText = currentExportData.powershell_export;
      else if (activeTab === 'cpanel') {
        el.innerText = `# Route any LLM tool or Codex agent directly through tokenswitch.zipperi.com
OPENAI_BASE_URL="https://tokenswitch.zipperi.com/v1"
OPENAI_API_KEY="Its.me@12345"
OPENAI_MODEL="${currentExportData.route.model}"

# Reasoning modes supported: minimal | low | medium | high | xhigh | max
# Pass via "reasoning_effort", "reasoning": {"effort": "..."}, model suffix (e.g. "gpt-6-luna-xhigh"), or X-Reasoning-Effort header:
curl https://tokenswitch.zipperi.com/v1/chat/completions \\
  -H "Authorization: Bearer Its.me@12345" \\
  -H "Content-Type: application/json" \\
  -d '{"model":"gpt-6-luna","reasoning_effort":"${eff}","messages":[{"role":"user","content":"Hello!"}],"stream":true}'`;
      }
    }

    function showTab(name, e) {
      activeTab = name;
      document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
      e.target.classList.add('active');
      renderTabSnippet();
    }

    function renderAccounts(accounts) {
      const tbody = document.getElementById('accountsTableBody');
      if (!accounts || accounts.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: var(--text-dim); padding: 24px;">No accounts found. Click "Login to OpenAI" to add one.</td></tr>';
        return;
      }

      tbody.innerHTML = accounts.map(acc => {
        let statusBadge = '';
        if (!acc.enabled) {
          statusBadge = '<span class="pill pill-disabled">Disabled</span>';
        } else if (acc.is_cooling_down) {
          statusBadge = `<span class="pill pill-cooldown">Cooldown (${acc.cooldown_remaining_secs}s)</span>`;
        } else if (acc.is_active_route) {
          statusBadge = '<span class="pill pill-active">Active</span>';
        } else {
          statusBadge = '<span class="pill pill-standby">Standby</span>';
        }

        const isOAuth = acc.auth_type === 'chatgpt_oauth' || (acc.api_key && acc.api_key.startsWith('eyJ'));
        const authBadge = isOAuth
          ? '<span class="pill pill-oauth">Codex OAuth</span>'
          : '<span class="pill pill-key">API Key</span>';

        const presetModels = ['gpt-6-luna', 'gpt-5.6-luna', 'gpt-5.6-terra', 'gpt-5.5', 'gpt-reserve', 'codex-auto-review', 'gpt-4o', 'gpt-4o-mini', 'o3-mini', 'o1'];
        const currentModel = acc.model || 'gpt-6-luna';
        const allOptions = presetModels.includes(currentModel) ? presetModels : [currentModel, ...presetModels];
        const modelOptionsHtml = allOptions.map(m => `<option value="${escapeHtml(m)}" ${m === currentModel ? 'selected' : ''}>${escapeHtml(m)}</option>`).join('') + '<option value="__custom__">+ Custom model...</option>';

        const reasoningLevels = ['minimal', 'low', 'medium', 'high', 'xhigh', 'max'];
        const currentEffort = acc.reasoning_effort || 'medium';
        const reasoningOptionsHtml = reasoningLevels.map(r => `<option value="${r}" ${r === currentEffort ? 'selected' : ''}>🧠 ${r}</option>`).join('');

        const totalTok = Number(acc.total_tokens || 0).toLocaleString();
        const inTok = Number(acc.prompt_tokens || 0).toLocaleString();
        const outTok = Number(acc.completion_tokens || 0).toLocaleString();
        const cachedTok = Number(acc.cached_tokens || 0).toLocaleString();
        const reasTok = Number(acc.reasoning_tokens || 0).toLocaleString();
        const tokLine = `<div style="font-size:11px; color:var(--text-muted); font-family:var(--font-mono); margin-top:3px;">${totalTok} tok <span style="color:var(--text-dim);">(${inTok} in / ${outTok} out)</span><br/><span style="color:#3fb950;">⚡ ${cachedTok} cached</span> • <span style="color:#ff9d66;">🧠 ${reasTok} reasoning</span></div>`;

        let quotaHtml = '';
        if (acc.quota && typeof acc.quota.remaining_percent !== 'undefined') {
          const rem = Number(acc.quota.remaining_percent);
          const used = Number(acc.quota.used_percent || (100 - rem));
          const plan = escapeHtml(String(acc.quota.plan_type || 'free').toUpperCase());
          const barColor = rem > 30 ? '#3fb950' : (rem > 10 ? '#e3b341' : '#f85149');
          const resetLabel = formatResetTime(acc.quota.reset_at, acc.quota.reset_after_seconds);

          quotaHtml = `
            <div style="min-width:170px;">
              <div style="display:flex; justify-content:space-between; align-items:center; font-size:12px; margin-bottom:4px;">
                <strong style="color:${barColor};">${rem}% Left</strong>
                <span style="font-size:10px; background:#1f242c; border:1px solid var(--border); padding:1px 5px; border-radius:3px; color:var(--text-muted); font-family:var(--font-mono);">${plan}</span>
              </div>
              <div style="width:100%; height:6px; background:#0b0d10; border:1px solid var(--border); border-radius:3px; overflow:hidden;">
                <div style="width:${Math.max(0, Math.min(100, rem))}%; height:100%; background:${barColor};"></div>
              </div>
              <div style="display:flex; justify-content:space-between; font-size:10px; color:var(--text-dim); margin-top:3px;">
                <span>${used}% used</span>
                <span>${escapeHtml(resetLabel)}</span>
              </div>
              ${tokLine}
            </div>
          `;
        } else if (isOAuth) {
          quotaHtml = `
            <div style="min-width:150px;">
              <button class="btn btn-sm" onclick="syncUsage(false)" style="font-size:11px; padding:2px 8px;">Sync Quota</button>
              ${tokLine}
            </div>
          `;
        } else {
          quotaHtml = `
            <div style="min-width:140px;">
              <span style="font-size:11px; color:var(--text-dim);">Standard API</span>
              ${tokLine}
            </div>
          `;
        }

        return `
          <tr>
            <td>${statusBadge}</td>
            <td>
              <strong style="color:var(--text);">${escapeHtml(acc.name)}</strong><br/>
              <span style="font-size:11px; color:var(--text-dim); font-family:var(--font-mono);">${escapeHtml(acc.id)}</span>
            </td>
            <td>${authBadge}</td>
            <td>
              <div style="display:flex; flex-direction:column; gap:4px;">
                <select style="font-family:var(--font-mono); font-size:12px; padding:4px 8px; background:#0b0d10; border:1px solid var(--border); color:#ff9d66; border-radius:4px; cursor:pointer;" onchange="updateAccountModel('${acc.id}', this.value, '${escapeHtml(currentModel)}')">
                  ${modelOptionsHtml}
                </select>
                <select title="Default Reasoning Effort (can be overridden per API request)" style="font-family:var(--font-mono); font-size:11px; padding:3px 8px; background:#0b0d10; border:1px solid var(--border); color:#3fb950; border-radius:4px; cursor:pointer;" onchange="updateAccountReasoning('${acc.id}', this.value)">
                  ${reasoningOptionsHtml}
                </select>
              </div>
            </td>
            <td>${quotaHtml}</td>
            <td>P${acc.priority}</td>
            <td>${acc.cooldown_secs}s</td>
            <td>
              <span style="color:#3fb950;">${acc.total_successes}</span> / 
              <span style="color:#f85149;">${acc.total_failures}</span>
            </td>
            <td>
              <div style="display:flex; gap:4px;">
                <button class="btn btn-sm" title="Simulate 429 Rate Limit" onclick="simulate429('${acc.id}')">429</button>
                <button class="btn btn-sm" onclick="toggleAccount('${acc.id}', ${!acc.enabled})">${acc.enabled ? 'Pause' : 'Resume'}</button>
                <button class="btn btn-sm btn-danger" onclick="deleteAccount('${acc.id}')">Del</button>
              </div>
            </td>
          </tr>
        `;
      }).join('');
    }

    async function updateAccountModel(id, val, prevModel) {
      let targetModel = val;
      if (val === '__custom__') {
        const custom = prompt('Enter custom model identifier for this account:', prevModel || 'gpt-4o');
        if (!custom || !custom.trim()) {
          fetchStatus();
          return;
        }
        targetModel = custom.trim();
      }
      const res = await fetch(`/api/accounts/${id}/model`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ model: targetModel })
      });
      if (res.ok) {
        showToast(`Updated model to ${targetModel}`);
      } else {
        showToast('Failed to update model');
      }
      fetchStatus();
    }

    async function updateAccountReasoning(id, effort) {
      const res = await fetch(`/api/accounts/${id}/reasoning`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reasoning_effort: effort })
      });
      if (res.ok) {
        showToast(`Updated reasoning mode to ${effort}`);
      } else {
        showToast('Failed to update reasoning mode');
      }
      fetchStatus();
    }

    async function forceSwitch() {
      const res = await fetch('/api/switch', { method: 'POST' });
      const data = await res.json();
      showToast(`Switched active route to: ${data.name || data.id}`);
      fetchStatus();
    }

    async function updateStrategy() {
      const val = document.getElementById('strategySelect').value;
      await fetch('/api/strategy', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ strategy: val })
      });
      showToast(`Strategy: ${val}`);
      fetchStatus();
    }

    async function simulate429(id) {
      await fetch('/api/report', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, status_code: 429, error_message: 'Simulated 429 Rate Limit' })
      });
      showToast(`Simulated 429 on ${id}. Switched!`);
      fetchStatus();
    }

    async function toggleAccount(id, enable) {
      await fetch(`/api/accounts/${id}/toggle`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ enabled: enable })
      });
      fetchStatus();
    }

    async function deleteAccount(id) {
      if (!confirm(`Delete account "${id}"?`)) return;
      await fetch(`/api/accounts/${id}`, { method: 'DELETE' });
      showToast(`Deleted ${id}`);
      fetchStatus();
    }

    function openOpenAiAuthModal() {
      document.getElementById('oaAuthToken').value = '';
      document.getElementById('oaAuthRefreshToken').value = '';
      document.getElementById('oaAuthStatus').style.display = 'none';
      document.getElementById('openAiAuthModal').style.display = 'flex';
    }

    function closeOpenAiAuthModal() {
      document.getElementById('openAiAuthModal').style.display = 'none';
    }

    async function connectOpenAiAuth() {
      const token = document.getElementById('oaAuthToken').value.trim();
      const refreshToken = document.getElementById('oaAuthRefreshToken').value.trim();
      const name = document.getElementById('oaAuthName').value.trim();
      const model = document.getElementById('oaAuthModel').value;
      const priority = parseInt(document.getElementById('oaAuthPriority').value, 10) || 1;
      const statusEl = document.getElementById('oaAuthStatus');

      if (!token && !refreshToken) {
        alert('Please provide an Access Token or Refresh Token.');
        return;
      }

      statusEl.style.display = 'block';
      statusEl.style.color = 'var(--text-muted)';
      statusEl.innerText = 'Connecting account...';

      const res = await fetch('/api/accounts/oauth-connect', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          token: token,
          refresh_token: refreshToken,
          name: name,
          model: model,
          priority: priority,
          cooldown_secs: 60
        })
      });

      const data = await res.json();
      if (res.ok && data.status === 'ok') {
        closeOpenAiAuthModal();
        showToast(data.message || 'Connected!');
        fetchStatus();
      } else {
        statusEl.style.color = '#f85149';
        statusEl.innerText = data.message || 'Failed to connect.';
      }
    }

    function openAddModal() {
      document.getElementById('accId').value = 'acc-' + Math.floor(Math.random() * 1000);
      document.getElementById('accountModal').style.display = 'flex';
    }

    function closeModal() {
      document.getElementById('accountModal').style.display = 'none';
    }

    async function saveCustomAccount() {
      const acc = {
        id: document.getElementById('accId').value.trim(),
        name: document.getElementById('accName').value.trim(),
        base_url: document.getElementById('accBaseUrl').value.trim(),
        api_key: document.getElementById('accApiKey').value.trim(),
        model: document.getElementById('accModel').value.trim(),
        priority: parseInt(document.getElementById('accPriority').value, 10) || 1,
        cooldown_secs: parseInt(document.getElementById('accCooldown').value, 10) || 60,
        enabled: true
      };

      await fetch('/api/accounts', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(acc)
      });
      closeModal();
      showToast('Account saved');
      fetchStatus();
    }

    let chatHistory = [];
    let chatSessionId = 'ts-session-' + Math.random().toString(36).substring(2, 10);
    let turnNumber = 0;

    function resetChatSession() {
      chatHistory = [];
      chatSessionId = 'ts-session-' + Math.random().toString(36).substring(2, 10);
      turnNumber = 0;
      document.getElementById('streamOutput').innerText = 'Session reset! Send a message below to start a new multi-turn cached Codex stream...';
      document.getElementById('streamStats').innerText = 'Turn 0 • 0 tokens';
      document.getElementById('testPrompt').value = 'Remember the secret codeword: AURORA-7. Confirm in one short sentence.';
    }

    async function runStreamingTest() {
      const inputEl = document.getElementById('testPrompt');
      const effortEl = document.getElementById('testReasoningEffort');
      const selectedEffort = effortEl ? effortEl.value : '';
      const prompt = inputEl.value.trim();
      if (!prompt) return;

      const outputEl = document.getElementById('streamOutput');
      const infoEl = document.getElementById('streamAccountInfo');
      const statsEl = document.getElementById('streamStats');
      const btn = document.getElementById('streamBtn');

      if (turnNumber === 0) {
        outputEl.innerText = '';
        // Include a >1,200 token system prompt so OpenAI's 1024-token prefix KV cache engages on Turn 2+
        const sysContext = 'You are an advanced autonomous coding assistant running on the OpenAI Codex Rust backend through TokenSwitch. Maintain full multi-turn context and respond concisely and accurately. '.repeat(55);
        chatHistory.push({ role: 'system', content: sysContext });
      }

      turnNumber++;
      chatHistory.push({ role: 'user', content: prompt });
      inputEl.value = '';
      outputEl.innerText += (outputEl.innerText ? '\n\n' : '') + `[You (Turn ${turnNumber})]: ${prompt}\n[Codex]: `;
      outputEl.scrollTop = outputEl.scrollHeight;
      btn.disabled = true;

      const startTime = performance.now();
      let tokenCount = 0;
      let assistantReply = '';
      let usageInfo = null;

      try {
        const reqBody = {
          prompt_cache_key: chatSessionId,
          messages: chatHistory,
          stream: true
        };
        if (selectedEffort) {
          reqBody.reasoning_effort = selectedEffort;
        }

        const response = await fetch('/v1/chat/completions', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Session-Id': chatSessionId
          },
          body: JSON.stringify(reqBody)
        });

        const activeName = response.headers.get('X-Routed-Account') || 'Active Account';
        const activeModel = response.headers.get('X-Routed-Model') || 'Model';
        const activeEffort = response.headers.get('X-Reasoning-Effort') || selectedEffort || 'medium';
        infoEl.innerText = `Routed: ${activeName} (${activeModel} • 🧠 ${activeEffort})`;

        const reader = response.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let buffer = '';

        while (true) {
          const { done, value } = await reader.read();
          if (done) break;

          buffer += decoder.decode(value, { stream: true });
          const lines = buffer.split('\n');
          buffer = lines.pop();

          for (const line of lines) {
            const trimmed = line.trim();
            if (!trimmed || trimmed.startsWith(':')) continue;
            if (trimmed === 'data: [DONE]') break;
            if (trimmed.startsWith('data: ')) {
              try {
                const chunk = JSON.parse(trimmed.substring(6));
                if (chunk.usage) {
                  usageInfo = chunk.usage;
                }
                const text = chunk.choices?.[0]?.delta?.content || '';
                if (text) {
                  assistantReply += text;
                  outputEl.innerText += text;
                  tokenCount++;
                  outputEl.scrollTop = outputEl.scrollHeight;
                  const elapsed = ((performance.now() - startTime) / 1000).toFixed(1);
                  statsEl.innerText = `Turn ${turnNumber} • ${tokenCount} chunks (${elapsed}s)`;
                }
              } catch (e) { }
            }
          }
        }

        chatHistory.push({ role: 'assistant', content: assistantReply });
        if (usageInfo) {
          const cached = usageInfo.prompt_tokens_details?.cached_tokens || 0;
          const reasTok = usageInfo.completion_tokens_details?.reasoning_tokens || 0;
          const elapsed = ((performance.now() - startTime) / 1000).toFixed(1);
          statsEl.innerText = `Turn ${turnNumber} • ${usageInfo.prompt_tokens} in (⚡ ${cached} cached) / ${usageInfo.completion_tokens} out (🧠 ${reasTok} reasoning) (${elapsed}s)`;
        }
        if (turnNumber === 1) {
          inputEl.value = 'What was the secret codeword I just told you?';
        }
      } catch (err) {
        outputEl.innerText += `\n[Stream Error]: ${err.message}`;
      } finally {
        btn.disabled = false;
        fetchStatus();
      }
    }

    async function logout() {
      await fetch('/api/logout');
      window.location.reload();
    }

    function copyText(id) {
      navigator.clipboard.writeText(document.getElementById(id).innerText);
      showToast('Copied to clipboard');
    }

    function showToast(msg) {
      const t = document.getElementById('toastMsg');
      t.innerText = msg;
      t.style.display = 'block';
      setTimeout(() => { t.style.display = 'none'; }, 2200);
    }

    async function triggerOpenAiLogin() {
      const w = 520, h = 680;
      const left = Math.round(window.screenX + (window.outerWidth - w) / 2);
      const top = Math.round(window.screenY + (window.outerHeight - h) / 2);
      const popup = window.open('http://localhost:1455/login', 'openai_login_popup', 'width=' + w + ',height=' + h + ',top=' + top + ',left=' + left + ',status=no,menubar=no,toolbar=no');

      if (!popup) {
        window.location.href = 'http://localhost:1455/login';
        return;
      }

      showToast('Login window opened. Please complete authentication.');
    }

    window.addEventListener('message', (event) => {
      if (event.data && event.data.type === 'tokenswitch_oauth_success') {
        showToast('Successfully connected OpenAI Account (' + (event.data.email || 'OAuth') + ')!');
        fetchStatus();
      }
    });
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('connected') === '1') {
      const email = urlParams.get('email') || 'Account';
      showToast(`Successfully connected OpenAI Account (${email})!`);
      window.history.replaceState({}, document.title, window.location.pathname);
    }
    if (urlParams.get('error')) {
      showToast(`OAuth Error: ${urlParams.get('error')}`);
      window.history.replaceState({}, document.title, window.location.pathname);
    }

    setInterval(fetchStatus, 3000);
    fetchStatus();
  </script>
<?php endif; ?>

</body>
</html>

