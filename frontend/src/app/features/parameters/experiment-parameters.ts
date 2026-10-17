import { Component, computed, effect, input, model, output } from '@angular/core';
import { ParameterGroup, ParameterValidation, ParameterValues } from '../../core/parameter-models';
import { DEFAULT_VERSION_INPUT_LIMIT, domNamespace, experimentSubmission, groupVisibility, prepareExperimentValues, validateExperimentParameters } from './parameter-state';
import { ParameterInputs, ParameterValueChange } from './parameter-inputs';

@Component({
  selector: 'app-experiment-parameters',
  imports: [ParameterInputs],
  template: `
    <div class="experiment-parameters">
      @if (!groups().length) { <p class="text-secondary">This system has no configurable parameters.</p> }
      @if (!validationState().valid) {
        <div class="alert alert-danger" role="status"><p class="mb-1 fw-semibold">Review the experiment parameters.</p><ul class="mb-0">@for (issue of validationState().issues; track $index) { @if (issue.severity === 'error') { <li>{{ issue.message }}</li> } }</ul></div>
      }
      @for (group of groups(); track $index; let groupIndex = $index) {
        @if (visible()[groupIndex]) {
        <section class="card mb-4" [attr.aria-labelledby]="namespace + '-group-' + groupIndex">
          <div class="card-header"><h3 class="card-title fs-5 mb-0" [id]="namespace + '-group-' + groupIndex">{{ group.title }}</h3></div>
          <div class="card-body">
            @for (element of group.elements; track $index) {
              <app-parameter-inputs [element]="element" [values]="draft()" [disabled]="disabled() || !visible()[groupIndex]" [maxVersions]="maxVersions()" [issues]="elementIssues(element.id)" (valueChange)="update($event)" />
            }
          </div>
        </section>
        }
      }
    </div>
  `,
})
export class ExperimentParameters {
  readonly groups = input<ParameterGroup[]>([]);
  /** Complete editable draft; inactive group values are retained for switching back. */
  readonly values = model<ParameterValues>({});
  readonly disabled = input(false);
  readonly maxVersions = input(DEFAULT_VERSION_INPUT_LIMIT);
  readonly validity = output<boolean>();
  readonly validation = output<ParameterValidation>();
  /** API-ready legacy field names, with inactive fields and stale version rows omitted. */
  readonly submissionChange = output<ParameterValues>();
  readonly namespace = domNamespace();
  readonly draft = computed(() => prepareExperimentValues(this.groups(), this.values(), this.maxVersions()));
  readonly visible = computed(() => groupVisibility(this.groups(), this.draft()));
  readonly submissionValues = computed(() => experimentSubmission(this.groups(), this.draft(), this.maxVersions()));
  readonly validationState = computed(() => validateExperimentParameters(this.groups(), this.draft(), this.maxVersions()));
  constructor() {
    effect(() => { this.validity.emit(this.validationState().valid); this.validation.emit(this.validationState()); this.submissionChange.emit(this.submissionValues()); });
  }
  update(change: ParameterValueChange): void {
    if (this.disabled()) return;
    this.values.set(prepareExperimentValues(this.groups(), { ...this.draft(), [change.key]: change.value }, this.maxVersions()));
  }
  elementIssues(id: string) { return this.validationState().issues.filter(issue => issue.elementId === id); }
}
