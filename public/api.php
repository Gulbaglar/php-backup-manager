<?php
declare(strict_types=1);
/** Backup Manager — JSON API. Requires an authenticated admin + a CSRF header. */
require __DIR__ . '/_boot.php';

use BackupManager\Auth;
use BackupManager\Config;
use BackupManager\Engine;
use BackupManager\I18n;
use BackupManager\Manager;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $d, int $code = 200): never { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function ok(array $x = []): never { out(['ok' => true] + $x); }
function bad(string $m, int $c = 422): never { out(['ok' => false, 'error' => $m], $c); }

if (!Auth::check()) bad(I18n::t('err.session_required'), 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad(I18n::t('err.post_required'), 405);
if (!Auth::csrfOk((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) bad(I18n::t('err.csrf'), 419);

$isChunk = ($_GET['action'] ?? '') === 'upload_chunk';
$in = $isChunk ? [] : json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$action = $isChunk ? 'upload_chunk' : (string) ($in['action'] ?? '');
$user = Auth::user();
$id = (string) ($in['id'] ?? '');
$lang = I18n::lang();

try {
    Manager::ensure();
    switch ($action) {
        case 'state':
            ok(['state' => Manager::state(), 'log' => Manager::logTail(40)]);

        case 'job':
            $j = Manager::job($id); if (!$j) bad(I18n::t('err.job_not_found'), 404);
            if (in_array($j['status'], ['queued', 'running'], true) && time() - (int) $j['heartbeat'] > 180) { Manager::activeJob(); $j = Manager::job($id); }
            ok(['job' => $j]);

        case 'start_backup':
            ok(Manager::start('backup', ['type' => ($in['type'] ?? 'full') === 'quick' ? 'quick' : 'full', 'origin' => 'manual', 'user' => $user, 'base_url' => bm_site_url(), 'lang' => $lang]));

        case 'verify':
            if (!Manager::meta($id)) bad(I18n::t('err.backup_not_found'), 404);
            ok(Manager::start('verify', ['source' => 'backup:' . $id, 'user' => $user, 'lang' => $lang]));

        case 'details': {
            $m = Manager::meta($id); if (!$m) bad(I18n::t('err.backup_not_found'), 404);
            $man = null;
            try { $man = Engine::inspect(Manager::path($id)); } catch (\Throwable $e) {}
            $m['exists'] = is_file(Manager::path($id));
            ok(['meta' => $m, 'manifest' => $man]);
        }

        case 'delete':
            Manager::delete($id);
            ok();

        case 'protect':
            Manager::setProtected($id, !empty($in['on']));
            ok();

        case 'save_settings':
            Manager::saveSettings(is_array($in['settings'] ?? null) ? $in['settings'] : []);
            ok(['settings' => Manager::settings()]);

        case 'upload_init': {
            $size = (int) ($in['size'] ?? 0);
            if ($size <= 0) bad(I18n::t('err.empty_file'));
            if (!preg_match('/\.tar\.gz$|\.tgz$/i', (string) ($in['name'] ?? ''))) bad(I18n::t('err.only_targz'));
            if ($size > (int) (disk_free_space(Manager::dir()) ?: PHP_INT_MAX)) bad(I18n::t('err.no_disk'));
            ok(['upload' => Manager::uploadInit((string) $in['name'], $size)]);
        }

        case 'upload_chunk':
            ok(['received' => Manager::uploadChunk((string) ($_GET['u'] ?? ''), (int) ($_GET['offset'] ?? -1), (string) file_get_contents('php://input'))]);

        case 'upload_finish':
            ok(Manager::uploadFinish((string) ($in['upload'] ?? '')));

        case 'start_restore': {
            if (!Auth::verifyPassword((string) ($in['password'] ?? ''))) { sleep(1); bad(I18n::t('err.password_wrong'), 403); }
            $mode = (string) ($in['mode'] ?? 'full');
            if (!in_array($mode, ['full', 'database', 'media', 'code', 'admin'], true)) bad(I18n::t('err.bad_mode'));
            $source = (string) ($in['source'] ?? '');
            if (str_starts_with($source, 'backup:') && !Manager::meta(substr($source, 7))) bad(I18n::t('err.backup_not_found'), 404);
            Manager::log(['event' => 'restore_requested', 'source' => $source, 'mode' => $mode, 'user' => $user]);
            ok(Manager::start('restore', ['source' => $source, 'mode' => $mode, 'user' => $user, 'base_url' => bm_site_url(), 'lang' => $lang]));
        }

        default:
            bad(I18n::t('err.unknown_action'), 400);
    }
} catch (InvalidArgumentException $e) {
    bad($e->getMessage());
} catch (\Throwable $e) {
    error_log('[BackupManager] ' . $e->getMessage());
    bad(I18n::t('err.server_error') . (Config::get('environment') === 'dev' ? ': ' . $e->getMessage() : ''), 500);
}
