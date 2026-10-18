<?php
declare(strict_types=1);

namespace Chronos\Tests\Support;

/** Encode the original v2 builder's flat POST format for saved-data tests. */
final class V2ExperimentFixture
{
    /**
     * Callers explicitly choose the active descriptors and submitted controls.
     * This fixture does not resolve visibility, defaults or trusted schema data.
     */
    public static function postData(array $elements, array $values, array $execution = []): array
    {
        $data = $values;
        $data['elements'] = [];
        foreach ($elements as $element) {
            $id = $element['id'];
            $parameter = $element['parameter'];
            $data['elements'][] = $id;
            $data[$id . '-type'] = $element['type'];
            $data[$id . '-parameter'] = $parameter;
            if (in_array($element['type'], ['percentage', 'percentage-dep', 'percentage-interval'], true)) {
                $dependency = $element['dependency'];
                $data['dependency-' . $dependency] = $parameter;
                if ($element['type'] !== 'percentage') $data[$parameter . '-dependency'] = $dependency;
                if ($element['type'] === 'percentage-dep') $data[$parameter . '-type'] = $element['parameter-type'] ?? 'int';
            }
        }
        return array_replace($data, $execution);
    }
}
