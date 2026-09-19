<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use BackupManager\Auth;
use BackupManager\I18n;

$error = '';
if (Auth::check()) { header('Location: index.php'); exit; }
$setup = Auth::needsSetup();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pw = (string) ($_POST['password'] ?? '');
        if ($setup) {
            if ($pw !== (string) ($_POST['password2'] ?? '')) throw new InvalidArgumentException(I18n::t('err.password_mismatch'));
            Auth::setPassword($pw);
        }
        if (Auth::login($pw)) { header('Location: index.php'); exit; }
        $error = I18n::t('err.login_failed');
    } catch (\Throwable $e) { $error = $e->getMessage(); }
}

ob_start(); ?>
<div class="panel">
  <h1><?= bm_e(I18n::t($setup ? 'ui.setup.title' : 'ui.login.title')) ?></h1>
  <?php if ($setup): ?><p class="muted small"><?= bm_e(I18n::t('ui.setup.intro')) ?></p><?php endif ?>
  <?php if ($error): ?><div class="flash err"><?= bm_e($error) ?></div><?php endif ?>
  <form method="post" autocomplete="off">
    <label><?= bm_e(I18n::t('ui.login.password')) ?></label>
    <input type="password" name="password" required minlength="<?= $setup ? 8 : 1 ?>" autofocus>
    <?php if ($setup): ?>
      <label><?= bm_e(I18n::t('ui.setup.repeat')) ?></label>
      <input type="password" name="password2" required minlength="8">
    <?php endif ?>
    <button class="bk-btn bk-primary" type="submit"><?= bm_e(I18n::t($setup ? 'ui.setup.submit' : 'ui.login.submit')) ?></button>
  </form>
</div>
<?php bm_page(I18n::t('ui.title'), (string) ob_get_clean(), false);
