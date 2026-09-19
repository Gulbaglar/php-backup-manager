<?php
declare(strict_types=1);

namespace BackupManager;

use PDO;
use RuntimeException;
use InvalidArgumentException;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use RecursiveCallbackFilterIterator;
use FilesystemIterator;
use SplFileInfo;

/**
 * Backup engine — independent of any host application (needs only PHP + pdo_sqlite + zlib + openssl).
 * The same code is used by the admin UI and by the stand-alone disaster-recovery script (bin/restore-backup.php),
 * which is also bundled inside every archive.
 *
 * Package format: <id>.tar.gz  (ustar + gzip, fully streamed — big files are never loaded into memory)
 *   metadata/manifest.json      first entry (fast inspection; contains the "layout" needed to restore anywhere)
 *   README-RESTORE.txt, restore/ (this engine + recovery script)
 *   database/database.sql       portable SQL dump          database/sensitive.enc  (AES-256-GCM encrypted secrets)
 *   website/…   uploads/<alias>/{images,videos,documents,other}/…   config/…
 *   metadata/checksums.json     last entry (SHA-256 of every file)
 */
require_once __DIR__ . '/Tar.php';

final class Engine
{
    public const FORMAT = 1;
    public const STMT_END = '-- @@STMT-END-7f3a@@';

    public const CATEGORIES = [
        'images'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'bmp', 'ico'],
        'videos'    => ['mp4', 'webm', 'mov', 'm4v', 'avi', 'mkv', 'ogv'],
        'documents' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'odt', 'rtf'],
    ];

    /* ================================================================ helpers */

    public static function t(string $k, array $p = []): string { return I18n::t($k, $p); }

    public static function category(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        foreach (self::CATEGORIES as $cat => $exts) if (in_array($ext, $exts, true)) return $cat;
        return 'other';
    }

    /** Machine key of the verification group an archive path belongs to. */
    public static function groupOf(string $path): string
    {
        if (str_starts_with($path, 'database/')) return 'database';
        if (str_starts_with($path, 'website/')) return 'website';
        if (preg_match('#^uploads/[^/]+/(images|videos|documents)/#', $path, $m)) return $m[1];
        if (str_starts_with($path, 'uploads/')) return 'other';
        if (str_starts_with($path, 'config/')) return 'config';
        return 'metadata';
    }

    public static function keyId(string $key): string { return substr(hash('sha256', $key), 0, 12); }

    public static function encrypt(string $plain, string $key): array
    {
        $iv = random_bytes(12);
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) throw new RuntimeException(self::t('err.encrypt'));
        return ['v' => 1, 'alg' => 'aes-256-gcm', 'key_id' => self::keyId($key), 'iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'data' => base64_encode($ct)];
    }

    public static function decrypt(array $b, string $key): ?string
    {
        if (($b['key_id'] ?? '') !== self::keyId($key)) return null;
        $p = openssl_decrypt((string) base64_decode((string) $b['data']), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
                             (string) base64_decode((string) $b['iv']), (string) base64_decode((string) $b['tag']));
        return $p === false ? null : $p;
    }

    public static function rrmdir(string $d): void
    {
        if (!is_dir($d)) return;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($d);
    }

    public static function safeRel(string $p): bool
    {
        return $p !== '' && $p[0] !== '/' && !str_contains($p, '..') && !str_contains($p, "\0") && !preg_match('#^[a-zA-Z]:#', $p);
    }

    /** Exclusion matcher: exact path / directory prefix / glob (against basename or full relative path). */
    public static function excluded(string $rel, array $patterns): bool
    {
        foreach ($patterns as $p) {
            $p = trim(str_replace('\\', '/', (string) $p), '/');
            if ($p === '') continue;
            if ($rel === $p || str_starts_with($rel, $p . '/')) return true;
            if (str_contains($p, '*') || str_contains($p, '?')) {
                if (fnmatch($p, $rel) || fnmatch($p, basename($rel))) return true;
            }
        }
        return false;
    }

    private static function builtinSkip(string $n): bool
    {
        return (bool) preg_match('/\.(log|part|tmp|restore-tmp)$/i', $n) || $n === 'Thumbs.db' || $n === '.DS_Store';
    }

    /** Everything that is NOT part of the "website" component (handled by other components or never backed up). */
    private static function websiteExcludes(string $root, array $layout, string $storageDir): array
    {
        $ex = $layout['exclude'];
        foreach ($layout['uploads'] as $dir) $ex[] = $dir;
        foreach (array_merge($layout['config_files'], $layout['secret_files']) as $f) $ex[] = $f;
        if ($layout['database']) { $p = $layout['database']['path']; array_push($ex, $p, $p . '-wal', $p . '-shm', $p . '-journal'); }
        $sd = str_replace('\\', '/', $storageDir);
        if (str_starts_with($sd, $root . '/')) $ex[] = substr($sd, strlen($root) + 1);
        return $ex;
    }

    /* ================================================================ SQL dump */

    private static function sqlValue($v): string
    {
        if ($v === null) return 'NULL';
        if (is_int($v)) return (string) $v;
        if (is_float($v)) return is_finite($v) ? sprintf('%.17g', $v) : 'NULL';
        $v = (string) $v;
        if (str_contains($v, "\0") || !mb_check_encoding($v, 'UTF-8')) return "X'" . bin2hex($v) . "'";
        return "'" . str_replace("'", "''", $v) . "'";
    }

    /**
     * Consistent dump: one read transaction (snapshot). Values matched by the "sensitive" rules are written EMPTY into the
     * dump and collected in $sensitive (they are stored AES-encrypted in a separate file).
     * @return array table => row count
     */
    public static function dumpDatabase(string $dbFile, string $outFile, array $sensitiveRules, array &$sensitive, string &$sqliteVersion, int &$fkViolations = 0): array
    {
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 15000');
        $sqliteVersion = (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();
        $fh = fopen($outFile, 'wb');
        if (!$fh) throw new RuntimeException(self::t('err.dump_write'));
        $m = self::STMT_END . "\n";
        $counts = [];
        $pdo->beginTransaction();
        try {
            fwrite($fh, '-- SQL dump (format ' . self::FORMAT . ') ' . date('c') . "\n");
            $objs = $pdo->query("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND sql IS NOT NULL ORDER BY rowid")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($objs as $o) {
                if ($o['type'] !== 'table') continue;
                $t = $o['name'];
                fwrite($fh, "-- TABLE {$t}\n" . $o['sql'] . ";\n" . $m);
                $cols = array_column($pdo->query('PRAGMA table_xinfo("' . str_replace('"', '""', $t) . '")')->fetchAll(PDO::FETCH_ASSOC), null, 'name');
                $names = [];
                foreach ($cols as $cn => $c) if ((int) $c['hidden'] === 0) $names[] = $cn;
                $colSql = implode(',', array_map(fn($n) => '"' . str_replace('"', '""', $n) . '"', $names));
                $rules = array_values(array_filter($sensitiveRules, fn($r) => ($r['table'] ?? '') === $t));
                $n = 0;
                $st = $pdo->query('SELECT ' . $colSql . ' FROM "' . str_replace('"', '""', $t) . '"');
                while ($row = $st->fetch(PDO::FETCH_NUM)) {
                    if ($rules) {
                        $assoc = array_combine($names, $row);
                        foreach ($rules as $r) {
                            $kc = $r['key_column']; $vc = $r['value_column'];
                            if (isset($assoc[$kc]) && preg_match((string) $r['pattern'], (string) $assoc[$kc]) && (string) $assoc[$vc] !== '') {
                                $sensitive[] = ['t' => $t, 'kc' => $kc, 'vc' => $vc, 'k' => (string) $assoc[$kc], 'v' => (string) $assoc[$vc]];
                                $assoc[$vc] = '';
                            }
                        }
                        $row = array_values($assoc);
                    }
                    fwrite($fh, 'INSERT INTO "' . $t . '" (' . $colSql . ') VALUES (' . implode(',', array_map(fn($v) => self::sqlValue($v), $row)) . ");\n" . $m);
                    $n++;
                }
                $counts[$t] = $n;
                foreach ($objs as $x) {
                    if (in_array($x['type'], ['index', 'trigger'], true) && $x['tbl_name'] === $t) fwrite($fh, $x['sql'] . ";\n" . $m);
                }
            }
            foreach ($objs as $o) if ($o['type'] === 'view') fwrite($fh, "-- VIEW {$o['name']}\n" . $o['sql'] . ";\n" . $m);
            fwrite($fh, "-- SEQUENCE\nDELETE FROM sqlite_sequence;\n" . $m);
            try {
                foreach ($pdo->query('SELECT name,seq FROM sqlite_sequence') as $s) {
                    fwrite($fh, 'INSERT INTO sqlite_sequence(name,seq) VALUES(' . self::sqlValue($s['name']) . ',' . (int) $s['seq'] . ");\n" . $m);
                }
            } catch (\Throwable $e) { /* no sqlite_sequence */ }
            fwrite($fh, "-- END OF DUMP\n");
            $fkViolations = count($pdo->query('PRAGMA foreign_key_check')->fetchAll());
        } finally {
            $pdo->rollBack();
            fclose($fh);
        }
        return $counts;
    }

    /** Reads the dump statement by statement: $each(?string $table, string $sql). @return bool END marker seen */
    private static function readDump(string $sqlFile, callable $each): bool
    {
        $fh = fopen($sqlFile, 'rb');
        if (!$fh) throw new RuntimeException(self::t('err.dump_read'));
        $buf = ''; $table = null; $end = false;
        while (($line = fgets($fh)) !== false) {
            $t = rtrim($line, "\r\n");
            if ($buf === '') {
                if (str_starts_with($t, '-- TABLE ')) { $table = substr($t, 9); continue; }
                if (str_starts_with($t, '-- VIEW '))  { $table = '#view:' . substr($t, 8); continue; }
                if ($t === '-- SEQUENCE')             { $table = '#sequence'; continue; }
                if ($t === '-- END OF DUMP')          { $end = true; continue; }
                if ($t === '' || str_starts_with($t, '-- SQL dump')) continue;
            }
            if ($t === self::STMT_END) { $each($table, $buf); $buf = ''; continue; }
            $buf .= $line;
        }
        fclose($fh);
        return $end;
    }

    /** Loads the dump into a temporary SQLite file; returns integrity + FK + row counts (restorability test). */
    public static function testDump(string $sqlFile, string $tmpDb): array
    {
        @unlink($tmpDb);
        $pdo = new PDO('sqlite:' . $tmpDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('BEGIN');
        try {
            $end = self::readDump($sqlFile, function (?string $t, string $sql) use ($pdo) { $pdo->exec($sql); });
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) { @$pdo->exec('ROLLBACK'); $pdo = null; @unlink($tmpDb); throw $e; }
        if (!$end) { $pdo = null; @unlink($tmpDb); throw new RuntimeException(self::t('err.dump_incomplete')); }
        $r = ['integrity' => (string) $pdo->query('PRAGMA integrity_check')->fetchColumn(), 'fk_violations' => count($pdo->query('PRAGMA foreign_key_check')->fetchAll())];
        $tables = [];
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $tables[$t] = (int) $pdo->query('SELECT COUNT(*) FROM "' . $t . '"')->fetchColumn();
        }
        $r['tables'] = $tables;
        $pdo = null;
        @unlink($tmpDb); @unlink($tmpDb . '-wal'); @unlink($tmpDb . '-shm');
        return $r;
    }

    /** Replaces the live database inside ONE transaction; on any error ROLLBACK (current data stays intact). */
    public static function replaceDatabase(string $dbFile, string $sqlFile, ?array $onlyTables = null, int $allowFk = 0): int
    {
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 20000');
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('BEGIN IMMEDIATE');
        $n = 0;
        try {
            if ($onlyTables === null) {
                foreach (['view', 'trigger', 'index', 'table'] as $ty) {
                    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='$ty' AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $nm) {
                        $pdo->exec('DROP ' . strtoupper($ty) . ' IF EXISTS "' . str_replace('"', '""', $nm) . '"');
                    }
                }
            }
            $end = self::readDump($sqlFile, function (?string $t, string $sql) use ($pdo, $onlyTables, &$n) {
                if ($onlyTables !== null) {
                    if ($t === null || !in_array($t, $onlyTables, true)) return;
                    if (str_starts_with($sql, 'CREATE TABLE')) $pdo->exec('DROP TABLE IF EXISTS "' . str_replace('"', '""', $t) . '"');
                }
                $pdo->exec($sql); $n++;
            });
            if (!$end) throw new RuntimeException(self::t('err.dump_corrupt'));
            if ($onlyTables === null) {
                $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
                if (count($fk) > $allowFk) throw new RuntimeException(self::t('err.fk_increase', ['n' => count($fk), 'was' => $allowFk]));
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable $x) {}
            throw $e;
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $n;
    }

    /* ================================================================ scanning */

    /** @return array<string,array{path:string,size:int,mtime:int}> rel => info */
    private static function walk(string $abs, string $relPrefix, ?callable $skip = null): array
    {
        $out = [];
        if (!is_dir($abs)) return $out;
        $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $f) use ($abs, $relPrefix, $skip) {
                if ($f->isLink()) return false;
                $rel = $relPrefix . ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($abs))), '/');
                if ($skip && $skip($rel, $f->isDir())) return false;
                return $f->isDir() || !self::builtinSkip($f->getFilename());
            }));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = $relPrefix . ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($abs))), '/');
            $out[$rel] = ['path' => $f->getPathname(), 'size' => (int) $f->getSize(), 'mtime' => (int) $f->getMTime()];
        }
        ksort($out);
        return $out;
    }

    public static function scanWebsite(string $root, array $layout, string $storageDir): array
    {
        $ex = self::websiteExcludes($root, $layout, $storageDir);
        $out = self::walk($root, '', fn(string $rel) => self::excluded($rel, $ex));
        foreach ($layout['keep_files'] as $k) {
            $k = trim(str_replace('\\', '/', (string) $k), '/');
            if (is_file("$root/$k")) $out[$k] = ['path' => "$root/$k", 'size' => (int) filesize("$root/$k"), 'mtime' => (int) filemtime("$root/$k")];
        }
        ksort($out);
        return $out;
    }

    /** @return array<string,array{images:array,videos:array,documents:array,other:array}> alias => category => rel => info */
    public static function scanUploads(string $root, array $layout): array
    {
        $r = [];
        foreach ($layout['uploads'] as $alias => $dir) {
            $r[$alias] = ['images' => [], 'videos' => [], 'documents' => [], 'other' => []];
            foreach (self::walk("$root/$dir", '') as $rel => $i) $r[$alias][self::category($rel)][$rel] = $i;
        }
        return $r;
    }

    /** External content (YouTube, CDNs) — reported by reference only. Scans text columns of the database. */
    public static function scanExternal(string $dbFile): array
    {
        $r = ['youtube_refs' => 0, 'hosts' => []];
        try {
            $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
                foreach ($pdo->query('PRAGMA table_info("' . $t . '")')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    if (stripos((string) $c['type'], 'CHAR') === false && stripos((string) $c['type'], 'TEXT') === false && $c['type'] !== '') continue;
                    try {
                        foreach ($pdo->query('SELECT "' . $c['name'] . '" FROM "' . $t . '" WHERE "' . $c['name'] . "\" LIKE '%http%' LIMIT 5000") as $row) {
                            $txt = (string) $row[$c['name']];
                            $r['youtube_refs'] += preg_match_all('#(youtube\.com|youtu\.be|youtube-nocookie\.com)#i', $txt);
                            if (preg_match_all('#https?://([a-z0-9.-]+)#i', $txt, $m)) foreach ($m[1] as $h) { $h = strtolower($h); $r['hosts'][$h] = ($r['hosts'][$h] ?? 0) + 1; }
                        }
                    } catch (\Throwable $e) {}
                }
            }
        } catch (\Throwable $e) {}
        arsort($r['hosts']);
        $r['hosts'] = array_slice($r['hosts'], 0, 30, true);
        return $r;
    }

    /* ================================================================ create */

    /**
     * @param array $o root, dir (storage), id, type(full|quick|pre-restore), scope[db,files,uploads,config], key(?string),
     *                 created_by, origin, site_url, app_name, app_version, env, layout, module_dir
     * @param callable $progress fn(string $step, string $state, ?int $pct, string $info)
     */
    public static function create(array $o, callable $progress): array
    {
        $root = $o['root']; $dir = $o['dir']; $id = $o['id']; $scope = $o['scope']; $key = $o['key'] ?? null; $layout = $o['layout'];
        $tmp = $dir . '/tmp'; if (!is_dir($tmp)) @mkdir($tmp, 0775, true);
        $out = $dir . '/' . $id . '.tar.gz'; $part = $out . '.part';
        $dbFile = $layout['database'] ? $root . '/' . $layout['database']['path'] : '';
        $scope['db'] = $scope['db'] && $layout['database'] && is_file($dbFile);
        $warnings = [];
        $sens = []; $tables = []; $dumpFile = $tmp . '/' . $id . '.sql'; $sqliteVer = ''; $fkViol = 0;

        if ($scope['db']) {
            $progress('database', 'running', 10, self::t('msg.dump_running'));
            $tables = self::dumpDatabase($dbFile, $dumpFile, $layout['sensitive'], $sens, $sqliteVer, $fkViol);
            $progress('database', 'done', 100, self::t('msg.dump_done', ['rows' => array_sum($tables), 'tables' => count($tables)]));
        } else { $progress('database', 'skipped', 100, self::t('msg.out_of_scope')); }

        $website = $scope['files'] ? self::scanWebsite($root, $layout, $dir) : [];
        $uploads = $scope['uploads'] ? self::scanUploads($root, $layout) : [];
        $cfgFiles = []; $secretMap = [];
        if ($scope['config']) {
            foreach ($layout['config_files'] as $f) if (is_file("$root/$f")) $cfgFiles['config/' . $f] = ['path' => "$root/$f", 'size' => (int) filesize("$root/$f"), 'mtime' => (int) filemtime("$root/$f")];
            foreach ($layout['secret_files'] as $f) foreach (glob("$root/$f") ?: [] as $sf) if (is_file($sf)) $secretMap[ltrim(substr(str_replace('\\', '/', $sf), strlen($root)), '/')] = (string) file_get_contents($sf);
        }
        $external = $scope['db'] ? self::scanExternal($dbFile) : [];

        $cat = fn(string $c) => array_reduce($uploads, fn($a, $u) => $a + count($u[$c]), 0);
        $catBytes = fn(string $c) => array_reduce($uploads, fn($a, $u) => $a + array_sum(array_column($u[$c], 'size')), 0);
        $sizeOf = fn(array $l) => array_sum(array_column($l, 'size'));
        $totalBytes = $sizeOf($website) + $sizeOf($cfgFiles) + $catBytes('images') + $catBytes('videos') + $catBytes('documents') + $catBytes('other') + ($scope['db'] ? (int) filesize($dumpFile) : 0);
        $fileCount = count($website) + count($cfgFiles) + $cat('images') + $cat('videos') + $cat('documents') + $cat('other') + ($scope['db'] ? 1 : 0);

        $components = [];
        foreach (['db' => 'database', 'files' => 'website', 'uploads' => 'uploads', 'config' => 'config'] as $k => $c) if ($scope[$k]) $components[] = $c;
        $encFiles = [];
        if ($scope['db'] && $layout['sensitive']) $encFiles[] = 'database/sensitive.enc';
        if ($secretMap) $encFiles[] = 'config/secrets.enc';

        $manifest = [
            'backup_id' => $id, 'backup_version' => 1, 'backup_format_version' => self::FORMAT,
            'application' => ['name' => $o['app_name'] ?? '', 'version' => $o['app_version'] ?? ''],
            'type' => $o['type'], 'origin' => $o['origin'] ?? 'manual', 'created_at' => date('c'), 'created_ts' => time(),
            'created_by' => $o['created_by'] ?? '', 'site_url' => $o['site_url'] ?? '',
            'database' => $scope['db'] ? ['type' => 'sqlite', 'version' => $sqliteVer, 'dump_file' => 'database/database.sql',
                                           'dump_size' => (int) filesize($dumpFile), 'fk_violations' => $fkViol, 'tables' => $tables] : null,
            'runtime' => ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'environment' => $o['env'] ?? 'prod'],
            'counts' => ['files' => $fileCount, 'total_bytes' => $totalBytes, 'website_files' => count($website),
                         'images' => $cat('images'), 'videos' => $cat('videos'), 'documents' => $cat('documents'), 'other' => $cat('other')],
            'upload_dirs' => array_keys($uploads),
            'components' => $components,
            'layout' => $layout,
            'encryption' => ['key_id' => $key ? self::keyId($key) : null, 'files' => $encFiles],
            'external' => ['local_media' => 'BACKED UP', 'youtube_refs' => (int) ($external['youtube_refs'] ?? 0), 'external_cdn' => 'NOT INCLUDED', 'hosts' => $external['hosts'] ?? []],
        ];

        $tar = new TarWriter($part, 3);
        $sums = [];
        $add = function (string $name, string $data) use ($tar, &$sums) { $sums[$name] = ['sha256' => $tar->addString($name, $data), 'size' => strlen($data)]; };
        try {
            $add('metadata/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $add('README-RESTORE.txt', self::readme($id, (string) $o['type']));
            $mod = $o['module_dir'] ?? '';
            if ($mod !== '') {
                foreach (['src/Engine.php', 'src/Tar.php', 'src/I18n.php', 'src/Config.php', 'lang/en.php', 'lang/tr.php', 'bin/restore-backup.php'] as $rel) {
                    if (is_file("$mod/$rel")) $add('restore/' . $rel, (string) file_get_contents("$mod/$rel"));
                }
            }
            if ($scope['db']) {
                $r = $tar->addFile('database/database.sql', $dumpFile);
                $sums['database/database.sql'] = ['sha256' => $r['sha256'], 'size' => $r['size']];
                if ($key && $layout['sensitive']) $add('database/sensitive.enc', json_encode(self::encrypt(json_encode($sens), $key)));
                elseif ($sens) $warnings[] = self::t('warn.secrets_skipped', ['n' => count($sens)]);
            }
            if ($secretMap) {
                if ($key) $add('config/secrets.enc', json_encode(self::encrypt(json_encode($secretMap), $key)));
                else $warnings[] = self::t('warn.env_skipped');
            }
            $steps = [['website', 'website/', $website]];
            foreach (['images', 'videos', 'documents', 'other'] as $c) {
                $list = [];
                foreach ($uploads as $alias => $u) foreach ($u[$c] as $rel => $i) $list["uploads/$alias/$c/$rel"] = $i;
                $steps[] = [$c, '', $list];
            }
            foreach ($steps as [$step, $prefix, $list]) {
                $inScope = $step === 'website' ? $scope['files'] : $scope['uploads'];
                if (!$inScope) { $progress($step, 'skipped', 100, self::t('msg.out_of_scope')); continue; }
                $tot = max(1, array_sum(array_column($list, 'size'))); $done = 0; $last = 0.0;
                $progress($step, 'running', 0, self::t('msg.n_files', ['n' => count($list)]));
                foreach ($list as $rel => $i) {
                    $name = $prefix . $rel;
                    $r = $tar->addFile($name, $i['path'], $i['size']);
                    $sums[$name] = ['sha256' => $r['sha256'], 'size' => $r['size']];
                    if ($r['warn']) $warnings[] = $r['warn'];
                    $done += $i['size'];
                    if (microtime(true) - $last > 0.4) { $progress($step, 'running', (int) floor($done * 100 / $tot), ''); $last = microtime(true); }
                }
                $progress($step, 'done', 100, self::t('msg.n_files', ['n' => count($list)]));
            }
            $progress('config', 'running', 50, '');
            foreach ($cfgFiles as $nm => $i) { $r = $tar->addFile($nm, $i['path'], $i['size']); $sums[$nm] = ['sha256' => $r['sha256'], 'size' => $r['size']]; }
            $progress('config', 'done', 100, $scope['config'] ? self::t('msg.config_done') : self::t('msg.out_of_scope'));
            $progress('checksums', 'running', 50, '');
            $tar->addString('metadata/checksums.json', json_encode(['algo' => 'sha256', 'files' => $sums], JSON_UNESCAPED_SLASHES));
            $tar->close();
            $progress('checksums', 'done', 100, self::t('msg.checksums_done', ['n' => count($sums)]));
        } catch (\Throwable $e) {
            $tar->abort(); @unlink($part); @unlink($dumpFile);
            throw $e;
        }
        @unlink($dumpFile);
        if (!@rename($part, $out)) { @unlink($part); throw new RuntimeException(self::t('err.finalize')); }
        return ['file' => $out, 'size' => (int) filesize($out), 'sha256' => hash_file('sha256', $out), 'manifest' => $manifest, 'warnings' => $warnings];
    }

    private static function readme(string $id, string $type): string
    {
        return <<<TXT
BACKUP PACKAGE / YEDEK PAKETİ  ({$id}, {$type})
==========================================================

[EN] This is a standard .tar.gz archive (opens with tar, 7-Zip, Windows 11).
RESTORE ON AN EMPTY SERVER (disaster recovery)
 1) Install PHP 8.1+ with pdo_sqlite, zlib, openssl.
 2) Unpack:   mkdir tmp && tar -xzf {$id}.tar.gz -C tmp
 3) Run:      php tmp/restore/bin/restore-backup.php {$id}.tar.gz --target /var/www/site [--key backup.key] [--lang en]
    It verifies every file (SHA-256), restores database/files/uploads/config, and runs a health check.
 4) Point your web server's document root to your site's public folder.
Secrets (passwords, tokens, .env) are NOT stored in plain text: they are AES-256-GCM encrypted. Pass the key file
you downloaded from the admin UI with --key. Without the key everything else is restored; secrets must be re-entered.

[TR] Bu dosya standart bir .tar.gz arşividir (tar, 7-Zip, Windows 11 ile açılır).
BOŞ BİR SUNUCUDA GERİ YÜKLEME (felaket kurtarma)
 1) PHP 8.1+ kurun (pdo_sqlite, zlib, openssl).
 2) Açın:     mkdir tmp && tar -xzf {$id}.tar.gz -C tmp
 3) Çalıştırın: php tmp/restore/bin/restore-backup.php {$id}.tar.gz --target /var/www/site [--key backup.key] [--lang tr]
    Betik her dosyayı doğrular (SHA-256), veritabanı/dosya/yüklemeleri/yapılandırmayı geri yükler ve sağlık kontrolü yapar.
 4) Web sunucusunun document root'unu sitenizin public klasörüne yönlendirin.
Gizli değerler (şifre, token, .env) açık metin saklanmaz: AES-256-GCM ile şifrelidir. Yönetim arayüzünden indirdiğiniz
anahtar dosyasını --key ile verin. Anahtar yoksa diğer her şey geri yüklenir; gizli değerleri yeniden girmeniz gerekir.

CONTENTS / İÇERİK
  metadata/manifest.json, metadata/checksums.json   database/database.sql   website/   uploads/<alias>/…   config/
TXT;
    }

    /* ================================================================ inspect & verify */

    public static function inspect(string $archive): array
    {
        $r = new TarReader($archive);
        try {
            $e = $r->next();
            if (!$e || $e['name'] !== 'metadata/manifest.json') throw new RuntimeException(self::t('err.not_backup'));
            $m = json_decode($r->readAll(4 * 1048576), true);
            if (!is_array($m)) throw new RuntimeException(self::t('err.manifest_unreadable'));
            return $m;
        } finally { $r->close(); }
    }

    public static function formatSupported(array $m): bool { return (int) ($m['backup_format_version'] ?? 0) === self::FORMAT; }

    /** Full verification: SHA-256 of every file + manifest + a real test load of the SQL dump. */
    public static function verify(string $archive, string $tmpDir, callable $progress): array
    {
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
        $errors = []; $seen = []; $manifest = null; $checks = null; $dumpTmp = null;
        $r = new TarReader($archive);
        $total = 1; $done = 0; $last = 0.0;
        try {
            while ($e = $r->next()) {
                $name = $e['name'];
                if (!self::safeRel($name)) { $errors[] = self::t('err.unsafe_path', ['p' => $name]); $r->skip(); continue; }
                $h = hash_init('sha256'); $buf = '';
                $fh = null;
                if ($name === 'database/database.sql') { $dumpTmp = $tmpDir . '/verify-' . bin2hex(random_bytes(4)) . '.sql'; $fh = fopen($dumpTmp, 'wb'); }
                $collect = in_array($name, ['metadata/manifest.json', 'metadata/checksums.json'], true);
                while (($c = $r->read()) !== null) {
                    hash_update($h, $c);
                    if ($fh) fwrite($fh, $c);
                    if ($collect) $buf .= $c;
                    $done += strlen($c);
                    if (microtime(true) - $last > 0.4) { $progress('verify', 'running', (int) min(99, floor($done * 100 / $total)), $name); $last = microtime(true); }
                }
                if ($fh) fclose($fh);
                $seen[$name] = ['sha256' => hash_final($h), 'size' => $e['size']];
                if ($name === 'metadata/manifest.json') {
                    $manifest = json_decode($buf, true);
                    if (is_array($manifest)) $total = max(1, (int) ($manifest['counts']['total_bytes'] ?? 1));
                } elseif ($name === 'metadata/checksums.json') {
                    $checks = json_decode($buf, true);
                }
            }
        } catch (\Throwable $ex) {
            $errors[] = self::t('err.archive_bad', ['m' => $ex->getMessage()]);
        } finally { $r->close(); }

        $groups = [];
        $mark = function (string $g, bool $ok, ?string $bad = null) use (&$groups) {
            $groups[$g] ??= ['state' => 'VERIFIED', 'count' => 0, 'bad' => []];
            $groups[$g]['count']++;
            if (!$ok) { $groups[$g]['state'] = 'FAILED'; if ($bad && count($groups[$g]['bad']) < 10) $groups[$g]['bad'][] = $bad; }
        };
        if (!is_array($manifest)) $errors[] = self::t('err.manifest_missing');
        elseif (!self::formatSupported($manifest)) $errors[] = self::t('err.format_unsupported', ['v' => $manifest['backup_format_version'] ?? '?', 'cur' => self::FORMAT]);
        if (!is_array($checks) || !isset($checks['files'])) $errors[] = self::t('err.checksums_missing');
        else {
            foreach ($checks['files'] as $p => $c) {
                if (!isset($seen[$p])) { $mark(self::groupOf($p), false, self::t('err.file_missing', ['p' => $p])); continue; }
                if (!hash_equals((string) $c['sha256'], $seen[$p]['sha256'])) $mark(self::groupOf($p), false, self::t('err.hash_differs', ['p' => $p]));
                else $mark(self::groupOf($p), true);
            }
            foreach ($seen as $p => $_) if (!isset($checks['files'][$p]) && $p !== 'metadata/checksums.json') $errors[] = self::t('err.unlisted_file', ['p' => $p]);
        }
        $dump = null;
        if (is_array($manifest) && !empty($manifest['database'])) {
            if (!$dumpTmp) { $errors[] = self::t('err.dump_missing'); $groups['database'] = ['state' => 'FAILED', 'count' => 0, 'bad' => [self::t('err.dump_missing')]]; }
            else {
                try {
                    $dump = self::testDump($dumpTmp, $tmpDir . '/verify-test-' . bin2hex(random_bytes(3)) . '.sqlite');
                    foreach (($manifest['database']['tables'] ?? []) as $t => $n) if (($dump['tables'][$t] ?? -1) !== (int) $n) $errors[] = self::t('err.rowcount', ['t' => $t]);
                    if ($dump['integrity'] !== 'ok') $errors[] = self::t('err.dump_integrity', ['r' => $dump['integrity']]);
                    $was = (int) ($manifest['database']['fk_violations'] ?? 0);
                    if ($dump['fk_violations'] > $was) $errors[] = self::t('err.dump_fk', ['n' => $dump['fk_violations'], 'was' => $was]);
                } catch (\Throwable $ex) {
                    $errors[] = self::t('err.dump_load_failed', ['m' => $ex->getMessage()]);
                    $groups['database'] = ['state' => 'FAILED', 'count' => 0, 'bad' => [self::t('err.dump_not_loadable')]];
                }
            }
        }
        if ($dumpTmp) @unlink($dumpTmp);
        foreach ($groups as $g => $v) if ($v['state'] === 'FAILED') $errors[] = self::t('err.group_failed', ['g' => $g]);
        $ok = !$errors && $manifest && $checks;
        $progress('verify', $ok ? 'done' : 'failed', 100, $ok ? self::t('msg.verified_all') : 'BACKUP CORRUPTED');
        return ['ok' => (bool) $ok, 'manifest' => $manifest, 'checks' => $checks['files'] ?? [], 'groups' => $groups, 'dump' => $dump, 'errors' => array_values(array_unique($errors))];
    }

    /* ================================================================ restore */

    /** Extracts into a temp folder, re-checking every file's SHA-256 while writing. */
    public static function extract(string $archive, string $stage, array $expected, callable $progress): int
    {
        self::rrmdir($stage); @mkdir($stage, 0775, true);
        $r = new TarReader($archive); $n = 0; $last = 0.0;
        try {
            while ($e = $r->next()) {
                $name = $e['name'];
                if (!self::safeRel($name)) throw new RuntimeException(self::t('err.unsafe_path', ['p' => $name]));
                if ($name === 'metadata/checksums.json') { $r->skip(); continue; }
                $dst = $stage . '/' . $name;
                if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0775, true);
                $fh = fopen($dst, 'wb'); if (!$fh) throw new RuntimeException(self::t('err.extract_write', ['p' => $name]));
                $h = hash_init('sha256');
                while (($c = $r->read()) !== null) { hash_update($h, $c); fwrite($fh, $c); }
                fclose($fh);
                if (isset($expected[$name]) && !hash_equals($expected[$name]['sha256'], hash_final($h))) throw new RuntimeException(self::t('err.extract_hash', ['p' => $name]));
                $n++;
                if (microtime(true) - $last > 0.4) { $progress('extract', 'running', null, $name); $last = microtime(true); }
            }
        } finally { $r->close(); }
        return $n;
    }

    private static function copyAtomic(string $src, string $dst): void
    {
        $d = dirname($dst); if (!is_dir($d)) @mkdir($d, 0775, true);
        $tmp = $dst . '.restore-tmp';
        if (!copy($src, $tmp)) throw new RuntimeException(self::t('err.copy_failed', ['p' => $dst]));
        if (!@rename($tmp, $dst)) { if (!@copy($tmp, $dst)) { @unlink($tmp); throw new RuntimeException(self::t('err.place_failed', ['p' => $dst])); } @unlink($tmp); }
    }

    /**
     * @param string $mode full|database|media|code|admin
     * @param array  $opts protect => root-relative path prefixes that must never be pruned (e.g. this module's own folder)
     */
    public static function apply(string $stage, string $root, string $mode, array $manifest, ?string $key, callable $progress, array $opts = []): array
    {
        $want = ['full' => ['db', 'uploads', 'website', 'config'], 'database' => ['db'], 'media' => ['uploads'], 'code' => ['website'], 'admin' => ['db']][$mode] ?? null;
        if (!$want) throw new InvalidArgumentException(self::t('err.unknown_mode'));
        $has = $manifest['components'] ?? [];
        $L = $manifest['layout'] ?? [];
        $protect = $opts['protect'] ?? [];
        $need = ['db' => 'database', 'uploads' => 'uploads', 'website' => 'website', 'config' => 'config'];
        $sum = ['mode' => $mode, 'notes' => []];
        foreach ($want as $c) {
            if (!in_array($need[$c], $has, true)) {
                if ($mode === 'full' && $c !== 'db') { $sum['notes'][] = self::t('note.component_skipped', ['c' => $need[$c]]); continue; }
                throw new RuntimeException(self::t('err.component_missing', ['c' => $need[$c], 'type' => $manifest['type'], 'mode' => $mode]));
            }
        }
        if ($mode === 'admin' && empty($L['admin_tables'])) throw new RuntimeException(self::t('err.no_admin_tables'));
        $dbRel = $L['database']['path'] ?? '';
        $dbFile = $root . '/' . $dbRel;
        $allowFk = (int) ($manifest['database']['fk_violations'] ?? 0);

        if (in_array('db', $want, true) && in_array('database', $has, true) && $dbRel !== '') {
            $progress('database', 'running', 20, self::t('msg.txn_loading'));
            if (!is_dir(dirname($dbFile))) @mkdir(dirname($dbFile), 0775, true);
            $sum['db_statements'] = self::replaceDatabase($dbFile, $stage . '/database/database.sql', $mode === 'admin' ? $L['admin_tables'] : null, $allowFk);
            $encFile = $stage . '/database/sensitive.enc';
            if (is_file($encFile)) {
                $plain = $key ? self::decrypt(json_decode((string) file_get_contents($encFile), true) ?: [], $key) : null;
                if ($plain === null) $sum['notes'][] = self::t('note.secrets_undecryptable');
                else {
                    $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    foreach (json_decode($plain, true) ?: [] as $s) {
                        $q = fn($x) => '"' . str_replace('"', '""', (string) $x) . '"';
                        $pdo->prepare('UPDATE ' . $q($s['t']) . ' SET ' . $q($s['vc']) . ' = ? WHERE ' . $q($s['kc']) . ' = ?')->execute([$s['v'], $s['k']]);
                    }
                    $sum['notes'][] = self::t('note.secrets_restored');
                }
            }
            $progress('database', 'done', 100, self::t($mode === 'admin' ? 'msg.db_restored_admin' : 'msg.db_restored'));
        }
        if (in_array('uploads', $want, true) && in_array('uploads', $has, true)) {
            $progress('uploads', 'running', 0, '');
            $c = 0; $rm = 0;
            foreach ($L['uploads'] ?? [] as $alias => $dir) {
                $want2 = [];
                foreach (['images', 'videos', 'documents', 'other'] as $cat) {
                    foreach (self::walk("$stage/uploads/$alias/$cat", '') as $rel => $i) { self::copyAtomic($i['path'], "$root/$dir/$rel"); $want2[$rel] = true; $c++; }
                }
                foreach (self::walk("$root/$dir", '') as $rel => $i) {
                    if (!isset($want2[$rel]) && basename($rel) !== '.gitkeep') { @unlink($i['path']); $rm++; }
                }
            }
            $sum['uploads_restored'] = $c; $sum['uploads_removed'] = $rm;
            $progress('uploads', 'done', 100, self::t('msg.uploads_done', ['n' => $c, 'rm' => $rm]));
        }
        if (in_array('website', $want, true) && in_array('website', $has, true)) {
            $progress('website', 'running', 0, '');
            $ex = array_merge(self::websiteExcludes($root, ['exclude' => $L['exclude'] ?? [], 'uploads' => $L['uploads'] ?? [], 'config_files' => $L['config_files'] ?? [],
                                                            'secret_files' => $L['secret_files'] ?? [], 'database' => $L['database'] ?? null], $opts['storage'] ?? ''), $protect);
            $c = 0; $rm = 0; $wantSet = [];
            foreach (self::walk("$stage/website", '') as $rel => $i) { self::copyAtomic($i['path'], "$root/$rel"); $wantSet[$rel] = true; $c++; }
            foreach (scandir("$stage/website") ?: [] as $top) {
                if ($top === '.' || $top === '..' || !is_dir("$stage/website/$top")) continue;
                foreach (self::walk("$root/$top", "$top/", fn(string $rel) => self::excluded($rel, $ex)) as $rel => $i) {
                    if (!isset($wantSet[$rel])) { @unlink($i['path']); $rm++; }
                }
            }
            $sum['files_restored'] = $c; $sum['files_removed'] = $rm;
            $progress('website', 'done', 100, self::t('msg.website_done', ['n' => $c, 'rm' => $rm]));
        }
        if (in_array('config', $want, true) && in_array('config', $has, true)) {
            $progress('config', 'running', 30, '');
            foreach (self::walk("$stage/config", '') as $rel => $i) {
                if ($rel === 'secrets.enc') continue;
                if (is_file("$root/$rel")) @copy("$root/$rel", "$root/$rel.pre-restore");
                self::copyAtomic($i['path'], "$root/$rel");
            }
            if (is_file("$stage/config/secrets.enc") && $key) {
                $plain = self::decrypt(json_decode((string) file_get_contents("$stage/config/secrets.enc"), true) ?: [], $key);
                if ($plain !== null) foreach (json_decode($plain, true) ?: [] as $rel => $txt) if (self::safeRel((string) $rel)) file_put_contents("$root/$rel", $txt);
            }
            $progress('config', 'done', 100, self::t('msg.config_restored'));
        }
        $progress('cache', 'running', 50, '');
        if (function_exists('opcache_reset')) @opcache_reset();
        $progress('cache', 'done', 100, self::t('msg.cache_cleared'));
        return $sum;
    }

    /* ================================================================ health check */

    /** @return array{ok:bool,checks:array<int,array{name:string,state:string,detail:string}>} */
    public static function health(string $root, array $layout, string $siteUrl = '', int $allowFk = 0): array
    {
        $c = []; $crit = false;
        $add = function (string $n, string $s, string $d = '', bool $critical = false) use (&$c, &$crit) { $c[] = ['name' => self::t($n), 'state' => $s, 'detail' => $d]; if ($s === 'FAIL' && $critical) $crit = true; };
        $H = $layout['health'] ?? [];
        if (!empty($layout['database']['path'])) {
            $dbFile = $root . '/' . $layout['database']['path'];
            try {
                $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $ic = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
                $fk = count($pdo->query('PRAGMA foreign_key_check')->fetchAll());
                $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
                $rows = 0; foreach ($tables as $t) $rows += (int) $pdo->query('SELECT COUNT(*) FROM "' . $t . '"')->fetchColumn();
                $add('health.database', $ic === 'ok' && $fk <= $allowFk ? 'OK' : 'FAIL', "integrity=$ic, FK=$fk" . ($allowFk ? " (" . self::t('health.already', ['n' => $allowFk]) . ")" : '') . ', ' . count($tables) . ' tables, ' . $rows . ' rows', true);
                foreach ($H['tables_nonempty'] ?? [] as $t) {
                    try { $n = (int) $pdo->query('SELECT COUNT(*) FROM "' . $t . '"')->fetchColumn(); $add('health.table', $n > 0 ? 'OK' : 'FAIL', "$t: $n", true); }
                    catch (\Throwable $e) { $add('health.table', 'FAIL', "$t: " . self::t('health.unreadable'), true); }
                }
                $miss = 0; $chk = 0;
                foreach ($H['referenced_files'] ?? [] as $rf) {
                    try {
                        foreach ($pdo->query('SELECT "' . $rf['column'] . '" FROM "' . $rf['table'] . '" WHERE "' . $rf['column'] . '" <> \'\'')->fetchAll(PDO::FETCH_COLUMN) as $f) {
                            $chk++; if (!is_file($root . '/' . trim($rf['dir'], '/') . '/' . basename((string) $f))) $miss++;
                        }
                    } catch (\Throwable $e) {}
                }
                if ($chk) $add('health.uploads', $miss === 0 ? 'OK' : 'WARN', $miss === 0 ? self::t('health.refs_ok', ['n' => $chk]) : self::t('health.refs_missing', ['m' => $miss, 'n' => $chk]));
            } catch (\Throwable $e) { $add('health.database', 'FAIL', $e->getMessage(), true); }
        }
        $req = $H['required_files'] ?? [];
        if ($req) {
            $miss = array_filter($req, fn($f) => !is_file($root . '/' . $f));
            $add('health.files', $miss ? 'FAIL' : 'OK', $miss ? self::t('health.missing') . ': ' . implode(', ', $miss) : self::t('health.n_present', ['n' => count($req)]), true);
        }
        foreach ($layout['uploads'] ?? [] as $alias => $dir) $add('health.upload_dir', is_dir("$root/$dir") && is_readable("$root/$dir") ? 'OK' : 'WARN', $dir);
        if ($siteUrl !== '') {
            foreach ($H['urls'] ?? ['/'] as $path) {
                $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true, 'header' => "User-Agent: BackupManagerHealth\r\n"]]);
                $body = @file_get_contents(rtrim($siteUrl, '/') . '/' . ltrim((string) $path, '/'), false, $ctx);
                $code = 0;
                foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int) $m[1];
                if ($body === false && $code === 0) $add('health.http', 'SKIP', $path . ': ' . self::t('health.unreachable'));
                elseif ($code >= 500) $add('health.http', 'FAIL', "$path: HTTP $code", true);
                else $add('health.http', $code >= 200 && $code < 400 ? 'OK' : 'WARN', "$path: HTTP $code");
            }
        }
        return ['ok' => !$crit, 'checks' => $c];
    }
}
