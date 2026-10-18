<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Extensions\Extensions;
use Chronos\Http\HttpError;
use Chronos\Application\Workflows\ParameterCompiler;
use Chronos\Application\Workflows\ExperimentDefinition;
use PHPUnit\Framework\TestCase;

final class V3WorkflowDefinitionTest extends TestCase
{
    private string $root;
    private ExperimentDefinition $definition;
    private Extensions $extensions;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/chronos-workflow-definition-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/webroot/systems/1', 0700, true);
        file_put_contents($this->root . '/webroot/systems/1/parameters.json', json_encode($this->schema(), JSON_THROW_ON_ERROR));
        $this->definition = new ExperimentDefinition($this->root);
        $this->extensions = new Extensions($this->root, 1, []);
    }

    protected function tearDown(): void
    {
        unlink($this->root . '/webroot/systems/1/parameters.json');
        rmdir($this->root . '/webroot/systems/1'); rmdir($this->root . '/webroot/systems'); rmdir($this->root . '/webroot'); rmdir($this->root);
    }

    private function schema(): array
    {
        return [['id' => 'base', 'depends' => '', 'elements' => [
            ['id' => 'count', 'type' => 'integer', 'parameter' => 'quantity', 'default' => 0],
            ['id' => 'flag', 'type' => 'boolean', 'parameter' => 'enabled', 'default' => false],
            ['id' => 'versions', 'type' => 'software-versions-list', 'parameter' => 'version'],
            ['id' => 'hidden', 'type' => 'string', 'parameter' => 'label', 'default' => 'Zürich'],
        ]]];
    }

    private function row(array $data): array
    {
        return ['systemId' => 1, 'phases' => 2, 'postData' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
    }

    public function testVersionedDocumentSeparatesMetadataAndRetainsZeroFalseAndUnicode(): void
    {
        $values = ParameterCompiler::values($this->schema(), ['version-version-0' => 'main']);
        $document = ExperimentDefinition::document($this->schema(), $values, 2, 'alter', false);
        $row = $this->row($document);
        self::assertSame('chronos-experiment', $this->definition->decode($row)['format']);
        self::assertSame($this->schema(), $this->definition->groups($row));
        self::assertSame($values, $this->definition->values($row));
        self::assertSame(['runs' => 2, 'distribution' => 'alter', 'warmUp' => false], $this->definition->execution($row));
        $jobs = $this->definition->compile($row, 10, $this->extensions);
        self::assertCount(2, $jobs);
        self::assertSame(['version' => 'main', 'quantity' => 0, 'enabled' => 'false', 'label' => 'Zürich', 'run' => 0], $jobs[0]['parameters']);
        self::assertSame($this->row($document), $row);
    }

    public function testLegacyV2AndV3DefinitionsRetainGeneratedJobShapeWithoutWrites(): void
    {
        $values = ParameterCompiler::values($this->schema(), ['version-numberOfVersions' => 2, 'version-version-0' => 'main', 'version-version-1' => 'release']);
        $legacy = ParameterCompiler::legacy($this->schema(), $values) + ['runs' => 2, 'run-distribution' => 'order'];
        $v2 = $legacy; unset($v2['_v3Elements']);
        $v3 = $legacy + ['_v3Groups' => $this->schema(), '_v3Values' => $values];
        $modern = ExperimentDefinition::document($this->schema(), $values, 2, 'order', false);
        $expected = $this->definition->compile($this->row($modern), 10, $this->extensions);
        foreach ([$v2, $v3] as $data) {
            $row = $this->row($data); $before = $row['postData'];
            self::assertSame($expected, $this->definition->compile($row, 10, $this->extensions));
            self::assertSame($this->schema(), $this->definition->groups($row));
            self::assertSame(['runs' => 2, 'distribution' => 'order', 'warmUp' => false], $this->definition->execution($row));
            self::assertSame($before, $row['postData']);
        }
    }

    public function testLegacySavedDescriptorsSurviveLaterSchemaChanges(): void
    {
        $values = ParameterCompiler::values($this->schema(), ['version-version-0' => 'main']);
        $legacy = ParameterCompiler::legacy($this->schema(), $values); unset($legacy['_v3Elements']);
        $row = $this->row($legacy);
        file_put_contents($this->root . '/webroot/systems/1/parameters.json', '[]');
        self::assertSame([], $this->definition->groups($row));
        $jobs = $this->definition->compile($row, 10, $this->extensions);
        self::assertSame(['version' => 'main', 'quantity' => 0, 'enabled' => 'false', 'label' => 'Zürich', 'run' => 0], $jobs[0]['parameters']);
    }

    public function testVersionSelectionOverridesOnlyTheRunInAllSupportedFormats(): void
    {
        $values = ParameterCompiler::values($this->schema(), ['version-version-0' => 'main']);
        $legacy = ParameterCompiler::legacy($this->schema(), $values);
        $v2 = $legacy; unset($v2['_v3Elements']);
        $v3 = $legacy + ['_v3Groups' => $this->schema(), '_v3Values' => $values];
        foreach ([$v2, $v3, ExperimentDefinition::document($this->schema(), $values, 1, 'alter', true)] as $data) {
            $row = $this->row($data); $before = $row['postData'];
            $jobs = $this->definition->compile($row, 10, $this->extensions, ['version' => ['release', 'next']]);
            self::assertSame(['release', 'next'], array_column(array_column($jobs, 'parameters'), 'version'));
            self::assertSame($before, $row['postData']);
        }
    }

    public function testMalformedVersionedStorageFailsExplicitly(): void
    {
        $this->expectException(HttpError::class);
        $this->definition->decode($this->row(['format' => ['name' => 'chronos-experiment', 'version' => 1], 'groups' => [], 'values' => 'broken', 'execution' => []]));
    }

    public function testUnknownStoredDocumentVersionCannotSilentlyScheduleAnEmptyJob(): void
    {
        $row = $this->row(['format' => ['name' => 'chronos-experiment', 'version' => 2],
            'groups' => $this->schema(), 'values' => ['quantity' => 7],
            'execution' => ['runs' => 1, 'distribution' => 'alter', 'warmUp' => true]]);
        try {
            $this->definition->compile($row, 10, $this->extensions);
            self::fail('An unsupported definition version must not create jobs.');
        } catch (HttpError $error) {
            self::assertSame(409, $error->status);
            self::assertSame('invalid_experiment', $error->errorCode);
        }
    }

    public function testLegalLegacyFormatAndVersionParametersCannotBecomeAnEnvelope(): void
    {
        $groups = [['id' => 'base', 'elements' => [
            ['id' => 'name-field', 'type' => 'string', 'parameter' => 'format', 'default' => 'chronos-experiment'],
            ['id' => 'version-field', 'type' => 'integer', 'parameter' => 'version', 'default' => 1],
        ]]];
        $values = ParameterCompiler::values($groups, []);
        $legacy = ParameterCompiler::legacy($groups, $values);
        unset($legacy['_v3Elements']);
        $row = $this->row($legacy);
        self::assertSame('legacy', $this->definition->decode($row)['format']);
        self::assertSame(['format' => 'chronos-experiment', 'version' => 1, 'run' => 0], $this->definition->compile($row, 10, $this->extensions)[0]['parameters']);
        self::assertSame($this->row($legacy), $row);
    }

    public function testLegacyV2ParameterNamedLikeDescriptorMetadataRemainsRunnable(): void
    {
        $legacy = ['elements' => ['metadata-input'], 'metadata-input-type' => 'string',
            'metadata-input-parameter' => '_v3Elements', '_v3Elements' => 'a real saved value',
            'runs' => 1, 'run-distribution' => 'alter'];
        $row = $this->row($legacy);
        self::assertSame(['_v3Elements' => 'a real saved value', 'run' => 0],
            $this->definition->compile($row, 10, $this->extensions)[0]['parameters']);
        self::assertSame($this->row($legacy), $row);
    }

}
