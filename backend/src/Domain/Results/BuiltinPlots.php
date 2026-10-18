<?php
declare(strict_types=1);

namespace Chronos\Domain\Results;

final class BuiltinPlots
{
    public const RENDERERS = ['bar-plot' => 'bar', 'line-plot' => 'line', 'line-plot-multi' => 'line', 'pie-plot' => 'pie', 'box-plot' => 'boxplot'];

    public static function types(): array { return array_keys(self::RENDERERS); }
    public static function supports(string $type): bool { return array_key_exists($type, self::RENDERERS); }

    public static function render(string $type, array $jobs, array $definition): array
    {
        $renderer = match ($type) {
            'bar-plot' => new BarPlot(),
            'pie-plot' => new PiePlot(),
            'line-plot' => new LinePlot(),
            'line-plot-multi' => new MultiLinePlot(),
            'box-plot' => new BoxPlot(),
            default => throw new \InvalidArgumentException('Unsupported plot type: ' . $type),
        };
        return $renderer->render($jobs, $definition);
    }
}
