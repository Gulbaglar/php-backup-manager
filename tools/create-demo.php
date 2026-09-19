<?php
declare(strict_types=1);
/**
 * Creates a small demo site (../demo-site) so you can try the Backup Manager end to end:
 *     php tools/create-demo.php
 * It contains a SQLite database with related tables, some "uploaded" files, a config file, and a .env with a fake secret.
 */
$root = dirname(__DIR__) . '/demo-site';
foreach (['public/uploads', 'data', 'config'] as $d) if (!is_dir("$root/$d")) mkdir("$root/$d", 0775, true);

file_put_contents("$root/public/index.php", <<<'PHP'
<?php
$db = new PDO('sqlite:' . dirname(__DIR__) . '/data/app.sqlite');
$title = $db->query("SELECT value FROM settings WHERE key='site_title'")->fetchColumn();
echo '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars((string) $title) . '</title><h1>' . htmlspecialchars((string) $title) . '</h1>';
foreach ($db->query('SELECT title, image FROM posts') as $p) echo '<p>' . htmlspecialchars($p['title']) . ' <img src="uploads/' . htmlspecialchars($p['image']) . '" width="24"></p>';
PHP);
file_put_contents("$root/config/app.php", "<?php\nreturn ['debug' => false];\n");
file_put_contents("$root/.env", "APP_SECRET=demo-secret-value-123\nMAIL_PASSWORD=hunter2\n");
file_put_contents("$root/data/.htaccess", "Require all denied\n");

// 1x1 PNG
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');
foreach (['a.png', 'b.png', 'c.png'] as $f) file_put_contents("$root/public/uploads/$f", $png . random_bytes(2000));
file_put_contents("$root/public/uploads/manual.pdf", "%PDF-1.4 demo document\n");

@unlink("$root/data/app.sqlite");
$db = new PDO('sqlite:' . "$root/data/app.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT)");
$db->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)");
$db->exec("CREATE TABLE pages (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE, body TEXT)");
$db->exec("CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id), title TEXT, image TEXT)");
$db->exec("INSERT INTO users(name,email) VALUES ('Alice','alice@example.com'),('Bob','bob@example.com')");
$db->exec("INSERT INTO settings VALUES ('site_title','Demo Site'),('smtp_password','s3cr3t-smtp-password'),('api_token','tok_live_123456')");
$db->exec("INSERT INTO pages(slug,body) VALUES ('about','About us — with ''quotes''; and semicolons;\nand a second line'),('contact','Contact')");
$db->exec("INSERT INTO posts(user_id,title,image) VALUES (1,'First post','a.png'),(1,'Second post','b.png'),(2,'Hello','c.png')");

echo "Demo site created: $root\n";
echo "Next:  php -S localhost:8080 -t public   (from the module folder) and open http://localhost:8080/\n";
