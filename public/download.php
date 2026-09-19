<?php
declare(strict_types=1);
/**
 * Backup download — authenticated admins only. The file is streamed in 1 MB chunks (never loaded into memory) and
 * supports HTTP Range, so an interrupted multi-GB download can be resumed.
 *   ?id=<backup-id>&t=<csrf>     backup archive
 *   ?key=1&t=<csrf>              encryption key file
 */
require __DIR__ . '/_boot.php';

use BackupManager\Auth;
use BackupManager\I18n;
use BackupManager\Manager;

if (!Auth::check()) { http_response_code(401); exit(I18n::t('err.session_required')); }
if (!Auth::csrfOk((string) ($_GET['t'] ?? ''))) { http_response_code(419); exit(I18n::t('err.csrf')); }
$user = Auth::user();
session_write_close();   // a long download must not lock other requests of the same session

if (isset($_GET['key'])) {
    $k = Manager::key();
    if (!$k) { http_response_code(404); exit(I18n::t('err.no_key')); }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="backup.key"');
    header('Cache-Control: no-store');
    echo base64_encode($k) . "\n";
    exit;
}

$id = (string) ($_GET['id'] ?? '');
if (!Manager::validId($id) || !is_file(Manager::path($id))) { http_response_code(404); exit(I18n::t('err.backup_not_found')); }
$file = Manager::path($id);
$size = (int) filesize($file);
$start = 0; $end = $size - 1;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') $start = (int) $m[1];
    if ($m[2] !== '') $end = min($end, (int) $m[2]);
    if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
while (ob_get_level() > 0) ob_end_clean();
@set_time_limit(0);
header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . $id . '.tar.gz"');
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$fh = fopen($file, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh) && !connection_aborted()) {
    $chunk = fread($fh, min(1048576, $left));
    if ($chunk === false || $chunk === '') break;
    echo $chunk; $left -= strlen($chunk);
    flush();
}
fclose($fh);
Manager::log(['event' => 'download', 'backup_id' => $id, 'user' => $user, 'bytes' => $end - $start + 1 - $left]);
