<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Extensions\Extensions;
use Chronos\Http\HttpError;
use Chronos\Application\Workflows\ParameterCompiler;
use Chronos\Application\Workflows\ExperimentDefinition;
use Chronos\Tests\Support\V2ExperimentFixture;
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

    public function testOriginalV2AndCurrentDefinitionsRetainGeneratedJobShapeWithoutWrites(): void
    {
        $values = ParameterCompiler::values($this->schema(), ['version-numberOfVersions' => 2, 'version-version-0' => 'main', 'version-version-1' => 'release']);
        $v2 = V2ExperimentFixture::postData($this->schema()[0]['elements'], $values, ['runs' => 2, 'run-distribution' => 'order']);
        $modern = ExperimentDefinition::document($this->schema(), $values, 2, 'order', false);
        $expected = $this->definition->compile($this->row($modern), 10, $this->extensions);
        $row = $this->row($v2); $before = $row['postData'];
        self::assertSame($expected, $this->definition->compile($row, 10, $this->extensions));
        self::assertSame($this->schema(), $this->definition->groups($row));
        self::assertSame($v2, $this->definition->values($row));
        self::assertSame(['runs' => 2, 'distribution' => 'order', 'warmUp' => false], $this->definition->execution($row));
        self::assertSame($before, $row['postData']);
    }

    public function testLegacySavedDescriptorsSurviveLaterSchemaChanges(): void
    {
        $values = ParameterCompiler::values($this->schema(), ['version-version-0' => 'main']);
        $legacy = V2ExperimentFixture::postData($this->schema()[0]['elements'], $values);
        $row = $this->row($legacy);
        file_put_contents($this->root . '/webroot/systems/1/parameters.json', '[]');
        self::assertSame([], $this->definition->groups($row));
        $jobs = $this->definition->compile($row, 10, $this->extensions);
        self::assertSame(['version' => 'main', 'quantity' => 0, 'enabled' => 'false', 'label' => 'Zürich', 'run' => 0], $jobs[0]['parameters']);
    }

    public function testVersionSelectionOverridesOnlyTheRunInAllSupportedFormats(): void
    {
        $values = ParameterCompiler::values($this->schema(), ['version-version-0' => 'main']);
        $v2 = V2ExperimentFixture::postData($this->schema()[0]['elements'], $values);
        foreach ([$v2, ExperimentDefinition::document($this->schema(), $values, 1, 'alter', true)] as $data) {
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
        $legacy = V2ExperimentFixture::postData($groups[0]['elements'], $values);
        $row = $this->row($legacy);
        self::assertSame('legacy', $this->definition->decode($row)['format']);
        self::assertSame(['format' => 'chronos-experiment', 'version' => 1, 'run' => 0], $this->definition->compile($row, 10, $this->extensions)[0]['parameters']);
        self::assertSame($this->row($legacy), $row);
    }

    public function testOriginalV2ParametersNamedLikePreviewMetadataRemainRunnableAndCopyable(): void
    {
        $legacy = ['elements' => ['metadata-input', 'groups-input', 'values-input'], 'metadata-input-type' => 'string',
            'metadata-input-parameter' => '_v3Elements', '_v3Elements' => 'a real saved value',
            'groups-input-type' => 'string', 'groups-input-parameter' => '_v3Groups', '_v3Groups' => 'a real group value',
            'values-input-type' => 'string', 'values-input-parameter' => '_v3Values', '_v3Values' => 'a real draft value',
            'runs' => 1, 'run-distribution' => 'alter'];
        $row = $this->row($legacy);
        self::assertSame(['_v3Elements' => 'a real saved value', '_v3Groups' => 'a real group value', '_v3Values' => 'a real draft value', 'run' => 0],
            $this->definition->compile($row, 10, $this->extensions)[0]['parameters']);
        self::assertSame($legacy, $this->definition->values($row));
        self::assertSame($this->schema(), $this->definition->groups($row));
        self::assertSame($this->row($legacy), $row);
    }

    public function testFlatUserListsCannotReplaceSavedDescriptorsCurrentSchemaOrOtherCopyValues(): void
    {
        $legacy = ['elements' => ['label'], 'label-type' => 'string', 'label-parameter' => 'label', 'label' => 'original value',
            '_v3Elements' => ['a user list'], '_v3Groups' => ['another user list'], '_v3Values' => ['a retained user list']];
        $row = $this->row($legacy);
        self::assertSame($this->schema(), $this->definition->groups($row));
        self::assertSame($legacy, $this->definition->values($row));
        self::assertSame(['label'], array_column($this->definition->elements($row), 'id'));
        self::assertSame(['label' => 'original value', 'run' => 0], $this->definition->compile($row, 10, $this->extensions)[0]['parameters']);
        self::assertSame($this->row($legacy), $row);
    }

}
