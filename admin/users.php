<?php
/**
 * Qndocs - 用户管理（仅管理员）
 */

require_once __DIR__ . '/_common.php';
require_admin();

$me = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    qn_require_csrf();
    $action = (string) post('action');
    $id     = (int) post('id');
    $target = $id > 0 ? DB::one('SELECT * FROM {users} WHERE id = ?', [$id]) : null;

    if ($action === 'create') {
        $username = trim((string) post('username'));
        $password = (string) post('password');
        if (!preg_match('/^[a-zA-Z0-9_\-\.]{3,32}$/', $username)) {
            flash('账号只能包含字母、数字、下划线、短横线、点，长度 3-32。', 'error');
        } elseif (mb_strlen($password, 'UTF-8') < 6) {
            flash('密码至少 6 位。', 'error');
        } elseif (DB::val('SELECT id FROM {users} WHERE username = ?', [$username])) {
            flash('该账号已存在。', 'error');
        } else {
            DB::insert('users', [
                'username'   => $username,
                'password'   => password_hash($password, PASSWORD_DEFAULT),
                'nickname'   => trim((string) post('nickname')),
                'email'      => trim((string) post('email')),
                'role'       => post('role') === 'admin' ? 'admin' : 'editor',
                'status'     => 1,
                'last_login' => null,
                'last_ip'    => '',
                'created_at' => qn_now(),
            ]);
            flash('成员「' . $username . '」创建成功。');
        }
    } elseif ($action === 'password' && $target) {
        $password = (string) post('password');
        if (mb_strlen($password, 'UTF-8') < 6) {
            flash('密码至少 6 位。', 'error');
        } else {
            DB::update('users', ['password' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$id]);
            flash('已重置「' . $target['username'] . '」的登录密码。');
        }
    } elseif ($action === 'toggle' && $target) {
        if ($id === $me) {
            flash('不能禁用自己的账号。', 'error');
        } else {
            DB::update('users', ['status' => (int) $target['status'] === 1 ? 0 : 1], 'id = ?', [$id]);
            flash('已' . ((int) $target['status'] === 1 ? '禁用' : '启用') . '「' . $target['username'] . '」。');
        }
    } elseif ($action === 'role' && $target) {
        if ($id === $me) {
            flash('不能修改自己的角色。', 'error');
        } else {
            $role = post('role') === 'admin' ? 'admin' : 'editor';
            if ($target['role'] === 'admin' && $role !== 'admin'
                && (int) DB::val("SELECT COUNT(*) FROM {users} WHERE role = 'admin' AND status = 1") <= 1) {
                flash('至少需要保留一个管理员账号。', 'error');
            } else {
                DB::update('users', ['role' => $role], 'id = ?', [$id]);
                flash('角色已更新为 ' . ($role === 'admin' ? '管理员' : '编辑') . '。');
            }
        }
    } elseif ($action === 'delete' && $target) {
        if ($id === $me) {
            flash('不能删除自己的账号。', 'error');
        } elseif ($target['role'] === 'admin'
            && (int) DB::val("SELECT COUNT(*) FROM {users} WHERE role = 'admin'") <= 1) {
            flash('至少需要保留一个管理员账号。', 'error');
        } else {
            DB::delete('users', 'id = ?', [$id]);
            flash('成员「' . $target['username'] . '」已删除。');
        }
    }
    redirect(admin_url('users.php'));
}

$users = DB::all('SELECT * FROM {users} ORDER BY id ASC');

admin_head('用户管理', 'users.php');
?>

<?php ad_card_open('成员列表'); ?>
  <div class="ad-table-wrap">
    <table class="ad-table">
      <thead><tr><th>账号</th><th>昵称</th><th>角色</th><th>状态</th><th>最后登录</th><th class="ops">操作</th></tr></thead>
      <tbody>
      <?php foreach ($users as $user): ?>
        <tr>
          <td class="ad-cell-main">
            <strong><?= e($user['username']) ?></strong>
            <?php if ((int) $user['id'] === $me): ?><span class="ad-badge info">当前登录</span><?php endif; ?>
            <?php if ($user['email'] !== ''): ?><span class="ad-slug"><?= e($user['email']) ?></span><?php endif; ?>
          </td>
          <td><?= e($user['nickname']) ?></td>
          <td>
            <form method="post" class="ad-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="role">
              <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
              <select name="role" class="ad-input xs" onchange="this.form.submit()" <?= (int) $user['id'] === $me ? 'disabled' : '' ?>>
                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>管理员</option>
                <option value="editor" <?= $user['role'] === 'editor' ? 'selected' : '' ?>>编辑</option>
              </select>
            </form>
          </td>
          <td><?= (int) $user['status'] === 1 ? '<span class="ad-badge ok">启用</span>' : '<span class="ad-badge muted">禁用</span>' ?></td>
          <td class="ad-muted">
            <?= e(qn_time_ago($user['last_login'])) ?>
            <?php if ($user['last_ip'] !== ''): ?><span class="ad-slug"><?= e($user['last_ip']) ?></span><?php endif; ?>
          </td>
          <td class="ops">
            <div class="ad-ops">
              <button type="button" class="ad-mini" data-prompt-password="<?= (int) $user['id'] ?>">改密码</button>
              <?php if ((int) $user['id'] !== $me): ?>
                <form method="post" class="ad-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                  <button class="ad-mini" type="submit"><?= (int) $user['status'] === 1 ? '禁用' : '启用' ?></button>
                </form>
                <form method="post" class="ad-inline" data-confirm="确定删除成员「<?= e($user['username']) ?>」？">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                  <button class="ad-mini danger" type="submit">删除</button>
                </form>
              <?php endif; ?>
            </div>
            <form method="post" class="ad-pass-form" id="adPassForm<?= (int) $user['id'] ?>" hidden>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="password">
              <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
              <input type="password" name="password" class="ad-input sm" placeholder="新密码（≥6 位）" required>
              <button class="ad-btn sm" type="submit">保存</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php ad_card_close(); ?>

<?php ad_card_open('新增成员'); ?>
  <form method="post" class="ad-form-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label><span>账号</span><input class="ad-input" type="text" name="username" required></label>
    <label><span>密码</span><input class="ad-input" type="text" name="password" required placeholder="至少 6 位"></label>
    <label><span>昵称</span><input class="ad-input" type="text" name="nickname"></label>
    <label><span>邮箱</span><input class="ad-input" type="email" name="email"></label>
    <label><span>角色</span>
      <select name="role">
        <option value="editor">编辑（可管理文档）</option>
        <option value="admin">管理员（全部权限）</option>
      </select>
    </label>
    <button class="ad-btn" type="submit"><?= qn_icon('plus') ?>添加成员</button>
  </form>
<?php ad_card_close(); ?>

<?php admin_foot(); ?>
