import { Component, DestroyRef, computed, effect, inject, input, output, signal, untracked } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { Api, ApiError, errorMessage } from '../../core/api';
import { ResultConfiguration, ResultConfigurationDocument, PlotTypeAliases } from '../../core/results-models';
import { Feedback } from '../../shared/feedback';
import { domNamespace, newParameterId } from '../parameters/parameter-state';
import { ResultConfigurationEditor } from './result-configuration-editor';

@Component({
  selector: 'app-experiment-results', imports: [Feedback, ResultConfigurationEditor],
  template: `
    <section class="card"><div class="card-header"><h2 class="card-title">Result configurations</h2></div><div class="card-body">
      <app-feedback [busy]="loading() || saving()" [error]="error()" [success]="success()" />
      @if (synchronizationFailed()) { <button class="btn btn-outline-primary mb-3" type="button" [disabled]="disabled() || saving()" (click)="retrySynchronization()">Push committed changes</button> }
      @if (loaded()) {
        <p class="text-secondary">Use a system configuration, or create an experiment configuration with its own chart definitions.</p>
        <fieldset [disabled]="saving() || disabled()"><legend class="visually-hidden">Select experiment results</legend><label class="form-label" [for]="namespace + '-selection'">Configuration used for this experiment's results</label><div class="d-flex flex-wrap gap-2 mb-4"><select class="form-select flex-grow-1 w-auto" [id]="namespace + '-selection'" [value]="selection()" (change)="selection.set($any($event.target).value)"><option [selected]="!selection()" value="">Choose a configuration</option>@for (entry of savedEntries(); track entry[0]) { <option [selected]="entry[0] === selection()" [value]="entry[0]">{{ entry[1].name }}{{ entry[0].startsWith('system-') ? ' (system)' : '' }}</option> }</select><button class="btn btn-outline-primary" type="button" (click)="select()" [disabled]="!selection() || selection() === resultId()">Use selected configuration</button></div>
        @if (systemEntries().length) { <h3 class="fs-5">System configurations</h3><div class="table-responsive mb-4"><table class="table table-striped align-middle"><caption>System definitions are managed by the system owner. Copy one to customize its charts for this experiment.</caption><thead><tr><th scope="col">Configuration</th><th scope="col">Overall charts</th><th scope="col">Individual charts</th><th scope="col">Action</th></tr></thead><tbody>@for (entry of systemEntries(); track entry[0]) { <tr><th scope="row">{{ entry[1].name }}</th><td>{{ entry[1].all.length }}</td><td>{{ entry[1].job.length }}</td><td><button class="btn btn-sm btn-outline-secondary" type="button" (click)="copySystem(entry[1])">Copy to this experiment</button></td></tr> }</tbody></table></div> }
        </fieldset>
        <h3 class="fs-5">Experiment configurations</h3><app-result-configuration-editor [configurationId]="requestedConfiguration()" [initialScope]="requestedScope()" [aliases]="aliases()" [document]="editable()" (documentChange)="update($event)" [idPrefix]="prefix()" [disabled]="saving() || disabled()" (validity)="valid.set($event)" />
        <div class="d-flex flex-wrap gap-2"><button class="btn btn-primary" type="button" [disabled]="disabled() || saving() || !dirty() || !valid()" (click)="save()">Save experiment configurations</button><button class="btn btn-outline-secondary" type="button" [disabled]="disabled() || saving() || !dirty()" (click)="discard()">Discard configuration changes</button></div>
      } @else if (!loading()) { <button class="btn btn-outline-primary" type="button" (click)="load()">Retry loading configurations</button> }
    </div></section>
  `,
})
export class ExperimentResults {
  readonly experimentId = input.required<number>(); readonly systemId = input.required<number>(); readonly resultId = input(''); readonly disabled = input(false);
  readonly configurationId = input(''); readonly initialScope = input<'all' | 'job' | ''>('');
  readonly resultIdChange = output<string>(); readonly dirtyChange = output<boolean>();
  private readonly api = inject(Api); private readonly route = inject(ActivatedRoute, { optional: true });
  private readonly queryConfiguration = signal(''); private readonly queryScope = signal<'all' | 'job' | ''>('');
  readonly requestedConfiguration = computed(() => this.configurationId() || this.queryConfiguration());
  readonly requestedScope = computed(() => this.initialScope() || this.queryScope());
  readonly namespace = domNamespace(); readonly loading = signal(false); readonly saving = signal(false); readonly loaded = signal(false); readonly dirty = signal(false); readonly valid = signal(true); readonly error = signal(''); readonly success = signal(''); readonly selection = signal('');
  readonly synchronizationFailed = signal(false); readonly aliases = signal<PlotTypeAliases>({});
  readonly saved = signal<ResultConfigurationDocument>({ version: '1.0', elements: {} }); readonly editable = signal<ResultConfigurationDocument>({ version: '1.0', elements: {} });
  readonly prefix = computed(() => `experiment-${this.experimentId()}`);
  readonly savedEntries = computed(() => Object.entries(this.saved().elements)); readonly systemEntries = computed(() => this.savedEntries().filter(([id]) => id.startsWith('system-')));
  private generation = 0;
  constructor() {
    effect(() => { const id = this.experimentId(); untracked(() => { void this.load(id); }); });
    const querySubscription = this.route?.queryParamMap.subscribe(query => { this.queryConfiguration.set(query.get('configuration') ?? ''); const scope = query.get('scope'); this.queryScope.set(scope === 'all' || scope === 'job' ? scope : ''); });
    inject(DestroyRef).onDestroy(() => { querySubscription?.unsubscribe(); ++this.generation; });
    effect(() => { this.selection.set(this.resultId()); });
    effect(() => { this.dirtyChange.emit(this.dirty()); });
  }
  async load(id = this.experimentId()): Promise<void> {
    const generation = ++this.generation; this.loading.set(true); this.error.set('');
    try {
      const [value, registry] = await Promise.all([this.api.get<ResultConfigurationDocument>(`/experiments/${id}/result-configurations`), this.api.get<{ aliases?: { plots?: PlotTypeAliases } }>(`/systems/${this.systemId()}/extensions`).catch(() => null)]);
      if (generation !== this.generation) return; this.aliases.set(registry?.aliases?.plots ?? {}); this.saved.set(value); this.resetDraft(); this.loaded.set(true);
    }
    catch (error) { if (generation === this.generation) this.error.set(errorMessage(error)); }
    finally { if (generation === this.generation) this.loading.set(false); }
  }
  private resetDraft(): void { this.editable.set({ ...structuredClone(this.saved()), elements: Object.fromEntries(Object.entries(this.saved().elements).filter(([id]) => id.startsWith(`${this.prefix()}-`))) }); this.dirty.set(false); }
  update(value: ResultConfigurationDocument): void { this.editable.set(value); this.dirty.set(true); }
  copySystem(configuration: ResultConfiguration): void { if (this.disabled() || this.saving()) return; const id = newParameterId(this.prefix()).slice(0, 50); this.update({ ...this.editable(), elements: { ...this.editable().elements, [id]: { ...structuredClone(configuration), name: `${configuration.name} (copy)` } } }); }
  async save(): Promise<void> {
    if (this.disabled() || this.saving() || !this.valid()) return;
    this.saving.set(true); this.error.set(''); this.success.set('');
    try { const document = { ...this.editable(), elements: { ...Object.fromEntries(this.systemEntries()), ...this.editable().elements } }; this.saved.set(await this.api.request<ResultConfigurationDocument>('PUT', `/experiments/${this.experimentId()}/result-configurations`, document)); this.resetDraft(); this.synchronizationFailed.set(false); this.success.set('Experiment result configurations saved.'); }
    catch (error) { if (error instanceof ApiError && error.code === 'repository_sync_failed') { this.synchronizationFailed.set(true); await this.load(); } this.error.set(errorMessage(error)); } finally { this.saving.set(false); }
  }
  async retrySynchronization(): Promise<void> {
    if (this.disabled() || this.saving()) return;
    this.saving.set(true); this.error.set(''); this.success.set('');
    try { await this.api.post(`/experiments/${this.experimentId()}/result-configurations/push`, {}); this.synchronizationFailed.set(false); this.success.set('Committed experiment result configuration synchronized.'); }
    catch (error) { this.error.set(errorMessage(error)); } finally { this.saving.set(false); }
  }
  async select(): Promise<void> {
    if (this.disabled() || this.saving() || !this.selection()) return;
    this.saving.set(true); this.error.set(''); this.success.set('');
    try { await this.api.patch(`/experiments/${this.experimentId()}`, { resultId: this.selection() }); this.resultIdChange.emit(this.selection()); this.success.set('The selected result configuration is now used by this experiment.'); }
    catch (error) { this.error.set(errorMessage(error)); } finally { this.saving.set(false); }
  }
  discard(): void { if (!this.disabled() && !this.saving()) this.resetDraft(); }
}
