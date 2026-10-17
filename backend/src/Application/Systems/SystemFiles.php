<?php
declare(strict_types=1);

namespace Chronos\Application\Systems;

use Chronos\Application\Shared\Context;
use Chronos\Application\Extensions\Extensions;
use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;
use Chronos\Application\Results\{PreparedDownload, ResultConfigurations};
use Chronos\Infrastructure\VersionControl\RepositoryInspector;

final class SystemFiles
{
    private readonly ConfigurationStore $files;
    private readonly RepositoryInspector $inspector;

    public function __construct(private readonly Context $context, private readonly string $root)
    {
        $this->files = new ConfigurationStore($context, $root);
        $this->inspector = new RepositoryInspector($root);
    }

    public function logo(int $id): PreparedDownload
    {
        $this->context->system($id);
        $path = $this->inspector->directory($id) . '/logo.png';
        if (!is_file($path) || is_link($path) || filesize($path) > 2097152) throw new HttpError(404, 'logo_not_found', 'This system has no logo.');
        $mime = @getimagesize($path)['mime'] ?? 'image/png';
        return new PreparedDownload($mime, null, static function () use ($path): void { readfile($path); });
    }

    public function saveLogo(int $id, array $body): array
    {
        $this->context->system($id);
        Input::fields($body, ['dataUrl']);
        if (!is_string($body['dataUrl'] ?? null) || !preg_match('#^data:image/(png|jpeg|gif);base64,([A-Za-z0-9+/=]+)$#D', $body['dataUrl'], $match)) Input::invalid('dataUrl', 'Choose a PNG, JPEG or GIF image.');
        $bytes = base64_decode($match[2], true);
        $size = $bytes === false ? false : @getimagesizefromstring($bytes);
        if (!$size || strlen($bytes) > 524288 || $size[0] > 4096 || $size[1] > 4096 || !in_array($size['mime'], ['image/png', 'image/jpeg', 'image/gif'], true)) Input::invalid('dataUrl', 'Choose an image of at most 512 KiB and 4096 × 4096 pixels.');
        // Decode and encode to discard active/polyglot payloads and normalize logo.png.
        if (!function_exists('imagecreatefromstring')) throw new HttpError(503, 'image_support_unavailable', 'The PHP GD extension is required to safely update system logos.');
        $image = @imagecreatefromstring($bytes);
        if ($image === false) Input::invalid('dataUrl', 'The image cannot be decoded.');
        ob_start(); imagepng($image); $png = (string) ob_get_clean(); unset($image);
        $this->files->writeBinary($id, 'logo.png', $png);

        return ['logoUrl' => '/api/v3/systems/' . $id . '/logo'];
    }

    public function import(int $id, array $body): array
    {
        $this->context->system($id);
        Input::fields($body, ['filename', 'content']);
        if (is_string($body['content'] ?? null)) {
            try { $content = json_decode($body['content'], true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { Input::invalid('content', 'Import a valid JSON document.'); }
        } else $content = DefinitionValues::arrays($body['content'] ?? null);
        $filename = $body['filename'] ?? null;
        if ($filename === 'parameters.json') $content = ParameterSchema::validate($content, new Extensions($this->root, $id));
        elseif ($filename === 'resultConfigurations.json') {
            $content = ResultConfigurations::validate($content, new Extensions($this->root, $id));
            $old = ConfigurationStore::resultDocument($this->root, $id);
            foreach (array_diff(array_keys($old['elements']), array_keys($content['elements'])) as $removed) if ($this->context->db->row('SELECT experimentId FROM `Experiment` WHERE systemId=? AND resultId=? LIMIT 1', [$id, $removed])) throw new HttpError(409, 'configuration_in_use', 'An imported document cannot remove a configuration used by an experiment.');
        } else Input::invalid('filename', 'Import parameters.json or resultConfigurations.json.');
        $this->files->writeJson($id, $filename, $content, 'Imported ' . $filename);
        return ['imported' => $filename];
    }

    public function export(int $id): PreparedDownload
    {
        $this->context->system($id);
        $path = $this->inspector->directory($id);
        $files = [];
        foreach (['parameters.json', 'resultConfigurations.json', 'results.json', 'resultsJob.json'] as $name) if (is_file($path . '/' . $name) && !is_link($path . '/' . $name) && filesize($path . '/' . $name) <= ConfigurationStore::MAX_BYTES) $files[$name] = $path . '/' . $name;
        return PreparedDownload::zip('system' . $id . '.zip', $files);
    }
}
