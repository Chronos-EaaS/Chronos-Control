<?php
declare(strict_types=1);

namespace Chronos\Domain\Parameters;

final class CheckboxSelectionProcessor implements ParameterProcessor
{
    public function process(array $data, string $parameter, array &$configurations): void
    {
        // when generating multi-job, we need to copy the current configurations and apply our setting for every of them
        // we have the interval, so we need to go from min to max in steps and copy all configurations and add the corresponding setting for each of them
        $newConfiguration = [];
        foreach ($data as $param => $val) {
            if (strpos((string) $param, $parameter . "-") === 0) {
                $opt = substr((string) $param, strlen($parameter) + 1);
                if ($data[$parameter . "-" . $opt] == 'on') {
                    foreach ($configurations as $configuration) {
                        $copy = $configuration;
                        $copy['parameters'][$parameter] = $opt;
                        $newConfiguration[] = $copy;
                    }
                }
            }
        }

        $configurations = $newConfiguration;
    }
}
