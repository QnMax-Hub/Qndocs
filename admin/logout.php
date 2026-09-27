<?php
/**
 * Qndocs - 退出登录
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

qn_logout();
flash('已安全退出登录。', 'info');
redirect(admin_url('login.php'));
