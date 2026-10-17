<?php
declare(strict_types=1);

/** Create verified download archives on private disk and stream them in bounded memory. */
class Zip_Library
{
    private ?ZipArchive $zip = null;
    private ?string $path = null;
    private array $entries = [];
    private bool $prepared = false;

    public function __construct(private readonly string $name, private readonly bool $sendHeaders = true)
    {
        if ($name === '' || strlen($name) > 200 || preg_match('/[\x00-\x1f\x7f\\\\\/]/', $name) || preg_match('//u', $name) !== 1) {
            throw new InvalidArgumentException('Choose a valid download filename.');
        }
        if (!class_exists(ZipArchive::class, false)) throw new RuntimeException('The PHP ZIP extension is required to create archives.');
        $path = tempnam(sys_get_temp_dir(), 'chronos-zip-');
        if ($path === false) throw new RuntimeException('The download archive cannot be staged.');
        $this->path = $path;
        try {
            if (!chmod($path, 0600)) throw new RuntimeException('The download archive cannot be protected.');
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('The download archive cannot be opened.');
            $this->zip = $zip;
        } catch (Throwable $error) { $this->remove(); throw $error; }
    }

    /** Preserve the legacy string API for small generated configuration documents. */
    public function addFile(string $data, string $name): void
    {
        $zip = $this->active(); $this->entry($name);
        if (!$zip->addFromString($name, $data, ZipArchive::FL_ENC_UTF_8)) throw new RuntimeException('A download entry cannot be created.');
        $this->entries[$name] = true;
    }

    /** libzip reads the file itself; no result archive is loaded into a PHP string. */
    public function addFilePath(string $source, string $name): void
    {
        $zip = $this->active(); $this->entry($name);
        if (!is_file($source) || is_link($source) || !is_readable($source)) throw new RuntimeException('A download source file is unavailable.');
        if (!$zip->addFile($source, $name, 0, 0, ZipArchive::FL_ENC_UTF_8) || !$zip->setCompressionName($name, ZipArchive::CM_STORE)) {
            throw new RuntimeException('A download source file cannot be added.');
        }
        $this->entries[$name] = true;
    }

    /** Finish all disk operations before the caller commits a successful HTTP response. */
    public function prepare(): void
    {
        if ($this->path === null) throw new LogicException('The download archive is already closed.');
        if ($this->prepared) return;
        $zip = $this->active();
        $this->zip = null;
        try {
            if (!@$zip->close()) throw new RuntimeException('The download archive could not be completed.');
            // libzip removes empty archives by default. Keep a valid empty ZIP,
            // including on PHP builds with older libzip and no archive flag API.
            if ($this->entries === [] && file_put_contents($this->path, "PK\x05\x06" . str_repeat("\0", 18)) !== 22) throw new RuntimeException('The empty download archive cannot be created.');
            if (!is_file($this->path) || is_link($this->path) || !chmod($this->path, 0600)) throw new RuntimeException('The download archive cannot be protected.');
            $this->prepared = true;
        } catch (Throwable $error) { $this->remove(); throw $error; }
    }

    /** An optional caller-owned stream supports CLI exports without HTTP headers. */
    public function done($output = null): void
    {
        if ($output !== null && (!is_resource($output) || get_resource_type($output) !== 'stream' || !preg_match('/[waxc+]/', stream_get_meta_data($output)['mode']))) throw new InvalidArgumentException('Choose a writable output stream.');
        $this->prepare();
        $source = null; $ownedOutput = false;
        try {
            $source = fopen($this->path, 'rb');
            if ($source === false) throw new RuntimeException('The download archive cannot be read.');
            if ($output === null) { $output = fopen('php://output', 'wb'); $ownedOutput = true; }
            if ($output === false) throw new RuntimeException('The download output cannot be opened.');
            if ($this->sendHeaders && PHP_SAPI !== 'cli') {
                if (headers_sent()) throw new RuntimeException('Download headers have already been sent.');
                $fallback = preg_replace('/[^A-Za-z0-9._ -]/', '_', $this->name);
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($this->name));
                header('Content-Length: ' . fstat($source)['size']);
                header('Cache-Control: no-store');
                header('X-Content-Type-Options: nosniff');
            }
            if (stream_copy_to_stream($source, $output) !== fstat($source)['size']) throw new RuntimeException('The download archive could not be written completely.');
        } finally {
            if (is_resource($source)) fclose($source);
            if ($ownedOutput && is_resource($output)) fclose($output);
            $this->remove();
        }
    }

    private function active(): ZipArchive
    {
        if ($this->zip === null) throw new LogicException('The download archive is already closed.');
        return $this->zip;
    }

    private function entry(string $name): void
    {
        if ($name === '' || strlen($name) > 1000 || str_starts_with($name, '/') || preg_match('/[\x00-\x1f\x7f\\\\:]/', $name) || preg_match('//u', $name) !== 1) throw new InvalidArgumentException('Choose a safe relative archive entry name.');
        foreach (explode('/', $name) as $part) if ($part === '' || $part === '.' || $part === '..') throw new InvalidArgumentException('Choose a safe relative archive entry name.');
        if (isset($this->entries[$name])) throw new InvalidArgumentException('Archive entry names must be unique.');
    }

    private function remove(): void
    {
        if ($this->path !== null) { @unlink($this->path); $this->path = null; }
    }

    public function __destruct()
    {
        if ($this->zip !== null) { try { @$this->zip->close(); } catch (Throwable) {} $this->zip = null; }
        $this->remove();
    }
}
