import { describe, expect, it } from 'vitest';
import { PARAMETER_TYPES, ParameterElement, ParameterGroup } from '../../core/parameter-models';
import { choices, dependingPercentagePreview, experimentSubmission, groupVisibility, newParameterId, prepareExperimentValues, validateExperimentParameters, validateParameterSchema } from './parameter-state';
import { elementSettings } from './parameter-schema-editor';

import { allBuiltinGroups, element, group } from './parameter-fixtures';

describe('parameter schema and experiment draft', () => {
  it('covers every built-in type and preserves English titles with spaces and Unicode', () => {
    const groups = allBuiltinGroups();
    expect(groups[0].elements.map(field => field.type).sort()).toEqual(PARAMETER_TYPES.map(kind => kind.type).sort());
    expect(validateParameterSchema(groups)).toEqual({ valid: true, issues: [] });
    expect(groups[0].title).toBe('Query plans with café & joins');
    const id = newParameterId('group');
    expect(id).toMatch(/^group-[A-Za-z0-9_-]+$/);
    expect(newParameterId('group')).not.toBe(id);
  });

  it('provides the legacy-specific settings for all twelve controls', () => {
    for (const kind of PARAMETER_TYPES) expect(elementSettings(kind.type).length).toBeGreaterThan(0);
    expect(elementSettings('interval').map(field => field.key)).toEqual(['default-min', 'default-max', 'default-step']);
    expect(elementSettings('percentage-interval').map(field => field.key)).toContain('default-start');
    expect(elementSettings('software-versions-list').map(field => field.key)).toEqual(['repository']);
  });

  it('copies numeric zero, false and an intentionally empty string without falling back', () => {
    const groups = [group([element('integer', 'n', { default: 99 }), element('boolean', 'flag', { default: true }), element('string', 'text', { default: 'fallback' })])];
    const saved = { n: 0, flag: false, text: '' };
    const values = prepareExperimentValues(groups, saved);
    expect(values['n']).toBe(0); expect(values['flag']).toBe(false); expect(values['text']).toBe('');
    expect(saved).toEqual({ n: 0, flag: false, text: '' });
  });

  it('retains zero and false defaults when no copied values exist', () => {
    const groups = [group([element('integer', 'n', { default: 0 }), element('boolean', 'flag', { default: false })])];
    expect(prepareExperimentValues(groups, {})).toMatchObject({ n: 0, flag: false });
    expect(validateExperimentParameters(groups, {}).valid).toBe(true);
  });

  it('copies a renamed field through its saved stable element ID', () => {
    const groups = [group([element('integer', 'new', { id: 'stable-id', default: 9 })])];
    expect(prepareExperimentValues(groups, { 'stable-id-parameter': 'old', old: 0 })['new']).toBe(0);
  });

  it('maps dropdown labels to values and uses a value label when names are absent', () => {
    expect(prepareExperimentValues(allBuiltinGroups(), {})['engine']).toBe('slow');
    expect(choices(element('dropdown', 'engine', { options: 'fast,slow' }))).toEqual([{ value: 'fast', label: 'fast' }, { value: 'slow', label: 'slow' }]);
  });

  it('does not restore default checkbox selections after an explicit clear or empty copy', () => {
    const field = element('checkbox-selection', 'choice', { options: 'red,blue', default: 'red' });
    const groups = [group([field])];
    expect(prepareExperimentValues(groups, {})['choice-red']).toBe('on');
    const cleared = prepareExperimentValues(groups, { 'choice-red': false });
    expect(cleared['choice-red']).toBe(false);
    expect(experimentSubmission(groups, cleared)).not.toHaveProperty('choice-red');
    expect(prepareExperimentValues(groups, { [`${field.id}-parameter`]: 'choice' })['choice-red']).toBe(false);
    expect(validateExperimentParameters(groups, cleared).valid).toBe(false);
  });

  it('migrates comma-separated version copies and keeps two controls independent', () => {
    const groups = [group([element('software-versions-list', 'client'), element('software-versions-list', 'server')])];
    const values = prepareExperimentValues(groups, { client: 'main,release/next', 'server-numberOfVersions': 1, 'server-version-0': 'v2' });
    expect(values).toMatchObject({ 'client-numberOfVersions': 2, 'client-version-0': 'main', 'client-version-1': 'release/next', 'server-version-0': 'v2' });
    const submission = experimentSubmission(groups, { ...values, 'client-numberOfVersions': 1 });
    expect(submission['client-version-0']).toBe('main');
    expect(submission).not.toHaveProperty('client-version-1');
    expect(submission['server-version-0']).toBe('v2');
  });

  it('calculates scalar and linked interval previews without generating jobs', () => {
    const groups = allBuiltinGroups();
    const values = prepareExperimentValues(groups, { total: 11 });
    expect(values['share']).toBe(2);
    expect([values['portion-start'], values['portion-end'], values['portion-step']]).toEqual([0, 11, 5]);
    const dependent = groups[0].elements.find(field => field.type === 'percentage-dep')!;
    expect(dependingPercentagePreview(dependent, values).map(item => item.value)).toEqual([0, 5.5, 2.5]);
    expect(values).not.toHaveProperty('jobs');
    expect(values).not.toHaveProperty('dependent-start');
  });

  it('propagates hidden/disabled dependency groups while retaining their drafts', () => {
    const groups = [group([element('boolean', 'enabled', { default: false })]),
      group([element('string', 'mode', { default: 'ready' })], { id: 'group-second', title: 'Second', depends: 'enabled', dependsValue: 'true' }),
      group([element('integer', 'size', { default: 0 })], { id: 'group-third', title: 'Third', depends: 'mode', dependsValue: 'ready' })];
    const hidden = prepareExperimentValues(groups, {});
    expect(groupVisibility(groups, hidden)).toEqual([true, false, false]);
    expect(hidden['size']).toBe(0);
    const payload = experimentSubmission(groups, hidden);
    expect(payload['elements']).toEqual(['field-enabled']);
    expect(payload).not.toHaveProperty('size'); expect(payload).not.toHaveProperty('mode');
    expect(groupVisibility(groups, prepareExperimentValues(groups, { ...hidden, enabled: true }))).toEqual([true, true, true]);
  });

  it('preserves parent form fields and emits legacy IDs, field names and percentage metadata', () => {
    const groups = allBuiltinGroups();
    const payload = experimentSubmission(groups, { name: 'Synthetic experiment', runs: 2, 'revision-version-0': 'main' });
    expect(payload['name']).toBe('Synthetic experiment'); expect(payload['runs']).toBe(2);
    expect(payload['elements']).toHaveLength(12);
    expect(payload['field-portion-type']).toBe('percentage-interval');
    expect(payload['field-portion-parameter']).toBe('portion');
    expect(payload['portion-dependency']).toBe('total');
    expect(payload['dependent-type']).toBe('float');
    expect(payload['reads,writes-start']).toBe('0:100');
  });

  it('validates all built-in defaults with a supplied software version', () => {
    const validation = validateExperimentParameters(allBuiltinGroups(), { 'revision-version-0': 'main' });
    expect(validation.valid).toBe(true);
    expect(validation.issues.every(issue => issue.severity === 'warning')).toBe(true);
  });

  it('rejects duplicate IDs/names, unresolved percentage dependencies and cycles', () => {
    const duplicate = [group([element('string', 'same'), element('integer', 'same')])];
    expect(validateParameterSchema(duplicate).issues.map(issue => issue.code)).toContain('duplicate-parameter');
    expect(validateParameterSchema(duplicate).issues.map(issue => issue.code)).toContain('duplicate-id');
    const missing = [group([element('percentage', 'part', { dependency: 'missing' })])];
    expect(validateParameterSchema(missing).valid).toBe(false);
    const cycle = [group([element('percentage', 'a', { dependency: 'b' }), element('percentage', 'b', { dependency: 'a' })])];
    expect(validateParameterSchema(cycle).issues.map(issue => issue.code)).toContain('dependency-cycle');
    expect(() => prepareExperimentValues(cycle, {})).not.toThrow();
  });

  it('rejects visibility cycles without recursion overflow', () => {
    const groups = [group([element('string', 'a')], { depends: 'b', dependsValue: 'x' }), group([element('string', 'b')], { id: 'group-b', depends: 'a', dependsValue: 'x' })];
    expect(validateParameterSchema(groups).issues.map(issue => issue.code)).toContain('group-cycle');
    expect(groupVisibility(groups, { a: 'x', b: 'x' })).toEqual([false, false]);
  });

  it('preserves an unknown extension definition and blocks unsafe submission', () => {
    const unknown = element('custom-image', 'image', { extension: { settings: ['keep', 0, false] } });
    const groups = [group([unknown])];
    const before = JSON.stringify(groups);
    expect(validateExperimentParameters(groups, { image: 'saved' }).valid).toBe(false);
    expect(validateParameterSchema(groups).issues.map(issue => issue.code)).toContain('unsupported-type');
    expect(JSON.stringify(groups)).toBe(before);
  });

  it('rejects unsafe intervals and invalid ratios rather than trying job expansion', () => {
    const interval = [group([element('interval', 'n')])];
    expect(validateExperimentParameters(interval, { 'n-min': 1, 'n-max': 5, 'n-step': -1 }).valid).toBe(false);
    expect(validateExperimentParameters(interval, { 'n-min': 0, 'n-max': 0, 'n-step': 0 }).valid).toBe(true);
    const ratio = [group([element('ratio', 'reads,writes')])];
    expect(validateExperimentParameters(ratio, { 'reads,writes-start': '0:100', 'reads,writes-end': '100:0', 'reads,writes-step': '25%' }).valid).toBe(true);
    expect(validateExperimentParameters(ratio, { 'reads,writes-start': '50:60', 'reads,writes-end': '100:0', 'reads,writes-step': '0' }).valid).toBe(false);
  });
  it('detects visibility cycles through a checkbox field name', () => {
    const groups = [group([element('checkbox-selection', 'mode', { options: 'yes,no', default: 'yes' })], { depends: 'mode-yes', dependsValue: 'on' })];
    expect(validateParameterSchema(groups).issues.map(issue => issue.code)).toContain('group-cycle');
    expect(groupVisibility(groups, prepareExperimentValues(groups, {}))).toEqual([false]);
  });

  it('reports obsolete copied checkbox choices instead of silently losing them', () => {
    const groups = [group([element('checkbox-selection', 'choice', { options: 'red,blue' })])];
    const copied = { 'field-choice-parameter': 'choice', 'choice-green': 'on' };
    const validation = validateExperimentParameters(groups, copied);
    expect(validation.valid).toBe(false);
    expect(validation.issues.some(issue => issue.message.includes('no longer available'))).toBe(true);
    expect(copied['choice-green']).toBe('on');
  });

  it('blocks a percentage whose required source belongs to an inactive group', () => {
    const groups = [group([element('boolean', 'enabled', { default: false })]),
      group([element('integer', 'base', { default: 10 })], { id: 'hidden', depends: 'enabled', dependsValue: 'true' }),
      group([element('percentage', 'part', { dependency: 'base', default: 50 })], { id: 'shown' })];
    expect(validateExperimentParameters(groups, {}).issues.some(issue => issue.message.includes('inactive group'))).toBe(true);
  });

  it('uses a configurable version-input guard without discarding imported values', () => {
    const groups = [group([element('software-versions-list', 'revision')])];
    const saved = { revision: 'one,two,three' };
    expect(validateExperimentParameters(groups, saved, 2).valid).toBe(false);
    expect(prepareExperimentValues(groups, saved, 3)['revision-version-2']).toBe('three');
    expect(validateExperimentParameters(groups, saved, 3).valid).toBe(true);
    expect(experimentSubmission(groups, saved, 3)['revision-version-2']).toBe('three');
  });

  it('rejects hexadecimal/non-finite numeric copies and empty ratio sides', () => {
    const groups = [group([element('integer', 'n')])];
    expect(validateExperimentParameters(groups, { n: '0x10' }).valid).toBe(false);
    expect(validateExperimentParameters(groups, { n: '1e999' }).valid).toBe(false);
    const ratio = [group([element('ratio', 'reads,writes')])];
    expect(validateExperimentParameters(ratio, { 'reads,writes-start': ':100', 'reads,writes-end': '100:0', 'reads,writes-step': '10' }).valid).toBe(false);
  });

});
