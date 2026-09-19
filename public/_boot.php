<?php
declare(strict_types=1);
/**
 * Single place that connects the web UI to the module. If you copy `public/` somewhere else (e.g. into
 * /admin/backup/ of your own project), change the path below to where you put the module's `src/` folder,
 * and optionally point to a custom config file:
 *
 *     define('BACKUP_MANAGER_CONFIG', '/path/to/my-config.php');
 */
require dirname(__DIR__) . '/src/bootstrap.php';

use BackupManager\I18n;

/** HTML-escape */
function bm_e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** Public URL of the SITE being backed up (used only for post-restore health checks). */
function bm_site_url(): string
{
    $c = (string) \BackupManager\Config::get('base_url', '');
    if ($c !== '') return rtrim($c, '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/** Language switcher links (keeps the rest of the query string). */
function bm_lang_switcher(): string
{
    $out = '';
    foreach (I18n::LANGS as $code => $name) {
        $q = $_GET; $q['lang'] = $code;
        $out .= '<a class="lang' . (I18n::lang() === $code ? ' on' : '') . '" href="?' . bm_e(http_build_query($q)) . '">' . bm_e($name) . '</a>';
    }
    return '<span class="langs" title="' . bm_e(I18n::t('ui.language')) . '">' . $out . '</span>';
}

function bm_page(string $title, string $body, bool $showUser = true): void
{
    ?><!doctype html>
<html lang="<?= bm_e(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= bm_e($title) ?></title>
<link rel="stylesheet" href="assets/backup.css">
</head>
<body>
<div class="topbar">
  <span class="brand"><b>Backup</b> Manager</span>
  <span class="spacer"></span>
  <?= bm_lang_switcher() ?>
  <?php if ($showUser): ?><a class="small" href="logout.php"><?= bm_e(I18n::t('ui.logout')) ?></a><?php endif ?>
</div>
<?= $body ?>
</body>
</html>
<?php
}
