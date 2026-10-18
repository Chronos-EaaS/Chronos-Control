<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Agents\JobCdlAssembler;
use Chronos\Application\Systems\ParameterSchema;
use Chronos\Application\Workflows\ParameterCompiler;
use Chronos\Http\HttpError;
use Chronos\Tests\Support\V2ExperimentFixture;
use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParameterTextTest extends TestCase
{
    private function schema(array $elements): array
    {
        return ParameterSchema::validate([['id' => 'group', 'title' => 'Group', 'elements' => $elements]]);
    }

    private function jobs(array $groups, array $input = []): array
    {
        $values = ParameterCompiler::values($groups, $input);
        $jobs = ParameterCompiler::compile(CHRONOS_TEST_ROOT, $groups, $values, [], 100);
        self::assertSame($jobs, ParameterCompiler::configurations(CHRONOS_TEST_ROOT, V2ExperimentFixture::postData($groups[0]['elements'], $values), 100));
        return $jobs;
    }

    public function testDropdownSubmittedAndDefaultChoiceValuesRetainExactWhitespace(): void
    {
        $groups = $this->schema([['id' => 'mode', 'name' => 'Mode', 'type' => 'dropdown', 'parameter' => 'mode',
            'options' => 'fast, slow ', 'names' => 'Fast, Slow label ', 'default' => ' slow ']]);
        self::assertSame(' slow ', $this->jobs($groups)[0]['parameters']['mode']);
        self::assertSame(' slow ', $this->jobs($groups, ['mode' => ' slow '])[0]['parameters']['mode']);
    }

    public function testDropdownDefaultLabelUsesTheUnmodifiedOptionAtTheMatchingIndex(): void
    {
        $groups = $this->schema([['id' => 'mode', 'name' => 'Mode', 'type' => 'dropdown', 'parameter' => 'mode',
            'options' => 'fast, slow ', 'names' => 'Fast, Slow label ', 'default' => ' Slow label ']]);
        self::assertSame(' slow ', $this->jobs($groups)[0]['parameters']['mode']);
    }

    public function testCheckboxDefaultsAndCopiedControlsRetainExactOptionSuffixes(): void
    {
        $groups = $this->schema([['id' => 'mode', 'name' => 'Mode', 'type' => 'checkbox-selection', 'parameter' => 'mode',
            'options' => 'fast, slow ', 'default' => ' slow ']]);
        self::assertSame(' slow ', $this->jobs($groups)[0]['parameters']['mode']);
        $copy = ['elements' => ['mode'], 'mode-parameter' => 'previous', 'previous- slow ' => 'on'];
        self::assertSame(' slow ', $this->jobs($groups, $copy)[0]['parameters']['mode']);
    }

    public function testWhitespaceDistinctChoicesRemainDistinct(): void
    {
        $groups = $this->schema([['id' => 'mode', 'name' => 'Mode', 'type' => 'checkbox-selection', 'parameter' => 'mode',
            'options' => ['slow', ' slow'], 'default' => ['slow', ' slow']]]);
        self::assertSame(['slow', ' slow'], array_column(array_column($this->jobs($groups), 'parameters'), 'mode'));
    }

    public function testSchemaOwnershipUsesExactChoiceControlsRatherThanTrimmedSpellings(): void
    {
        $groups = $this->schema([
            ['id' => 'mode', 'name' => 'Mode', 'type' => 'checkbox-selection', 'parameter' => 'mode', 'options' => 'fast, slow', 'default' => ' slow'],
            ['id' => 'other', 'name' => 'Other', 'type' => 'string', 'parameter' => 'mode-slow', 'default' => 'literal'],
        ]);
        self::assertSame(['mode' => ' slow', 'mode-slow' => 'literal', 'run' => 0], $this->jobs($groups)[0]['parameters']);
    }

    public function testPlainStringDefaultsSubmittedValuesAndCdlKeepTheirExactText(): void
    {
        $value = " \t Zürich & XML <value> \r\n ";
        $groups = $this->schema([['id' => 'label', 'name' => 'Label', 'type' => 'string', 'parameter' => 'label', 'default' => $value]]);
        foreach ([[], ['label' => $value]] as $input) {
            $parameters = $this->jobs($groups, $input)[0]['parameters'];
            self::assertSame($value, $parameters['label']);
            $document = new DOMDocument();
            self::assertTrue($document->loadXML(JobCdlAssembler::build(1, $parameters)));
            self::assertSame($value, $document->getElementsByTagName('label')->item(0)->textContent);
        }
    }

    public function testStringListRetainsWhitespaceInTheFirstAndLastEntries(): void
    {
        $groups = $this->schema([['id' => 'labels', 'name' => 'Labels', 'type' => 'string-list', 'parameter' => 'label', 'default' => ' first , second ']]);
        self::assertSame([' first ', ' second '], array_column(array_column($this->jobs($groups), 'parameters'), 'label'));
    }

    public function testWhitespaceOnlyStringsAreDataRatherThanMissingMetadata(): void
    {
        $value = " \t\r\n ";
        $groups = $this->schema([['id' => 'label', 'name' => 'Label', 'type' => 'string', 'parameter' => 'label', 'default' => $value]]);
        self::assertSame($value, $this->jobs($groups)[0]['parameters']['label']);
    }

    public function testSoftwareVersionPayloadsArePreservedByTheCompiler(): void
    {
        $groups = $this->schema([['id' => 'version', 'name' => 'Version', 'type' => 'software-versions-list', 'parameter' => 'version']]);
        self::assertSame(' release label ', $this->jobs($groups, ['version-numberOfVersions' => 1, 'version-version-0' => ' release label '])[0]['parameters']['version']);
    }

    public function testOverrideTextValidationRetainsBytesAndEnforcesItsRawLength(): void
    {
        self::assertSame(' release label ', ParameterCompiler::parameterText(' release label ', 'versions', 256, true));
        $boundary = str_repeat(' ', 249) . 'release';
        self::assertSame($boundary, ParameterCompiler::parameterText($boundary, 'versions', 256, true));
        $this->expectException(HttpError::class);
        ParameterCompiler::parameterText(' ' . $boundary, 'versions', 256, true);
    }

    public static function unsafeOverrideValues(): array
    {
        return [[null], [5], [[]], [''], ['   '], ["bad\0version"], ["bad\x01version"], ["\xff"]];
    }

    #[DataProvider('unsafeOverrideValues')]
    public function testOverrideValidationStillRejectsMissingInvalidAndUnsafeText(mixed $value): void
    {
        try { ParameterCompiler::parameterText($value, 'versions', 256, true); self::fail('Invalid override text was accepted.'); }
        catch (HttpError $error) {
            self::assertSame(422, $error->status);
            self::assertArrayHasKey('versions', $error->fields);
        }
    }
}
