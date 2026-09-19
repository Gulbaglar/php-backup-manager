# PHP Yedekleme Yöneticisi (Backup Manager)

**PHP + SQLite siteleri için tam / hızlı yedek, doğrulama, geri yükleme ve felaket kurtarma — Türkçe ve İngilizce yönetim arayüzüyle.**

🇬🇧 English: [README.md](README.md)

> Bir yedek, **doğrulanıp** **geri yüklenebildiği** kanıtlanana kadar başarılı sayılmaz.

Framework gerektirmeyen, bağımsız bir modül. Herhangi bir PHP sitesinin (dosyalar + isteğe bağlı SQLite veritabanı) yanına koyun, config'i projenize yönlendirin: yedek oluşturma, doğrulama, indirme ve geri yükleme için web arayüzü ile, elinizde yalnızca yedek dosyası olsa bile **boş bir sunucuda** siteyi yeniden kurabilen bağımsız bir script elde edersiniz.

## Özellikler

- **Tam Yedek** — veritabanı (tutarlı SQL snapshot), site dosyaları, yüklenen medya (görsel / video / belge / diğer), yapılandırma.
- **Hızlı Yedek** — yalnızca veritabanı; saniyeler; büyük bir değişiklik veya yayından önce ideal.
- **Bütünlük** — her dosya için SHA-256 + manifest + her yedekten sonra SQL dökümünün *gerçekten yüklenebildiğinin testi*. Bozuk yedek ⇒ `BACKUP CORRUPTED — DO NOT RESTORE`.
- **Her yerde akış (stream)** — `.tar.gz` 1 MB'lık parçalarla yazılıp okunur (çok GB'lık videolar RAM'e yüklenmez), Range destekli indirme, geri yükleme için parçalı yükleme (`upload_max_filesize` sınırı yok).
- **Güvenli geri yükleme** — doğrula → geçici klasöre çıkar → dökümü test et → zorunlu *Restore Öncesi Güvenlik Yedeği* → uygula → sağlık kontrolü → kontrol başarısızsa **otomatik geri alma**. Veritabanı **tek transaction** içinde değiştirilir (hata olursa hiçbir şey değişmez).
- **Geri yükleme modları** — Tam · Yalnızca Veritabanı · Yalnızca Medya · Yalnızca Kod/Site Dosyaları · Yalnızca Yönetici İçeriği (sizin seçtiğiniz tablolar).
- **Gizli değerler asla düz metin saklanmaz** — eşleşen veritabanı değerleri (ör. `smtp_password`) ve `.env` dosyaları AES-256-GCM ile şifrelenir; anahtar dosyasını ayrıca indirirsiniz.
- **Felaket kurtarma** — her arşivin içinde `restore/bin/restore-backup.php` bulunur. Boş sunucuda: `tar` ile açın, tek komut çalıştırın.
- **Otomatik yedek** — günlük / 3 günde bir / haftalık / aylık (Tam ve Hızlı ayrı), tür başına saklama; **Korumalı** yedekler asla silinmez.
- **Özel depolama** — yedekler web kökünün dışında durur; indirme yalnızca yetkili uç noktadan.
- **Canlı ilerlemeli arka plan işleri** — tarayıcıyı kapatmak yedeği/geri yüklemeyi durdurmaz.
- **İki arayüz dili (English / Türkçe)** — her sayfanın üstünden seçilir; varsayılan config'den ayarlanır.
- Her yedek / doğrulama / geri yükleme / indirme için **günlük**.

## Gereksinimler

`pdo_sqlite`, `zlib`, `openssl` (openssl yalnızca gizli değerleri şifrelemek için) eklentileriyle PHP 8.1+. Composer paketi yok, derleme adımı yok.

> Yalnızca **SQLite** desteklenir (ya da hiç veritabanı yok — sadece dosya yedeği). MySQL/PostgreSQL henüz desteklenmiyor.

## Hızlı başlangıç (2 dakika, demo siteyle)

```bash
git clone <bu repo> php-backup-manager && cd php-backup-manager
php tools/create-demo.php            # ./demo-site oluşturur (SQLite DB, yüklemeler, sahte gizli değerli .env)
php -S localhost:8080 -t public      # http://localhost:8080/ adresini açın
```

1. İlk ziyarette **şifre oluşturmanız** istenir (`storage/auth.json` içinde hash olarak saklanır).
2. **Tam Yedek Al**'a basın, sonra **Geri Yükle**'yi deneyin (önce `demo-site/` içinde bir şeyi bozun!).
3. Üstteki **English | Türkçe** ile dili değiştirin.

## Kendi projeniz için kullanın

`config/config.example.php` dosyasını `config/config.php` olarak kopyalayıp düzenleyin:

| Anahtar | Anlamı |
|---|---|
| `root` | korunacak projenin mutlak yolu |
| `storage` | yedeklerin durduğu yer — **web kökünün dışında olmalı** |
| `database` | `['type'=>'sqlite','path'=>'data/app.sqlite']` (`root`'a göre) veya `null` |
| `website.exclude` | "site dosyaları"na dahil olmayan klasörler/desenler |
| `uploads` | kullanıcı yüklemeleri: `takma ad => klasör` |
| `config_files` / `secret_files` | olduğu gibi yedeklenen yapılandırmalar / **yalnızca şifreli** yedeklenen `.env` benzeri dosyalar |
| `sensitive` | değerleri şifreli saklanacak DB satırları (tablo, anahtar sütunu, değer sütunu, regex) |
| `admin_tables` | *Yalnızca Yönetici İçeriği* modunda geri yüklenen tablolar (boşsa mod gizlenir) |
| `health` | gerekli dosyalar, boş olmaması gereken tablolar, istek atılacak URL'ler, DB'nin işaret ettiği dosyalar |
| `language` | varsayılan arayüz dili: `en` veya `tr` |
| `auth` | `builtin` (kendi şifresi) veya `callback` (uygulamanızın girişini kullan — bkz. `src/Auth.php`) |

Web'e **yalnızca `public/`** açın (document root veya alt klasör). `public/`'ı başka yere kopyalarsanız `public/_boot.php` içindeki tek `require` satırını düzenleyin.

İsteğe bağlı: geri yükleme sürerken ziyaretçilere kısa bir "bakım" sayfası göstermek için sitenizin giriş dosyasının en üstüne `require '/yol/php-backup-manager/src/maintenance-guard.php';` ekleyin.

## Felaket kurtarma (boş sunucu, yönetim arayüzü yok)

Yalnızca yedek dosyası yeterli (gizli değerler de dönsün istiyorsanız anahtar dosyası da):

```bash
mkdir tmp && tar -xzf backup-full-2026-01-31-08-45.tar.gz -C tmp
php tmp/restore/bin/restore-backup.php backup-full-2026-01-31-08-45.tar.gz \
    --target /var/www/site --key backup.key --lang tr
```

Script her dosyayı doğrular, veritabanı/dosya/yükleme/yapılandırmayı geri yükler, sağlık kontrolü yapar ve sonraki adımı söyler. Arşiv düz bir `.tar.gz`'dir — 7-Zip / Windows 11 ile de açabilirsiniz.

## Otomatik yedek

Arayüzden açın. Zamanı gelen yedek, sayfayı açtığınızda arka planda başlar; gözetimsiz sunucularda cron ekleyin:

```cron
0 3 * * * php /yol/php-backup-manager/bin/worker.php --auto
```

## Güvenlik notları

- Yedekler ve anahtar `storage/` içindedir (Apache'de `.htaccess` korur; Nginx'te web kökünün dışında tutun).
- Geri yükleme için şifreniz yeniden sorulur. Tüm uç noktalar admin oturumu + CSRF başlığı ister.
- Gizli değerler yalnızca anahtar dosyasıyla çözülebilir. **Anahtarı yedeklerden ayrı saklayın.**
- İlk kurulum admin şifresini oluşturur: kurduktan hemen sonra, herkese açmadan önce arayüzü açın.

## Diller

`lang/en.php` referans, `lang/tr.php` Türkçe çeviridir. Yeni dil eklemek için `en.php`'yi kopyalayıp çevirin, kodu `src/I18n.php` içindeki `I18n::LANGS`'e ekleyin. `php tools/check-i18n.php` dil dosyalarını denetler.

## Yapı

```
src/        Engine (yedek/doğrulama/geri yükleme), Manager (işler, geçmiş), Auth, Config, I18n, Tar
bin/        worker.php (arka plan/cron), restore-backup.php (felaket kurtarma)
public/     web arayüzü (index, api, download, login) — web'den erişilebilir olması gereken tek klasör
lang/       en.php, tr.php        config/  config.example.php        tools/  demo + dil denetleyici
```

## ☕ Projeyi Destekle

Bu proje ücretsiz ve açık kaynaklıdır.

Eğer işini kolaylaştırdıysa, projen için faydalı olduysa veya gelecekteki geliştirmeleri desteklemek istiyorsan bana bir kahve ısmarlayabilirsin. ❤️

[![Buy Me a Coffee](https://img.shields.io/badge/Buy%20Me%20a%20Coffee-Destek%20Ol-orange?style=for-the-badge&logo=buymeacoffee)](https://www.buymeacoffee.com/Gulbaglar)

Açık kaynak geliştirmeyi desteklediğin için teşekkür ederim!

## Geliştirici

**Kahraman Gülbağlar**  
[https://www.gulbaglar.com](https://www.gulbaglar.com)

## Lisans

MIT — bkz. [LICENSE](LICENSE).
