<?php
declare(strict_types=1);

namespace Chronos\Application\Workflows;

use Chronos\Application\Extensions\Extensions;
use Chronos\Http\HttpError;
use Chronos\Application\Systems\ConfigurationStore;

/** Versioned storage adapter; reads legacy experiments without migrating their data. */
final class ExperimentDefinition
{
    private const FORMAT = 'chronos-experiment';

    public function __construct(private readonly string $root)
    {
    }

    public function decode(array $experiment): array
    {
        try { $data = json_decode($experiment['postData'], true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new HttpError(409, 'invalid_experiment', 'The saved experiment parameters cannot be read.'); }
        if (!is_array($data)) throw new HttpError(409, 'invalid_experiment', 'The saved experiment parameters cannot be read.');
        // A legacy experiment may contain legal scalar parameters named format/version.
        // Its explicit descriptor list always wins over an envelope-like parameter value.
        if (isset($data['elements']) && is_array($data['elements']) && array_is_list($data['elements'])) {
            return ['format' => 'legacy', 'legacy' => $data];
        }
        $marker = $data['format'] ?? null;
        if (is_array($marker) && ($marker['name'] ?? null) === self::FORMAT && ($marker['version'] ?? null) === 1) {
            if (!is_array($data['groups'] ?? null) || !array_is_list($data['groups'])
                || !is_array($data['values'] ?? null) || !is_array($data['execution'] ?? null)) {
                throw new HttpError(409, 'invalid_experiment', 'The saved experiment definition is incomplete.');
            }
            $data['format'] = self::FORMAT;
            return $data;
        }
        return ['format' => 'legacy', 'legacy' => $data];
    }

    public static function document(array $groups, array $values, int $runs, string $distribution, bool $warmUp): array
    {
        return ['format' => ['name' => self::FORMAT, 'version' => 1], 'groups' => $groups, 'values' => (object) $values,
            'execution' => ['runs' => $runs, 'distribution' => $distribution, 'warmUp' => $warmUp]];
    }

    public function groups(array $experiment): array
    {
        $definition = $this->decode($experiment);
        if ($definition['format'] === self::FORMAT) return $definition['groups'];
        $legacy = $definition['legacy'];
        return isset($legacy['_v3Groups']) && is_array($legacy['_v3Groups'])
            ? $legacy['_v3Groups'] : ConfigurationStore::parameterGroups($this->root, (int) $experiment['systemId']);
    }

    public function values(array $experiment): array
    {
        $definition = $this->decode($experiment);
        if ($definition['format'] === self::FORMAT) return $definition['values'];
        $legacy = $definition['legacy'];
        return isset($legacy['_v3Values']) && is_array($legacy['_v3Values']) ? $legacy['_v3Values'] : $legacy;
    }

    public function execution(array $experiment): array
    {
        $definition = $this->decode($experiment);
        if ($definition['format'] === self::FORMAT) return $definition['execution'];
        $legacy = $definition['legacy'];
        return ['runs' => (int) ($legacy['runs'] ?? 1), 'distribution' => $legacy['run-distribution'] ?? 'alter',
            'warmUp' => !((int) $experiment['phases'] & 2)];
    }

    public function elements(array $experiment): array
    {
        $definition = $this->decode($experiment);
        return $definition['format'] === self::FORMAT
            ? ParameterCompiler::elements($definition['groups'], $definition['values'], $definition['execution'])
            : ParameterCompiler::legacyElements($definition['legacy']);
    }

    public function compile(array $experiment, int $limit, Extensions $extensions, array $versions = []): array
    {
        $definition = $this->decode($experiment);
        $modern = $definition['format'] === self::FORMAT;
        $values = $modern ? $definition['values'] : $definition['legacy'];
        foreach ($versions as $parameter => $entries) {
            $values[$parameter . '-numberOfVersions'] = count($entries);
            foreach ($entries as $index => $version) $values[$parameter . '-version-' . $index] = $version;
        }
        return $modern ? ParameterCompiler::compile($this->root, $definition['groups'], $values, $definition['execution'], $limit, $extensions)
            : ParameterCompiler::configurations($this->root, $values, $limit, $extensions);
    }
}
