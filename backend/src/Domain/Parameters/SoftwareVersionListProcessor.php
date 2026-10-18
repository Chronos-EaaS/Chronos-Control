<?php
declare(strict_types=1);

namespace Chronos\Domain\Parameters;

final class SoftwareVersionListProcessor implements ParameterProcessor
{
    public function process(array $data, string $parameter, array &$configurations): void
    {
        // when generating multi-job, we need to copy the current configurations and apply our setting for every of them
        $newConfigurations = [];

        $numOfVersions = $data[$parameter . "-numberOfVersions"];
        for ($i = 0; $i < $numOfVersions; $i++) {
            foreach ($configurations as $configuration) {
                $copy = $configuration;
                $copy['parameters'][$parameter] = $data[$parameter . "-version-" . $i];
                $newConfigurations[] = $copy;
            }
        }


        $configurations = $newConfigurations;
    }
}
