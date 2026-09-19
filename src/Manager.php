<?php
declare(strict_types=1);

namespace BackupManager;

use PDO;
use RuntimeException;
use InvalidArgumentException;

/**
 * Backup manager — history, jobs/progress, retention, automation, chunked upload.
 * Backups live in the private storage folder (never inside the web root); downloads only go through authenticated endpoints.
 */
final class Manager
{
    public const INTERVALS = ['daily' => 86400, '3days' => 259200, 'weekly' => 604800, 'monthly' => 2592000];
    public const STEP_KEYS = ['database', 'website', 'images', 'videos', 'documents', 'other', 'config', 'checksums', 'verify', 'extract', 'validate', 'emergency', 'uploads', 'cache', 'health'];

    public static function t(string $k, array $p = []): string { return I18n::t($k, $p); }
    public static function dir(): string { return Config::storage(); }

    public static function ensure(): void
    {
        foreach (['', '/jobs', '/tmp', '/incoming'] as $d) if (!is_dir(self::dir() . $d)) @mkdir(self::dir() . $d, 0775, true);
        $ht = self::dir() . '/.htaccess';
        if (!is_file($ht)) file_put_contents($ht, "# Backups must never be reachable from the web\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        if (!is_file(self::dir() . '/index.html')) file_put_contents(self::dir() . '/index.html', '');
    }

    private static function jread(string $f): ?array
    {
        $j = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
        return is_array($j) ? $j : null;
    }
    public static function jwrite(string $f, array $d): void
    {
        $tmp = $f . '.' . bin2hex(random_bytes(3)) . '.tmp';
        file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        if (!@rename($tmp, $f)) { @copy($tmp, $f); @unlink($tmp); }
    }

    /* ------------------------------------------------------------ key & settings */

    public static function key(): ?string
    {
        if (!function_exists('openssl_encrypt')) return null;
        $f = self::dir() . '/backup.key';
        if (!is_file($f)) { self::ensure(); file_put_contents($f, base64_encode(random_bytes(32))); @chmod($f, 0600); }
        $k = base64_decode(trim((string) file_get_contents($f)), true);
        return $k !== false && strlen($k) === 32 ? $k : null;
    }

    public static function settings(): array
    {
        return (self::jread(self::dir() . '/settings.json') ?? []) + ['auto_full' => 'disabled', 'auto_quick' => 'disabled', 'keep_full' => 5, 'keep_quick' => 10];
    }
    public static function saveSettings(array $c): void
    {
        self::ensure();
        $old = self::settings();
        $ok = array_keys(self::INTERVALS);
        foreach (['auto_full', 'auto_quick'] as $k) if (isset($c[$k])) $old[$k] = ($c[$k] === 'disabled' || in_array($c[$k], $ok, true)) ? $c[$k] : 'disabled';
        foreach (['keep_full', 'keep_quick'] as $k) if (isset($c[$k])) $old[$k] = max(1, min(100, (int) $c[$k]));
        self::jwrite(self::dir() . '/settings.json', $old);
    }

    /* ------------------------------------------------------------ records */

    public static function validId(string $id): bool { return (bool) preg_match('/^[a-z0-9][a-z0-9\-]{3,80}$/', $id); }
    public static function path(string $id): string { return self::dir() . '/' . $id . '.tar.gz'; }
    public static function meta(string $id): ?array { return self::validId($id) ? self::jread(self::dir() . '/' . $id . '.meta.json') : null; }
    public static function saveMeta(string $id, array $m): void { self::jwrite(self::dir() . '/' . $id . '.meta.json', $m); }

    public static function newId(string $type): string
    {
        $prefix = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) Config::get('id_prefix', 'backup'))) ?: 'backup';
        $base = ($type === 'pre-restore' ? 'pre-restore' : $prefix . '-' . $type) . '-' . date('Y-m-d-H-i');
        $id = $base; $n = 2;
        while (is_file(self::path($id)) || is_file(self::dir() . '/' . $id . '.meta.json')) $id = $base . '-' . $n++;
        return $id;
    }

    /** @return array[] newest first */
    public static function all(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.meta.json') ?: [] as $f) {
            $m = self::jread($f); if (!$m || empty($m['id'])) continue;
            $m['exists'] = is_file(self::path($m['id']));
            $out[] = $m;
        }
        usort($out, fn($a, $b) => ($b['created_ts'] ?? 0) <=> ($a['created_ts'] ?? 0));
        return $out;
    }

    public static function delete(string $id): void
    {
        $m = self::meta($id);
        if (!$m) throw new InvalidArgumentException(self::t('err.backup_not_found'));
        if (!empty($m['protected'])) throw new InvalidArgumentException(self::t('err.protected'));
        @unlink(self::path($id)); @unlink(self::dir() . '/' . $id . '.meta.json');
        self::log(['event' => 'delete', 'backup_id' => $id]);
    }

    public static function setProtected(string $id, bool $on): void
    {
        $m = self::meta($id); if (!$m) throw new InvalidArgumentException(self::t('err.backup_not_found'));
        $m['protected'] = $on; self::saveMeta($id, $m);
    }

    public static function log(array $e): void
    {
        self::ensure();
        @file_put_contents(self::dir() . '/backup.log.jsonl', json_encode(['time' => date('c')] + $e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }
    public static function logTail(int $n = 40): array
    {
        $f = self::dir() . '/backup.log.jsonl'; if (!is_file($f)) return [];
        $lines = array_slice(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -$n);
        return array_reverse(array_values(array_filter(array_map(fn($l) => json_decode($l, true), $lines))));
    }

    /* ------------------------------------------------------------ jobs */

    private static function jobFile(string $id): string { return self::dir() . '/jobs/' . $id . '.json'; }
    public static function job(string $id): ?array { return preg_match('/^job-[a-f0-9]{12}$/', $id) ? self::jread(self::jobFile($id)) : null; }

    public static function activeJob(): ?array
    {
        foreach (glob(self::dir() . '/jobs/job-*.json') ?: [] as $f) {
            $j = self::jread($f); if (!$j || !in_array($j['status'], ['queued', 'running'], true)) continue;
            if (time() - (int) ($j['heartbeat'] ?? 0) > 180) { $j['status'] = 'failed'; $j['error'] = self::t('err.worker_died'); $j['finished'] = time(); self::jwrite($f, $j); continue; }
            return $j;
        }
        return null;
    }

    private static function stepsFor(string $kind, array $p): array
    {
        if ($kind === 'verify') $keys = ['verify'];
        elseif ($kind === 'backup') $keys = ($p['type'] ?? 'full') === 'quick' ? ['database', 'checksums', 'verify'] : ['database', 'website', 'images', 'videos', 'documents', 'other', 'config', 'checksums', 'verify'];
        else {
            $keys = ['verify', 'extract', 'validate', 'emergency', 'database', 'uploads', 'website', 'config', 'cache', 'health'];
            $use = ['full' => ['database', 'uploads', 'website', 'config'], 'database' => ['database'], 'media' => ['uploads'], 'code' => ['website'], 'admin' => ['database']][$p['mode'] ?? 'full'] ?? [];
            $keys = array_values(array_filter($keys, fn($k) => !in_array($k, ['database', 'uploads', 'website', 'config'], true) || in_array($k, $use, true)));
        }
        return array_map(fn($k) => ['key' => $k, 'state' => 'waiting', 'pct' => 0, 'info' => ''], $keys);
    }

    private static function newJob(string $kind, array $params): string
    {
        self::ensure();
        if (self::activeJob()) throw new InvalidArgumentException(self::t('err.job_running'));
        $id = 'job-' . bin2hex(random_bytes(6));
        $params['lang'] = $params['lang'] ?? I18n::lang();
        self::jwrite(self::jobFile($id), ['id' => $id, 'kind' => $kind, 'params' => $params, 'status' => 'queued', 'steps' => self::stepsFor($kind, $params),
            'started' => time(), 'heartbeat' => time(), 'finished' => 0, 'error' => '', 'result' => null]);
        return $id;
    }

    /** Creates a job and starts the background worker (falls back to running inline if processes can't be spawned). */
    public static function start(string $kind, array $params): array
    {
        $id = self::newJob($kind, $params);
        if (self::spawn($id)) return ['job' => $id, 'inline' => false];
        ignore_user_abort(true); @set_time_limit(0); @ini_set('memory_limit', '512M');
        self::run($id);
        return ['job' => $id, 'inline' => true];
    }

    public static function phpBinary(): ?string
    {
        if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && PHP_BINARY !== '' && is_file(PHP_BINARY)) return PHP_BINARY;
        foreach (['/usr/local/bin/php', '/usr/bin/php', '/opt/alt/php83/usr/bin/php', '/opt/alt/php82/usr/bin/php', '/opt/alt/php81/usr/bin/php'] as $p) if (@is_executable($p)) return $p;
        return null;
    }

    private static function spawn(string $jobId): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $php = self::phpBinary();
        if (!$php) return false;
        $script = Config::moduleDir() . '/bin/worker.php';
        $env = defined('BACKUP_MANAGER_CONFIG') ? 'BACKUP_MANAGER_CONFIG=' . escapeshellarg((string) BACKUP_MANAGER_CONFIG) . ' ' : '';
        if (PHP_OS_FAMILY === 'Windows') {
            if (!function_exists('popen') || in_array('popen', $disabled, true)) return false;
            if (defined('BACKUP_MANAGER_CONFIG')) putenv('BACKUP_MANAGER_CONFIG=' . BACKUP_MANAGER_CONFIG);
            $p = @popen('start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($jobId) . ' > NUL 2>&1', 'r');
            if (!$p) return false; pclose($p); return true;
        }
        if (!function_exists('exec') || in_array('exec', $disabled, true)) return false;
        @exec($env . 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($jobId) . ' > /dev/null 2>&1 &');
        return true;
    }

    /* ------------------------------------------------------------ runners (worker) */

    private static function reporter(array &$job): callable
    {
        $last = 0.0;
        return function (string $step, string $state, ?int $pct, string $info = '') use (&$job, &$last) {
            foreach ($job['steps'] as &$s) {
                if ($s['key'] !== $step) continue;
                $changed = $s['state'] !== $state;
                $s['state'] = $state; if ($pct !== null) $s['pct'] = $pct; if ($info !== '') $s['info'] = $info;
                $now = microtime(true);
                if ($changed || $now - $last > 0.5) { $job['heartbeat'] = time(); self::jwrite(self::jobFile($job['id']), $job); $last = $now; }
                return;
            }
        };
    }
    private static function finishJob(array &$job, string $status, string $error = '', $result = null): void
    {
        $job['status'] = $status; $job['error'] = $error; $job['result'] = $result; $job['finished'] = time(); $job['heartbeat'] = time();
        self::jwrite(self::jobFile($job['id']), $job);
    }

    public static function run(string $jobId): void
    {
        $job = self::job($jobId); if (!$job) return;
        I18n::set((string) ($job['params']['lang'] ?? Config::get('language', 'en')));
        $job['status'] = 'running'; $job['pid'] = getmypid(); $job['heartbeat'] = time();
        self::jwrite(self::jobFile($jobId), $job);
        $t0 = time();
        try {
            $res = match ($job['kind']) {
                'backup'  => self::runBackup($job),
                'verify'  => self::runVerify($job),
                'restore' => self::runRestore($job),
            };
            self::finishJob($job, 'done', '', $res);
        } catch (\Throwable $e) {
            self::log(['event' => $job['kind'] . '_failed', 'job' => $jobId, 'error' => $e->getMessage(), 'duration' => time() - $t0, 'user' => $job['params']['user'] ?? '']);
            foreach ($job['steps'] as &$s) if ($s['state'] === 'running') $s['state'] = 'failed';
            self::finishJob($job, 'failed', $e->getMessage());
        }
    }

    private static function summarizeVerify(array $v): array
    {
        return ['ok' => $v['ok'], 'groups' => $v['groups'], 'errors' => $v['errors'],
                'db' => $v['dump'] ? ['integrity' => $v['dump']['integrity'], 'fk_violations' => $v['dump']['fk_violations'], 'tables' => count($v['dump']['tables'])] : null];
    }

    /** Creates a backup and verifies it. A backup is not successful until it is verified. */
    public static function createBackup(string $type, array $p, callable $rep): array
    {
        $scope = $type === 'quick' ? ['db' => true, 'files' => false, 'uploads' => false, 'config' => false]
               : ($p['scope'] ?? ['db' => true, 'files' => true, 'uploads' => true, 'config' => true]);
        $id = self::newId($type);
        $t0 = time();
        $r = Engine::create(['root' => Config::root(), 'dir' => self::dir(), 'id' => $id, 'type' => $type, 'scope' => $scope, 'key' => self::key(),
            'created_by' => $p['user'] ?? '', 'origin' => $p['origin'] ?? 'manual', 'site_url' => $p['base_url'] ?? '', 'env' => Config::get('environment', 'prod'),
            'app_name' => Config::get('app_name', ''), 'app_version' => Config::get('app_version', ''), 'layout' => Config::layout(), 'module_dir' => Config::moduleDir()], $rep);
        $m = $r['manifest'];
        $meta = ['id' => $id, 'type' => $type, 'origin' => $p['origin'] ?? 'manual', 'created_ts' => $m['created_ts'], 'created_by' => $p['user'] ?? '',
                 'size' => $r['size'], 'sha256' => $r['sha256'], 'status' => 'unverified', 'protected' => !empty($p['protected']),
                 'counts' => $m['counts'], 'components' => $m['components'], 'warnings' => $r['warnings'], 'duration' => time() - $t0, 'verified_at' => 0, 'verify' => null];
        self::saveMeta($id, $meta);
        $v = Engine::verify(self::path($id), self::dir() . '/tmp', $rep);
        $meta['status'] = $v['ok'] ? 'verified' : 'failed'; $meta['verified_at'] = time(); $meta['verify'] = self::summarizeVerify($v);
        self::saveMeta($id, $meta);
        self::log(['event' => 'backup', 'backup_id' => $id, 'type' => $type, 'origin' => $meta['origin'], 'user' => $meta['created_by'], 'size' => $r['size'],
                   'duration' => time() - $t0, 'result' => $meta['status'], 'errors' => $v['errors'], 'warnings' => $r['warnings']]);
        if (!$v['ok']) throw new RuntimeException('BACKUP CORRUPTED — DO NOT RESTORE. ' . implode(' | ', $v['errors']));
        if (($p['origin'] ?? 'manual') === 'auto') self::prune();
        return ['id' => $id, 'size' => $r['size'], 'status' => 'verified'];
    }

    private static function runBackup(array &$job): array
    {
        return self::createBackup($job['params']['type'] === 'quick' ? 'quick' : 'full', $job['params'], self::reporter($job));
    }

    private static function resolveArchive(string $source): array
    {
        if (str_starts_with($source, 'backup:')) {
            $id = substr($source, 7); if (!self::validId($id) || !is_file(self::path($id))) throw new RuntimeException(self::t('err.archive_not_found'));
            return [self::path($id), $id, false];
        }
        if (str_starts_with($source, 'upload:')) {
            $u = substr($source, 7); if (!preg_match('/^[a-f0-9]{16}$/', $u) || !is_file(self::dir() . "/incoming/$u.tar.gz")) throw new RuntimeException(self::t('err.upload_not_found'));
            return [self::dir() . "/incoming/$u.tar.gz", $u, true];
        }
        throw new RuntimeException(self::t('err.invalid_source'));
    }

    private static function runVerify(array &$job): array
    {
        [$file, $id] = self::resolveArchive($job['params']['source']);
        $v = Engine::verify($file, self::dir() . '/tmp', self::reporter($job));
        $m = self::meta($id);
        if ($m) {
            $m['status'] = $v['ok'] ? 'verified' : 'failed'; $m['verified_at'] = time(); $m['verify'] = self::summarizeVerify($v);
            if ($v['ok']) { $m['sha256_now'] = hash_file('sha256', $file); $m['hash_matches'] = hash_equals((string) $m['sha256'], $m['sha256_now']); }
            self::saveMeta($id, $m);
        }
        self::log(['event' => 'verify', 'backup_id' => $id, 'user' => $job['params']['user'] ?? '', 'result' => $v['ok'] ? 'verified' : 'FAILED', 'errors' => $v['errors']]);
        return self::summarizeVerify($v) + ['hash_ok' => $m ? ($m['hash_matches'] ?? null) : null];
    }

    /** Paths that a code restore must never delete: this module itself (if it lives inside the site root). */
    private static function protectPaths(): array
    {
        $root = Config::root(); $mod = str_replace('\\', '/', Config::moduleDir());
        return str_starts_with($mod, $root . '/') ? [substr($mod, strlen($root) + 1)] : [];
    }

    private static function runRestore(array &$job): array
    {
        $p = $job['params']; $mode = $p['mode'] ?? 'full'; $rep = self::reporter($job); $t0 = time();
        [$file, $srcId, $isUpload] = self::resolveArchive($p['source']);
        $stage = self::dir() . '/tmp/restore-' . $job['id'];
        $flag = self::dir() . '/maintenance.flag';
        $root = Config::root();
        $fkBefore = self::currentFk();
        $opts = ['protect' => self::protectPaths(), 'storage' => self::dir()];

        // 1) verify — nothing is touched if the archive is damaged
        $v = Engine::verify($file, self::dir() . '/tmp', $rep);
        if (!$v['ok']) throw new RuntimeException('BACKUP CORRUPTED — DO NOT RESTORE. ' . implode(' | ', $v['errors']));
        $man = $v['manifest'];
        if (!empty($man['database']) && ($man['database']['type'] ?? '') !== 'sqlite') throw new RuntimeException(self::t('err.db_type'));
        $rep('extract', 'running', 0, '');
        try {
            // 2) extract to temp (re-hash), 3) test-load the dump
            Engine::extract($file, $stage, $v['checks'], $rep);
            $rep('extract', 'done', 100, self::t('msg.extract_done'));
            if (!empty($man['database'])) {
                $rep('validate', 'running', 50, '');
                $t = Engine::testDump($stage . '/database/database.sql', self::dir() . '/tmp/validate-' . $job['id'] . '.sqlite');
                if ($t['integrity'] !== 'ok' || $t['fk_violations'] > (int) ($man['database']['fk_violations'] ?? 0)) throw new RuntimeException(self::t('err.dump_inconsistent'));
                $rep('validate', 'done', 100, self::t('msg.validate_done', ['n' => count($t['tables'])]));
            } else { $rep('validate', 'skipped', 100, self::t('msg.no_database')); }

            // 4) mandatory safety backup of the current system — restore does not start if this fails
            file_put_contents($flag, (string) time());
            $scope = ['full' => ['db' => true, 'files' => true, 'uploads' => true, 'config' => true], 'database' => ['db' => true, 'files' => false, 'uploads' => false, 'config' => false],
                      'admin' => ['db' => true, 'files' => false, 'uploads' => false, 'config' => false], 'media' => ['db' => false, 'files' => false, 'uploads' => true, 'config' => false],
                      'code' => ['db' => false, 'files' => true, 'uploads' => false, 'config' => false]][$mode];
            $rep('emergency', 'running', 0, self::t('msg.emergency_running'));
            $emerg = self::createBackup('pre-restore', ['scope' => $scope, 'user' => $p['user'] ?? '', 'origin' => 'auto', 'protected' => true, 'base_url' => $p['base_url'] ?? ''],
                function (string $s, string $st, ?int $pc, string $i = '') use ($rep) { $rep('emergency', 'running', $pc, self::t('step.' . $s)); });
            $rep('emergency', 'done', 100, $emerg['id']);

            // 5) apply
            $summary = Engine::apply($stage, $root, $mode, $man, self::key(), $rep, $opts);
            @unlink($flag);
            // 6) health check — automatic rollback on a critical failure
            $rep('health', 'running', 50, '');
            $allowFk = (int) ($man['database']['fk_violations'] ?? 0);
            if (!in_array($mode, ['full', 'database'], true)) $allowFk = max($allowFk, $fkBefore);
            $h = Engine::health($root, $man['layout'] ?? Config::layout(), (string) ($p['base_url'] ?? Config::get('base_url', '')), $allowFk);
            if (!$h['ok']) {
                file_put_contents($flag, (string) time());
                $rb = self::rollback($emerg['id'], $mode, $opts);
                @unlink($flag);
                $rep('health', 'failed', 100, self::t('msg.health_rolled_back'));
                self::log(['event' => 'restore', 'source' => $srcId, 'mode' => $mode, 'user' => $p['user'] ?? '', 'result' => 'ROLLED BACK', 'health' => $h, 'rollback' => $rb]);
                throw new RuntimeException(self::t('err.health_failed') . ' ' . json_encode(array_values(array_filter($h['checks'], fn($c) => $c['state'] === 'FAIL')), JSON_UNESCAPED_UNICODE));
            }
            $rep('health', 'done', 100, 'RESTORE SUCCESSFUL');
            self::log(['event' => 'restore', 'source' => $srcId, 'mode' => $mode, 'user' => $p['user'] ?? '', 'result' => 'SUCCESSFUL', 'duration' => time() - $t0,
                       'restored_backup' => $man['backup_id'], 'restored_created' => $man['created_at'], 'emergency_backup' => $emerg['id'], 'health' => $h, 'summary' => $summary]);
            if ($isUpload) { @unlink($file); @unlink(self::dir() . "/incoming/$srcId.json"); }
            return ['health' => $h, 'summary' => $summary, 'emergency_backup' => $emerg['id']];
        } finally {
            @unlink($flag);
            Engine::rrmdir($stage);
        }
    }

    private static function rollback(string $emergId, string $mode, array $opts): array
    {
        $stage = self::dir() . '/tmp/rollback-' . $emergId;
        try {
            $ev = Engine::verify(self::path($emergId), self::dir() . '/tmp', function () {});
            if (!$ev['ok']) return ['ok' => false, 'error' => 'safety backup could not be verified'];
            Engine::extract(self::path($emergId), $stage, $ev['checks'], function () {});
            Engine::apply($stage, Config::root(), $mode, $ev['manifest'], self::key(), function () {}, $opts);
            return ['ok' => Engine::health(Config::root(), $ev['manifest']['layout'], '', (int) ($ev['manifest']['database']['fk_violations'] ?? 0))['ok']];
        } catch (\Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        finally { Engine::rrmdir($stage); }
    }

    private static function currentFk(): int
    {
        $db = Config::layout()['database'];
        if (!$db) return 0;
        try { $p = new PDO('sqlite:' . Config::root() . '/' . $db['path']); return count($p->query('PRAGMA foreign_key_check')->fetchAll()); }
        catch (\Throwable $e) { return 0; }
    }

    /* ------------------------------------------------------------ retention & automation */

    /** Only AUTOMATIC backups are pruned; Protected and manual backups never are. */
    public static function prune(): void
    {
        $cfg = self::settings();
        foreach (['full', 'quick'] as $type) {
            $list = array_values(array_filter(self::all(), fn($m) => $m['type'] === $type && ($m['origin'] ?? '') === 'auto' && empty($m['protected'])));
            foreach (array_slice($list, (int) $cfg['keep_' . $type]) as $m) {
                @unlink(self::path($m['id'])); @unlink(self::dir() . '/' . $m['id'] . '.meta.json');
                self::log(['event' => 'retention_delete', 'backup_id' => $m['id'], 'type' => $type]);
            }
        }
    }

    /** Called when admin pages open (cheap) and by `php bin/worker.php --auto` (cron). */
    public static function maybeAutoRun(bool $inline = false): void
    {
        try {
            $cfg = self::settings(); $now = time();
            if (self::activeJob()) return;
            foreach (['full', 'quick'] as $type) {
                $mode = $cfg['auto_' . $type];
                if (!isset(self::INTERVALS[$mode])) continue;
                $autos = array_values(array_filter(self::all(), fn($m) => $m['type'] === $type && ($m['origin'] ?? '') === 'auto'));
                $last = $autos ? (int) $autos[0]['created_ts'] : 0;
                $attempt = (int) ($cfg['last_attempt_' . $type] ?? 0);
                if ($now - $last < self::INTERVALS[$mode] || $now - $attempt < 3600) continue;
                $cfg['last_attempt_' . $type] = $now;
                self::jwrite(self::dir() . '/settings.json', $cfg);
                $id = self::newJob('backup', ['type' => $type, 'origin' => 'auto', 'user' => 'auto']);
                if ($inline) self::run($id);
                elseif (!self::spawn($id)) { $j = self::job($id); $j['status'] = 'failed'; $j['error'] = self::t('err.spawn_failed'); $j['finished'] = time(); self::jwrite(self::jobFile($id), $j); }
                return;
            }
        } catch (\Throwable $e) { /* never break the page */ }
    }

    /* ------------------------------------------------------------ state for the UI */

    public static function state(): array
    {
        self::ensure();
        foreach (glob(self::dir() . '/incoming/*') ?: [] as $f) if (filemtime($f) < time() - 86400) @unlink($f);
        foreach (glob(self::dir() . '/jobs/job-*.json') ?: [] as $f) if (filemtime($f) < time() - 7 * 86400) @unlink($f);
        $all = self::all();
        $ok = array_values(array_filter($all, fn($m) => ($m['status'] ?? '') === 'verified' && !empty($m['exists'])));
        $used = array_sum(array_map(fn($m) => !empty($m['exists']) ? (int) $m['size'] : 0, $all));
        return ['backups' => $all, 'last' => $all[0] ?? null, 'last_ok' => $ok[0] ?? null,
                'last_full' => current(array_filter($all, fn($m) => $m['type'] === 'full')) ?: null,
                'last_quick' => current(array_filter($all, fn($m) => $m['type'] === 'quick')) ?: null,
                'storage_used' => $used, 'storage_path' => self::dir(), 'disk_free' => (int) @disk_free_space(self::dir()),
                'settings' => self::settings(), 'key_id' => ($k = self::key()) ? Engine::keyId($k) : null, 'active_job' => self::activeJob(),
                'php_ok' => function_exists('gzopen') && extension_loaded('pdo_sqlite'), 'openssl' => function_exists('openssl_encrypt'),
                'has_database' => (bool) Config::layout()['database'], 'has_admin_tables' => (bool) Config::layout()['admin_tables'],
                'app_name' => Config::get('app_name', ''), 'root' => Config::root()];
    }

    /* ------------------------------------------------------------ chunked upload (huge files, no php.ini limits) */

    public static function uploadInit(string $name, int $size): string
    {
        self::ensure();
        $u = bin2hex(random_bytes(8));
        file_put_contents(self::dir() . "/incoming/$u.part", '');
        self::jwrite(self::dir() . "/incoming/$u.json", ['name' => basename($name), 'size' => $size, 'created' => time()]);
        return $u;
    }
    public static function uploadChunk(string $u, int $offset, string $data): int
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $u)) throw new InvalidArgumentException(self::t('err.upload_invalid'));
        $f = self::dir() . "/incoming/$u.part";
        if (!is_file($f) || (int) filesize($f) !== $offset) throw new InvalidArgumentException(self::t('err.upload_order'));
        file_put_contents($f, $data, FILE_APPEND);
        return (int) filesize($f);
    }
    public static function uploadFinish(string $u): array
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $u)) throw new InvalidArgumentException(self::t('err.upload_invalid'));
        $part = self::dir() . "/incoming/$u.part"; $fin = self::dir() . "/incoming/$u.tar.gz";
        $meta = self::jread(self::dir() . "/incoming/$u.json");
        if (!is_file($part) || !$meta || (int) filesize($part) !== (int) $meta['size']) throw new InvalidArgumentException(self::t('err.upload_incomplete'));
        rename($part, $fin);
        try { $m = Engine::inspect($fin); }
        catch (\Throwable $e) { @unlink($fin); @unlink(self::dir() . "/incoming/$u.json"); throw new InvalidArgumentException(self::t('err.not_valid_backup', ['m' => $e->getMessage()])); }
        return ['upload' => $u, 'manifest' => $m, 'supported' => Engine::formatSupported($m), 'name' => $meta['name'], 'size' => (int) $meta['size']];
    }
}
