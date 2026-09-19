<?php
declare(strict_types=1);
/**
 * Contributor tool: checks the language files.
 *     php tools/check-i18n.php
 * - every language file must have exactly the same keys as lang/en.php
 * - every literal key used in src/, public/, bin/ (t('…'), T('…'), self::t('…')) must exist
 */
$dir = dirname(__DIR__);
$en = require "$dir/lang/en.php";
$fail = 0;
foreach (glob("$dir/lang/*.php") as $f) {
    $code = basename($f, '.php'); if ($code === 'en') continue;
    $d = require $f;
    foreach (array_diff_key($en, $d) as $k => $_) { echo "[$code] missing key: $k\n"; $fail++; }
    foreach (array_diff_key($d, $en) as $k => $_) { echo "[$code] extra key: $k\n"; $fail++; }
    foreach ($en as $k => $v) {           // placeholders must match
        preg_match_all('/\{(\w+)\}/', $v, $a); preg_match_all('/\{(\w+)\}/', (string) ($d[$k] ?? ''), $b);
        if (isset($d[$k]) && array_diff($a[1], $b[1])) { echo "[$code] placeholder mismatch: $k\n"; $fail++; }
    }
}
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (!preg_match('#/(src|public|bin)/.*\.(php|js)$#', $p) || str_contains($p, 'demo-site')) continue;
    $s = file_get_contents($p);
    preg_match_all('/(?<![\w$])(?:I18n::t|self::t|Manager::t|Engine::t|\$T|[^\w]t|T)\(\s*[\'"]([a-z][a-z0-9_.\-]*)[\'"]/i', $s, $m);
    foreach (array_unique($m[1]) as $k) if (!isset($en[$k]) && str_contains($k, '.') && !str_ends_with($k, '.')) { echo "missing in en.php: $k   ($p)\n"; $fail++; }
}
echo $fail ? "\n$fail problem(s)\n" : "OK — language files are consistent.\n";
exit($fail ? 1 : 0);
