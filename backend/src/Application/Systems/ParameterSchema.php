<?php
declare(strict_types=1);

namespace Chronos\Application\Systems;

use Chronos\Application\Extensions\Extensions;
use Chronos\Application\Shared\Input;

final class ParameterSchema
{
    public static function validate(mixed $value, ?Extensions $extensions = null): array
    {
        $groups = DefinitionValues::arrays($value);
        if (!is_array($groups) || !array_is_list($groups) || count($groups) > 1000) Input::invalid('groups', 'Submit a group array of at most 1000 groups.');
        $ids = $parameters = $dependencies = [];
        foreach ($groups as $gi => &$group) {
            $field = 'groups.' . $gi;
            if (!is_array($group)) Input::invalid($field, 'Submit a group object.');
            $id = DefinitionValues::identifier($group['id'] ?? null, $field . '.id');
            if (isset($ids[$id])) Input::invalid($field . '.id', 'Each group and input needs a unique ID.');
            $ids[$id] = true;
            Input::text($group['title'] ?? null, $field . '.title', 256);
            $group['depends'] ??= '';
            $group['dependsValue'] ??= '';
            Input::text($group['depends'], $field . '.depends', 256, false);
            if (!is_scalar($group['dependsValue']) && $group['dependsValue'] !== null) Input::invalid($field . '.dependsValue', 'Use a scalar condition value.');
            if (!is_array($group['elements'] ?? null) || !array_is_list($group['elements']) || count($group['elements']) > 1000) Input::invalid($field . '.elements', 'Submit an input array.');
            foreach ($group['elements'] as $ei => $element) {
                $ef = $field . '.elements.' . $ei;
                if (!is_array($element)) Input::invalid($ef, 'Submit an input object.');
                $eid = DefinitionValues::identifier($element['id'] ?? null, $ef . '.id');
                if (isset($ids[$eid])) Input::invalid($ef . '.id', 'Each group and input needs a unique ID.');
                $ids[$eid] = true;
                $type = $element['type'] ?? null;
                if (is_string($type) && $extensions !== null) $type = $extensions->parameterBase($type);
                if (!in_array($type, Extensions::PARAMETER_TYPES, true)) Input::invalid($ef . '.type', 'This custom input requires a registered v3 editor and renderer before it can be saved.');
                Input::text($element['name'] ?? null, $ef . '.name', 256);
                $names = explode(',', Input::text($element['parameter'] ?? null, $ef . '.parameter', 256));
                if (count($names) !== ($type === 'ratio' ? 2 : 1)) Input::invalid($ef . '.parameter', 'Use one parameter name, or two comma-separated names for a ratio.');
                foreach ($names as $name) {
                    if (!preg_match('/^[\p{L}_][\p{L}\p{N}_.-]*$/uD', $name) || in_array($name, ['run', 'elements'], true) || isset($parameters[$name])) Input::invalid($ef . '.parameter', 'Use distinct valid parameter names. The names run and elements are reserved.');
                    $parameters[$name] = $id;
                }
                if (in_array($type, ['dropdown', 'checkbox-selection'], true)) {
                    $options = $element['options'] ?? '';
                    $options = is_array($options) ? $options : (is_string($options) ? explode(',', $options) : []);
                    if ($options === [] || count($options) !== count(array_unique($options, SORT_REGULAR))) Input::invalid($ef . '.options', 'Supply distinct choices.');
                    foreach ($options as $option) Input::text($option, $ef . '.options', 256);
                    if ($type === 'dropdown' && isset($element['names']) && $element['names'] !== '' && (!is_string($element['names']) || count(explode(',', $element['names'])) !== count($options))) Input::invalid($ef . '.names', 'Supply one display label for each choice.');
                    if ($type === 'checkbox-selection') foreach ($options as $option) $parameters[$names[0] . '-' . $option] = $id;
                }
                if (str_starts_with($type, 'percentage')) {
                    Input::text($element['dependency'] ?? null, $ef . '.dependency', 256);
                    $dependencies[$names[0]] = $element['dependency'];
                    if (isset($element['parameter-type']) && !in_array($element['parameter-type'], ['int', 'float'], true)) Input::invalid($ef . '.parameter-type', 'Use int or float.');
                }
            }
        }
        unset($group);
        $parameters += self::controlOwners($groups, $extensions);
        foreach ($dependencies as $name => $dependency) {
            if (!isset($parameters[$dependency])) Input::invalid('groups', 'A percentage dependency does not exist.');
            $seen = [$name => true];
            while (isset($dependencies[$dependency])) {
                if (isset($seen[$dependency])) Input::invalid('groups', 'Percentage dependencies must not contain a cycle.');
                $seen[$dependency] = true;
                $dependency = $dependencies[$dependency];
            }
        }
        $groupDependencies = [];
        foreach ($groups as $group) if ($group['depends'] !== '' && isset($parameters[$group['depends']])) $groupDependencies[$group['id']] = $parameters[$group['depends']];
        foreach ($groupDependencies as $id => $dependency) {
            $seen = [$id => true];
            while (true) {
                if (isset($seen[$dependency])) Input::invalid('groups', 'Group visibility must not depend on itself or contain a cycle.');
                $seen[$dependency] = true;
                if (!isset($groupDependencies[$dependency])) break;
                $dependency = $groupDependencies[$dependency];
            }
        }
        return $groups;
    }

    /** A form field/output belongs to one input, independently of schema order. */
    private static function controlOwners(array $groups, ?Extensions $extensions): array
    {
        $owners = $versionPrefixes = [];
        foreach ($groups as $group) {
            foreach ($group['elements'] as $element) {
                $type = $extensions?->parameterBase($element['type']) ?? $element['type'];
                $parameter = trim($element['parameter']);
                $keys = $type === 'ratio' ? explode(',', $parameter) : [$parameter];
                $suffixes = match ($type) {
                    'interval' => ['min', 'max', 'step'],
                    'ratio' => ['start', 'end', 'step'],
                    'percentage-interval' => ['percentage-start', 'percentage-end', 'percentage-step'],
                    'percentage', 'percentage-dep' => ['percentage'],
                    'software-versions-list' => ['numberOfVersions'],
                    'checkbox-selection' => array_map('trim', is_array($element['options']) ? $element['options'] : explode(',', $element['options'])),
                    default => [],
                };
                foreach ($suffixes as $suffix) $keys[] = $parameter . '-' . $suffix;
                foreach ($keys as $key) {
                    if (isset($owners[$key])) Input::invalid('groups', 'Input fields and generated parameter names must not overlap: ' . $key . '.');
                    $owners[$key] = ['input' => $element['id'], 'group' => $group['id']];
                }
                if ($type === 'software-versions-list') $versionPrefixes[$parameter] = $element['id'];
            }
        }
        // A version input supports indexes 0..999. Match that namespace without
        // allocating 1,000 generated keys for every definition in a large schema.
        foreach ($owners as $key => $owner) {
            if (preg_match('/^(.*)-version-(?:0|[1-9][0-9]{0,2})$/D', $key, $match)
                && isset($versionPrefixes[$match[1]]) && $owner['input'] !== $versionPrefixes[$match[1]]) {
                Input::invalid('groups', 'Input fields and generated parameter names must not overlap: ' . $key . '.');
            }
        }
        return array_map(static fn(array $owner): string => $owner['group'], $owners);
    }

}
