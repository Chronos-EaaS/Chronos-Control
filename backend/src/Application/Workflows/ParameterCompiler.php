<?php
declare(strict_types=1);

namespace Chronos\Application\Workflows;

use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;
use Chronos\Application\Extensions\Extensions;
use Chronos\Domain\Parameters\BuiltinParameters;
use Chronos\Domain\Parameters\PercentageIntervalProcessor;

/** Compile trusted schema + editable values into the unchanged agent job format. */
final class ParameterCompiler
{
    private const TYPES = BuiltinParameters::TYPES;

    public static function values(array $groups, mixed $input, ?Extensions $extensions = null): array
    {
        if(!is_array($input)){Input::invalid('parameters','Provide parameter values as an object.');}
        // Callers supply a flat value map; legal groups/values keys are user data.
        $values=$input;
        $legacyIds = is_array($input['elements'] ?? null) && array_is_list($input['elements']) ? array_fill_keys(array_filter($input['elements'], 'is_string'), true) : [];
        foreach($values as$key=>$value){if(!is_string($key)||strlen($key)>256||(!is_scalar($value)&&$value!==null&&(!is_array($value)||!array_is_list($value)))){Input::invalid('parameters','Use scalar values or a list of choices.');}if(is_string($value)&&(!mb_check_encoding($value,'UTF-8')||str_contains($value,"\0")||strlen($value)>16384)){Input::invalid('parameters','A parameter value is invalid or too long.');}}
        foreach($groups as$group){foreach($group['elements']as$e){$p=$e['parameter'];$id=$e['id'];$copied=isset($legacyIds[$id]);$old=$copied&&is_string($input[$id.'-parameter']??null)?$input[$id.'-parameter']:$p;
            $set=static function(string$suffix,mixed$fallback)use(&$values,$input,$p,$old):void{if(!array_key_exists($p.$suffix,$values)){$values[$p.$suffix]=array_key_exists($old.$suffix,$input)?$input[$old.$suffix]:$fallback;}};
            switch($extensions?->parameterBase($e['type'])??$e['type']){
                case'checkbox-selection':$defaults=self::choiceValues($e['default']??'');foreach(self::choiceValues($e['options']??'')as$option){$set('-'.$option,$copied?false:(in_array($option,$defaults,true)?'on':false));}break;
                case'software-versions-list':$oldVersions=self::csv($input[$old]??'');$set('-numberOfVersions',count($oldVersions)?:1);$count=Input::integer($values[$p.'-numberOfVersions'],'parameters.'.$p,1,1000);for($i=0;$i<$count;$i++){$set('-version-'.$i,$oldVersions[$i]??'');}break;
                case'interval':foreach(['min','max','step']as$suffix){$set('-'.$suffix,$e['default-'.$suffix]??'');}break;
                case'ratio':foreach(['start','end','step']as$suffix){$set('-'.$suffix,$e['default-'.$suffix]??'');}break;
                case'percentage-interval':foreach(['start','end','step']as$suffix){$set('-percentage-'.$suffix,$e['default-'.$suffix]??'');}break;
                case'percentage':case'percentage-dep':$set('-percentage',$e['default']??'');break;
                case'dropdown':$options=self::choiceValues($e['options']??'');$default=self::scalar($e['default']??'');$index=array_search($default,$options,true);if($index===false){$index=array_search($default,self::choiceValues($e['names']??''),true);}$set('', $options[$index===false?0:$index]??'');break;
                default:$set('',$e['default']??'');
            }
        }}
        return $values;
    }

    public static function csv(mixed $input): array
    {
        if(is_array($input)){return array_values(array_map('strval',$input));}
        return is_scalar($input)&&trim((string)$input)!==''?array_map('trim',explode(',',(string)$input)):[];
    }

    /** Choice spellings are agent data, including whitespace around a value or label. */
    public static function choiceValues(mixed $input): array
    {
        if (is_array($input)) return array_values(array_map('strval', $input));
        return is_scalar($input) && (string) $input !== '' ? explode(',', (string) $input) : [];
    }

    /** Schema and execution metadata never share the editable parameter namespace. */
    public static function compile(string $root, array $groups, array $values, array $execution, int $limit, ?Extensions $extensions = null): array
    {
        return self::generate(self::elements($groups, $values, $execution, $extensions), $values, $execution, $limit, $extensions);
    }

    public static function elements(array $groups, array $values, array $execution = [], ?Extensions $extensions = null): array
    {
        $owners = self::fieldOwners($groups, $values, $extensions);
        $definitions = $derived = [];
        foreach ($groups as $index => $group) {
            foreach ($group['elements'] as $element) {
                $type = $element['type'];
                // Inactive unknown adapters must remain inert. Active entries
                // still require a ready processor when jobs are generated.
                if ($extensions !== null) {
                    try { $type = $extensions->parameterBase($type); }
                    catch (HttpError) { }
                }
                $definitions[$element['parameter']] = array_replace($element, ['baseType' => $type]);
            }
        }
        foreach ($definitions as $parameter => $element) {
            if ($element['baseType'] === 'percentage') $derived[$parameter] = [$element, ''];
            elseif ($element['baseType'] === 'percentage-interval') foreach (['start', 'end', 'step'] as $suffix) {
                $key = $parameter . '-' . $suffix;
                if (!isset($definitions[$key])) $derived[$key] = [$element, '-' . $suffix];
            }
        }
        $parentValues = ['runs' => $execution['runs'] ?? 1, 'run-distribution' => $execution['distribution'] ?? 'alter',
            'phase_warmUp' => ($execution['warmUp'] ?? true) ? '1' : '0'];
        foreach ($parentValues as $key => $value) if (!isset($owners[$key])) $values[$key] = $value;
        $shown = $visiting = $calculated = $calculating = [];
        // A browser preview is editable request data. Resolve only percentages
        // needed by visibility, so invalid drafts in inactive groups stay inert.
        $value = function (string $key) use (&$value, &$visible, &$values, &$calculated, &$calculating, $derived, $definitions, $owners): array {
            if (!isset($derived[$key])) return [array_key_exists($key, $values), $values[$key] ?? null];
            if (isset($calculated[$key])) return [true, $values[$key]];
            if (isset($calculating[$key])) Input::invalid('parameters', 'Percentage dependencies must not contain a cycle.');
            $calculating[$key] = true;
            [$element, $suffix] = $derived[$key];
            $dependency = $element['dependency'] ?? null;
            if (!is_string($dependency) || !isset($definitions[$dependency])
                || !in_array($definitions[$dependency]['baseType'], ['integer', 'string', 'dropdown', 'percentage'], true)) {
                Input::invalid('parameters.' . $element['parameter'], 'Use a scalar numeric dependency for this percentage.');
            }
            if (!$visible($owners[$dependency])) { unset($calculating[$key]); return [false, null]; }
            [, $base] = $value($dependency);
            $absolute = self::number($base, $key . ' dependency')
                * self::number($values[$element['parameter'] . '-percentage' . $suffix] ?? null, $key . ' percentage') / 100;
            $values[$key] = ($element['parameter-type'] ?? 'int') === 'float' ? $absolute : (int) floor($absolute);
            unset($calculating[$key]); $calculated[$key] = true;
            return [true, $values[$key]];
        };
        $visible = function (int $index) use (&$visible, &$shown, &$visiting, $owners, $groups, $value): bool {
            if (array_key_exists($index, $shown)) return $shown[$index];
            if (isset($visiting[$index])) Input::invalid('parameters', 'Conditional parameter groups contain a cycle.');
            $visiting[$index] = true;
            $group = $groups[$index];
            $dependency = $group['depends'] ?? '';
            $answer = $dependency === '';
            if (!$answer && (!isset($owners[$dependency]) || $visible($owners[$dependency]))) {
                [$exists, $actual] = $value($dependency);
                $answer = $exists && self::scalar($actual) === self::scalar($group['dependsValue'] ?? '');
            }
            unset($visiting[$index]);
            return $shown[$index] = $answer;
        };
        $elements = [];
        foreach ($groups as $index => $group) {
            if ($visible($index)) array_push($elements, ...$group['elements']);
        }
        return $elements;
    }

    /** Declared parameters own their names before any generated controls. */
    private static function fieldOwners(array $groups, array $values, ?Extensions $extensions): array
    {
        $owners = [];
        foreach ($groups as $index => $group) foreach ($group['elements'] as $element) $owners[$element['parameter']] = $index;
        foreach ($groups as $index => $group) foreach ($group['elements'] as $element) {
            $type = $element['type'];
            if ($extensions !== null) {
                try { $type = $extensions->parameterBase($type); }
                catch (HttpError) { }
            }
            foreach (self::fieldKeys($element, $type, $values) as $key) $owners[$key] ??= $index;
        }
        return $owners;
    }

    /** Exact native controls; a shared spelling prefix does not imply ownership. */
    private static function fieldKeys(array $element, string $type, array $values): array
    {
        $parameter = $element['parameter'];
        $suffixes = match ($type) {
            'checkbox-selection' => self::choiceValues($element['options'] ?? ''),
            'interval' => ['min', 'max', 'step'],
            'ratio' => ['start', 'end', 'step'],
            'percentage' => ['percentage'],
            'percentage-dep' => ['percentage', 'dependency', 'type'],
            'percentage-interval' => ['start', 'end', 'step', 'percentage-start', 'percentage-end', 'percentage-step', 'dependency', 'type'],
            'software-versions-list' => ['numberOfVersions'],
            default => [],
        };
        $keys = array_map(static fn(string $suffix): string => $parameter . '-' . $suffix, $suffixes);
        if ($type === 'software-versions-list') foreach (array_keys($values) as $key) {
            if (preg_match('/^' . preg_quote($parameter, '/') . '-version-(?:0|[1-9][0-9]{0,2})$/D', $key)) $keys[] = $key;
        }
        if (!in_array($type, ['checkbox-selection', 'interval', 'ratio', 'percentage-dep', 'percentage-interval', 'software-versions-list'], true)) array_unshift($keys, $parameter);
        return $keys;
    }

    private static function scalar(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : '');
    }

    /** Validate agent-bound text without normalizing its value. */
    public static function parameterText(mixed $value, string $field, int $max, bool $required = false): string
    {
        // Reuse text safety checks while keeping the submitted parameter bytes.
        // Metadata intentionally uses Input::text's normal trimming instead.
        Input::text($value, $field, $max, $required);
        if (mb_strlen($value, 'UTF-8') > $max) Input::invalid($field, 'This parameter value is too long.');
        return $value;
    }

    private static function number(mixed $value, string $field, bool $integer = false): float|int
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || !is_numeric($value)
            || !is_finite((float) $value) || abs((float) $value) > 1e12 || ($integer && (float) $value !== (float) (int) $value)) {
            Input::invalid('parameters.' . $field, 'Enter a finite' . ($integer ? ' whole' : '') . ' number.');
        }
        return $integer ? (int) $value : (float) $value;
    }

    private static function rangeFactor(array &$data, string $parameter, string $first, string $last, int $limit, bool $integer = true): int
    {
        $start = self::number($data[$parameter . '-' . $first] ?? null, $parameter . '-' . $first, $integer);
        $end = self::number($data[$parameter . '-' . $last] ?? null, $parameter . '-' . $last, $integer);
        $step = self::number($data[$parameter . '-step'] ?? null, $parameter . '-step', $integer);
        if ($start !== $end && ($step == 0 || ($end > $start && $step < 0) || ($end < $start && $step > 0))) {
            Input::invalid('parameters.' . $parameter . '-step', 'Choose a nonzero step toward the end value.');
        }
        if ($start === $end) $step = 1;
        if ($start > $end) { [$start, $end] = [$end, $start]; $step = abs($step); }
        try { $factor = PercentageIntervalProcessor::rangeCount($start, $end, $step); }
        catch (\InvalidArgumentException) { Input::invalid('parameters', 'This interval exceeds the maximum number of jobs.'); }
        if ($factor < 1 || $factor > $limit) Input::invalid('parameters', 'This interval exceeds the maximum number of jobs.');
        $data[$parameter . '-' . $first] = $start;
        $data[$parameter . '-' . $last] = $end;
        $data[$parameter . '-step'] = $step;
        return $factor;
    }

    /** Read original v2 flat experiments without rewriting their stored data. */
    public static function legacyElements(array $data): array
    {
        $ids = $data['elements'] ?? [];
        if (!is_array($ids) || !array_is_list($ids)) Input::invalid('parameters', 'The saved experiment contains an invalid element list.');
        $elements = [];
        foreach ($ids as $id) {
            if (!is_string($id)) Input::invalid('parameters', 'The saved experiment contains an invalid element ID.');
            $parameter = $data[$id . '-parameter'] ?? null;
            $elements[] = ['id' => $id, 'type' => $data[$id . '-type'] ?? null, 'parameter' => $parameter,
                'dependency' => is_string($parameter) ? ($data[$parameter . '-dependency'] ?? null) : null,
                'parameter-type' => is_string($parameter) ? ($data[$parameter . '-type'] ?? 'int') : 'int'];
        }
        return $elements;
    }

    public static function configurations(string $root, array $data, int $limit, ?Extensions $extensions = null): array
    {
        $elements = self::legacyElements($data);
        foreach ($elements as &$element) {
            // Original v2 ordinary percentages saved the computed absolute text,
            // without typed source metadata. They use the same scalar processor.
            if ($element['type'] === 'percentage') $element['type'] = 'string';
        }
        unset($element);
        return self::generate($elements, $data,
            ['runs' => $data['runs'] ?? 1, 'distribution' => $data['run-distribution'] ?? 'alter'], $limit, $extensions);
    }

    private static function entries(array $elements, ?Extensions $extensions): array
    {
        $result = [];
        foreach ($elements as $element) {
            $original = $element['type'] ?? null;
            $type = is_string($original) ? ($extensions?->parameterBase($original) ?? $original) : null;
            $parameter = $element['parameter'] ?? null;
            if (!is_string($type) || !in_array($type, self::TYPES, true) || !is_string($parameter) || $parameter === '') {
                Input::invalid('parameters', 'The experiment contains an unsupported parameter.');
            }
            $result[] = ['type' => $type, 'customType' => in_array($original, self::TYPES, true) ? null : $original,
                'parameter' => $parameter,
                'definition' => $element];
        }
        return $result;
    }

    /** Resolve percentages from trusted active definitions, never a submitted intermediate preview. */
    private static function derivedValues(array $entries, array $values): array
    {
        $owners = [];
        foreach ($entries as $index => $entry) foreach (explode(',', $entry['parameter']) as $parameter) $owners[$parameter] = $index;
        $completed = $visiting = [];
        $derive = function (int $index) use (&$derive, &$values, &$completed, &$visiting, $owners, $entries): void {
            if (isset($completed[$index])) return;
            if (isset($visiting[$index])) Input::invalid('parameters', 'Percentage dependencies must not contain a cycle.');
            $entry = $entries[$index];
            $type = $entry['type'];
            if (!in_array($type, ['percentage', 'percentage-interval'], true)) { $completed[$index] = true; return; }
            $visiting[$index] = true;
            $parameter = $entry['parameter'];
            $dependency = $entry['definition']['dependency'] ?? null;
            if (!is_string($dependency) || !isset($owners[$dependency])) Input::invalid('parameters.' . $parameter, 'The percentage dependency must belong to an active parameter group.');
            $owner = $owners[$dependency];
            if (!in_array($entries[$owner]['type'], ['integer', 'string', 'dropdown', 'percentage'], true)) Input::invalid('parameters.' . $parameter, 'Use a scalar numeric dependency for this percentage.');
            $derive($owner);
            $base = self::number($values[$dependency] ?? null, $parameter . ' dependency');
            $convert = static function (mixed $value) use ($base, $parameter, $entry): float|int {
                $result = $base * self::number($value, $parameter . ' percentage') / 100;
                return ($entry['definition']['parameter-type'] ?? 'int') === 'float' ? $result : (int) floor($result);
            };
            if ($type === 'percentage') $values[$parameter] = $convert($values[$parameter . '-percentage'] ?? null);
            // Interval bounds are calculated in the processor's private data.
            // Their preview spellings may also be legal unrelated inputs.
            unset($visiting[$index]); $completed[$index] = true;
        };
        foreach (array_keys($entries) as $index) $derive($index);
        return $values;
    }

    /** Depending percentages execute after their source while independent sweep order stays unchanged. */
    private static function orderedSingles(array $entries): array
    {
        $owners = [];
        foreach ($entries as $index => $entry) foreach (explode(',', $entry['parameter']) as $parameter) $owners[$parameter] = $index;
        $result = $completed = $visiting = [];
        $visit = function (int $index) use (&$visit, &$result, &$completed, &$visiting, $entries, $owners): void {
            if (isset($completed[$index])) return;
            if (isset($visiting[$index])) Input::invalid('parameters', 'Percentage dependencies must not contain a cycle.');
            $visiting[$index] = true; $entry = $entries[$index];
            if ($entry['type'] === 'percentage-dep') {
                $dependency = $entry['definition']['dependency'] ?? null;
                if (is_string($dependency) && isset($owners[$dependency])) $visit($owners[$dependency]);
            }
            unset($visiting[$index]); $completed[$index] = true; $result[] = $entry;
        };
        foreach (array_keys($entries) as $index) $visit($index);
        return $result;
    }

    private static function processorData(array $entry, array $values): array
    {
        $parameter = $entry['parameter']; $type = $entry['type']; $data = $values;
        if (str_starts_with($type, 'percentage')) {
            $dependency = $entry['definition']['dependency'] ?? null;
            if (!is_string($dependency) || $dependency === '') Input::invalid('parameters', 'A percentage dependency is missing.');
            $data[$parameter . '-dependency'] = $dependency;
            $data['dependency-' . $dependency] = $parameter;
            $data[$parameter . '-type'] = ($entry['definition']['parameter-type'] ?? 'int') === 'float' ? 'float' : 'int';
        }
        if ($type === 'percentage-interval') {
            $base = self::number($values[$entry['definition']['dependency']] ?? null, $parameter . ' dependency');
            foreach (['start', 'end', 'step'] as $suffix) {
                $result = $base * self::number($values[$parameter . '-percentage-' . $suffix] ?? null, $parameter . ' percentage') / 100;
                $data[$parameter . '-' . $suffix] = ($entry['definition']['parameter-type'] ?? 'int') === 'float' ? $result : (int) floor($result);
            }
        }
        if ($type === 'checkbox-selection' && array_key_exists('options', $entry['definition'])) {
            foreach (array_keys($data) as $key) if (str_starts_with($key, $parameter . '-')) unset($data[$key]);
            foreach (self::choiceValues($entry['definition']['options']) as $option) {
                $value = $values[$parameter . '-' . $option] ?? false;
                if ($value === true || $value === 'on') $data[$parameter . '-' . $option] = 'on';
            }
        }
        return $data;
    }

    private static function process(array $entry, array $data, array &$configurations, int $limit, ?Extensions $extensions): void
    {
        try {
            if ($entry['customType'] !== null) $extensions->processParameter($entry['customType'], $data, $entry['parameter'], $configurations, $limit);
            else BuiltinParameters::process($entry['type'], $data, $entry['parameter'], $configurations);
        } catch (HttpError $error) { throw $error; }
        catch (\Exception) { Input::invalid('parameters.' . $entry['parameter'], 'This parameter configuration cannot generate jobs.'); }
    }

    /** Bound each Cartesian expansion before invoking a built-in or explicitly trusted processor. */
    private static function generate(array $elements, array $values, array $execution, int $limit, ?Extensions $extensions): array
    {
        $limit = max(1, min($limit, 100000));
        $runs = Input::integer($execution['runs'] ?? 1, 'runs', 1, $limit);
        $distribution = $execution['distribution'] ?? 'alter';
        if (!in_array($distribution, ['order', 'alter', 'rand'], true)) Input::invalid('distribution', 'Choose a supported run distribution.');
        $entries = self::entries($elements, $extensions);
        $values = self::derivedValues($entries, $values);
        $singles = $multiples = [];
        foreach ($entries as $entry) {
            if (in_array($entry['type'], ['boolean', 'dropdown', 'integer', 'percentage', 'percentage-dep', 'string'], true)) $singles[] = $entry;
            else $multiples[] = $entry;
        }
        $repeat = static function (array $configurations, int $count): array {
            $result = [];
            for ($run = 0; $run < $count; $run++) foreach ($configurations as $configuration) { $configuration['parameters']['run'] = $run; $result[] = $configuration; }
            return $result;
        };
        $configurations = [['parameters' => []]];
        if ($distribution === 'order') $configurations = $repeat($configurations, $runs);
        foreach ($multiples as $entry) {
            $parameter = $entry['parameter']; $type = $entry['type']; $data = self::processorData($entry, $values); $factor = 1;
            switch ($type) {
                case 'interval': $factor = self::rangeFactor($data, $parameter, 'min', 'max', $limit); break;
                case 'percentage-interval':
                    $factor = isset($configurations[0][($data[$parameter . '-dependency'] ?? '') . '-step']) ? 1
                        : self::rangeFactor($data, $parameter, 'start', 'end', $limit, $data[$parameter . '-type'] !== 'float'); break;
                case 'string-list': $data[$parameter] = self::parameterText($data[$parameter] ?? null, 'parameters.' . $parameter, 16384); $factor = count(explode(',', $data[$parameter])); break;
                case 'checkbox-selection': $factor = 0; foreach ($data as $key => $value) if (str_starts_with($key, $parameter . '-') && $value === 'on') $factor++; break;
                case 'software-versions-list':
                    $factor = Input::integer($data[$parameter . '-numberOfVersions'] ?? null, 'parameters.' . $parameter, 1, 1000);
                    for ($index = 0; $index < $factor; $index++) $data[$parameter . '-version-' . $index] = self::parameterText($data[$parameter . '-version-' . $index] ?? null, 'parameters.' . $parameter, 256, true);
                    break;
                case 'ratio':
                    $start = explode(':', str_replace('%', '', self::scalar($data[$parameter . '-start'] ?? '')));
                    $end = explode(':', str_replace('%', '', self::scalar($data[$parameter . '-end'] ?? '')));
                    if (count($start) !== 2 || count($end) !== 2 || count(explode(',', $parameter)) !== 2) Input::invalid('parameters.' . $parameter, 'Enter two percentages totalling 100.');
                    $a = self::number($start[0], $parameter, true); $b = self::number($start[1], $parameter, true);
                    $c = self::number($end[0], $parameter, true); $d = self::number($end[1], $parameter, true);
                    $step = self::number(str_replace('%', '', self::scalar($data[$parameter . '-step'] ?? '')), $parameter, true);
                    if ($a + $b !== 100 || $c + $d !== 100 || min($a, $b, $c, $d) < 0 || $step <= 0 || $step > 100 || $c < $a) Input::invalid('parameters.' . $parameter, 'Enter an increasing ratio range with a positive step.');
                    $factor = (int) floor(($c - $a) / $step) + 1;
                    break;
            }
            if ($factor < 1) Input::invalid('parameters.' . $parameter, 'Select at least one value.');
            $reserve = $distribution === 'order' ? 1 : $runs;
            if ($factor > intdiv($limit, max(1, count($configurations) * $reserve))) Input::invalid('parameters', 'This evaluation exceeds the configured maximum number of jobs.');
            self::process($entry, $data, $configurations, $limit, $extensions);
            if (count($configurations) > $limit) Input::invalid('parameters', 'This evaluation exceeds the configured maximum number of jobs.');
        }
        foreach (self::orderedSingles($singles) as $entry) {
            $parameter = $entry['parameter']; $data = self::processorData($entry, $values);
            if ($entry['type'] === 'dropdown' && array_key_exists('options', $entry['definition'])
                && !in_array(self::scalar($values[$parameter] ?? ''), self::choiceValues($entry['definition']['options']), true)) Input::invalid('parameters.' . $parameter, 'Choose an available option.');
            if ($entry['type'] === 'integer') $data[$parameter] = self::number($data[$parameter] ?? null, $parameter, true);
            elseif ($entry['type'] === 'boolean') {
                $value = self::scalar($data[$parameter] ?? '');
                if (!in_array($value, ['true', 'false', '1', '0'], true)) Input::invalid('parameters.' . $parameter, 'Choose true or false.');
                $data[$parameter] = in_array($value, ['true', '1'], true) ? 'true' : 'false';
            } elseif ($entry['type'] === 'percentage-dep') self::number($data[$parameter . '-percentage'] ?? null, $parameter);
            else $data[$parameter] = self::parameterText(self::scalar($data[$parameter] ?? ''), 'parameters.' . $parameter, 16384);
            self::process($entry, $data, $configurations, $limit, $extensions);
        }
        if ($distribution !== 'order') { $configurations = $repeat($configurations, $runs); if ($distribution === 'rand') shuffle($configurations); }
        if ($configurations === [] || count($configurations) > $limit) Input::invalid('parameters', 'Choose at least one job within the configured limit.');
        return $configurations;
    }
}
