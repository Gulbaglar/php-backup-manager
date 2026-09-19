<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use BackupManager\Auth;
use BackupManager\I18n;
use BackupManager\Manager;

Auth::require();
Manager::ensure();
Manager::maybeAutoRun();

ob_start(); ?>
<div class="bk" id="bk">
  <h1><?= bm_e(I18n::t('ui.title')) ?></h1>
  <p class="muted small"><?= bm_e(I18n::t('ui.intro')) ?></p>

  <div class="bk-warn" id="bkWarn" hidden></div>
  <section class="bk-grid" id="bkStatus"></section>

  <section class="bk-actions">
    <button class="bk-btn bk-primary" id="bFull"><?= bm_e(I18n::t('ui.btn.full')) ?></button>
    <button class="bk-btn" id="bQuick"><?= bm_e(I18n::t('ui.btn.quick')) ?></button>
    <button class="bk-btn" id="bRestore"><?= bm_e(I18n::t('ui.btn.restore')) ?></button>
    <a class="bk-btn" id="bKey" href="#"><?= bm_e(I18n::t('ui.btn.key')) ?></a>
    <span class="muted small"><?= bm_e(I18n::t('ui.hint.quick')) ?></span>
  </section>

  <section class="bk-panel" id="bkJob" hidden></section>

  <section class="bk-panel">
    <h2><?= bm_e(I18n::t('ui.history')) ?></h2>
    <div class="bk-tablewrap"><table class="bk-table" id="bkTable"></table></div>
  </section>

  <section class="bk-panel">
    <h2><?= bm_e(I18n::t('ui.auto.title')) ?></h2>
    <div class="bk-form" id="bkAuto"></div>
  </section>

  <section class="bk-panel">
    <h2><?= bm_e(I18n::t('ui.log')) ?></h2>
    <div class="bk-log" id="bkLog"></div>
  </section>

  <div class="bk-modal" id="bkModal" hidden><div class="bk-modal-box" id="bkModalBox"></div></div>
  <input type="file" id="bkFile" accept=".gz,.tgz,application/gzip" hidden>
</div>
<script>
window.BK_BOOT = <?= json_encode([
    'api'      => 'api.php',
    'download' => 'download.php',
    'csrf'     => Auth::csrf(),
    'lang'     => I18n::lang(),
    'i18n'     => I18n::forJs(),
    'cron'     => 'php ' . str_replace('\\', '/', dirname(__DIR__)) . '/bin/worker.php --auto',
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="assets/backup.js?v=<?= (int) @filemtime(__DIR__ . '/assets/backup.js') ?>"></script>
<?php bm_page(I18n::t('ui.title'), (string) ob_get_clean());
