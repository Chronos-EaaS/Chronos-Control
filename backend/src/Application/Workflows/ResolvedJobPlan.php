<?php
declare(strict_types=1);

namespace Chronos\Application\Workflows;

use Chronos\Application\Agents\JobCdlAssembler;
use Chronos\Application\Artifacts\CemArtifactSetup;
use Chronos\Application\Benchmarks\{AdapterBinding, BenchmarkCatalog, BenchmarkContract};
use Chronos\Application\Shared\{Context, Dto, Input};
use Chronos\Domain\Workflows\{Environment, JobPhases};
use Chronos\Infrastructure\Persistence\DatabaseJson;

/** The only path from a scheduled repetition to its persisted execution inputs. */
final class ResolvedJobPlan
{
    private readonly array $general;
    private readonly array $setup;
    private readonly string $identifier;

    public function __construct(
        Context $context,
        private readonly int $systemId,
        private readonly string $environment,
        private readonly int $phases,
        private readonly ?array $binding,
    ) {
        [, $cem] = Environment::parse($environment);
        $rows = $context->db->rows("SELECT section,item,value FROM `Setting` WHERE systemId=? AND section IN ('general','setup') ORDER BY settingId", [$systemId]);
        $settings = [];
        foreach ($rows as $row) $settings[$row['section']][$row['item']] = $row['value'];
        $this->setup = $cem ? ($settings['setup'] ?? []) : [];
        $this->general = $settings['general'] ?? [];
        $this->identifier = $binding['definition']['systemIdentifier'] ?? BenchmarkCatalog::systemIdentifier($context->root, $systemId);
        try {
            $configured = CemArtifactSetup::configuredManifest($this->setup);
        } catch (\RuntimeException $error) {
            Input::invalid('artifacts', $error->getMessage());
        }
        $artifactManifest = $binding['definition']['artifacts'] ?? null;
        if ($artifactManifest !== null && $configured !== null) {
            // The binding declares every artifact that must be verified for this measurement.
            $bound = array_column($artifactManifest['artifacts'], null, 'role');
            foreach ($configured->artifacts as $required) {
                $entry = $bound[$required['role']] ?? null;
                if ($entry === null || $entry['sha256'] !== $required['sha256'] || $entry['sizeBytes'] !== $required['sizeBytes']) {
                    Input::invalid('artifacts', 'Include each configured CEM setup artifact in the binding with its expected digest and size.');
                }
            }
        }
        if ($binding !== null) {
            $policy = $binding['benchmark']['contract']['lifecycle']['warmUp'];
            $warmUp = !($phases & JobPhases::EXCLUDE_WARM_UP);
            if (($policy === 'required' && !$warmUp) || ($policy === 'disabled' && $warmUp)) {
                Input::invalid('warmUp', 'Match the warm-up policy of the benchmark version.');
            }
        }
    }

    public function resolve(array $parameters, bool $generated): array
    {
        $binding = $this->binding;
        $effective = $parameters;
        if ($binding !== null) {
            $run = $generated ? array_intersect_key($parameters, ['run' => true]) : [];
            $parameters = BenchmarkContract::parameters($binding['benchmark']['contract']['parameters'], array_diff_key($parameters, $run), $generated);
            $effective = AdapterBinding::parameters($binding['definition'], $parameters) + $run;
            $parameters += $run;
        }
        $version = $binding['definition']['systemVersion'] ?? null;
        $adapter = $binding['definition']['adapter'] ?? AdapterBinding::CDL;
        $artifactManifest = $binding['definition']['artifacts'] ?? null;
        $evaluation = array_replace($this->general, $effective);
        $labels = [];
        foreach ($parameters as $key => $value) $labels[] = $key . ': ' . (is_scalar($value) ? (string) $value : json_encode($value));
        $title = 'Job[' . implode(', ', $labels) . ']';
        $configuration = ['parameters' => $parameters, 'title' => $title];
        if ($binding !== null) $configuration['executionProtocol'] = ExecutionAttempts::PROTOCOL;
        $spec = [
            'schemaVersion' => 1,
            'protocol' => $binding === null ? 'legacy' : ExecutionAttempts::PROTOCOL,
            'system' => ['id' => $this->systemId, 'identifier' => $this->identifier, 'version' => $version],
            'benchmark' => $binding['benchmark'] ?? null,
            'binding' => $binding === null ? null : ['id' => $binding['id'], 'version' => $binding['version']],
            'adapter' => $adapter,
            'artifacts' => $artifactManifest,
            'environment' => $this->environment,
            'phases' => $this->phases,
            'parameters' => (object) $parameters,
            'effectiveParameters' => (object) $evaluation,
            'setup' => (object) $this->setup,
        ];
        $cdlValues = $binding === null ? $effective
            : array_map(static fn(mixed $value): mixed => is_bool($value) ? ($value ? 'true' : 'false') : $value, $effective);
        $spec['cdl'] = JobCdlAssembler::build($this->systemId, $cdlValues, $this->general, $this->setup);
        $spec['sha256'] = hash('sha256', DatabaseJson::encode($spec, 'jobs', 1048576));
        $identity = $parameters;
        unset($identity['run']);
        return [
            'description' => Dto::storedText($title, 'parameters', 65535),
            'configuration' => DatabaseJson::encode($configuration, 'parameters'),
            'configurationIdentifier' => sha1(json_encode($identity, JSON_THROW_ON_ERROR)),
            'specification' => DatabaseJson::encode($spec, 'jobs', 1048576),
            'adapter' => $binding === null ? null : $adapter,
            'systemIdentifier' => $this->identifier,
        ];
    }
}
