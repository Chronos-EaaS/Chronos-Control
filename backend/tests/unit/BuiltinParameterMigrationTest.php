<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Workflows\ParameterCompiler;
use Chronos\Application\Systems\ParameterSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Saved-copy restoration is exercised through the actual v3 compiler boundary. */
final class BuiltinParameterMigrationTest extends TestCase
{
    public function testCheckboxOptionsKeepRepeatedParameterPrefixesThroughValidatedCompilation(): void
    {
        $groups = ParameterSchema::validate([[
            'id' => 'choices',
            'title' => 'Choices',
            'elements' => [[
                'id' => 'mode-choice',
                'name' => 'Mode',
                'type' => 'checkbox-selection',
                'parameter' => 'mode',
                'options' => 'mode-slow,mode-fast',
            ]],
        ]]);
        $submitted = ['mode-mode-slow' => 'on', 'mode-mode-fast' => 'on'];
        $values = ParameterCompiler::values($groups, $submitted);
        $original = $values;

        self::assertSame([
            ['parameters' => ['mode' => 'mode-slow', 'run' => 0]],
            ['parameters' => ['mode' => 'mode-fast', 'run' => 0]],
        ], ParameterCompiler::compile('', $groups, $values, [], 10));
        self::assertSame($original, $values, 'Processing must preserve submitted controls.');
    }

    #[DataProvider('savedCopies')]
    public function testEverySavedControlRestoresIntoItsCurrentParameterName(string $type, array $definition, array $saved, array $expected): void
    {
        $element = ['id' => 'field', 'type' => $type, 'parameter' => 'current', ...$definition];
        $groups = [['elements' => [$element]]];
        $input = ['elements' => ['field'], 'field-parameter' => 'previous', ...$saved];
        $original = $input;
        $values = ParameterCompiler::values($groups, $input);
        foreach ($expected as $key => $value) self::assertSame($value, $values[$key], $type . ': ' . $key);
        self::assertSame($original, $input, 'Restoration must not rewrite the saved experiment.');
    }

    public static function savedCopies(): iterable
    {
        yield 'boolean false' => ['boolean', ['default' => 'true'], ['previous' => false], ['current' => false]];
        yield 'integer zero' => ['integer', ['default' => '9'], ['previous' => 0], ['current' => 0]];
        yield 'integer string zero' => ['integer', ['default' => '9'], ['previous' => '0'], ['current' => '0']];
        yield 'integer false draft' => ['integer', ['default' => '9'], ['previous' => false], ['current' => false]];
        yield 'string empty' => ['string', ['default' => 'replacement'], ['previous' => ''], ['current' => '']];
        yield 'string list' => ['string-list', [], ['previous' => 'alpha,beta'], ['current' => 'alpha,beta']];
        yield 'dropdown stored option' => ['dropdown', ['options' => 'slow,fast', 'names' => 'Slow,Fast'], ['previous' => 'fast'], ['current' => 'fast']];
        yield 'selected and explicitly absent checkboxes' => ['checkbox-selection', ['options' => 'slow,fast', 'default' => 'fast'], ['previous-slow' => 'on'], ['current-slow' => 'on', 'current-fast' => false]];
        yield 'integer interval' => ['interval', [], ['previous-min' => '0', 'previous-max' => '8', 'previous-step' => '2'], ['current-min' => '0', 'current-max' => '8', 'current-step' => '2']];
        yield 'ordinary percentage zero' => ['percentage', [], ['previous-percentage' => '0'], ['current-percentage' => '0']];
        yield 'depending percentage' => ['percentage-dep', [], ['previous-percentage' => '25'], ['current-percentage' => '25']];
        yield 'percentage interval controls' => ['percentage-interval', [], ['previous-percentage-start' => '0', 'previous-percentage-end' => '100', 'previous-percentage-step' => '20'], ['current-percentage-start' => '0', 'current-percentage-end' => '100', 'current-percentage-step' => '20']];
        yield 'ratio controls' => ['ratio', ['parameter' => 'reads,writes'], ['field-parameter' => 'oldReads,oldWrites', 'oldReads,oldWrites-start' => '0:100', 'oldReads,oldWrites-end' => '100:0', 'oldReads,oldWrites-step' => '50'], ['reads,writes-start' => '0:100', 'reads,writes-end' => '100:0', 'reads,writes-step' => '50']];
        yield 'software versions from historical scalar' => ['software-versions-list', [], ['previous' => 'main,release/next'], ['current-numberOfVersions' => 2, 'current-version-0' => 'main', 'current-version-1' => 'release/next']];
        yield 'software versions from stored list controls' => ['software-versions-list', [], ['previous-numberOfVersions' => 2, 'previous-version-0' => 'main', 'previous-version-1' => 'release/next'], ['current-numberOfVersions' => 2, 'current-version-0' => 'main', 'current-version-1' => 'release/next']];
    }
}
