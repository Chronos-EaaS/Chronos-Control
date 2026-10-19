import { Component, computed, effect, input, model, output } from '@angular/core';
import { ParameterGroup, ParameterValidation, ParameterValues, ParameterTypeAliases } from '../../core/parameter-models';
import { DEFAULT_VERSION_INPUT_LIMIT, domNamespace, experimentSubmission, groupVisibility, prepareExperimentValues, parameterRenderGroups, validateExperimentParameters } from './parameter-state';
import { ParameterInputs, ParameterValueChange } from './parameter-inputs';

@Component({
  selector: 'app-experiment-parameters',
  imports: [ParameterInputs],
  templateUrl: './experiment-parameters.html',
  styleUrl: './experiment-parameters.scss',
})
export class ExperimentParameters {
  readonly groups = input<ParameterGroup[]>([]);
  readonly aliases = input<ParameterTypeAliases>({});
  readonly renderedGroups = computed(() => parameterRenderGroups(this.groups(), this.aliases()));
  /** Complete editable draft; inactive group values are retained for switching back. */
  readonly values = model<ParameterValues>({});
  readonly disabled = input(false);
  /** Execution controls affect visibility without becoming system parameters. */
  readonly visibilityContext = input<Readonly<ParameterValues>>({});
  readonly maxVersions = input(DEFAULT_VERSION_INPUT_LIMIT);
  readonly validity = output<boolean>();
  readonly validation = output<ParameterValidation>();
  /** API-ready legacy field names, with inactive fields and stale version rows omitted. */
  readonly submissionChange = output<ParameterValues>();
  readonly namespace = domNamespace();
  readonly draft = computed(() => prepareExperimentValues(this.renderedGroups(), this.values(), this.maxVersions()));
  readonly visible = computed(() => groupVisibility(this.renderedGroups(), this.draft(), this.visibilityContext()));
  readonly submissionValues = computed(() => experimentSubmission(this.renderedGroups(), this.draft(), this.maxVersions(), this.visibilityContext()));
  readonly validationState = computed(() => validateExperimentParameters(this.renderedGroups(), this.draft(), this.maxVersions(), this.visibilityContext()));
  constructor() {
    effect(() => { this.validity.emit(this.validationState().valid); this.validation.emit(this.validationState()); this.submissionChange.emit(this.submissionValues()); });
  }
  update(change: ParameterValueChange): void {
    if (this.disabled()) return;
    this.values.set(prepareExperimentValues(this.renderedGroups(), { ...this.draft(), [change.key]: change.value }, this.maxVersions()));
  }
  elementIssues(id: string) { return this.validationState().issues.filter(issue => issue.elementId === id); }
}
