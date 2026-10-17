import { Component, computed, effect, input, model, output, signal } from '@angular/core';
import { PARAMETER_TYPES, ParameterElement, ParameterGroup, ParameterValidation } from '../../core/parameter-models';
import { domNamespace, knownParameterType, newParameterId, validateParameterSchema, valueText } from './parameter-state';

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
  template: `
    <div class="parameter-schema-editor">
      <div class="d-flex flex-wrap gap-2 mb-3"><button type="button" class="btn btn-outline-primary" (click)="addGroup()" [disabled]="disabled()">Add group</button><button type="button" class="btn btn-outline-secondary" (click)="undo()" [disabled]="disabled() || previous() === null">Undo last change</button></div>
      @if (validationState().issues.length) {
        <div class="alert alert-danger" role="status"><p class="fw-semibold mb-1">Review the parameter definition.</p><ul class="mb-0">@for (issue of validationState().issues; track $index) { <li>{{ issue.message }}</li> }</ul></div>
      }
      <datalist [id]="namespace + '-parameters'">@for (name of parameterNames(); track $index) { <option [value]="name"></option> }</datalist>
      @if (!groups().length) { <p class="text-secondary">Add a group to begin defining this system's experiment parameters.</p> }
      @for (group of groups(); track $index; let groupIndex = $index) {
        <section class="card mb-4" [attr.aria-labelledby]="id(groupIndex, -1, 'heading')">
          <div class="card-header d-flex flex-wrap align-items-center gap-2"><h3 class="fs-5 mb-0 me-auto" [id]="id(groupIndex, -1, 'heading')">{{ group.title || 'Untitled group' }}</h3><div class="btn-group" role="group" [attr.aria-label]="'Reorder ' + group.title"><button class="btn btn-sm btn-outline-secondary" type="button" (click)="moveGroup(groupIndex, -1)" [disabled]="disabled() || groupIndex === 0" [attr.aria-label]="'Move group ' + group.title + ' up'">Move up</button><button class="btn btn-sm btn-outline-secondary" type="button" (click)="moveGroup(groupIndex, 1)" [disabled]="disabled() || groupIndex === groups().length - 1" [attr.aria-label]="'Move group ' + group.title + ' down'">Move down</button></div><button class="btn btn-sm btn-outline-danger" type="button" (click)="removeGroup(groupIndex)" [disabled]="disabled()">Delete group</button></div>
          <div class="card-body">
            <fieldset [disabled]="disabled()"><legend class="visually-hidden">Group settings</legend><div class="row g-3 mb-4">
              <div class="col-12 col-md-6"><label class="form-label" [for]="id(groupIndex, -1, 'title')">Group title</label><input class="form-control" required [id]="id(groupIndex, -1, 'title')" [value]="group.title" (input)="updateGroup(groupIndex, 'title', $any($event.target).value)" /></div>
              <div class="col-12 col-md-6"><label class="form-label" [for]="id(groupIndex, -1, 'id')">Group ID</label><input class="form-control" [id]="id(groupIndex, -1, 'id')" [value]="group.id" readonly /><p class="form-text">Stable ID independent of the title.</p></div>
              <div class="col-12 col-md-6"><label class="form-label" [for]="id(groupIndex, -1, 'depends')">Show when parameter</label><input class="form-control" [id]="id(groupIndex, -1, 'depends')" [attr.list]="namespace + '-parameters'" [value]="group.depends" (input)="updateGroup(groupIndex, 'depends', $any($event.target).value)" placeholder="Always visible if empty" /></div>
              <div class="col-12 col-md-6"><label class="form-label" [for]="id(groupIndex, -1, 'dependsValue')">Has value</label><input class="form-control" [id]="id(groupIndex, -1, 'dependsValue')" [value]="text(group.dependsValue)" (input)="updateGroup(groupIndex, 'dependsValue', $any($event.target).value)" [disabled]="!group.depends" /></div>
            </div></fieldset>
            @for (element of group.elements; track $index; let elementIndex = $index) {
              <fieldset [disabled]="disabled()" class="border rounded p-3 mb-3"><legend class="float-none w-auto px-1 fs-6 fw-semibold">{{ element.name || 'New parameter' }}</legend>
                @if (!supported(element.type)) { <div class="alert alert-danger" role="alert">Unsupported type “{{ element.type }}”. Its data is preserved; a registered editor and renderer are required before saving.</div> }
                <div class="row g-3">
                  <div class="col-12 col-md-4"><label class="form-label" [for]="id(groupIndex, elementIndex, 'type')">Input type</label><select class="form-select" [id]="id(groupIndex, elementIndex, 'type')" [value]="element.type" (change)="updateElement(groupIndex, elementIndex, 'type', $any($event.target).value)">@if (!supported(element.type)) { <option [value]="element.type">{{ element.type }} (unsupported)</option> } @for (kind of kinds; track kind.type) { <option [value]="kind.type">{{ kind.label }}</option> }</select></div>
                  <div class="col-12 col-md-4"><label class="form-label" [for]="id(groupIndex, elementIndex, 'name')">Label</label><input class="form-control" required [id]="id(groupIndex, elementIndex, 'name')" [value]="element.name" (input)="updateElement(groupIndex, elementIndex, 'name', $any($event.target).value)" /></div>
                  <div class="col-12 col-md-4"><label class="form-label" [for]="id(groupIndex, elementIndex, 'parameter')">{{ element.type === 'ratio' ? 'Two parameter names (comma-separated)' : 'Parameter name' }}</label><input class="form-control" required [id]="id(groupIndex, elementIndex, 'parameter')" [value]="element.parameter" (input)="updateElement(groupIndex, elementIndex, 'parameter', $any($event.target).value)" /></div>
                  @for (setting of settings(element.type); track setting.key) {
                    <div class="col-12 col-md-6"><label class="form-label" [for]="id(groupIndex, elementIndex, setting.key)">{{ setting.label }}</label>
                      @if (setting.options) { <select class="form-select" [id]="id(groupIndex, elementIndex, setting.key)" [value]="text(element[setting.key]) || (setting.key === 'parameter-type' ? 'int' : 'false')" (change)="updateElement(groupIndex, elementIndex, setting.key, $any($event.target).value)">@for (option of setting.options; track option.value) { <option [value]="option.value">{{ option.label }}</option> }</select> }
                      @else { <input class="form-control" [type]="setting.type ?? 'text'" step="any" [id]="id(groupIndex, elementIndex, setting.key)" [attr.list]="setting.key === 'dependency' ? namespace + '-parameters' : null" [value]="text(element[setting.key])" (input)="updateElement(groupIndex, elementIndex, setting.key, $any($event.target).value)" /> }
                    </div>
                  }
                </div>
                <div class="d-flex flex-wrap gap-2 mt-3"><div class="btn-group" role="group" [attr.aria-label]="'Reorder ' + element.name"><button type="button" class="btn btn-sm btn-outline-secondary" (click)="moveElement(groupIndex, elementIndex, -1)" [attr.aria-label]="'Move parameter ' + element.name + ' up'" [disabled]="elementIndex === 0">Move up</button><button type="button" class="btn btn-sm btn-outline-secondary" (click)="moveElement(groupIndex, elementIndex, 1)" [attr.aria-label]="'Move parameter ' + element.name + ' down'" [disabled]="elementIndex === group.elements.length - 1">Move down</button></div><button type="button" class="btn btn-sm btn-outline-danger" (click)="removeElement(groupIndex, elementIndex)">Delete parameter</button>
                  @if (groups().length > 1) { <label class="visually-hidden" [for]="id(groupIndex, elementIndex, 'move')">Move {{ element.name }} to another group</label><select class="form-select form-select-sm w-auto" [id]="id(groupIndex, elementIndex, 'move')" (change)="moveToGroup(groupIndex, elementIndex, $any($event.target).value); $any($event.target).value = ''"><option value="">Move to group…</option>@for (destination of groups(); track $index; let destinationIndex = $index) { @if (destinationIndex !== groupIndex) { <option [value]="destinationIndex">{{ destination.title }}</option> } }</select> }
                </div>
              </fieldset>
            }
            <button type="button" class="btn btn-outline-primary" (click)="addElement(groupIndex)" [disabled]="disabled()">Add parameter</button>
          </div>
        </section>
      }
    </div>
  `,
})
export class ParameterSchemaEditor {
  readonly groups = model<ParameterGroup[]>([]);
  readonly disabled = input(false);
  readonly validation = output<ParameterValidation>();
  readonly validity = output<boolean>();
  readonly previous = signal<ParameterGroup[] | null>(null);
  readonly namespace = domNamespace();
  readonly kinds = PARAMETER_TYPES;
  readonly text = valueText;
  readonly supported = knownParameterType;
  readonly settings = elementSettings;
  readonly validationState = computed(() => validateParameterSchema(this.groups()));
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
  removeGroup(index: number): void { this.edit(groups => { groups.splice(index, 1); }); }
  updateGroup(index: number, key: 'title' | 'depends' | 'dependsValue', value: string): void { this.edit(groups => { groups[index] = { ...groups[index], [key]: value }; }); }
  addElement(group: number): void { this.edit(groups => groups[group].elements.push({ id: newParameterId('element'), type: 'string', name: 'New parameter', parameter: '', default: '' })); }
  removeElement(group: number, index: number): void { this.edit(groups => { groups[group].elements.splice(index, 1); }); }
  updateElement(group: number, index: number, key: string, value: string): void { this.edit(groups => { groups[group].elements[index] = { ...groups[group].elements[index], [key]: value }; }); }
  moveGroup(index: number, direction: number): void { this.edit(groups => { const to = index + direction; if (to >= 0 && to < groups.length) [groups[index], groups[to]] = [groups[to], groups[index]]; }); }
  moveElement(group: number, index: number, direction: number): void { this.edit(groups => { const elements = groups[group].elements, to = index + direction; if (to >= 0 && to < elements.length) [elements[index], elements[to]] = [elements[to], elements[index]]; }); }
  moveToGroup(group: number, index: number, destination: string): void { if (destination !== '' && Number.isInteger(Number(destination))) this.edit(groups => { const target = groups[Number(destination)]; if (target && Number(destination) !== group) target.elements.push(...groups[group].elements.splice(index, 1)); }); }
  undo(): void { if (!this.disabled() && this.previous() !== null) { const current = this.groups(); this.lastEdited = this.previous()!; this.groups.set(this.lastEdited); this.previous.set(current); } }
}
