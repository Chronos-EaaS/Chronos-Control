<?php
declare(strict_types=1);

namespace Chronos\Infrastructure\Downloads;

/** Prepare the complete archive before the HTTP layer starts its response. */
final class ZipDownload
{
    public static function prepare(string $filename, array $files): \Closure
    {
        $zip = new \Zip_Library($filename);
        foreach ($files as $name => $path) $zip->addFilePath($path, $name);
        $zip->prepare();
        return static function () use ($zip): void { $zip->done(); };
    }
}
