<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';
\BackupManager\Auth::logout();
header('Location: login.php');
