import { Component, computed, effect, input, model, output, signal } from '@angular/core';
import { PLOT_TYPES, PlotDefinition, ResultConfigurationDocument, PlotTypeAliases } from '../../core/results-models';
import { domNamespace, newParameterId } from '../parameters/parameter-state';
import { isPlotType, plotRenderType, validateResultConfigurations } from './result-state';

@Component({
  selector: 'app-result-configuration-editor',
  template: `
    @if (issues().length) { <div class="alert alert-danger" role="status"><ul class="mb-0">@for (issue of issues(); track $index) { <li>{{ issue }}</li> }</ul></div> }
    <fieldset [disabled]="disabled()"><legend class="visually-hidden">Result configuration editor</legend>
      <div class="d-flex flex-wrap gap-2 mb-3"><button type="button" class="btn btn-outline-primary" (click)="add()">New configuration</button><button type="button" class="btn btn-outline-secondary" (click)="copy()" [disabled]="!selected()">Copy configuration</button><button type="button" class="btn btn-outline-secondary" (click)="undo()" [disabled]="!previous()">Undo last change</button></div>
      @if (entries().length) {
        <label class="form-label" [for]="namespace + '-configuration'">Result configuration</label><select class="form-select mb-3" [id]="namespace + '-configuration'" [value]="selectedId()" (change)="selection.set($any($event.target).value)">@for (entry of entries(); track entry[0]) { <option [selected]="entry[0] === selectedId()" [value]="entry[0]">{{ entry[1].name }} ({{ entry[0] }})</option> }</select>
        @if (selected(); as configuration) {
          <div class="row g-3 mb-3"><div class="col-12 col-md-8"><label class="form-label" [for]="namespace + '-name'">Configuration name</label><input class="form-control" [id]="namespace + '-name'" [value]="configuration.name" (input)="rename($any($event.target).value)" /></div><div class="col-12 col-md-4 d-flex align-items-end"><button class="btn btn-outline-danger" type="button" (click)="pendingDelete.set(selectedId())">Delete configuration…</button></div></div>
          @if (pendingDelete() === selectedId()) { <div class="alert alert-warning" role="alert">Delete this configuration from the draft? Experiments using it will prevent saving.<div class="d-flex gap-2 mt-2"><button class="btn btn-danger" type="button" (click)="remove()">Delete configuration</button><button class="btn btn-outline-secondary" type="button" (click)="pendingDelete.set('')">Cancel</button></div></div> }
          @for (scope of scopes(); track scope.key) {
            <section class="border rounded p-3 mb-3"><h3 class="fs-5">{{ scope.label }}</h3><p class="text-secondary">{{ scope.help }}</p>
              @for (plot of configuration[scope.key]; track $index; let index = $index) {
                <fieldset class="border rounded p-3 mb-3"><legend class="float-none w-auto fs-6 px-1">{{ plot.name || 'Untitled plot' }}</legend>
                  @if (!supported(plot.type)) { <div class="alert alert-danger">Unsupported plot type “{{ plot.type }}”. Its definition is preserved.</div> }
                  <div class="row g-3"><div class="col-12 col-md-6"><label class="form-label" [for]="id(scope.key,index,'type')">Chart type</label><select class="form-select" [id]="id(scope.key,index,'type')" [value]="plot.type" (change)="update(scope.key,index,'type',$any($event.target).value)">@if (!supported(plot.type)) { <option [selected]="true" [value]="plot.type">{{ plot.type }} (unsupported)</option> }@for (kind of types(); track kind.type) { <option [selected]="kind.type === plot.type" [value]="kind.type">{{ kind.label }}</option> }</select></div><div class="col-12 col-md-6"><label class="form-label" [for]="id(scope.key,index,'name')">Title</label><input class="form-control" [id]="id(scope.key,index,'name')" [value]="plot.name" (input)="update(scope.key,index,'name',$any($event.target).value)" /></div>
                  <div class="col-12 col-md-6"><label class="form-label" [for]="id(scope.key,index,'parameter')">{{ complex(plot.type) ? 'Configuration parameters to iterate over' : 'Result metric names' }} (comma-separated)</label><input class="form-control" [id]="id(scope.key,index,'parameter')" [value]="plot.parameter" (input)="update(scope.key,index,'parameter',$any($event.target).value)" /></div>
                  @if (complex(plot.type)) { <div class="col-12 col-md-6"><label class="form-label" [for]="id(scope.key,index,'plotting')">Result metric to plot</label><input class="form-control" [id]="id(scope.key,index,'plotting')" [value]="plot.plotting ?? ''" (input)="update(scope.key,index,'plotting',$any($event.target).value)" /></div> }
                  @if (renderType(plot.type) === 'box-plot') { <div class="col-12 col-md-6"><label class="form-label" [for]="id(scope.key,index,'orientation')">Orientation</label><select class="form-select" [id]="id(scope.key,index,'orientation')" [value]="plot.orientation ?? 'vertical'" (change)="update(scope.key,index,'orientation',$any($event.target).value)"><option [selected]="(plot.orientation ?? 'vertical') === 'vertical'" value="vertical">Vertical</option><option [selected]="plot.orientation === 'horizontal'" value="horizontal">Horizontal</option></select></div> }</div>
                  <div class="d-flex flex-wrap gap-2 mt-3"><button class="btn btn-sm btn-outline-secondary" type="button" (click)="move(scope.key,index,-1)" [disabled]="index === 0" [attr.aria-label]="'Move ' + plot.name + ' up'">Move up</button><button class="btn btn-sm btn-outline-secondary" type="button" (click)="move(scope.key,index,1)" [disabled]="index === configuration[scope.key].length - 1" [attr.aria-label]="'Move ' + plot.name + ' down'">Move down</button><button class="btn btn-sm btn-outline-danger" type="button" (click)="deletePlot(scope.key,index)">Delete plot</button></div>
                </fieldset>
              }
              <button type="button" class="btn btn-outline-primary" (click)="addPlot(scope.key)">Add chart</button>
            </section>
          }
        }
      } @else { <p class="text-secondary">No result configuration is defined. Add one to configure evaluation and individual job charts.</p> }
    </fieldset>
  `,
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
  readonly selection = signal(''); readonly pendingDelete = signal('');
  readonly entries = computed(() => Object.entries(this.document().elements));
  readonly selectedId = computed(() => Object.hasOwn(this.document().elements, this.selection()) ? this.selection() : this.entries()[0]?.[0] ?? '');
  readonly selected = computed(() => this.document().elements[this.selectedId()]);
  readonly issues = computed(() => validateResultConfigurations(this.document(), this.aliases()));
  readonly namespace = domNamespace(); readonly types = computed(() => [...PLOT_TYPES, ...Object.entries(this.aliases()).filter(([type, base]) => !isPlotType(type) && isPlotType(base)).map(([type, base]) => ({ type, label: `${type} (${PLOT_TYPES.find(item => item.type === base)!.label})` }))]);
  renderType(type: string): string { return plotRenderType(type, this.aliases()); }
  supported(type: string): boolean { return isPlotType(this.renderType(type)); }
  private readonly scopeDefinitions = [{ key: 'all', label: 'All jobs', help: 'Charts compare all finished job configurations in an evaluation.' }, { key: 'job', label: 'Individual configurations', help: 'Charts are repeated for each job configuration and aggregate its finished runs.' }] as const;
  readonly scopes = computed(() => this.initialScope() === 'job' ? [...this.scopeDefinitions].reverse() : this.scopeDefinitions);
  private lastEdited: ResultConfigurationDocument | null = null;
  constructor() {
    effect(() => { this.selection.set(this.configurationId()); });
    effect(() => { this.validity.emit(this.issues().length === 0); this.validation.emit(this.issues()); });
    effect(() => { if (this.document() !== this.lastEdited) { this.previous.set(null); this.pendingDelete.set(''); } });
  }
  complex(type: string): boolean { return ['line-plot-multi', 'box-plot'].includes(this.renderType(type)); }
  id(scope: string, index: number, field: string): string { return `${this.namespace}-${scope}-${index}-${field}`; }
  private edit(change: (value: ResultConfigurationDocument) => void): void {
    if (this.disabled()) return;
    const before = this.document(), next = structuredClone(before); change(next);
    this.previous.set(before); this.lastEdited = next; this.document.set(next);
  }
  add(): void { const id = newParameterId(this.idPrefix()).slice(0, 50); this.edit(value => { value.elements[id] = { name: 'New configuration', all: [], job: [] }; }); this.selection.set(id); }
  copy(): void { const entry = this.selected(); if (!entry) return; const id = newParameterId(this.idPrefix()).slice(0, 50); this.edit(value => { value.elements[id] = { ...structuredClone(entry), name: `${entry.name} (copy)` }; }); this.selection.set(id); }
  rename(name: string): void { const id = this.selectedId(); this.edit(value => { value.elements[id].name = name; }); }
  remove(): void { const id = this.selectedId(); this.edit(value => { delete value.elements[id]; }); this.pendingDelete.set(''); }
  addPlot(scope: 'all' | 'job'): void { const id = this.selectedId(); this.edit(value => value.elements[id][scope].push({ id: newParameterId('plot'), type: 'bar-plot', name: 'New chart', parameter: '' })); }
  update(scope: 'all' | 'job', index: number, key: string, value: string): void { const id = this.selectedId(); this.edit(document => { document.elements[id][scope][index] = { ...document.elements[id][scope][index], [key]: value }; }); }
  deletePlot(scope: 'all' | 'job', index: number): void { if (this.disabled() || !window.confirm(`Delete plot “${this.selected()[scope][index].name}” from the draft?`)) return; const id = this.selectedId(); this.edit(value => { value.elements[id][scope].splice(index, 1); }); }
  move(scope: 'all' | 'job', index: number, direction: number): void { const id = this.selectedId(); this.edit(value => { const plots: PlotDefinition[] = value.elements[id][scope], next = index + direction; if (next >= 0 && next < plots.length) [plots[index], plots[next]] = [plots[next], plots[index]]; }); }
  undo(): void { if (!this.disabled() && this.previous()) { const current = this.document(); this.lastEdited = this.previous()!; this.document.set(this.lastEdited); this.previous.set(current); } }
}
