<?php
declare(strict_types=1);
/**
 * Disaster recovery: restores a backup onto an EMPTY server, without the admin UI.
 * This script is also bundled inside every backup archive (restore/bin/restore-backup.php).
 *
 *   php restore-backup.php <backup.tar.gz> --target /var/www/site [--key backup.key] [--mode full] [--lang en|tr] [--yes]
 *
 *   --target DIR   folder to restore into (created if missing)
 *   --key FILE     the key file downloaded from the admin UI (decrypts secrets; without it secrets stay empty)
 *   --mode         full (default) | database | media | code | admin
 *   --lang         en | tr   (default: en)
 *   --yes          do not ask for confirmation
 * Needs: PHP 8.1+ with pdo_sqlite, zlib (and openssl for secrets). Nothing else.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__) . '/src/I18n.php';
require dirname(__DIR__) . '/src/Engine.php';

use BackupManager\Engine;
use BackupManager\I18n;

@set_time_limit(0);
@ini_set('memory_limit', '512M');

$args = array_slice($argv, 1); $opt = ['mode' => 'full', 'lang' => 'en']; $pos = [];
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--yes') $opt['yes'] = true;
    elseif (in_array($args[$i], ['--target', '--key', '--mode', '--lang'], true)) $opt[substr($args[$i], 2)] = $args[++$i] ?? '';
    else $pos[] = $args[$i];
}
I18n::set((string) $opt['lang']);
$T = fn(string $k, array $p = []) => I18n::t($k, $p);
$say = fn(string $s) => print($s . "\n");

$archive = $pos[0] ?? '';
if ($archive === '' || !is_file($archive) || empty($opt['target'])) { fwrite(STDERR, $T('cli.usage') . "\n"); exit(2); }
foreach (['pdo_sqlite', 'zlib'] as $ext) if (!extension_loaded($ext)) { fwrite(STDERR, $T('cli.ext_missing', ['e' => $ext]) . "\n"); exit(2); }

$target = rtrim(str_replace('\\', '/', (string) $opt['target']), '/');
$key = null;
if (!empty($opt['key'])) {
    $k = base64_decode(trim((string) @file_get_contents($opt['key'])), true);
    if ($k !== false && strlen($k) === 32) $key = $k; else $say($T('cli.key_unreadable'));
}

$say('== ' . $T('cli.title') . ' ==');
try {
    $m = Engine::inspect($archive);
    $c = $m['counts'];
    $say($T('cli.backup', ['id' => $m['backup_id'], 'type' => $m['type'], 'date' => $m['created_at']]));
    $say($T('cli.content', ['c' => implode(', ', $m['components']), 'f' => $c['files'], 'i' => $c['images'], 'v' => $c['videos'], 'd' => $c['documents']]));
    if (!Engine::formatSupported($m)) { fwrite(STDERR, $T('cli.format_unsupported') . "\n"); exit(1); }
    $prog = function (string $s, string $st, ?int $p, string $i = '') use ($say) {
        static $seen = [];
        if (!isset($seen["$s$st"]) && in_array($st, ['running', 'done', 'failed'], true)) { $seen["$s$st"] = 1; $say(sprintf('  %-10s %-8s %s', $s, $st, $i)); }
    };

    $say($T('cli.step_verify'));
    $tmp = $target . '/.restore-tmp'; @mkdir($tmp, 0775, true);
    $v = Engine::verify($archive, $tmp, $prog);
    if (!$v['ok']) { fwrite(STDERR, "\nBACKUP CORRUPTED — DO NOT RESTORE\n - " . implode("\n - ", $v['errors']) . "\n"); exit(1); }
    foreach ($v['groups'] as $g => $x) $say(sprintf('  %-12s %s (%d)', $g, $x['state'], $x['count']));

    $dbRel = $m['layout']['database']['path'] ?? '';
    $existing = $dbRel !== '' && is_file("$target/$dbRel");
    if ($existing && empty($opt['yes'])) {
        echo "\n" . $T('cli.overwrite', ['t' => $target]) . ' ';
        $a = strtolower(trim((string) fgets(STDIN)));
        if (!in_array($a, ['yes', 'y', 'evet', 'e'], true)) { $say($T('cli.cancelled')); exit(1); }
    }
    if ($existing) { $bak = "$target/$dbRel.pre-restore-" . date('YmdHis'); copy("$target/$dbRel", $bak); $say($T('cli.db_copied', ['p' => $bak])); }

    $say($T('cli.step_extract'));
    $stage = $tmp . '/stage-' . bin2hex(random_bytes(3));
    Engine::extract($archive, $stage, $v['checks'], $prog);
    if (!empty($m['database'])) {
        $say($T('cli.step_dump'));
        $t = Engine::testDump($stage . '/database/database.sql', $tmp . '/test.sqlite');
        if ($t['integrity'] !== 'ok' || $t['fk_violations'] > (int) ($m['database']['fk_violations'] ?? 0)) { fwrite(STDERR, $T('err.dump_inconsistent') . "\n"); exit(1); }
    }
    $say($T('cli.step_apply'));
    $sum = Engine::apply($stage, $target, (string) $opt['mode'], $m, $key, $prog, ['storage' => $target . '/.restore-tmp']);
    foreach ($sum['notes'] as $n) $say('  ' . $T('cli.note') . ': ' . $n);
    Engine::rrmdir($tmp);
    $say($T('cli.step_health'));
    $h = Engine::health($target, $m['layout'], '', (int) ($m['database']['fk_violations'] ?? 0));
    foreach ($h['checks'] as $ch) $say(sprintf('  %-24s %-5s %s', $ch['name'], $ch['state'], $ch['detail']));
    if (!$h['ok']) { fwrite(STDERR, "\nRESTORE FAILED — " . $T('cli.health_failed') . "\n"); exit(1); }
    $say("\nRESTORE SUCCESSFUL");
    $say($T('cli.next', ['t' => $target]));
    $say($T('cli.login_note'));
} catch (\Throwable $e) {
    fwrite(STDERR, "\n" . $T('cli.error') . ': ' . $e->getMessage() . "\n");
    exit(1);
}
