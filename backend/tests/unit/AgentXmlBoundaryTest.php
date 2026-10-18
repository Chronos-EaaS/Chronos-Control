<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Agents\JobCdlAssembler;
use Chronos\Application\Extensions\Extensions;
use Chronos\Application\Shared\Input;
use Chronos\Application\Systems\ParameterSchema;
use Chronos\Application\Workflows\ParameterCompiler;
use Chronos\Domain\Configuration\CdlDocument;
use Chronos\Http\HttpError;
use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentXmlBoundaryTest extends TestCase
{
    private function parameterGroups(string $type, string $value, string $parameter = 'label'): array
    {
        $element = ['id' => 'input', 'name' => 'Input', 'parameter' => $parameter, 'type' => $type, 'default' => $value];
        if (in_array($type, ['dropdown', 'checkbox-selection'], true)) $element['options'] = [$value];
        return [['id' => 'group', 'title' => 'Group', 'elements' => [$element]]];
    }

    private function compile(array $groups): array
    {
        $element = $groups[0]['elements'][0];
        $input = $element['type'] === 'software-versions-list'
            ? ['label-numberOfVersions' => 1, 'label-version-0' => $element['default']] : [];
        return ParameterCompiler::compile('/unused', $groups, ParameterCompiler::values($groups, $input), [], 10);
    }

    private function reject(callable $action, string $field, int $status = 422, string $code = 'validation_failed'): void
    {
        try { $action(); self::fail('An unusable agent XML configuration was accepted.'); }
        catch (HttpError $error) {
            self::assertSame($status, $error->status);
            self::assertSame($code, $error->errorCode);
            if ($field !== '') self::assertArrayHasKey($field, $error->fields);
        }
    }

    public static function forbiddenText(): iterable
    {
        foreach (["\u{FFFE}", "\u{FFFF}"] as $character) foreach (['string', 'dropdown', 'checkbox-selection', 'string-list', 'software-versions-list'] as $type) {
            yield sprintf('U+%04X %s', mb_ord($character), $type) => [$type, 'before' . $character . 'after'];
        }
    }

    #[DataProvider('forbiddenText')]
    public function testNewParameterSchemasOrActiveValuesRejectXmlForbiddenCharacters(string $type, string $value): void
    {
        $field = in_array($type, ['dropdown', 'checkbox-selection'], true) ? 'groups.0.elements.0.options' : 'parameters.label';
        $this->reject(fn() => $this->compile(ParameterSchema::validate($this->parameterGroups($type, $value))), $field);
    }

    #[DataProvider('forbiddenText')]
    public function testPreviouslySavedSchemaValuesAreRejectedAtCompilationBeforeAnyJobIsStored(string $type, string $value): void
    {
        // The evaluation path reads stored definitions without saving a schema.
        $this->reject(fn() => $this->compile($this->parameterGroups($type, $value)), 'parameters.label');
    }

    public static function legalText(): iterable
    {
        foreach (['🧪', "\u{1FFFE}"] as $character) foreach (['string', 'dropdown', 'checkbox-selection', 'string-list', 'software-versions-list'] as $type) {
            yield sprintf('U+%04X %s', mb_ord($character), $type) => [$type, " \t Zürich & XML <value> \r\n " . $character . ' '];
        }
    }

    #[DataProvider('legalText')]
    public function testLegalSupplementaryTextAndRawWhitespaceStillCompileAndRoundTrip(string $type, string $value): void
    {
        $groups = ParameterSchema::validate($this->parameterGroups($type, $value));
        $jobs = $this->compile($groups);
        self::assertSame($value, $jobs[0]['parameters']['label']);
        $document = new DOMDocument();
        self::assertTrue($document->loadXML(JobCdlAssembler::build(1, $jobs[0]['parameters'])));
        self::assertSame($value, $document->getElementsByTagName('label')->item(0)?->textContent);
        self::assertSame(trim($value), Input::text($value, 'metadata', 256));
    }

    public static function unsupportedNames(): iterable
    {
        yield 'Deseret letter' => ["\u{10400}"];
        yield 'supplementary ideograph' => ["\u{20000}"];
        yield 'feminine ordinal letter' => ["\u{00AA}"];
        yield 'micro letter' => ["\u{00B5}"];
        yield 'masculine ordinal letter' => ["\u{00BA}"];
        yield 'leading name whitespace' => [' label'];
        yield 'trailing name whitespace' => ['label '];
    }

    #[DataProvider('unsupportedNames')]
    public function testSchemaNamesMustBeSupportedByTheActualAgentXmlBuilder(string $name): void
    {
        $this->reject(fn() => ParameterSchema::validate($this->parameterGroups('string', 'safe', $name)), 'groups.0.elements.0.parameter');
        $ratio = $this->parameterGroups('ratio', '', 'reads,' . $name);
        $this->reject(fn() => ParameterSchema::validate($ratio), 'groups.0.elements.0.parameter');
    }

    #[DataProvider('unsupportedNames')]
    public function testOriginalV2NamesCannotBypassAgentXmlValidation(string $name): void
    {
        $saved = ['elements' => ['input'], 'input-type' => 'string', 'input-parameter' => $name, $name => 'safe'];
        $this->reject(fn() => ParameterCompiler::configurations('/unused', $saved, 10), 'parameters.' . $name);
    }

    public static function badExtensionOutput(): iterable
    {
        yield 'forbidden text FFFE' => ['label', "before\u{FFFE}after"];
        yield 'forbidden text FFFF' => ['label', "before\u{FFFF}after"];
        yield 'unsupported supplementary name' => ["\u{10400}", 'safe'];
        yield 'unbound namespace' => ['ns:label', 'safe'];
    }

    #[DataProvider('badExtensionOutput')]
    public function testTrustedProcessorsCannotReturnXmlInvalidParameterNamesOrText(string $name, string $value): void
    {
        $root = sys_get_temp_dir() . '/chronos-xml-extension-' . bin2hex(random_bytes(8));
        $directory = $root . '/webroot/systems/1';
        mkdir($directory . '/parameters/custom-output', 0700, true);
        file_put_contents($directory . '/extensions.v3.json', json_encode(['format' => 1, 'parameters' => [
            ['type' => 'custom-output', 'name' => 'Output', 'baseType' => 'string', 'processor' => 'parameters/custom-output/process.php'],
        ]], JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/parameters/custom-output/process.php', '<?php $allConfigurations = ' . var_export([['parameters' => [$name => $value]]], true) . ';');
        try {
            $extensions = new Extensions($root, 1, [1]);
            $groups = $this->parameterGroups('custom-output', 'safe');
            $this->reject(fn() => ParameterCompiler::compile($root, $groups, ['label' => 'safe'], [], 10, $extensions), 'parameters.' . $name);
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }

    public static function storedInvalidValues(): iterable
    {
        foreach (['parameters', 'general', 'setup'] as $section) foreach (["\u{FFFE}", "\u{FFFF}"] as $character) {
            yield $section . sprintf(' U+%04X', mb_ord($character)) => [$section, ['label' => $character]];
        }
        foreach (['parameters', 'general', 'setup'] as $section) yield $section . ' unsupported name' => [$section, ["\u{10400}" => 'safe']];
    }

    #[DataProvider('storedInvalidValues')]
    public function testInvalidHistoricalInputsFailWithAControlledCdlError(string $section, array $values): void
    {
        $this->reject(fn() => JobCdlAssembler::build(1, $section === 'parameters' ? $values : [],
            $section === 'general' ? $values : [], $section === 'setup' ? $values : []), '', 409, 'cdl_invalid');
    }

    public function testDomainDocumentCannotSerializeForbiddenTextEvenWithoutAnApplicationCaller(): void
    {
        $document = new CdlDocument(1);
        $this->expectException(\InvalidArgumentException::class);
        $document->getEvaluation()->appendChild($document->createElement('label', "bad\u{FFFF}value"));
    }

    public function testInvalidTextInAnInactiveDraftDoesNotBlockValidActiveParameters(): void
    {
        $groups = [
            ['id' => 'active', 'title' => 'Active', 'elements' => [['id' => 'label', 'name' => 'Label', 'type' => 'string', 'parameter' => 'label', 'default' => 'safe']]],
            ['id' => 'inactive', 'title' => 'Inactive', 'depends' => 'show', 'dependsValue' => 'yes', 'elements' => [['id' => 'draft', 'name' => 'Draft', 'type' => 'string', 'parameter' => 'draft', 'default' => "bad\u{FFFF}value"]]],
        ];
        $values = ParameterCompiler::values($groups, ['show' => 'no', 'draft' => "bad\u{FFFE}value"]);
        $jobs = ParameterCompiler::compile('/unused', $groups, $values, [], 10);
        self::assertSame(['label' => 'safe', 'run' => 0], $jobs[0]['parameters']);
        self::assertSame("bad\u{FFFE}value", $values['draft']);
    }
}
