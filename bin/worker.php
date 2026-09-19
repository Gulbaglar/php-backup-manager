<?php
declare(strict_types=1);
/**
 * Background worker (CLI only):
 *   php bin/worker.php job-xxxxxxxxxxxx     job started by the web UI
 *   php bin/worker.php --auto               for cron: creates due automatic backups
 * The job keeps running even if the browser is closed; progress is read from storage/jobs/.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ignore_user_abort(true);
@set_time_limit(0);
@ini_set('memory_limit', '512M');
require dirname(__DIR__) . '/src/bootstrap.php';
$arg = $argv[1] ?? '';
if ($arg === '--auto') { \BackupManager\Manager::maybeAutoRun(true); exit(0); }
if (!preg_match('/^job-[a-f0-9]{12}$/', $arg)) { fwrite(STDERR, "Usage: php bin/worker.php <job-id> | --auto\n"); exit(2); }
\BackupManager\Manager::run($arg);
