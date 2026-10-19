import { Component, computed, effect, input, model, output, signal } from '@angular/core';
import { PARAMETER_TYPES, ParameterElement, ParameterGroup, ParameterValidation, ParameterTypeAliases } from '../../core/parameter-models';
import { domNamespace, knownParameterType, parameterRenderType, parameterRenderGroups, newParameterId, validateParameterSchema, valueText } from './parameter-state';

interface SettingField { key: string; label: string; type?: string; options?: { value: string; label: string }[]; }
const numericType = { key: 'parameter-type', label: 'Calculated value type', options: [{ value: 'int', label: 'Integer (round down)' }, { value: 'float', label: 'Decimal' }] };
const dependency = { key: 'dependency', label: 'Depending on parameter' };
const defaultField = { key: 'default', label: 'Default value' };

export function elementSettings(type: string): SettingField[] {
  switch (type) {
    case 'boolean': return [{ key: 'default', label: 'Default value', options: [{ value: 'false', label: 'False' }, { value: 'true', label: 'True' }] }];
    case 'checkbox-selection': return [{ key: 'options', label: 'Available values (comma-separated)' }, { key: 'default', label: 'Initially selected values (comma-separated)' }];
    case 'dropdown': return [{ key: 'options', label: 'Available values (comma-separated)' }, { key: 'names', label: 'Display names (comma-separated, same order)' }, defaultField];
    case 'integer': return [{ ...defaultField, type: 'number' }];
    case 'interval': return [{ key: 'default-min', label: 'Default minimum', type: 'number' }, { key: 'default-max', label: 'Default maximum', type: 'number' }, { key: 'default-step', label: 'Default step', type: 'number' }];
    case 'percentage': case 'percentage-dep': return [numericType, dependency, { key: 'default', label: 'Default percentage', type: 'number' }];
    case 'percentage-interval': return [numericType, dependency, { key: 'default-start', label: 'Default start percentage', type: 'number' }, { key: 'default-end', label: 'Default end percentage', type: 'number' }, { key: 'default-step', label: 'Default step percentage', type: 'number' }];
    case 'ratio': return [{ key: 'default-start', label: 'Default start ratio (for example 0:100)' }, { key: 'default-end', label: 'Default end ratio (for example 100:0)' }, { key: 'default-step', label: 'Default step (left value increases)' }];
    case 'software-versions-list': return [{ key: 'repository', label: 'Git repository', type: 'text' }];
    case 'string-list': return [{ key: 'default', label: 'Default values (comma-separated)' }];
    case 'string': return [defaultField];
    default: return [];
  }
}

@Component({
  selector: 'app-parameter-schema-editor',
  templateUrl: './parameter-schema-editor.html',
  styleUrl: './parameter-schema-editor.scss',
})
export class ParameterSchemaEditor {
  readonly groups = model<ParameterGroup[]>([]);
  readonly aliases = input<ParameterTypeAliases>({});
  readonly disabled = input(false);
  readonly validation = output<ParameterValidation>();
  readonly validity = output<boolean>();
  readonly previous = signal<ParameterGroup[] | null>(null);
  readonly namespace = domNamespace();
  readonly kinds = computed(() => [...PARAMETER_TYPES, ...Object.entries(this.aliases()).filter(([type, base]) => !knownParameterType(type) && knownParameterType(base)).map(([type, base]) => ({ type, label: `${type} (${PARAMETER_TYPES.find(item => item.type === base)!.label})` }))]);
  readonly text = valueText;
  renderType(type: string): string { return parameterRenderType(type, this.aliases()); }
  supported(type: string): boolean { return knownParameterType(this.renderType(type)); }
  settings(type: string): SettingField[] { return elementSettings(this.renderType(type)); }
  readonly validationState = computed(() => validateParameterSchema(parameterRenderGroups(this.groups(), this.aliases())));
  readonly parameterNames = computed(() => this.groups().flatMap(group => group.elements.flatMap(element => element.parameter.split(',').map(name => name.trim()))).filter(Boolean));
  private lastEdited: ParameterGroup[] | null = null;
  constructor() {
    effect(() => { this.validation.emit(this.validationState()); this.validity.emit(this.validationState().valid); });
    effect(() => { if (this.groups() !== this.lastEdited) this.previous.set(null); });
  }
  id(group: number, element: number, field: string): string { return `${this.namespace}-${group}-${element}-${field}`; }
  private edit(change: (groups: ParameterGroup[]) => void): void {
    if (this.disabled()) return;
    const before = this.groups(), next = before.map(group => ({ ...group, elements: group.elements.map(element => ({ ...element })) }));
    change(next); this.previous.set(before); this.lastEdited = next; this.groups.set(next);
  }
  addGroup(): void { this.edit(groups => groups.push({ id: newParameterId('group'), title: 'New group', depends: '', dependsValue: '', elements: [] })); }
  removeGroup(index: number): void { if (!this.disabled() && window.confirm(`Delete group “${this.groups()[index].title}” and all its parameters from the draft?`)) this.edit(groups => { groups.splice(index, 1); }); }
  updateGroup(index: number, key: 'title' | 'depends' | 'dependsValue', value: string): void { this.edit(groups => { groups[index] = { ...groups[index], [key]: value }; }); }
  addElement(group: number): void { this.edit(groups => groups[group].elements.push({ id: newParameterId('element'), type: 'string', name: 'New parameter', parameter: '', default: '' })); }
  removeElement(group: number, index: number): void { if (!this.disabled() && window.confirm(`Delete parameter “${this.groups()[group].elements[index].name}” from the draft?`)) this.edit(groups => { groups[group].elements.splice(index, 1); }); }
  updateElement(group: number, index: number, key: string, value: string): void { this.edit(groups => { groups[group].elements[index] = { ...groups[group].elements[index], [key]: value }; }); }
  moveGroup(index: number, direction: number): void { this.edit(groups => { const to = index + direction; if (to >= 0 && to < groups.length) [groups[index], groups[to]] = [groups[to], groups[index]]; }); }
  moveElement(group: number, index: number, direction: number): void { this.edit(groups => { const elements = groups[group].elements, to = index + direction; if (to >= 0 && to < elements.length) [elements[index], elements[to]] = [elements[to], elements[index]]; }); }
  moveToGroup(group: number, index: number, destination: string): void { if (destination !== '' && Number.isInteger(Number(destination))) this.edit(groups => { const target = groups[Number(destination)]; if (target && Number(destination) !== group) target.elements.push(...groups[group].elements.splice(index, 1)); }); }
  undo(): void { if (!this.disabled() && this.previous() !== null) { const current = this.groups(); this.lastEdited = this.previous()!; this.groups.set(this.lastEdited); this.previous.set(current); } }
}
