<?php
declare(strict_types=1);

namespace Chronos\Domain\Parameters;

final class RatioProcessor implements ParameterProcessor
{
    public function process(array $data, string $parameter, array &$configurations): void
    {
        // when generating multi-job, we need to copy the current configurations and apply our setting for every of them
        // we have the interval, so we need to go from min to max in steps and copy all configurations and add the corresponding setting for each of them
        $newConfigurations = [];

        // parse ratios
        $start = explode(":", str_replace("%", "", (string) $data[$parameter . "-start"]));
        $end = explode(":", str_replace("%", "", (string) $data[$parameter . "-end"]));
        $step = str_replace("%", "", (string) $data[$parameter . "-step"]);

        if (sizeof($start) != 2 || sizeof($end) != 2) {
            throw new \RuntimeException("Invalid ratio detected!");
        } else if (intval($start[0]) + intval($start[1]) != 100) {
            throw new \RuntimeException("Start ration does not equal 100%!");
        } else if (intval($end[0]) + intval($end[1]) != 100) {
            throw new \RuntimeException("Start ration does not equal 100%!");
        } else if (intval($step) <= 0 || intval($step) > 100) {
            throw new \RuntimeException("Invalid ratio step size!");
        }

        $params = explode(",", $parameter);
        if (sizeof($params) != 2) {
            throw new \RuntimeException("Invalid number of parameters for ratio!");
        }

        $ratio = [intval($start[0]), intval($start[1])];
        for (; $ratio[0] <= $end[0]; $ratio[0] += intval($step), $ratio[1] -= intval($step)) {
            foreach ($configurations as $configuration) {
                $copy = $configuration;
                $copy['parameters'][$params[0]] = $ratio[0];
                $copy['parameters'][$params[1]] = $ratio[1];
                $newConfigurations[] = $copy;
            }
        }

        $configurations = $newConfigurations;
    }
}
