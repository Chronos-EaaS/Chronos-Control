<?php
declare(strict_types=1);

namespace Chronos\Domain\Parameters;

/** The supported processing contract is explicit and independent of UI files. */
final class BuiltinParameters
{
    public const TYPES = ['boolean', 'checkbox-selection', 'dropdown', 'integer', 'interval', 'percentage', 'percentage-interval', 'percentage-dep', 'ratio', 'software-versions-list', 'string-list', 'string'];
    private const MULTIPLE = ['checkbox-selection', 'interval', 'percentage-interval', 'ratio', 'software-versions-list', 'string-list'];

    public static function types(): array { return self::TYPES; }

    public static function isMultiple(string $type): bool
    {
        self::processor($type);
        return in_array($type, self::MULTIPLE, true);
    }

    public static function process(string $type, array $data, string $parameter, array &$configurations): void
    {
        self::processor($type)->process($data, $parameter, $configurations);
    }

    private static function processor(string $type): ParameterProcessor
    {
        return match ($type) {
            'boolean', 'dropdown', 'integer', 'percentage', 'string' => new ScalarProcessor(),
            'checkbox-selection' => new CheckboxSelectionProcessor(),
            'interval' => new IntegerIntervalProcessor(),
            'percentage-dep' => new DependentPercentageProcessor(),
            'percentage-interval' => new PercentageIntervalProcessor(),
            'ratio' => new RatioProcessor(),
            'software-versions-list' => new SoftwareVersionListProcessor(),
            'string-list' => new StringListProcessor(),
            default => throw new \InvalidArgumentException('Unsupported parameter type: ' . $type),
        };
    }
}
