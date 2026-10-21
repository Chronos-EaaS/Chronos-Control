import { Component, computed, input, signal } from '@angular/core';
import { ParameterGroup, ParameterTypeAliases, ParameterValues } from '../../core/parameter-models';
import {
  domNamespace,
  elementValueKeys,
  groupVisibility,
  parameterRenderGroups,
  prepareExperimentValues,
  valueText,
} from './parameter-state';
import { ExperimentParameters } from './experiment-parameters';

@Component({
  selector: 'app-parameter-schema-preview',
  imports: [ExperimentParameters],
  templateUrl: './parameter-schema-preview.html',
  styleUrl: './parameter-schema-preview.scss',
})
export class ParameterSchemaPreview {
  readonly groups = input<ParameterGroup[]>([]);
  readonly aliases = input<ParameterTypeAliases>({});
  readonly disabled = input(false);
  readonly sampleValues = signal<ParameterValues>({});
  readonly parentValues = signal<ParameterValues>({});
  readonly namespace = domNamespace();
  readonly text = valueText;
  private readonly renderedGroups = computed(() =>
    parameterRenderGroups(this.groups(), this.aliases()),
  );
  private readonly defaults = computed(() => prepareExperimentValues(this.renderedGroups(), {}));
  readonly contextKeys = computed(() => {
    const groups = this.renderedGroups(),
      defaults = this.defaults();
    const owned = new Set(Object.keys(defaults));
    const versionPrefixes: string[] = [];
    for (const group of groups)
      for (const element of group.elements) {
        owned.add(element.parameter);
        for (const key of elementValueKeys(element, defaults)) owned.add(key);
        if (element.type === 'software-versions-list')
          versionPrefixes.push(element.parameter + '-version-');
      }
    return [
      ...new Set(
        groups
          .map((group) => group.depends)
          .filter(
            (key) =>
              key &&
              !owned.has(key) &&
              !versionPrefixes.some(
                (prefix) =>
                  key.startsWith(prefix) &&
                  /^(?:0|[1-9][0-9]{0,2})$/.test(key.slice(prefix.length)),
              ),
          ),
      ),
    ];
  });
  readonly visibilityContext = computed<ParameterValues>(() =>
    Object.fromEntries(this.contextKeys().map((key) => [key, this.conditionValue(key)])),
  );
  readonly hiddenGroups = computed(() => {
    const groups = this.renderedGroups();
    const visible = groupVisibility(
      groups,
      prepareExperimentValues(groups, this.sampleValues()),
      this.visibilityContext(),
    );
    return groups.filter((_, index) => !visible[index]);
  });

  conditionId(key: string): string {
    return this.namespace + '-condition-' + encodeURIComponent(key);
  }
  conditionValue(key: string): string {
    return Object.hasOwn(this.parentValues(), key) ? valueText(this.parentValues()[key]) : '';
  }
  conditionHint(key: string): string {
    const values = [
      ...new Set(
        this.groups()
          .filter((group) => group.depends === key)
          .map((group) => valueText(group.dependsValue) || '(empty)'),
      ),
    ];
    return 'Matching values used by groups: ' + values.join(', ');
  }
  updateCondition(key: string, value: string): void {
    if (!this.disabled()) this.parentValues.update((values) => ({ ...values, [key]: value }));
  }
  reset(): void {
    if (!this.disabled()) {
      this.sampleValues.set({});
      this.parentValues.set({});
    }
  }
}
