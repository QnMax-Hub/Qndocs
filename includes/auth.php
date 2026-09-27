<?php
/**
 * Qndocs - 登录 / 权限
 */

function qn_current_user()
{
    static $user = null;
    static $loaded = false;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    $id = isset($_SESSION['qn_uid']) ? (int) $_SESSION['qn_uid'] : 0;
    if ($id <= 0 || !class_exists('DB') || !DB::ready()) {
        return null;
    }
    $row = DB::one('SELECT * FROM {users} WHERE id = ? AND status = 1', [$id]);
    if (!$row) {
        unset($_SESSION['qn_uid']);
        return null;
    }
    $user = $row;
    return $user;
}

function is_logged_in(): bool
{
    return qn_current_user() !== null;
}

function current_user_id(): int
{
    $u = qn_current_user();
    return $u ? (int) $u['id'] : 0;
}

function current_user_name(): string
{
    $u = qn_current_user();
    return $u ? ($u['nickname'] !== '' ? $u['nickname'] : $u['username']) : '';
}

function is_admin(): bool
{
    $u = qn_current_user();
    return $u && $u['role'] === 'admin';
}

/** 登录，返回 [bool $ok, string $message] */
function qn_login(string $username, string $password): array
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return [false, '请输入用户名和密码'];
    }

    $lock = $_SESSION['qn_login_lock'] ?? 0;
    if ($lock > time()) {
        return [false, '尝试次数过多，请 ' . ceil(($lock - time()) / 60) . ' 分钟后再试'];
    }

    $user = DB::one('SELECT * FROM {users} WHERE username = ? LIMIT 1', [$username]);
    $hash = $user ? (string) $user['password'] : '';
    $valid = false;
    if ($user && $hash !== '') {
        $valid = password_verify($password, $hash);
        if (!$valid && strlen($hash) === 32 && hash_equals($hash, md5($password))) {
            // 兼容极早期的 MD5 密码，登录后自动升级为 bcrypt
            $valid = true;
            DB::update('users', ['password' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [(int) $user['id']]);
        }
    }
    if (!$valid) {
        $fails = (int) ($_SESSION['qn_login_fails'] ?? 0) + 1;
        $_SESSION['qn_login_fails'] = $fails;
        if ($fails >= 5) {
            $_SESSION['qn_login_lock'] = time() + 600;
            $_SESSION['qn_login_fails'] = 0;
        }
        return [false, '用户名或密码不正确'];
    }
    if ((int) $user['status'] !== 1) {
        return [false, '该账号已被禁用'];
    }

    unset($_SESSION['qn_login_fails'], $_SESSION['qn_login_lock']);
    session_regenerate_id(true);
    $_SESSION['qn_uid'] = (int) $user['id'];
    $_SESSION['qn_login_at'] = time();

    DB::update('users', [
        'last_login' => qn_now(),
        'last_ip'    => qn_client_ip(),
    ], 'id = ?', [(int) $user['id']]);

    return [true, '登录成功'];
}

function qn_logout(): void
{
    unset($_SESSION['qn_uid'], $_SESSION['qn_login_at']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/** 后台页面守卫 */
function require_login(): void
{
    if (!is_logged_in()) {
        $target = $_SERVER['REQUEST_URI'] ?? '';
        redirect(admin_url('login.php' . ($target !== '' ? '?redirect=' . rawurlencode($target) : '')));
    }
}

/** 仅管理员 */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('权限不足：仅管理员可以访问该页面。');
    }
}
