<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';

use Trafic\Auth;

$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim((string) ($_POST['username'] ?? ''));
    $p = (string) ($_POST['password'] ?? '');
    if (Auth::login($u, $p)) {
        header('Location: ' . admin_url('/'));
        exit;
    }
    $err = 'Invalid username or password.';
}

if (Auth::check()) {
    header('Location: ' . admin_url('/'));
    exit;
}

layout_head('Login');
?>
<div class="card login-box">
  <h1>Sign in</h1>
  <?php if ($err): ?><div class="flash flash-err"><?= h($err) ?></div><?php endif; ?>
  <form method="post">
    <div class="field">
      <label>Username</label>
      <input type="text" name="username" autofocus required>
    </div>
    <div class="field">
      <label>Password</label>
      <input type="password" name="password" required>
    </div>
    <button class="btn" type="submit">Sign in</button>
  </form>
</div>
<?php
layout_foot();
