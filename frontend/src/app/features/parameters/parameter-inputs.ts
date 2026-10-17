import { Component, computed, input, output } from '@angular/core';
import { ParameterElement, ParameterIssue, ParameterValues } from '../../core/parameter-models';
import { choices, DEFAULT_VERSION_INPUT_LIMIT, dependingPercentagePreview, domNamespace, knownParameterType, numeric, valueText } from './parameter-state';

interface InputField { key: string; label: string; type: 'text' | 'number'; readonly?: boolean; step?: string; }
export interface ParameterValueChange { key: string; value: unknown; }

@Component({
  selector: 'app-parameter-inputs',
  template: `
    <fieldset [disabled]="disabled()" class="border rounded p-3 mb-3" [attr.aria-describedby]="id('help')">
      <legend class="float-none w-auto px-1 fs-6 fw-semibold">{{ element().name }} <span class="fw-normal text-secondary">({{ element().parameter }})</span></legend>
      @if (!supported()) {
        <div class="alert alert-danger mb-0" role="alert">Unsupported parameter type “{{ element().type }}”. A registered renderer is required before this experiment can be submitted.</div>
      } @else if (element().type === 'checkbox-selection') {
        <div class="d-flex flex-wrap gap-3">
          @for (option of available(); track $index; let optionIndex = $index) {
            <div class="form-check"><input [attr.aria-describedby]="describedBy()" [attr.aria-invalid]="invalid()" type="checkbox" class="form-check-input" [id]="id('choice-' + optionIndex)" [name]="element().parameter + '-' + option.value" [checked]="checked(option.value)" (change)="choose(option.value, $any($event.target).checked)" />
              <label class="form-check-label" [for]="id('choice-' + optionIndex)">{{ option.label }}</label></div>
          }
        </div>
      } @else if (element().type === 'boolean' || element().type === 'dropdown') {
        <label class="form-label" [for]="id('select')">Value</label>
        <select [attr.aria-describedby]="describedBy()" [attr.aria-invalid]="invalid()" class="form-select" [id]="id('select')" [name]="element().parameter" [value]="read(element().parameter)" (change)="valueChange.emit({key: element().parameter, value: $any($event.target).value})">
          @if (element().type === 'boolean') { <option value="false">False</option><option value="true">True</option> }
          @else {
            @if (!hasChoice()) { <option [value]="read(element().parameter)">Saved value: {{ read(element().parameter) || '(empty)' }}</option> }
            @for (option of available(); track $index) { <option [value]="option.value">{{ option.label }}</option> }
          }
        </select>
      } @else if (element().type === 'software-versions-list') {
        <label class="form-label" [for]="id('version-count')">Number of versions</label>
        <input [attr.aria-describedby]="describedBy()" [attr.aria-invalid]="invalid()" class="form-control mb-3" type="number" min="1" step="1" [max]="maxVersions()" [id]="id('version-count')" [name]="element().parameter + '-numberOfVersions'" [value]="read(element().parameter + '-numberOfVersions')" (change)="valueChange.emit({key: element().parameter + '-numberOfVersions', value: $any($event.target).value})" />
        <div class="row g-3">
          @for (index of versionIndices(); track index) {
            <div class="col-12 col-md-6"><label class="form-label" [for]="id('version-' + index)">Version {{ index + 1 }}</label>
              <input [attr.aria-describedby]="describedBy()" [attr.aria-invalid]="invalid()" class="form-control" required type="text" [id]="id('version-' + index)" [name]="element().parameter + '-version-' + index" [value]="read(element().parameter + '-version-' + index)" (input)="valueChange.emit({key: element().parameter + '-version-' + index, value: $any($event.target).value})" autocomplete="off" /></div>
          }
        </div>
      } @else {
        <div class="row g-3">
          @for (field of fields(); track field.key) {
            <div [class]="fields().length === 1 ? 'col-12' : 'col-12 col-sm-6 col-xl-4'">
              <label class="form-label" [for]="id(field.key)">{{ field.label }}</label>
              <input [attr.aria-describedby]="describedBy()" [attr.aria-invalid]="invalid()" class="form-control" [id]="id(field.key)" [name]="field.key" [type]="field.type" [attr.step]="field.step ?? 'any'" [readOnly]="field.readonly ?? false" [value]="read(field.key)" (input)="edit(field, $any($event.target).value)" />
            </div>
          }
          @if (element().type === 'percentage-dep') {
            @for (preview of preview(); track preview.label; let index = $index) {
              <div class="col-12 col-sm-6 col-xl-4"><label class="form-label" [for]="id('preview-' + index)">{{ preview.label }} (calculated)</label><input [attr.aria-describedby]="describedBy()" [attr.aria-invalid]="invalid()" class="form-control" type="number" readonly [id]="id('preview-' + index)" [value]="preview.value" /></div>
            }
          }
        </div>
      }
      <p class="form-text mb-0" [id]="id('help')">{{ help() }}</p>
      @if (issues().length) { <div [id]="id('errors')">@for (issue of issues(); track $index) { <p class="mt-2 mb-0" [class.text-danger]="issue.severity === 'error'" [class.text-warning-emphasis]="issue.severity === 'warning'">{{ issue.message }}</p> }</div> }
    </fieldset>
  `,
})
export class ParameterInputs {
  readonly element = input.required<ParameterElement>();
  readonly values = input.required<ParameterValues>();
  readonly disabled = input(false);
  readonly maxVersions = input(DEFAULT_VERSION_INPUT_LIMIT);
  readonly issues = input<ParameterIssue[]>([]);
  readonly valueChange = output<ParameterValueChange>();
  private readonly namespace = domNamespace();
  readonly invalid = computed(() => this.issues().some(issue => issue.severity === 'error'));
  readonly describedBy = computed(() => this.id('help') + (this.issues().length ? ' ' + this.id('errors') : ''));
  readonly supported = computed(() => knownParameterType(this.element().type));
  readonly available = computed(() => choices(this.element()));
  readonly preview = computed(() => dependingPercentagePreview(this.element(), this.values()));
  readonly versionIndices = computed(() => {
    const count = numeric(this.values()[`${this.element().parameter}-numberOfVersions`]);
    return count !== null && Number.isInteger(count) && count > 0 && count <= this.maxVersions() ? Array.from({ length: count }, (_, index) => index) : [];
  });
  readonly help = computed(() => {
    switch (this.element().type) {
      case 'string-list': return 'Comma-separated values. Spaces within values are preserved.';
      case 'checkbox-selection': return 'Select at least one value. Each selected value varies the experiment parameter.';
      case 'ratio': return 'Start and end must each total 100. The left value increases by the chosen step.';
      case 'software-versions-list': return 'Enter a branch, tag or revision for each version to compare.';
      case 'percentage': case 'percentage-interval': case 'percentage-dep': return `Calculated from ${this.element().dependency ?? 'the selected dependency'}.`;
      default: return '';
    }
  });
  readonly fields = computed<InputField[]>(() => {
    const e = this.element(), p = e.parameter;
    const number = (suffix: string, label: string, readonly = false): InputField => ({ key: p + suffix, label, type: 'number', readonly, step: e['parameter-type'] === 'float' ? 'any' : '1' });
    switch (e.type) {
      case 'integer': return [number('', 'Value')];
      case 'interval': return [number('-min', 'Minimum'), number('-max', 'Maximum'), number('-step', 'Step')];
      case 'ratio': return ['start', 'end', 'step'].map(suffix => ({ key: `${p}-${suffix}`, label: suffix === 'step' ? 'Step' : suffix === 'start' ? 'Start ratio' : 'End ratio', type: 'text' }));
      case 'percentage': return [{ ...number('-percentage', 'Percentage'), step: 'any' }, number('', 'Absolute value (calculated)', true)];
      case 'percentage-dep': return [{ ...number('-percentage', 'Percentage'), step: 'any' }];
      case 'percentage-interval': return [{ ...number('-percentage-start', 'Start percentage'), step: 'any' }, { ...number('-percentage-end', 'End percentage'), step: 'any' }, { ...number('-percentage-step', 'Step percentage'), step: 'any' }, number('-start', 'Start (calculated)', true), number('-end', 'End (calculated)', true), number('-step', 'Step (calculated)', true)];
      case 'string': case 'string-list': return [{ key: p, label: e.type === 'string' ? 'Value' : 'Values', type: 'text' }];
      default: return [];
    }
  });
  id(part: string): string { return `${this.namespace}-${encodeURIComponent(part)}`; }
  read(key: string): string { return valueText(this.values()[key]); }
  checked(option: string): boolean { const value = this.values()[`${this.element().parameter}-${option}`]; return value === 'on' || value === true; }
  hasChoice(): boolean { return this.available().some(option => option.value === this.read(this.element().parameter)); }
  choose(option: string, checked: boolean): void { this.valueChange.emit({ key: `${this.element().parameter}-${option}`, value: checked ? 'on' : false }); }
  edit(field: InputField, value: string): void { if (!field.readonly) this.valueChange.emit({ key: field.key, value }); }
}
