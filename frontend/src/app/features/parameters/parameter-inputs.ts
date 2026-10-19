import { Component, computed, input, output } from '@angular/core';
import { ParameterElement, ParameterIssue, ParameterValues } from '../../core/parameter-models';
import { choices, DEFAULT_VERSION_INPUT_LIMIT, dependingPercentagePreview, domNamespace, knownParameterType, numeric, percentageIntervalPreview, valueText } from './parameter-state';

interface InputField { key: string; label: string; type: 'text' | 'number'; readonly?: boolean; step?: string; prefix?: string; }
export interface ParameterValueChange { key: string; value: unknown; }

@Component({
  selector: 'app-parameter-inputs',
  templateUrl: './parameter-inputs.html',
  styleUrl: './parameter-inputs.scss',
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
  readonly describedBy = computed(() => [this.help() ? this.id('help') : '', this.issues().length ? this.id('errors') : ''].filter(Boolean).join(' ') || null);
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
      case 'percentage': return [{ ...number('-percentage', 'Percentage'), step: 'any', prefix: '%' }, number('', 'Absolute value (calculated)', true)];
      case 'percentage-dep': return [{ ...number('-percentage', 'Percentage'), step: 'any', prefix: '%' }];
      case 'percentage-interval': return [{ ...number('-percentage-start', 'Start percentage'), step: 'any', prefix: 'Start %' }, { ...number('-percentage-end', 'End percentage'), step: 'any', prefix: 'End %' }, { ...number('-percentage-step', 'Step percentage'), step: 'any', prefix: 'Step %' }, number('-start', 'Start (calculated)', true), number('-end', 'End (calculated)', true), number('-step', 'Step (calculated)', true)];
      case 'string': case 'string-list': return [{ key: p, label: e.type === 'string' ? 'Value' : 'Values', type: 'text' }];
      default: return [];
    }
  });
  id(part: string): string { return `${this.namespace}-${encodeURIComponent(part)}`; }
  read(key: string): string {
    const preview = this.element().type === 'percentage-interval' ? percentageIntervalPreview(this.element(), this.values()) : {};
    return valueText(Object.hasOwn(preview, key) ? preview[key] : this.values()[key]);
  }
  checked(option: string): boolean { const value = this.values()[`${this.element().parameter}-${option}`]; return value === 'on' || value === true; }
  hasChoice(): boolean { return this.available().some(option => option.value === this.read(this.element().parameter)); }
  choose(option: string, checked: boolean): void { this.valueChange.emit({ key: `${this.element().parameter}-${option}`, value: checked ? 'on' : false }); }
  edit(field: InputField, value: string): void { if (!field.readonly) this.valueChange.emit({ key: field.key, value }); }
}
