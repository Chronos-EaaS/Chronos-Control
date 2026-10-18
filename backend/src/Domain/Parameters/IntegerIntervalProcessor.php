<?php
declare(strict_types=1);

namespace Chronos\Domain\Parameters;

final class IntegerIntervalProcessor implements ParameterProcessor
{
    public function process(array $data, string $parameter, array &$configurations): void
    {
        // when generating multi-job, we need to copy the current configurations and apply our setting for every of them
        // we have the interval, so we need to go from min to max in steps and copy all configurations and add the corresponding setting for each of them
        $newConfigurations = [];
        if (intval($data[$parameter . "-step"]) == 0 && intval($data[$parameter . "-min"]) == intval($data[$parameter . "-max"])) {
            $data[$parameter . "-step"] = 1; // we set this to allow setting step size 0 if there no interval at all to overcome the endless loop
        }
        if (intval($data[$parameter . "-step"]) == 0) {
            throw new \RuntimeException("Invalid step size causing endless loop!");
        }
        if (intval($data[$parameter . "-step"]) < 0 && intval($data[$parameter . "-min"]) <= intval($data[$parameter . "-max"])) {
            throw new \InvalidArgumentException('An ascending integer interval requires a positive step.');
        }
        for ($i = intval($data[$parameter . "-min"]); $i <= intval($data[$parameter . "-max"]); $i += intval($data[$parameter . "-step"])) {
            foreach ($configurations as $configuration) {
                $copy = $configuration;
                $copy['parameters'][$parameter] = $i;
                $newConfigurations[] = $copy;
            }
        }
        $configurations = $newConfigurations;
    }
}
