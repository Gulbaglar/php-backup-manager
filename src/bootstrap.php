<?php
declare(strict_types=1);

/**
 * Loads the module: autoloader, config, language. Include this file from any entry point:
 *     require '/path/to/php-backup-manager/src/bootstrap.php';
 */
if (!defined('BACKUP_MANAGER_LOADED')) {
    define('BACKUP_MANAGER_LOADED', true);
    spl_autoload_register(function (string $class): void {
        $prefix = 'BackupManager\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
        $file = __DIR__ . '/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) require_once $file;
    });
    $cfg = \BackupManager\Config::load();
    date_default_timezone_set((string) ($cfg['timezone'] ?? 'UTC'));
    \BackupManager\I18n::init((string) $cfg['language']);
}
