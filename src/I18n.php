<?php
declare(strict_types=1);

namespace BackupManager;

/**
 * Minimal i18n: PHP arrays in lang/<code>.php. UI language is chosen by the person who installs/uses the module:
 * language switcher at the top of the UI (?lang=tr|en → cookie), otherwise config 'language', otherwise English.
 * To add a language: copy lang/en.php to lang/<code>.php, translate, and add the code to I18n::LANGS.
 */
final class I18n
{
    public const LANGS = ['en' => 'English', 'tr' => 'Türkçe'];
    private static string $lang = 'en';
    private static array $dict = [];
    private static array $fallback = [];

    /** Web: ?lang= → cookie → $default. CLI: $default. */
    public static function init(string $default = 'en'): void
    {
        $lang = isset(self::LANGS[$default]) ? $default : 'en';
        if (PHP_SAPI !== 'cli') {
            $cookie = (string) ($_COOKIE['bm_lang'] ?? '');
            if (isset(self::LANGS[$cookie])) $lang = $cookie;
            $q = (string) ($_GET['lang'] ?? '');
            if (isset(self::LANGS[$q])) {
                $lang = $q;
                if (!headers_sent()) setcookie('bm_lang', $q, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
            }
        }
        self::set($lang);
    }

    public static function set(string $lang): void
    {
        if (!isset(self::LANGS[$lang])) $lang = 'en';
        self::$lang = $lang;
        self::$fallback = self::$fallback ?: (require dirname(__DIR__) . '/lang/en.php');
        self::$dict = $lang === 'en' ? self::$fallback : (array) (require dirname(__DIR__) . '/lang/' . $lang . '.php');
    }

    public static function lang(): string { return self::$lang; }

    /** Translate; {name} placeholders are replaced from $p. Missing keys fall back to English, then to the key itself. */
    public static function t(string $key, array $p = []): string
    {
        if (!self::$fallback) self::set(self::$lang);
        $s = self::$dict[$key] ?? self::$fallback[$key] ?? $key;
        foreach ($p as $k => $v) $s = str_replace('{' . $k . '}', (string) $v, $s);
        return $s;
    }

    /** Whole dictionary for the browser (only "ui." keys). */
    public static function forJs(): array
    {
        if (!self::$fallback) self::set(self::$lang);
        $out = [];
        foreach (self::$fallback as $k => $v) if (str_starts_with($k, 'ui.')) $out[$k] = self::$dict[$k] ?? $v;
        return $out;
    }
}
