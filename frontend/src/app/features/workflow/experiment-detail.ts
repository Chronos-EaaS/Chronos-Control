import { Component, DestroyRef, ElementRef, ViewChild, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Api, errorMessage } from '../../core/api';
import { Activity, PageData } from '../../core/models';
import { Evaluation, Experiment, RunOptions } from '../../core/workflow-models';
import { UnsavedChanges } from '../../core/unsaved';
import { dateTime } from '../../core/time';
import { Feedback } from '../../shared/feedback';
import { ActivityList } from '../../shared/activity';
import { ExperimentResults } from '../results/experiment-results';
import { DataColumn, DataGrid, localGridRows } from '../../shared/data-grid';

@Component({ imports: [FormsModule, RouterLink, Feedback, ActivityList, ExperimentResults, DataGrid], templateUrl: './experiment-detail.html' })
export class ExperimentDetail {
  private readonly api = inject(Api); private readonly route = inject(ActivatedRoute); private readonly router = inject(Router);
  readonly changes = inject(UnsavedChanges); readonly formatTime = dateTime;
  @ViewChild('runDialog') private dialog!: ElementRef<HTMLDialogElement>;
  readonly experiment = signal<Experiment | null>(null); readonly evaluations = signal<Evaluation[]>([]); readonly events = signal<Activity[]>([]); readonly options = signal<RunOptions | null>(null);
  readonly evaluationPageSize = signal(25); readonly defaultEnvironments = signal<RunOptions['environments']>([]); readonly environmentError = signal('');
  readonly evaluationColumns: DataColumn<Evaluation>[] = [
    { key: 'id', label: 'ID', value: row => row.internalId }, { key: 'name', label: 'Name', value: row => row.name },
    { key: 'description', label: 'Description', value: row => row.description, className: 'description' },
    { key: 'starred', label: 'Starred', value: row => row.starred ? 'Starred' : 'Not starred' },
    { key: 'actions', label: 'Actions', value: () => '', sortable: false, searchable: false },
  ];
  readonly busy = signal(true); readonly saving = signal(false); readonly error = signal(''); readonly success = signal(''); readonly runError = signal(''); readonly optionsBusy = signal(false);
  name = ''; description = ''; defaultEnvironment = ''; archived = 'false'; runName = ''; runDescription = ''; environment = ''; versions: Record<string, string[]> = {}; private id = 0; private generation = 0; private runDirty = false; private metadataDirty = false; private resultsDirty = false;
  constructor() {
    inject(DestroyRef).onDestroy(() => { ++this.generation; this.changes.dirty.set(false); });
    this.route.paramMap.pipe(takeUntilDestroyed()).subscribe(params => { this.id = Number(params.get('id')); ++this.generation; this.experiment.set(null); void this.load(); });
  }
  async load(): Promise<void> {
    const generation = this.generation; const id = this.id;
    this.busy.set(true); this.error.set(''); this.environmentError.set('');
    try {
      const experiment = await this.api.get<Experiment>(`/experiments/${id}`); if (generation !== this.generation) return;
      this.experiment.set(experiment); if (!this.changes.dirty()) { this.name = experiment.name; this.description = experiment.description; this.defaultEnvironment = experiment.defaultEnvironment ?? ''; }
      await Promise.all([this.loadEvaluations(), this.loadEvents(), this.loadDefaultEnvironments(generation, id)]);
    } catch (error) { if (generation === this.generation) this.error.set(errorMessage(error)); }
    finally { if (generation === this.generation) this.busy.set(false); }
  }
  private async loadDefaultEnvironments(generation: number, id: number): Promise<void> {
    try { const options = await this.api.get<RunOptions>(`/experiments/${id}/run-options`); if (generation === this.generation) this.defaultEnvironments.set(options.environments); }
    catch (error) { if (generation === this.generation) this.environmentError.set(errorMessage(error)); }
  }
  async loadEvaluations(): Promise<void> { const generation = this.generation; try { const data = await localGridRows<Evaluation>(this.api, `/experiments/${this.id}/evaluations`, { archived: this.archived }); if (generation !== this.generation) return; this.evaluations.set(data.rows); this.evaluationPageSize.set(data.pageSize); } catch (error) { if (generation === this.generation) this.error.set(errorMessage(error)); } }
  private async loadEvents(): Promise<void> { const generation = this.generation; const events = await this.api.get<PageData<Activity>>(`/experiments/${this.id}/events`, { pageSize: 20, sort: '-id' }); if (generation === this.generation) this.events.set(events.items); }
  defaultEnvironmentAvailable(): boolean { return this.defaultEnvironments().some(environment => environment.key === this.defaultEnvironment); }
  metadataChanged(): void { this.metadataDirty = true; this.syncDirty(); }
  resultsChanged(dirty: boolean): void { this.resultsDirty = dirty; this.syncDirty(); }
  resultSelected(id: string): void { this.experiment.update(value => value ? { ...value, resultId: id } : value); }
  private syncDirty(): void { this.changes.dirty.set(this.metadataDirty || this.resultsDirty || this.runDirty); }
  async save(): Promise<void> { await this.mutate(async () => { this.experiment.set(await this.api.patch<Experiment>(`/experiments/${this.id}`, { name: this.name, description: this.description, ...(this.defaultEnvironment && this.defaultEnvironment !== this.experiment()?.defaultEnvironment ? { defaultEnvironment: this.defaultEnvironment } : {}) })); this.metadataDirty = false; this.syncDirty(); }, 'Experiment saved.'); }
  async toggleArchive(evaluation: Evaluation): Promise<void> { if (!window.confirm(`${evaluation.archived ? 'Restore' : 'Archive'} ${evaluation.name}?`)) return; await this.mutate(async () => { await this.api.patch(`/evaluations/${evaluation.id}`, { archived: !evaluation.archived }); await this.loadEvaluations(); }, evaluation.archived ? 'Evaluation restored.' : 'Evaluation archived.'); }
  async openRun(): Promise<void> {
    if (this.saving() || this.optionsBusy()) return;
    if (this.resultsDirty) {
      this.error.set('Save or discard changes in Result configurations before running an evaluation.');
      return;
    }
    if (!this.changes.mayDiscard()) return;
    const existing = this.experiment(); if (existing) { this.name = existing.name; this.description = existing.description; this.defaultEnvironment = existing.defaultEnvironment ?? ''; }
    this.metadataDirty = false; this.runDirty = false; this.error.set(''); this.syncDirty();
    this.runName = `Evaluation ${dateTime(new Date().toISOString())}`; this.runDescription = ''; this.runDirty = false; this.runError.set(''); this.options.set(null); this.optionsBusy.set(true); this.dialog.nativeElement.showModal();
    try { const options = await this.api.get<RunOptions>(`/experiments/${this.id}/run-options`); this.options.set(options); this.environment = options.defaultEnvironment || options.environments[0]?.key || ''; this.versions = Object.fromEntries(options.versions.map(version => [version.parameter, [...version.values]])); }
    catch (error) { this.runError.set(errorMessage(error)); } finally { this.optionsBusy.set(false); }
  }
  runChanged(): void { this.runDirty = true; this.syncDirty(); }
  cancelRun(event?: Event): void { event?.preventDefault(); if (!this.saving() && (!this.runDirty || window.confirm('Discard this evaluation configuration?'))) { this.dialog.nativeElement.close(); this.runDirty = false; this.syncDirty(); } }
  async run(): Promise<void> {
    if (this.saving() || !this.environment) return;
    this.saving.set(true); this.runError.set('');
    try { const evaluation = await this.api.post<Evaluation>(`/experiments/${this.id}/evaluations`, { name: this.runName, description: this.runDescription, environment: this.environment, versions: this.versions }); this.runDirty = false; this.syncDirty(); this.dialog.nativeElement.close(); await this.router.navigate(['/evaluations', evaluation.id]); }
    catch (error) { this.runError.set(errorMessage(error)); } finally { this.saving.set(false); }
  }
  private async mutate(action: () => Promise<void>, success: string): Promise<void> { if (this.saving()) return; this.saving.set(true); this.error.set(''); this.success.set(''); try { await action(); this.success.set(success); } catch (error) { this.error.set(errorMessage(error)); } finally { this.saving.set(false); } }
}
