<?php
declare(strict_types=1);

namespace Chronos\Domain\Parameters;

final class DependentPercentageProcessor implements ParameterProcessor
{
    public function process(array $data, string $parameter, array &$configurations): void
    {
        // when generating multi-job, we need to copy the current configurations and apply our setting for every of them
        // we have the interval, so we need to go from min to max in steps and copy all configurations and add the corresponding setting for each of them

        $dep = null;
        if (isset($data[$parameter . "-dependency"])) {
            $dep = $data[$parameter . "-dependency"];
        }
        if ($dep === null) {
            throw new \RuntimeException("No dependency for percentage dependency element defined!");
        }

        $percentage = $data[$parameter . "-percentage"];

        $newConfigurations = [];
        foreach ($configurations as $configuration) {
            $copy = $configuration;
            if (!isset($copy['parameters'][$dep])) {
                throw new \RuntimeException("Dependency value for percentage dependency element is not set");
            }

            if (!isset($percentage)) {
                throw new \RuntimeException("No value set for: " . $parameter . "-percentage");
            } else if (!is_numeric($percentage)) {
                if ($percentage == "") {
                    $percentage = 0;
                } else {
                    throw new \RuntimeException("Non-numeric value set for: " . $parameter . "-percentage");
                }
            }

            $value = $copy['parameters'][$dep] * $percentage / 100;
            if ($data[$parameter . "-type"] == 'int') {
                $value = floor($value);
            }
            $copy['parameters'][$parameter] = $value;
            $newConfigurations[] = $copy;
        }
        $configurations = $newConfigurations;
    }
}
