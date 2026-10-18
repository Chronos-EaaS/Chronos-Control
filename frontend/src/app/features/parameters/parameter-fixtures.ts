// Synthetic test-only schemas; no runtime component imports this module.
import { ParameterElement, ParameterGroup } from '../../core/parameter-models';

export const element = (type: string, parameter: string, extra: Partial<ParameterElement> = {}): ParameterElement => ({ id: `field-${parameter.replace(/,/g, '-')}`, name: parameter, type, parameter, ...extra });
export const group = (elements: ParameterElement[], extra: Partial<ParameterGroup> = {}): ParameterGroup => ({ id: 'group-main', title: 'Query plans with café & joins', depends: '', dependsValue: '', elements, ...extra });

export function allBuiltinGroups(): ParameterGroup[] {
  return [group([
    element('boolean', 'enabled', { default: false }),
    element('integer', 'total', { default: 100 }),
    element('string', 'note', { default: '<not markup>' }),
    element('dropdown', 'engine', { options: 'fast,slow', names: 'Fast,Slow', default: 'Slow' }),
    element('checkbox-selection', 'store', { options: 'mem,disk', default: 'mem' }),
    element('interval', 'workers', { 'default-min': 1, 'default-max': 3, 'default-step': 1 }),
    element('percentage', 'share', { default: 25, dependency: 'total', 'parameter-type': 'int' }),
    element('percentage-interval', 'portion', { dependency: 'total', 'default-start': 0, 'default-end': 100, 'default-step': 50, 'parameter-type': 'int' }),
    element('percentage-dep', 'dependent', { default: 50, dependency: 'portion', 'parameter-type': 'float' }),
    element('ratio', 'reads,writes', { 'default-start': '0:100', 'default-end': '100:0', 'default-step': '25%' }),
    element('software-versions-list', 'revision', { repository: 'https://example.invalid/repository' }),
    element('string-list', 'dataset', { default: 'alpha, beta' }),
  ])];
}
