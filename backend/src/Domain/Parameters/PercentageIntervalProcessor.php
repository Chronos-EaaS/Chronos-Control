<?php
declare(strict_types=1);

namespace Chronos\Domain\Parameters;

final class PercentageIntervalProcessor implements ParameterProcessor
{
    /** Count inclusive steps before allocation, with tolerance only for float endpoint rounding. */
    public static function rangeCount(int|float $start, int|float $end, int|float $step): int
    {
        if (!is_finite((float) $start) || !is_finite((float) $end) || !is_finite((float) $step)
            || $step <= 0 || $end < $start) {
            throw new \InvalidArgumentException('The interval requires finite bounds and a positive step.');
        }
        $steps = ($end - $start) / $step;
        if (is_float($start) || is_float($end) || is_float($step)) {
            $nearest = round($steps);
            if (abs($steps - $nearest) <= 8 * PHP_FLOAT_EPSILON * max(1.0, abs($steps))) $steps = $nearest;
        }
        if (!is_finite($steps) || $steps >= PHP_INT_MAX) {
            throw new \InvalidArgumentException('The interval has too many steps.');
        }
        return (int) floor($steps) + 1;
    }

    public function process(array $data, string $parameter, array &$configurations): void
    {
        $floating = ($data[$parameter . '-type'] ?? 'int') === 'float';
        $start = $floating ? (float) $data[$parameter . '-start'] : (int) $data[$parameter . '-start'];
        $end = $floating ? (float) $data[$parameter . '-end'] : (int) $data[$parameter . '-end'];
        $step = $floating ? (float) $data[$parameter . '-step'] : (int) $data[$parameter . '-step'];
        $newConfigurations = [];
        if ($step == 0 && $start !== $end) {
            throw new \RuntimeException("Invalid step size causing endless loop!");
        }

        if (isset($configurations[0][$data[$parameter . "-dependency"] . "-step"])) {
            foreach ($configurations as $configuration) {
                $s = $configuration[$data[$parameter . "-dependency"] . "-step"];
                $value = $start + $step * ($s - 1);

                $copy = $configuration;
                $copy['parameters'][$parameter] = $value;
                $newConfigurations[] = $copy;
            }
        } else {
            if ($start > $end) {
                [$start, $end] = [$end, $start];
                $step = -$step;
            }

            if ($start === $end) {
                $step = 1;
            }
            $count = self::rangeCount($start, $end, $step);
            for ($index = 0; $index < $count; $index++) {
                $value = $start + $step * $index;
                if ($floating && $index === $count - 1
                    && abs($value - $end) <= 8 * PHP_FLOAT_EPSILON * max(1.0, abs($value), abs($end))) $value = $end;
                foreach ($configurations as $configuration) {
                    $copy = $configuration;
                    $copy['parameters'][$parameter] = $value;
                    if ($start !== $end) {
                        // if the current setting is the special case that there will be no range due to start == end,
                        // we don't have steps as this might mess up if other ranges in the same dependency should do steps
                        $copy[$data[$parameter . "-dependency"] . "-step"] = $index + 1;
                    }
                    $newConfigurations[] = $copy;
                }
            }
        }
        $configurations = $newConfigurations;
    }
}
