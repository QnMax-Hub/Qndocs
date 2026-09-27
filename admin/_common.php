<?php
/**
 * Qndocs - 后台公共入口：加载引导 + 登录校验
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_install();

if (!DB::ready()) {
    http_response_code(500);
    exit('数据库未连接，请检查 data/config.php。');
}

require_login();
require_once __DIR__ . '/layout.php';
