import {
  afterNextRender,
  Component,
  computed,
  effect,
  ElementRef,
  inject,
  Injector,
  input,
  model,
  output,
  signal,
  untracked,
  ViewChild,
} from '@angular/core';
import {
  PLOT_TYPES,
  PlotDefinition,
  ResultConfigurationDocument,
  PlotTypeAliases,
} from '../../core/results-models';
import { Icon } from '../../shared/icon';
import { domNamespace, newParameterId } from '../parameters/parameter-state';
import {
  isPlotType,
  plotRenderType,
  resultConfigurationIssues,
  ResultConfigurationIssue,
} from './result-state';
import { ResultConfigurationPreview } from './result-configuration-preview';

type Scope = 'all' | 'job';
interface ChartSelection {
  configuration: string;
  scope: Scope;
  index: number | null;
}

@Component({
  selector: 'app-result-configuration-editor',
  imports: [Icon, ResultConfigurationPreview],
  templateUrl: './result-configuration-editor.html',
  styleUrl: './result-configuration-editor.scss',
})
export class ResultConfigurationEditor {
  @ViewChild('previewDialog') private previewDialog?: ElementRef<HTMLDialogElement>;
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly injector = inject(Injector);
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
  readonly search = signal('');
  readonly newType = signal('bar-plot');
  readonly preview = signal(false);
  readonly focused = signal<ChartSelection>({ configuration: '', scope: 'all', index: 0 });
  readonly entries = computed(() => Object.entries(this.document().elements));
  readonly selectedId = computed(() =>
    Object.hasOwn(this.document().elements, this.selection())
      ? this.selection()
      : (this.entries()[0]?.[0] ?? ''),
  );
  readonly selected = computed(() => this.document().elements[this.selectedId()]);
  readonly issueDetails = computed(() =>
    resultConfigurationIssues(this.document(), this.aliases()),
  );
  readonly issues = computed(() => this.issueDetails().map((issue) => issue.message));
  readonly namespace = domNamespace();
  readonly types = computed(() => [
    ...PLOT_TYPES,
    ...Object.entries(this.aliases())
      .filter(([type, base]) => !isPlotType(type) && isPlotType(base))
      .map(([type, base]) => ({
        type,
        label: type + ' (' + PLOT_TYPES.find((item) => item.type === base)!.label + ')',
      })),
  ]);
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
  readonly active = computed(() => {
    const requested = this.focused();
    const scope =
      requested.configuration === this.selectedId()
        ? requested.scope
        : this.initialScope() === 'job'
          ? 'job'
          : 'all';
    const definition = this.scopeDefinitions.find((item) => item.key === scope)!;
    const index = requested.configuration === this.selectedId() ? requested.index : 0;
    const plot = index === null ? null : (this.selected()?.[scope][index] ?? null);
    return { ...definition, scope, index: plot ? index : null, plot };
  });
  readonly outline = computed(() => {
    const query = this.search().trim().toLocaleLowerCase();
    return this.scopes().map((scope) => ({
      ...scope,
      plots: (this.selected()?.[scope.key] ?? [])
        .map((plot, index) => ({ plot, index }))
        .filter(
          ({ plot }) =>
            !query ||
            (
              scope.label +
              ' ' +
              plot.name +
              ' ' +
              plot.id +
              ' ' +
              plot.parameter +
              ' ' +
              (plot.plotting ?? '') +
              ' ' +
              this.typeLabel(plot.type)
            )
              .toLocaleLowerCase()
              .includes(query),
        ),
    }));
  });
  readonly count = computed(
    () => (this.selected()?.all.length ?? 0) + (this.selected()?.job.length ?? 0),
  );
  private lastEdited: ResultConfigurationDocument | null = null;
  private previousContext: ChartSelection = { configuration: '', scope: 'all', index: 0 };

  constructor() {
    effect(() => {
      const id = this.configurationId(),
        scope = this.initialScope() === 'job' ? 'job' : 'all';
      this.selection.set(id);
      this.focused.set({ configuration: id, scope, index: 0 });
      this.search.set('');
      this.closePreview();
    });
    effect(() => {
      this.validity.emit(this.issues().length === 0);
      this.validation.emit(this.issues());
    });
    effect(() => {
      if (this.document() !== this.lastEdited) {
        untracked(() => {
          this.previous.set(null);
          this.pendingDelete.set('');
          this.search.set('');
          this.newType.set('bar-plot');
          this.focused.set({
            configuration: this.selectedId(),
            scope: this.initialScope() === 'job' ? 'job' : 'all',
            index: 0,
          });
          this.closePreview();
        });
      }
    });
  }
  renderType(type: string): string {
    return plotRenderType(type, this.aliases());
  }
  supported(type: string): boolean {
    return isPlotType(this.renderType(type));
  }
  typeLabel(type: string): string {
    return this.types().find((item) => item.type === type)?.label ?? type;
  }
  complex(type: string): boolean {
    return ['line-plot-multi', 'box-plot'].includes(this.renderType(type));
  }
  id(scope: string, index: number, field: string): string {
    return this.namespace + '-' + scope + '-' + index + '-' + field;
  }
  selectConfiguration(id: string): void {
    this.selection.set(id);
    this.focused.set({
      configuration: this.selectedId(),
      scope: this.initialScope() === 'job' ? 'job' : 'all',
      index: 0,
    });
    this.search.set('');
    this.pendingDelete.set('');
    this.closePreview();
  }
  selectScope(scope: Scope): void {
    this.focused.set({ configuration: this.selectedId(), scope, index: null });
    this.focusControl(this.namespace + '-new-type');
  }
  selectPlot(scope: Scope, index: number): void {
    if (!this.selected()?.[scope][index]) return;
    this.focused.set({ configuration: this.selectedId(), scope, index });
    this.focusControl(this.id(scope, index, 'name'));
  }
  configurationIssues(id: string): ResultConfigurationIssue[] {
    return this.issueDetails().filter((issue) => issue.configurationId === id);
  }
  chartIssues(scope: Scope, index: number): ResultConfigurationIssue[] {
    return this.configurationIssues(this.selectedId()).filter(
      (issue) => issue.scope === scope && issue.index === index,
    );
  }
  fieldError(field: string): string {
    const active = this.active();
    return this.chartIssues(active.scope, active.index ?? -1)
      .filter((issue) => issue.field === field)
      .map((issue) => issue.message)
      .join(' ');
  }
  configurationError(): string {
    return this.configurationIssues(this.selectedId())
      .filter((issue) => issue.field === 'configurationName')
      .map((issue) => issue.message)
      .join(' ');
  }
  issueContext(issue: ResultConfigurationIssue): string {
    const configuration =
      issue.configurationId === undefined || !Object.hasOwn(this.document().elements, issue.configurationId)
        ? undefined
        : this.document().elements[issue.configurationId];
    if (!configuration) return 'Result definitions';
    const scope = this.scopeDefinitions.find((item) => item.key === issue.scope);
    const plot =
      issue.scope !== undefined && issue.index !== undefined
        ? configuration[issue.scope][issue.index]
        : undefined;
    return [
      configuration.name || issue.configurationId,
      scope?.label,
      plot ? 'Chart ' + (issue.index! + 1) + ' · ' + (plot.name || 'Untitled plot') : '',
    ]
      .filter(Boolean)
      .join(' / ');
  }
  revealIssue(issue: ResultConfigurationIssue): void {
    if (
      issue.configurationId === undefined ||
      !Object.hasOwn(this.document().elements, issue.configurationId)
    )
      return;
    this.selectConfiguration(issue.configurationId);
    if (issue.scope !== undefined && issue.index !== undefined) {
      this.selectPlot(issue.scope, issue.index);
      this.focusControl(this.id(issue.scope, issue.index, issue.field ?? 'name'));
    } else
      this.focusControl(
        this.namespace + (issue.field === 'configurationId' ? '-configuration' : '-name'),
      );
  }
  private focusControl(id: string): void {
    const expected = this.document(),
      selected = this.context();
    afterNextRender(
      () => {
        const current = this.context();
        if (
          this.document() !== expected ||
          current.configuration !== selected.configuration ||
          current.scope !== selected.scope ||
          current.index !== selected.index
        )
          return;
        const node = this.host.nativeElement.ownerDocument.getElementById(id);
        if (node && this.host.nativeElement.contains(node)) {
          for (
            let parent = node.parentElement;
            parent && parent !== this.host.nativeElement;
            parent = parent.parentElement
          ) {
            if (parent instanceof HTMLDetailsElement) parent.open = true;
          }
          node.focus();
          node.scrollIntoView?.({ block: 'nearest', behavior: 'instant' });
        }
      },
      { injector: this.injector },
    );
  }
  private context(): ChartSelection {
    return {
      configuration: this.selectedId(),
      scope: this.active().scope,
      index: this.active().index,
    };
  }
  private edit(change: (value: ResultConfigurationDocument) => void): boolean {
    if (this.disabled()) return false;
    const before = this.document(),
      next = structuredClone(before);
    change(next);
    this.previousContext = this.context();
    this.previous.set(before);
    this.lastEdited = next;
    this.document.set(next);
    return true;
  }
  add(): void {
    if (this.disabled()) return;
    const id = newParameterId(this.idPrefix()).slice(0, 50);
    this.edit((value) => {
      value.elements[id] = { name: 'New configuration', all: [], job: [] };
    });
    this.selectConfiguration(id);
    this.selectScope(this.initialScope() === 'job' ? 'job' : 'all');
    this.focusControl(this.namespace + '-name');
  }
  copy(): void {
    const entry = this.selected();
    if (this.disabled() || !entry) return;
    const id = newParameterId(this.idPrefix()).slice(0, 50);
    this.edit((value) => {
      value.elements[id] = { ...structuredClone(entry), name: entry.name + ' (copy)' };
    });
    this.selectConfiguration(id);
    this.focusControl(this.namespace + '-name');
  }
  rename(name: string): void {
    const id = this.selectedId();
    if (!this.selected() || this.selected().name === name) return;
    this.edit((value) => {
      value.elements[id].name = name;
    });
  }
  requestDelete(): void {
    if (!this.disabled() && this.selected()) this.pendingDelete.set(this.selectedId());
  }
  remove(): void {
    const id = this.selectedId();
    if (this.disabled() || !this.selected()) return;
    this.edit((value) => {
      delete value.elements[id];
    });
    this.selectConfiguration('');
  }
  addPlot(scope: Scope): void {
    const id = this.selectedId(),
      configuration = this.selected();
    if (this.disabled() || !configuration) return;
    const type = this.types().some((item) => item.type === this.newType())
      ? this.newType()
      : 'bar-plot';
    const index = configuration[scope].length;
    this.edit((value) =>
      value.elements[id][scope].push({
        id: newParameterId('plot'),
        type,
        name: 'New chart',
        parameter: '',
      }),
    );
    this.search.set('');
    this.selectPlot(scope, index);
  }
  update(scope: Scope, index: number, key: string, value: string): void {
    const id = this.selectedId(),
      plot = this.selected()?.[scope][index];
    if (!plot || plot[key] === value) return;
    this.edit((document) => {
      document.elements[id][scope][index] = {
        ...document.elements[id][scope][index],
        [key]: value,
      };
    });
  }
  deletePlot(scope: Scope, index: number): void {
    const plot = this.selected()?.[scope][index];
    if (
      this.disabled() ||
      !plot ||
      !window.confirm('Delete plot “' + plot.name + '” from the draft?')
    )
      return;
    const id = this.selectedId(),
      selected = this.context();
    if (selected.scope === scope && selected.index !== null) {
      if (selected.index === index)
        selected.index =
          this.selected()[scope].length > 1
            ? Math.min(index, this.selected()[scope].length - 2)
            : null;
      else if (selected.index > index) selected.index--;
    }
    this.edit((value) => {
      value.elements[id][scope].splice(index, 1);
    });
    this.focused.set(selected);
  }
  move(scope: Scope, index: number, direction: number): void {
    const plots = this.selected()?.[scope],
      next = index + direction;
    if (this.disabled() || !plots?.[index] || !plots[next] || !Number.isInteger(next)) return;
    const id = this.selectedId(),
      selected = this.context();
    if (selected.scope === scope) {
      if (selected.index === index) selected.index = next;
      else if (selected.index === next) selected.index = index;
    }
    this.edit((value) => {
      const items = value.elements[id][scope];
      [items[index], items[next]] = [items[next], items[index]];
    });
    this.focused.set(selected);
  }
  undo(): void {
    if (this.disabled() || !this.previous()) return;
    const current = this.document(),
      selected = this.context(),
      restore = this.previousContext;
    this.lastEdited = this.previous()!;
    this.document.set(this.lastEdited);
    this.previous.set(current);
    this.previousContext = selected;
    this.selection.set(restore.configuration);
    this.focused.set(restore);
    this.search.set('');
    this.pendingDelete.set('');
    this.closePreview();
  }
  openPreview(trigger: HTMLButtonElement): void {
    const dialog = this.previewDialog?.nativeElement;
    if (!dialog || dialog.open || !this.selected()) return;
    trigger.focus({ preventScroll: true });
    this.preview.set(true);
    dialog.showModal();
  }
  closePreview(): void {
    this.preview.set(false);
    const dialog = this.previewDialog?.nativeElement;
    if (dialog?.open) dialog.close();
  }
  previewClosed(): void {
    if (!this.previewDialog?.nativeElement.open) this.preview.set(false);
  }
  cancelPreview(event: Event): void {
    event.preventDefault();
    this.closePreview();
  }
  dismissPreview(event: MouseEvent): void {
    const dialog = this.previewDialog?.nativeElement;
    if (!dialog || event.target !== dialog) return;
    const bounds = dialog.getBoundingClientRect();
    if (
      event.clientX < bounds.left ||
      event.clientX > bounds.right ||
      event.clientY < bounds.top ||
      event.clientY > bounds.bottom
    )
      this.closePreview();
  }
}
