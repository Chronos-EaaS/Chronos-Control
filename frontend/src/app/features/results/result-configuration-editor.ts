import { Component, computed, effect, input, model, output, signal } from '@angular/core';
import {
  PLOT_TYPES,
  PlotDefinition,
  ResultConfigurationDocument,
  PlotTypeAliases,
} from '../../core/results-models';
import { domNamespace, newParameterId } from '../parameters/parameter-state';
import { isPlotType, plotRenderType, validateResultConfigurations } from './result-state';

@Component({
  selector: 'app-result-configuration-editor',
  templateUrl: './result-configuration-editor.html',
  styleUrl: './results-reference.scss',
})
export class ResultConfigurationEditor {
  readonly document = model<ResultConfigurationDocument>({ version: '1.0', elements: {} });
  readonly disabled = input(false);
  readonly aliases = input<PlotTypeAliases>({});
  readonly idPrefix = input('system');
  readonly configurationId = input('');
  readonly initialScope = input<'all' | 'job' | ''>('');
  readonly validity = output<boolean>();
  readonly validation = output<string[]>();
  readonly previous = signal<ResultConfigurationDocument | null>(null);
  readonly selection = signal('');
  readonly pendingDelete = signal('');
  readonly entries = computed(() => Object.entries(this.document().elements));
  readonly selectedId = computed(() =>
    Object.hasOwn(this.document().elements, this.selection())
      ? this.selection()
      : (this.entries()[0]?.[0] ?? ''),
  );
  readonly selected = computed(() => this.document().elements[this.selectedId()]);
  readonly issues = computed(() => validateResultConfigurations(this.document(), this.aliases()));
  readonly namespace = domNamespace();
  readonly types = computed(() => [
    ...PLOT_TYPES,
    ...Object.entries(this.aliases())
      .filter(([type, base]) => !isPlotType(type) && isPlotType(base))
      .map(([type, base]) => ({
        type,
        label: `${type} (${PLOT_TYPES.find((item) => item.type === base)!.label})`,
      })),
  ]);
  renderType(type: string): string {
    return plotRenderType(type, this.aliases());
  }
  supported(type: string): boolean {
    return isPlotType(this.renderType(type));
  }
  private readonly scopeDefinitions = [
    {
      key: 'all',
      label: 'All jobs',
      help: 'Charts compare all finished job configurations in an evaluation.',
    },
    {
      key: 'job',
      label: 'Individual configurations',
      help: 'Charts are repeated for each job configuration and aggregate its finished runs.',
    },
  ] as const;
  readonly scopes = computed(() =>
    this.initialScope() === 'job' ? [...this.scopeDefinitions].reverse() : this.scopeDefinitions,
  );
  private lastEdited: ResultConfigurationDocument | null = null;
  constructor() {
    effect(() => {
      this.selection.set(this.configurationId());
    });
    effect(() => {
      this.validity.emit(this.issues().length === 0);
      this.validation.emit(this.issues());
    });
    effect(() => {
      if (this.document() !== this.lastEdited) {
        this.previous.set(null);
        this.pendingDelete.set('');
      }
    });
  }
  complex(type: string): boolean {
    return ['line-plot-multi', 'box-plot'].includes(this.renderType(type));
  }
  id(scope: string, index: number, field: string): string {
    return `${this.namespace}-${scope}-${index}-${field}`;
  }
  private edit(change: (value: ResultConfigurationDocument) => void): void {
    if (this.disabled()) return;
    const before = this.document(),
      next = structuredClone(before);
    change(next);
    this.previous.set(before);
    this.lastEdited = next;
    this.document.set(next);
  }
  add(): void {
    const id = newParameterId(this.idPrefix()).slice(0, 50);
    this.edit((value) => {
      value.elements[id] = { name: 'New configuration', all: [], job: [] };
    });
    this.selection.set(id);
  }
  copy(): void {
    const entry = this.selected();
    if (!entry) return;
    const id = newParameterId(this.idPrefix()).slice(0, 50);
    this.edit((value) => {
      value.elements[id] = { ...structuredClone(entry), name: `${entry.name} (copy)` };
    });
    this.selection.set(id);
  }
  rename(name: string): void {
    const id = this.selectedId();
    this.edit((value) => {
      value.elements[id].name = name;
    });
  }
  remove(): void {
    const id = this.selectedId();
    this.edit((value) => {
      delete value.elements[id];
    });
    this.pendingDelete.set('');
  }
  addPlot(scope: 'all' | 'job'): void {
    const id = this.selectedId();
    this.edit((value) =>
      value.elements[id][scope].push({
        id: newParameterId('plot'),
        type: 'bar-plot',
        name: 'New chart',
        parameter: '',
      }),
    );
  }
  update(scope: 'all' | 'job', index: number, key: string, value: string): void {
    const id = this.selectedId();
    this.edit((document) => {
      document.elements[id][scope][index] = {
        ...document.elements[id][scope][index],
        [key]: value,
      };
    });
  }
  deletePlot(scope: 'all' | 'job', index: number): void {
    if (
      this.disabled() ||
      !window.confirm(`Delete plot “${this.selected()[scope][index].name}” from the draft?`)
    )
      return;
    const id = this.selectedId();
    this.edit((value) => {
      value.elements[id][scope].splice(index, 1);
    });
  }
  move(scope: 'all' | 'job', index: number, direction: number): void {
    const id = this.selectedId();
    this.edit((value) => {
      const plots: PlotDefinition[] = value.elements[id][scope],
        next = index + direction;
      if (next >= 0 && next < plots.length)
        [plots[index], plots[next]] = [plots[next], plots[index]];
    });
  }
  undo(): void {
    if (!this.disabled() && this.previous()) {
      const current = this.document();
      this.lastEdited = this.previous()!;
      this.document.set(this.lastEdited);
      this.previous.set(current);
    }
  }
}
