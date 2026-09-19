<?php
declare(strict_types=1);

namespace BackupManager;

/** Loads config/config.php (falls back to config.example.php) and resolves paths. */
final class Config
{
    private static ?array $c = null;
    public static string $file = '';

    public static function moduleDir(): string { return dirname(__DIR__); }

    public static function load(?string $file = null): array
    {
        if (self::$c !== null && $file === null) return self::$c;
        $file = $file ?? (defined('BACKUP_MANAGER_CONFIG') ? BACKUP_MANAGER_CONFIG : (getenv('BACKUP_MANAGER_CONFIG') ?: ''));
        if ($file === '' || !is_file($file)) {
            $file = self::moduleDir() . '/config/config.php';
            if (!is_file($file)) $file = self::moduleDir() . '/config/config.example.php';
        }
        self::$file = $file;
        $c = require $file;
        if (!is_array($c)) throw new \RuntimeException('config must return an array: ' . $file);
        $c += [
            'language' => 'en', 'app_name' => 'My Website', 'app_version' => '', 'environment' => 'prod', 'id_prefix' => 'backup',
            'timezone' => 'UTC', 'root' => dirname(self::moduleDir()), 'storage' => self::moduleDir() . '/storage', 'base_url' => '',
            'database' => null, 'website' => [], 'uploads' => [], 'config_files' => [], 'secret_files' => [],
            'sensitive' => [], 'admin_tables' => [], 'health' => [], 'auth' => ['mode' => 'builtin', 'username' => 'admin'],
        ];
        $c['website'] += ['exclude' => ['.git', 'node_modules'], 'keep_files' => []];
        $c['health'] += ['required_files' => [], 'tables_nonempty' => [], 'urls' => ['/'], 'referenced_files' => []];
        $c['root'] = rtrim(str_replace('\\', '/', (string) $c['root']), '/');
        $c['storage'] = rtrim(str_replace('\\', '/', (string) $c['storage']), '/');
        return self::$c = $c;
    }

    public static function get(string $key, $default = null)
    {
        return self::load()[$key] ?? $default;
    }
    public static function root(): string { return (string) self::load()['root']; }
    public static function storage(): string { return (string) self::load()['storage']; }

    /**
     * The "layout" is what gets stored in every backup's manifest, so a restore (even on an empty server, without this
     * config) knows where things go. It contains no secrets.
     */
    public static function layout(): array
    {
        $c = self::load();
        $db = $c['database'];
        if ($db && ($db['type'] ?? 'sqlite') !== 'sqlite') throw new \RuntimeException('Only SQLite is supported.');
        return [
            'database' => $db ? ['type' => 'sqlite', 'path' => ltrim(str_replace('\\', '/', (string) $db['path']), '/')] : null,
            'exclude' => array_values($c['website']['exclude']),
            'keep_files' => array_values($c['website']['keep_files']),
            'uploads' => array_map(fn($p) => trim(str_replace('\\', '/', (string) $p), '/'), $c['uploads']),
            'config_files' => array_values($c['config_files']),
            'secret_files' => array_values($c['secret_files']),
            'sensitive' => array_values($c['sensitive']),
            'admin_tables' => array_values($c['admin_tables']),
            'health' => $c['health'],
        ];
    }
}
