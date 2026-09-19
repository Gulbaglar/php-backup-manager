<?php
/**
 * Backup Manager configuration.
 *
 * Copy this file to config/config.php and edit it. (If config.php does not exist, this example is used — it points at
 * the demo site created by `php tools/create-demo.php`, so you can try everything immediately.)
 *
 * Paths marked "relative" are relative to 'root'. Only SQLite databases are supported (or none: files-only backups).
 */
return [
    // Default UI language: 'en' or 'tr'. Whoever uses the module can also switch it at the top of every page.
    'language'    => 'en',

    // Shown inside backups (manifest) and the UI. Free text.
    'app_name'    => 'My Website',
    'app_version' => '',
    'environment' => 'prod',          // 'dev' shows technical error details in the UI
    'timezone'    => 'UTC',           // e.g. 'Europe/Istanbul'
    'id_prefix'   => 'backup',        // backup files are named <prefix>-full-2026-01-31-08-45.tar.gz

    // The project you want to protect (absolute path). Everything below is relative to it.
    'root'        => __DIR__ . '/../demo-site',

    // Where backups are stored. MUST be outside your web root (the default folder next to this module is fine
    // as long as only `public/` is exposed by your web server).
    'storage'     => __DIR__ . '/../storage',

    // Public URL of the site itself (only used for the post-restore HTTP health check). Leave empty to derive it
    // from the current request (scheme://host).
    'base_url'    => '',

    // SQLite database (relative path) or null for files-only backups.
    'database'    => ['type' => 'sqlite', 'path' => 'data/app.sqlite'],

    // Site source files: everything under 'root' except these (relative paths, folders, or globs like *.map).
    // Uploads, config files, the database and the backup storage folder are handled separately and excluded
    // automatically.
    'website'     => [
        'exclude'    => ['.git', 'node_modules', 'vendor/bin', '*.map'],
        'keep_files' => ['data/.htaccess'],     // files inside an otherwise excluded folder that must still be backed up
    ],

    // User-uploaded media. alias => relative folder. Sorted into images/videos/documents/other inside the backup.
    'uploads'     => ['media' => 'public/uploads'],

    // Config files backed up as-is (relative).
    'config_files' => ['config/app.php'],

    // Secret files (globs allowed) — stored ONLY encrypted (AES-256-GCM) inside the backup.
    'secret_files' => ['.env'],

    // Secret values inside the database: rows whose key column matches the pattern are stored encrypted, not in the SQL dump.
    'sensitive'   => [
        ['table' => 'settings', 'key_column' => 'key', 'value_column' => 'value', 'pattern' => '/(pass|secret|token|api[_-]?key)/i'],
    ],

    // Tables restored by the "Admin Content Only" mode (leave empty to hide that mode).
    'admin_tables' => ['pages', 'settings'],

    // Checks run after every restore (a critical failure triggers an automatic rollback).
    'health'      => [
        'required_files'   => ['public/index.php'],                       // relative to root
        'tables_nonempty'  => ['users'],                                  // these tables must contain rows
        'urls'             => ['/'],                                      // requested via base_url; HTTP 5xx = failure
        'referenced_files' => [                                           // DB columns pointing to uploaded files
            ['table' => 'posts', 'column' => 'image', 'dir' => 'public/uploads'],
        ],
    ],

    // Access control.
    //  'builtin'  : one admin account; you create the password the first time you open the UI.
    //  'callback' : use YOUR application's login (see src/Auth.php for the closures to provide).
    'auth'        => ['mode' => 'builtin', 'username' => 'admin'],
];
