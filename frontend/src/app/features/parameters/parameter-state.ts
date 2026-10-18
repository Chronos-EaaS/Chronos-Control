import { PARAMETER_TYPES, ParameterChoice, ParameterElement, ParameterGroup, ParameterIssue, ParameterValidation, ParameterValues, ParameterTypeAliases } from '../../core/parameter-models';

export const DEFAULT_VERSION_INPUT_LIMIT = 1000;
let sequence = 0;
export function newParameterId(prefix = 'field'): string {
  const suffix = globalThis.crypto?.randomUUID?.() ?? `${Date.now().toString(36)}-${++sequence}`;
  return `${prefix}-${suffix}`;
}
export function domNamespace(): string { return `chronos-parameters-${++sequence}`; }
export function valueText(value: unknown): string { return value === null || value === undefined ? '' : String(value); }
export function knownParameterType(type: string): boolean { return PARAMETER_TYPES.some(item => item.type === type); }
/** Resolve only one explicit server alias to a bundled native input. */
export function parameterRenderType(type: string, aliases: ParameterTypeAliases = {}): string {
  return knownParameterType(type) ? type : Object.hasOwn(aliases, type) && knownParameterType(aliases[type]) ? aliases[type] : type;
}
export function parameterRenderGroups(groups: ParameterGroup[], aliases: ParameterTypeAliases = {}): ParameterGroup[] {
  return groups.map(group => ({ ...group, elements: group.elements.map(element => {
    const type = parameterRenderType(element.type, aliases);
    return type === element.type ? element : { ...element, type };
  }) }));
}
export function csv(value: unknown): string[] {
  return Array.isArray(value) ? value.map(valueText) : value === undefined || value === null || value === '' ? [] : String(value).split(',');
}
export function choices(element: ParameterElement): ParameterChoice[] {
  const labels = csv(element.names);
  return csv(element.options).map((value, index) => ({ value, label: labels[index] ?? value }));
}
export function numeric(value: unknown): number | null {
  if (typeof value !== 'string' && typeof value !== 'number') return null;
  if (typeof value === 'string' && !/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/.test(value.trim())) return null;
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}
function put(target: ParameterValues, key: string, value: unknown): void {
  // A parameter named __proto__ must never invoke a JavaScript prototype setter.
  Object.defineProperty(target, key, { value, writable: true, enumerable: true, configurable: true });
}
const own = (object: object, key: string): boolean => Object.hasOwn(object, key);
const percentTypes = new Set(['percentage', 'percentage-interval', 'percentage-dep']);
// Early XML syntax feedback; the server's native XML builder remains authoritative.
const xmlBmpNameStart = 'A-Z_a-z\\u00C0-\\u00D6\\u00D8-\\u00F6\\u00F8-\\u02FF\\u0370-\\u037D\\u037F-\\u1FFF'
  + '\\u200C-\\u200D\\u2070-\\u218F\\u2C00-\\u2FEF\\u3001-\\uD7FF\\uF900-\\uFDCF\\uFDF0-\\uFFFD';
const xmlBmpName = new RegExp(`^[${xmlBmpNameStart}][${xmlBmpNameStart}0-9.\\-\\u00B7\\u0300-\\u036F\\u203F-\\u2040]*$`, 'u');

export function validateParameterSchema(groups: ParameterGroup[]): ParameterValidation {
  const issues: ParameterIssue[] = [];
  const ids = new Set<string>();
  const owners = new Map<string, { group: ParameterGroup; element: ParameterElement }>();
  const issue = (code: string, message: string, group: ParameterGroup, element?: ParameterElement, field?: string) =>
    issues.push({ code, message, severity: 'error', groupId: group.id, elementId: element?.id, field });
  const checkId = (id: string, group: ParameterGroup, element?: ParameterElement) => {
    if (!/^[A-Za-z0-9][A-Za-z0-9_-]*$/.test(id)) issue('invalid-id', 'IDs must contain only letters, digits, hyphens or underscores.', group, element, 'id');
    if (ids.has(id)) issue('duplicate-id', `The ID “${id}” is used more than once.`, group, element, 'id');
    ids.add(id);
  };
  for (const group of groups) {
    checkId(group.id, group);
    if (!group.title.trim()) issue('group-title', 'Enter a group title.', group, undefined, 'title');
    for (const element of group.elements) {
      checkId(element.id, group, element);
      if (!knownParameterType(element.type)) issue('unsupported-type', `The parameter type “${element.type}” needs a registered renderer. This schema cannot be submitted safely.`, group, element, 'type');
      if (!element.name.trim()) issue('element-name', 'Enter a parameter label.', group, element, 'name');
      const names = element.type === 'ratio' ? element.parameter.split(',') : [element.parameter];
      if (element.type === 'ratio' && (names.length !== 2 || element.parameter.split(',').some(name => name !== name.trim()))) issue('ratio-parameters', 'A ratio needs exactly two comma-separated parameter names.', group, element, 'parameter');
      for (const name of names) {
        if (name !== name.trim() || !/^[\p{L}_][\p{L}\p{N}_.-]*$/u.test(name) || !xmlBmpName.test(name)) issue('parameter-name', 'Use a parameter name beginning with a letter or underscore, without spaces or XML punctuation.', group, element, 'parameter');
        if (name === 'run' || name === 'elements') issue('reserved-parameter', `“${name}” is reserved for experiment metadata.`, group, element, 'parameter');
        if (owners.has(name)) issue('duplicate-parameter', `The parameter “${name}” is already defined.`, group, element, 'parameter');
        owners.set(name, { group, element });
      }
      if (['dropdown', 'checkbox-selection'].includes(element.type)) {
        const options = choices(element);
        if (!options.length || options.some(option => option.value.trim() === '')) issue('choices-empty', 'Provide non-empty comma-separated choice values.', group, element, 'options');
        if (new Set(options.map(option => option.value)).size !== options.length) issue('choices-duplicate', 'Choice values must be unique.', group, element, 'options');
        if (element.type === 'dropdown' && csv(element.names).length && csv(element.names).length !== options.length) issue('choice-labels', 'Provide one display name per choice value, in the same order.', group, element, 'names');
      }
      if (percentTypes.has(element.type) && !element.dependency?.trim()) issue('dependency-required', 'Select the parameter used by this percentage.', group, element, 'dependency');
    }
  }
  for (const group of groups) for (const element of group.elements) {
    if (!percentTypes.has(element.type) || !element.dependency) continue;
    const visited = new Set<string>([element.parameter]);
    let dependency: string | undefined = element.dependency;
    while (dependency) {
      if (visited.has(dependency)) { issue('dependency-cycle', 'Percentage dependencies must not form a cycle.', group, element, 'dependency'); break; }
      visited.add(dependency);
      const owner = owners.get(dependency);
      if (!owner) { issue('dependency-missing', `The dependency “${dependency}” is not defined.`, group, element, 'dependency'); break; }
      dependency = percentTypes.has(owner.element.type) ? owner.element.dependency : undefined;
    }
  }
  const visibilityOwners = new Map(owners);
  for (const group of groups) for (const element of group.elements) {
    for (const key of elementValueKeys(element, {})) if (!visibilityOwners.has(key)) visibilityOwners.set(key, { group, element });
  }
  const visibilityOwner = (key: string) => visibilityOwners.get(key) ?? Array.from(owners.values()).find(owner => owner.element.type === 'software-versions-list' && key.startsWith(`${owner.element.parameter}-version-`));
  for (const group of groups) {
    const visited = new Set<string>([group.id]);
    let dependency = group.depends;
    while (dependency) {
      const owner = visibilityOwner(dependency);
      if (!owner) break; // May refer to a parent form input, such as runs.
      if (visited.has(owner.group.id)) { issue('group-cycle', 'Group visibility must not depend on itself or form a cycle.', group, undefined, 'depends'); break; }
      visited.add(owner.group.id); dependency = owner.group.depends;
    }
  }
  return { valid: !issues.some(item => item.severity === 'error'), issues };
}

/** Calculated controls are a view of one input, independent of user parameters. */
export function percentageIntervalPreview(element: ParameterElement, values: ParameterValues): ParameterValues {
  const base = numeric(values[element.dependency ?? '']);
  return Object.fromEntries(['start', 'end', 'step'].map(suffix => {
    const percent = numeric(values[`${element.parameter}-percentage-${suffix}`]);
    const absolute = base === null || percent === null ? NaN : base * percent / 100;
    return [`${element.parameter}-${suffix}`, Number.isFinite(absolute) ? element['parameter-type'] === 'float' ? absolute : Math.floor(absolute) : ''];
  }));
}

/** Preserve saved 0, false and empty strings by presence, never by truthiness. */
export function prepareExperimentValues(groups: ParameterGroup[], input: ParameterValues, maxVersions = DEFAULT_VERSION_INPUT_LIMIT): ParameterValues {
  const values: ParameterValues = { ...input };
  const legacyElements = new Set(Array.isArray(input['elements']) ? input['elements'].filter((id): id is string => typeof id === 'string') : []);
  for (const group of groups) for (const element of group.elements) {
    const name = element.parameter;
    // Descriptor fields belong to v2's explicit elements list. A modern user
    // parameter may legally have the same spelling as one of those fields.
    const copied = legacyElements.has(element.id);
    const previousName = copied ? valueText(input[`${element.id}-parameter`]) || name : name;
    const read = (suffix: string, fallback: unknown) => {
      const key = name + suffix, previousKey = previousName + suffix;
      if (own(input, key)) return input[key];
      return own(input, previousKey) ? input[previousKey] : fallback;
    };
    const set = (suffix: string, fallback: unknown) => put(values, name + suffix, read(suffix, fallback));
    switch (element.type) {
      case 'checkbox-selection': {
        const defaults = csv(element.default);
        for (const option of choices(element)) set(`-${option.value}`, copied ? false : defaults.includes(option.value) ? 'on' : false);
        break;
      }
      case 'software-versions-list': {
        const legacy = csv(input[previousName]);
        const existing = Object.keys(input).filter(key => key.startsWith(`${previousName}-version-`)).length;
        set('-numberOfVersions', existing || legacy.length || 1);
        const count = numeric(values[`${name}-numberOfVersions`]);
        // Rendering limits are enforced separately; never allocate an unbounded loop.
        if (count !== null && Number.isInteger(count) && count > 0 && count <= maxVersions) {
          for (let index = 0; index < count; index++) set(`-version-${index}`, legacy[index] ?? '');
        }
        break;
      }
      case 'interval':
        set('-min', element['default-min'] ?? ''); set('-max', element['default-max'] ?? ''); set('-step', element['default-step'] ?? ''); break;
      case 'ratio':
        set('-start', element['default-start'] ?? ''); set('-end', element['default-end'] ?? ''); set('-step', element['default-step'] ?? ''); break;
      case 'percentage-interval':
        set('-percentage-start', element['default-start'] ?? ''); set('-percentage-end', element['default-end'] ?? ''); set('-percentage-step', element['default-step'] ?? ''); break;
      case 'percentage': case 'percentage-dep': set('-percentage', element.default ?? ''); break;
      case 'boolean': set('', element.default ?? 'false'); break;
      case 'dropdown': {
        const available = choices(element);
        const initial = valueText(element.default);
        const selected = available.find(option => option.value === initial || option.label === initial)?.value ?? available[0]?.value ?? '';
        set('', selected); break;
      }
      default: set('', element.default ?? '');
    }
  }
  const elements = new Map(groups.flatMap(group => group.elements.map(element => [element.parameter, element] as const)));
  const completed = new Set<string>(), visiting = new Set<string>();
  const derive = (element: ParameterElement): void => {
    if (completed.has(element.id) || visiting.has(element.id)) return;
    visiting.add(element.id);
    const dependency = elements.get(element.dependency ?? '');
    if (dependency) derive(dependency);
    const base = numeric(values[element.dependency ?? '']);
    const integer = element['parameter-type'] !== 'float';
    const convert = (percentage: unknown) => {
      const percent = numeric(percentage);
      if (base === null || percent === null) return '';
      const calculated = base * percent / 100;
      return Number.isFinite(calculated) ? integer ? Math.floor(calculated) : calculated : '';
    };
    if (element.type === 'percentage') put(values, element.parameter, convert(values[`${element.parameter}-percentage`]));
    if (element.type === 'percentage-interval') for (const [key, value] of Object.entries(percentageIntervalPreview(element, values))) {
      if (!elements.has(key)) put(values, key, value);
    }
    visiting.delete(element.id); completed.add(element.id);
  };
  for (const group of groups) for (const element of group.elements) derive(element);
  return values;
}

export function groupVisibility(groups: ParameterGroup[], values: ParameterValues, context: Readonly<ParameterValues> = {}): boolean[] {
  const owner = new Map<string, number>();
  groups.forEach((group, index) => group.elements.forEach(element => owner.set(element.parameter, index)));
  groups.forEach((group, index) => group.elements.forEach(element => {
    for (const key of elementValueKeys(element, values)) if (!owner.has(key)) owner.set(key, index);
  }));
  const result = new Map<number, boolean>(), visiting = new Set<number>();
  const visible = (index: number): boolean => {
    if (result.has(index)) return result.get(index)!;
    if (visiting.has(index)) return false;
    visiting.add(index);
    const group = groups[index];
    const dependencyOwner = owner.get(group.depends);
    // Parent execution controls are a separate read-only namespace. A real
    // parameter with the same name always owns its dependency value.
    const source = dependencyOwner !== undefined || !own(context, group.depends) ? values : context;
    const shown = !group.depends || ((dependencyOwner === undefined || visible(dependencyOwner))
      && own(source, group.depends) && valueText(source[group.depends]) === valueText(group.dependsValue));
    visiting.delete(index); result.set(index, shown); return shown;
  };
  return groups.map((_, index) => visible(index));
}

export function elementValueKeys(element: ParameterElement, values: ParameterValues): string[] {
  const p = element.parameter;
  switch (element.type) {
    case 'checkbox-selection': return choices(element).map(option => `${p}-${option.value}`);
    case 'software-versions-list': return [`${p}-numberOfVersions`, ...Object.keys(values).filter(key => key.startsWith(`${p}-version-`))];
    case 'interval': return ['min', 'max', 'step'].map(suffix => `${p}-${suffix}`);
    case 'ratio': return ['start', 'end', 'step'].map(suffix => `${p}-${suffix}`);
    case 'percentage': return [p, `${p}-percentage`];
    case 'percentage-dep': return [`${p}-percentage`, `${p}-dependency`, `${p}-type`];
    case 'percentage-interval': return ['start', 'end', 'step'].flatMap(suffix => [`${p}-${suffix}`, `${p}-percentage-${suffix}`]).concat(`${p}-dependency`);
    default: return [p];
  }
}

/** Flat legacy field names; group/element JSON and unrelated parent form fields stay intact. */
export function experimentSubmission(groups: ParameterGroup[], input: ParameterValues, maxVersions = DEFAULT_VERSION_INPUT_LIMIT, context: Readonly<ParameterValues> = {}): ParameterValues {
  const values = prepareExperimentValues(groups, input, maxVersions), visible = groupVisibility(groups, values, context);
  const payload: ParameterValues = { ...values, elements: [] };
  for (const group of groups) for (const element of group.elements) {
    for (const key of elementValueKeys(element, values)) delete payload[key];
    delete payload[`${element.id}-type`]; delete payload[`${element.id}-parameter`];
    if (element.dependency) delete payload[`dependency-${element.dependency}`];
  }
  groups.forEach((group, groupIndex) => {
    if (!visible[groupIndex]) return;
    for (const element of group.elements) {
      (payload['elements'] as string[]).push(element.id);
      put(payload, `${element.id}-type`, element.type); put(payload, `${element.id}-parameter`, element.parameter);
      for (const key of elementValueKeys(element, values)) {
        if (element.type === 'checkbox-selection' && values[key] !== 'on' && values[key] !== true) continue;
        if (element.type === 'software-versions-list' && key.includes('-version-')) {
          const index = Number(key.slice(key.lastIndexOf('-') + 1));
          if (index >= Number(values[`${element.parameter}-numberOfVersions`])) continue;
        }
        if (own(values, key)) put(payload, key, values[key]);
      }
      if (percentTypes.has(element.type) && element.dependency) {
        put(payload, `dependency-${element.dependency}`, element.parameter);
        if (element.type !== 'percentage') put(payload, `${element.parameter}-dependency`, element.dependency);
        if (element.type === 'percentage-dep') put(payload, `${element.parameter}-type`, element['parameter-type'] ?? 'int');
      }
    }
  });
  return payload;
}

export function dependingPercentagePreview(element: ParameterElement, values: ParameterValues): { label: string; value: number | string }[] {
  const base = element.dependency ?? '', percent = numeric(values[`${element.parameter}-percentage`]);
  const convert = (value: unknown): number | string => {
    const n = numeric(value);
    if (n === null || percent === null) return '';
    const result = n * percent / 100;
    return Number.isFinite(result) ? element['parameter-type'] === 'float' ? result : Math.floor(result) : '';
  };
  if (own(values, `${base}-start`) || own(values, `${base}-min`)) return [
    { label: 'Start', value: convert(values[`${base}-start`] ?? values[`${base}-min`]) },
    { label: 'End', value: convert(values[`${base}-end`] ?? values[`${base}-max`]) },
    { label: 'Step', value: convert(values[`${base}-step`]) },
  ];
  return [{ label: 'Absolute value', value: convert(values[base]) }];
}

export function validateExperimentParameters(groups: ParameterGroup[], input: ParameterValues, maxVersions = DEFAULT_VERSION_INPUT_LIMIT, context: Readonly<ParameterValues> = {}): ParameterValidation {
  const base = validateParameterSchema(groups);
  const issues = [...base.issues], values = prepareExperimentValues(groups, input, maxVersions), visible = groupVisibility(groups, values, context);
  const declaredFields = new Set(groups.flatMap(group => group.elements.flatMap(element => [
    ...element.parameter.split(','), ...elementValueKeys(element, values),
  ])));
  const add = (element: ParameterElement, group: ParameterGroup, field: string, message: string, severity: 'error' | 'warning' = 'error') =>
    issues.push({ code: 'parameter-value', message, severity, groupId: group.id, elementId: element.id, field });
  const totals = new Map<string, { sum: number; group: ParameterGroup; element: ParameterElement }>();
  groups.forEach((group, index) => {
    if (!visible[index]) return;
    for (const element of group.elements) {
      const p = element.parameter, n = (suffix = '') => numeric(values[p + suffix]);
      switch (element.type) {
        case 'integer': if (valueText(values[p]) !== '' && (n() === null || !Number.isInteger(n()))) add(element, group, p, 'Enter a whole number.'); break;
        case 'boolean': if (!['true', 'false'].includes(valueText(values[p]))) add(element, group, p, 'Select True or False.'); break;
        case 'dropdown': if (!choices(element).some(option => option.value === valueText(values[p]))) add(element, group, p, 'Select an available choice.'); break;
        case 'checkbox-selection': {
          const available = choices(element).map(option => `${p}-${option.value}`);
          if (!available.some(key => values[key] === 'on' || values[key] === true)) add(element, group, p, 'Select at least one value.');
          // A shared prefix does not turn another declared input into a choice.
          if (Object.keys(values).some(key => key.startsWith(`${p}-`) && !available.includes(key) && !declaredFields.has(key) && (values[key] === 'on' || values[key] === true))) add(element, group, p, 'A saved selection is no longer available. Review the available choices before continuing.');
          break;
        }
        case 'interval': {
          const min = n('-min'), max = n('-max'), step = n('-step');
          if (min === null || max === null || step === null || ![min, max, step].every(Number.isInteger) || min > max || (min !== max && step <= 0)) add(element, group, p, 'Enter whole-number bounds with minimum ≤ maximum and a positive step (zero is allowed for equal bounds).');
          break;
        }
        case 'ratio': {
          const parse = (suffix: string) => valueText(values[p + suffix]).replaceAll('%', '').split(':').map(value => numeric(value) ?? NaN);
          const start = parse('-start'), end = parse('-end'), step = numeric(valueText(values[`${p}-step`]).replaceAll('%', ''));
          if (![start, end].every(pair => pair.length === 2 && pair.every(value => Number.isInteger(value) && value >= 0 && value <= 100) && pair[0] + pair[1] === 100) || start[0] > end[0] || step === null || !Number.isInteger(step) || step <= 0 || step > 100) add(element, group, p, 'Use two complementary ratios totaling 100, increasing the left value, with a whole-number step from 1 to 100.');
          break;
        }
        case 'software-versions-list': {
          const count = n('-numberOfVersions');
          if (count === null || !Number.isInteger(count) || count < 1 || count > maxVersions) add(element, group, `${p}-numberOfVersions`, `Enter between 1 and ${maxVersions} versions.`);
          else for (let version = 0; version < count; version++) if (!valueText(values[`${p}-version-${version}`]).trim()) add(element, group, `${p}-version-${version}`, `Enter version ${version + 1}.`);
          break;
        }
      }
      if (percentTypes.has(element.type)) {
        const dependencyGroup = groups.findIndex(candidate => candidate.elements.some(field => field.parameter === element.dependency));
        if (dependencyGroup >= 0 && !visible[dependencyGroup]) add(element, group, p, 'This percentage depends on a parameter in an inactive group.');
        const suffixes = element.type === 'percentage-interval' ? ['-percentage-start', '-percentage-end', '-percentage-step'] : ['-percentage'];
        for (const suffix of suffixes) {
          const value = n(suffix);
          if (value === null || (!suffix.endsWith('-step') && value < 0)) add(element, group, p + suffix, 'Enter a finite percentage; start/end percentages must be non-negative.');
          if (value !== null && element.dependency && !suffix.endsWith('-step')) {
            const key = element.dependency + suffix;
            const total = totals.get(key);
            totals.set(key, { sum: (total?.sum ?? 0) + value, group, element });
          }
        }
        const previews = element.type === 'percentage-dep' ? dependingPercentagePreview(element, values) : [{ value: values[p] }];
        if (element.type !== 'percentage-interval' && previews.some(preview => preview.value === '')) add(element, group, p, 'The dependency needs a numeric value before this percentage can be calculated.');
        if (element.type === 'percentage-interval') {
          const preview = percentageIntervalPreview(element, values);
          const start = numeric(preview[`${p}-start`]), end = numeric(preview[`${p}-end`]), step = numeric(preview[`${p}-step`]);
          if (start === null || end === null || step === null || start !== end && (step === 0 || Math.sign(end - start) !== Math.sign(step))) add(element, group, p, 'The calculated interval needs a non-zero step directed from start toward end.');
        }
      }
    }
  });
  for (const { sum, group, element } of totals.values()) if (Math.abs(sum - 100) > 1e-8) add(element, group, element.parameter, `Percentages sharing this dependency total ${sum}%; review whether they should total 100%.`, 'warning');
  return { valid: !issues.some(issue => issue.severity === 'error'), issues };
}
