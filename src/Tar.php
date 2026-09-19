<?php
declare(strict_types=1);

namespace BackupManager;

use RuntimeException;

/** Streaming ustar writer (gzip). Big files are read/written in 1 MB chunks. */
final class TarWriter
{
    private $gz;
    public function __construct(private string $path, int $level = 3)
    {
        $this->gz = @gzopen($path, 'wb' . $level);
        if (!$this->gz) throw new RuntimeException(I18n::t('err.archive_create', ['p' => $path]));
    }
    private function w(string $d): void { if ($d !== '' && @gzwrite($this->gz, $d) === false) throw new RuntimeException(I18n::t('err.write_failed')); }

    private function header(string $name, int $size, int $mtime): string
    {
        $prefix = '';
        if (strlen($name) > 100) {
            $cut = false;
            for ($i = min(155, strlen($name) - 1); $i > 0; $i--) if ($name[$i] === '/' && strlen($name) - $i - 1 <= 100) { $cut = $i; break; }
            if ($cut === false) throw new RuntimeException(I18n::t('err.path_too_long', ['p' => $name]));
            $prefix = substr($name, 0, $cut); $name = substr($name, $cut + 1);
        }
        $sizeF = $size < 8589934592 ? sprintf('%011o', $size) . "\0" : "\x80\0\0\0" . pack('J', $size);   // base-256 above 8 GiB
        $h = str_pad($name, 100, "\0") . '0000644' . "\0" . '0000000' . "\0" . '0000000' . "\0" . $sizeF
           . sprintf('%011o', $mtime) . "\0" . '        ' . '0' . str_repeat("\0", 100)
           . "ustar\0" . '00' . str_repeat("\0", 64) . '0000000' . "\0" . '0000000' . "\0" . str_pad($prefix, 155, "\0") . str_repeat("\0", 12);
        $sum = array_sum(array_map('ord', str_split($h)));
        return substr($h, 0, 148) . sprintf('%06o', $sum) . "\0 " . substr($h, 156);
    }

    public function addString(string $name, string $data): string
    {
        $this->w($this->header($name, strlen($data), time()));
        $this->w($data);
        $this->w(str_repeat("\0", (512 - strlen($data) % 512) % 512));
        return hash('sha256', $data);
    }

    /** @return array{sha256:string,size:int,warn:?string} */
    public function addFile(string $name, string $path, ?int $size = null): array
    {
        $size = $size ?? (int) filesize($path);
        $fh = @fopen($path, 'rb');
        if (!$fh) throw new RuntimeException(I18n::t('err.file_unreadable', ['p' => $path]));
        $this->w($this->header($name, $size, (int) @filemtime($path)));
        $h = hash_init('sha256'); $left = $size; $warn = null;
        while ($left > 0) {
            $c = fread($fh, min(1048576, $left));
            if ($c === false || $c === '') { $c = str_repeat("\0", min(1048576, $left)); $warn = I18n::t('warn.file_shrank', ['p' => $name]); }
            hash_update($h, $c); $this->w($c); $left -= strlen($c);
        }
        fclose($fh);
        $this->w(str_repeat("\0", (512 - $size % 512) % 512));
        return ['sha256' => hash_final($h), 'size' => $size, 'warn' => $warn];
    }

    public function close(): void { $this->w(str_repeat("\0", 1024)); gzclose($this->gz); $this->gz = null; }
    public function abort(): void { if ($this->gz) { @gzclose($this->gz); $this->gz = null; } }
}

/** Streaming ustar reader (gzip). */
final class TarReader
{
    private $gz;
    private int $remain = 0;
    private int $pad = 0;
    public function __construct(string $path)
    {
        $this->gz = @gzopen($path, 'rb');
        if (!$this->gz) throw new RuntimeException(I18n::t('err.archive_open'));
    }
    public function close(): void { if ($this->gz) { @gzclose($this->gz); $this->gz = null; } }

    private function exact(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $c = @gzread($this->gz, $n - strlen($buf));
            if ($c === false || $c === '') { if (@gzeof($this->gz) || $c === false) throw new RuntimeException(I18n::t('err.archive_truncated')); continue; }
            $buf .= $c;
        }
        return $buf;
    }

    /** @return ?array{name:string,size:int,mtime:int} */
    public function next(): ?array
    {
        $this->skip();
        $h = $this->exact(512);
        if (trim($h, "\0") === '') return null;
        $chk = (int) octdec(trim(substr($h, 148, 8), "\0 "));
        $sum = array_sum(array_map('ord', str_split(substr($h, 0, 148)))) + 256 + array_sum(array_map('ord', str_split(substr($h, 156))));
        if ($chk !== $sum) throw new RuntimeException(I18n::t('err.header_checksum'));
        $name = rtrim(substr($h, 0, 100), "\0");
        $prefix = rtrim(substr($h, 345, 155), "\0");
        if ($prefix !== '') $name = $prefix . '/' . $name;
        $sf = substr($h, 124, 12);
        $size = (ord($sf[0]) & 0x80) !== 0 ? (int) unpack('J', substr($sf, 4, 8))[1] : (int) octdec(trim($sf, "\0 "));
        $this->remain = $size; $this->pad = (512 - $size % 512) % 512;
        return ['name' => $name, 'size' => $size, 'mtime' => (int) octdec(trim(substr($h, 136, 12), "\0 "))];
    }

    /** Next chunk of the current entry; null when the entry is finished. */
    public function read(int $max = 1048576): ?string
    {
        if ($this->remain <= 0) return null;
        $c = $this->exact(min($max, $this->remain));
        $this->remain -= strlen($c);
        return $c;
    }
    public function readAll(int $limit): string
    {
        $o = '';
        while (($c = $this->read()) !== null) { $o .= $c; if (strlen($o) > $limit) throw new RuntimeException(I18n::t('err.entry_too_large')); }
        return $o;
    }
    public function skip(): void
    {
        while ($this->remain > 0) { $this->read(); }
        if ($this->pad > 0) { $this->exact($this->pad); $this->pad = 0; }
    }
}
