<?php
declare(strict_types=1);

namespace Chronos\Domain\Parameters;

final class StringListProcessor implements ParameterProcessor
{
    public function process(array $data, string $parameter, array &$configurations): void
    {
        // when generating multi-job, we need to copy the current configurations and apply our setting for every of them
        // we have the interval, so we need to go from min to max in steps and copy all configurations and add the corresponding setting for each of them
        $newConfigurations = [];
        $split = explode(",", (string) $data[$parameter]);
        foreach ($split as $value) {
            foreach ($configurations as $configuration) {
                $copy = $configuration;
                $copy['parameters'][$parameter] = $value;
                $newConfigurations[] = $copy;
            }
        }

        $configurations = $newConfigurations;
    }
}
