# PHP Backup Manager

**Full & quick backups, verification, restore and disaster recovery for PHP + SQLite websites — with an admin UI in English and Turkish.**

🇹🇷 Türkçe: [README.tr.md](README.tr.md)

> A backup is not successful until it has been **verified** and can be **restored**.

A self-contained, framework-free module. Drop it next to any PHP site (files + optional SQLite database), point the config at your project, and you get a web UI to create, verify, download and restore backups — and a stand-alone script that can rebuild the site on a brand-new, empty server from nothing but the backup file.

## Features

- **Full Backup** — database (consistent SQL snapshot), site files, uploaded media (sorted into images / videos / documents / other), configuration.
- **Quick Backup** — database only; seconds; ideal right before you deploy or change something big.
- **Integrity** — SHA-256 for every file + manifest + a *real test load* of the SQL dump after every backup. Damaged backup ⇒ `BACKUP CORRUPTED — DO NOT RESTORE`.
- **Streaming everywhere** — `.tar.gz` written/read in 1 MB chunks (multi-GB videos never sit in RAM), Range-capable download, chunked upload for restore (no `upload_max_filesize` limits).
- **Safe restore** — verify → extract to temp → test-load the dump → mandatory *Pre-Restore Safety Backup* → apply → health check → **automatic rollback** if the health check fails. The database is replaced inside **one transaction** (on error nothing changes).
- **Restore modes** — Full · Database only · Media only · Code/site files only · Admin content only (tables you choose).
- **Secrets are never stored in plain text** — matching DB values (e.g. `smtp_password`) and `.env` files are AES-256-GCM encrypted; download the key file separately.
- **Disaster recovery** — every archive contains `restore/bin/restore-backup.php`. On an empty server: unpack with `tar`, run one command.
- **Automatic backups** — daily / every 3 days / weekly / monthly (Full and Quick separately), retention per type, **Protected** backups are never deleted.
- **Private storage** — backups live outside the web root; downloads only through the authenticated endpoint.
- **Background jobs with live progress** — closing the browser doesn't stop a backup/restore.
- **Two UI languages (English / Türkçe)** — switch at the top of every page; default set in the config.
- **Logging** of every backup / verify / restore / download.

## Requirements

PHP 8.1+ with `pdo_sqlite`, `zlib`, `openssl` (openssl only needed for encrypting secrets). No Composer packages, no build step.

> Only **SQLite** databases are supported (or none — files-only backups). MySQL/PostgreSQL are not supported yet.

## Quick start (2 minutes, with the demo site)

```bash
git clone <this repo> php-backup-manager && cd php-backup-manager
php tools/create-demo.php            # creates ./demo-site (SQLite DB, uploads, .env with fake secrets)
php -S localhost:8080 -t public      # open http://localhost:8080/
```

1. The first visit asks you to **create a password** (stored hashed in `storage/auth.json`).
2. Click **Create Full Backup**, then try **Restore** (change something in `demo-site/` first!).
3. Switch the language with **English | Türkçe** at the top.

## Use it for your own project

Copy `config/config.example.php` to `config/config.php` and edit:

| Key | Meaning |
|---|---|
| `root` | absolute path of the project to protect |
| `storage` | where backups live — **must be outside the web root** |
| `database` | `['type'=>'sqlite','path'=>'data/app.sqlite']` (relative to `root`) or `null` |
| `website.exclude` | folders/globs that are not part of the "site files" |
| `uploads` | `alias => folder` of user-uploaded media |
| `config_files` / `secret_files` | configs saved as-is / `.env`-style files saved **encrypted only** |
| `sensitive` | DB rows whose values are stored encrypted (table, key column, value column, regex) |
| `admin_tables` | tables restored by *Admin Content Only* (empty = mode hidden) |
| `health` | required files, non-empty tables, URLs to request, files referenced from DB columns |
| `language` | default UI language: `en` or `tr` |
| `auth` | `builtin` (own password) or `callback` (use your app's login — see `src/Auth.php`) |

Expose **only `public/`** to the web (document root or a sub-folder). If you copy `public/` somewhere else, edit the single `require` in `public/_boot.php`.

Optional: add `require '/path/to/php-backup-manager/src/maintenance-guard.php';` at the top of your site's entry point so visitors get a short "maintenance" page while a restore is running.

## Disaster recovery (empty server, no admin UI)

You only need the backup file (and the key file if you want secrets restored):

```bash
mkdir tmp && tar -xzf backup-full-2026-01-31-08-45.tar.gz -C tmp
php tmp/restore/bin/restore-backup.php backup-full-2026-01-31-08-45.tar.gz \
    --target /var/www/site --key backup.key --lang en
```

The script verifies every file, restores database/files/uploads/config, runs a health check and tells you what to do next. The archive is a plain `.tar.gz` — you can also open it with 7-Zip / Windows 11.

## Automatic backups

Enable them in the UI. A due backup starts in the background when you open the page; for unattended servers add cron:

```cron
0 3 * * * php /path/to/php-backup-manager/bin/worker.php --auto
```

## Security notes

- Backups and the key live in `storage/` (protected by `.htaccess` on Apache; on Nginx keep it outside the web root).
- Restore needs your password again. All endpoints require the admin session + a CSRF header.
- Secrets are only decryptable with the key file. **Store the key separately from the backups.**
- First-run setup creates the admin password: open the UI right after installing, before exposing it publicly.

## Languages

`lang/en.php` is the reference, `lang/tr.php` the Turkish translation. To add a language copy `en.php`, translate, add the code to `I18n::LANGS` in `src/I18n.php`. `php tools/check-i18n.php` checks the files.

## Layout

```
src/        Engine (backup/verify/restore), Manager (jobs, history), Auth, Config, I18n, Tar
bin/        worker.php (background/cron), restore-backup.php (disaster recovery)
public/     web UI (index, api, download, login) — the only folder that must be web-accessible
lang/       en.php, tr.php        config/  config.example.php        tools/  demo + i18n checker
```

## ☕ Support the Project

This project is free and open source.

If it saved you time, helped your project, or you simply want to support future development, you can buy me a coffee. ❤️

[![Buy Me a Coffee](https://img.shields.io/badge/Buy%20Me%20a%20Coffee-Support-orange?style=for-the-badge&logo=buymeacoffee)](https://www.buymeacoffee.com/Gulbaglar)

Thank you for supporting open-source development!

## Author

**Kahraman Gülbağlar**  
https://www.gulbaglar.com

## License

MIT — see [LICENSE](LICENSE).
