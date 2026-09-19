<?php
/**
 * OPTIONAL. Include at the very top of your site's entry point (index.php) so visitors see a short "maintenance" page
 * while a restore is running (instead of a half-copied site):
 *
 *     require '/path/to/php-backup-manager/src/maintenance-guard.php';
 *
 * The flag file is created by a restore and removed automatically (stale flags expire after 30 minutes).
 */
(function (): void {
    if (PHP_SAPI === 'cli') return;
    $storage = getenv('BACKUP_MANAGER_STORAGE') ?: dirname(__DIR__) . '/storage';
    $flag = $storage . '/maintenance.flag';
    if (!is_file($flag)) return;
    if (time() - (int) @filemtime($flag) > 1800) { @unlink($flag); return; }
    http_response_code(503);
    header('Retry-After: 60');
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><title>Maintenance</title><body style="font:16px system-ui;display:grid;place-items:center;height:100vh;margin:0"><p>Maintenance in progress — please try again in a minute.</p>');
})();
