<?php
declare(strict_types=1);

namespace Chronos\Application\Results;

use Chronos\Http\HttpError;
use Chronos\Infrastructure\Downloads\ZipDownload;

final class PreparedDownload
{
    public function __construct(public readonly string $contentType, public readonly ?string $filename, public readonly \Closure $stream) {}

    public static function zip(string $filename, array $files): self
    {
        try {
            $stream = ZipDownload::prepare($filename, $files);
        } catch (\Throwable) {
            throw new HttpError(503, 'archive_unavailable', 'The download archive could not be prepared. Try again when its source files and temporary storage are available.');
        }
        return new self('application/zip', $filename, $stream);
    }
}
