import {
  ChangeDetectorRef,
  Component,
  DestroyRef,
  ElementRef,
  ViewChild,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Api, errorMessage } from '../../core/api';
import { LiveSubscription, Realtime } from '../../core/realtime';
import { Activity, PageData } from '../../core/models';
import { Evaluation, Experiment, RunOptions } from '../../core/workflow-models';
import { UnsavedChanges } from '../../core/unsaved';
import { dateTime } from '../../core/time';
import { Icon } from '../../shared/icon';
import { Feedback } from '../../shared/feedback';
import { ActivityList } from '../../shared/activity';
import { ExperimentResults } from '../results/experiment-results';
import { DataColumn, DataGrid, localGridRows } from '../../shared/data-grid';

@Component({
  imports: [FormsModule, RouterLink, Feedback, Icon, ActivityList, ExperimentResults, DataGrid],
  templateUrl: './experiment-detail.html',
  styleUrl: './workflow-reference.scss',
})
export class ExperimentDetail {
  private readonly api = inject(Api);
  private readonly realtime = inject(Realtime);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly detector = inject(ChangeDetectorRef);
  readonly changes = inject(UnsavedChanges).scope(inject(DestroyRef));
  readonly formatTime = dateTime;
  @ViewChild('runDialog') private dialog!: ElementRef<HTMLDialogElement>;
  private readonly resultConfigurations = viewChild(ExperimentResults);
  readonly experiment = signal<Experiment | null>(null);
  readonly evaluations = signal<Evaluation[]>([]);
  readonly events = signal<Activity[]>([]);
  readonly activityError = signal('');
  readonly options = signal<RunOptions | null>(null);
  readonly evaluationPageSize = signal(25);
  readonly defaultEnvironments = signal<RunOptions['environments']>([]);
  readonly environmentError = signal('');
  readonly evaluationColumns: DataColumn<Evaluation>[] = [
    { key: 'id', label: 'ID', value: (row) => row.internalId },
    { key: 'name', label: 'Name', value: (row) => row.name },
    {
      key: 'description',
      label: 'Description',
      value: (row) => row.description,
      className: 'description',
    },
    { key: 'actions', label: 'Actions', value: () => '', sortable: false, searchable: false },
  ];
  readonly evaluationSortColumns: DataColumn<Evaluation>[] = [
    { key: 'starred', label: 'Starred', value: (row) => (row.starred ? 'Starred' : 'Not starred') },
  ];
  readonly evaluationLink = (row: Evaluation): (string | number)[] => ['/evaluations', row.id];
  readonly busy = signal(true);
  readonly saving = signal(false);
  readonly error = signal('');
  readonly success = signal('');
  readonly runError = signal('');
  readonly optionsBusy = signal(false);
  name = '';
  description = '';
  defaultEnvironment = '';
  archived = 'false';
  runName = '';
  runDescription = '';
  environment = '';
  versions: Record<string, string[]> = {};
  private id = 0;
  private generation = 0;
  private evaluationsSequence = 0;
  private runDirty = false;
  private metadataDirty = false;
  private resultsDirty = false;
  private activitySubscription?: LiveSubscription<PageData<Activity>>;
  private activityId = 0;
  private activitySequence = 0;
  constructor() {
    inject(DestroyRef).onDestroy(() => {
      ++this.generation;
      ++this.activitySequence;
      this.activitySubscription?.close();
      this.changes.setDirty(false);
    });
    this.route.paramMap.pipe(takeUntilDestroyed()).subscribe((params) => {
      this.id = Number(params.get('id'));
      ++this.generation;
      this.changes.reset();
      this.metadataDirty = false;
      this.resultsDirty = false;
      this.runDirty = false;
      this.saving.set(false);
      this.optionsBusy.set(false);
      this.experiment.set(null);
      void this.load();
    });
  }
  async load(): Promise<void> {
    const generation = this.generation;
    const id = this.id;
    this.busy.set(true);
    this.error.set('');
    this.environmentError.set('');
    this.watchActivity();
    try {
      const experiment = await this.api.get<Experiment>(`/experiments/${id}`);
      if (generation !== this.generation) return;
      this.experiment.set(experiment);
      if (!this.changes.dirty()) {
        this.name = experiment.name;
        this.description = experiment.description;
        this.defaultEnvironment = experiment.defaultEnvironment ?? '';
      }
      await Promise.all([
        this.loadEvaluations(),
        this.loadDefaultEnvironments(generation, id),
      ]);
    } catch (error) {
      if (generation === this.generation) this.error.set(errorMessage(error));
    } finally {
      if (generation === this.generation) this.busy.set(false);
    }
  }
  private async loadDefaultEnvironments(generation: number, id: number): Promise<void> {
    try {
      const options = await this.api.get<RunOptions>(`/experiments/${id}/run-options`);
      if (generation === this.generation) this.defaultEnvironments.set(options.environments);
    } catch (error) {
      if (generation === this.generation) this.environmentError.set(errorMessage(error));
    }
  }
  async loadEvaluations(): Promise<void> {
    const generation = this.generation,
      sequence = ++this.evaluationsSequence,
      archived = this.archived;
    try {
      const data = await localGridRows<Evaluation>(
        this.api,
        `/experiments/${this.id}/evaluations`,
        { archived },
      );
      if (
        generation !== this.generation ||
        sequence !== this.evaluationsSequence ||
        archived !== this.archived
      )
        return;
      this.evaluations.set(data.rows);
      this.evaluationPageSize.set(data.pageSize);
    } catch (error) {
      if (
        generation === this.generation &&
        sequence === this.evaluationsSequence &&
        archived === this.archived
      )
        this.error.set(errorMessage(error));
    }
  }
  private watchActivity(): void {
    if (this.activitySubscription && this.activityId === this.id) return;
    this.activitySubscription?.close();
    const sequence = ++this.activitySequence;
    this.activityId = this.id;
    this.events.set([]);
    this.activityError.set('');
    this.activitySubscription = this.realtime.watch<PageData<Activity>>(`/experiments/${this.id}/events`, { pageSize: 20, sort: '-id' }, data => {
      if (sequence === this.activitySequence) { this.events.set(data.items); this.activityError.set(''); }
    }, error => { if (sequence === this.activitySequence) this.activityError.set(errorMessage(error)); });
    // Activity must not hold the general editor or evaluation controls busy.
    void this.activitySubscription.ready.catch(() => {});
  }
  defaultEnvironmentAvailable(): boolean {
    return this.defaultEnvironments().some(
      (environment) => environment.key === this.defaultEnvironment,
    );
  }
  metadataChanged(): void {
    this.metadataDirty = true;
    this.syncDirty();
  }
  resultsChanged(dirty: boolean): void {
    this.resultsDirty = dirty;
    this.syncDirty();
  }
  resultSelected(id: string): void {
    this.experiment.update((value) => (value ? { ...value, resultId: id } : value));
  }
  private syncDirty(): void {
    this.changes.setDirty(this.metadataDirty || this.resultsDirty || this.runDirty);
  }
  async save(): Promise<void> {
    await this.mutate(async (current) => {
      const value = await this.api.patch<Experiment>(`/experiments/${this.id}`, {
        name: this.name,
        description: this.description,
        ...(this.defaultEnvironment &&
        this.defaultEnvironment !== this.experiment()?.defaultEnvironment
          ? { defaultEnvironment: this.defaultEnvironment }
          : {}),
      });
      if (!current()) return;
      this.experiment.set(value);
      this.metadataDirty = false;
      this.syncDirty();
    }, 'Experiment saved.');
  }
  async toggleArchive(evaluation: Evaluation): Promise<void> {
    if (!window.confirm(`${evaluation.archived ? 'Restore' : 'Archive'} ${evaluation.name}?`))
      return;
    await this.mutate(
      async (current) => {
        await this.api.patch(`/evaluations/${evaluation.id}`, { archived: !evaluation.archived });
        if (current()) await this.loadEvaluations();
      },
      evaluation.archived ? 'Evaluation restored.' : 'Evaluation archived.',
    );
  }
  async openRun(): Promise<void> {
    if (this.runUnavailable() || this.dialog.nativeElement.open) return;
    if (this.resultsDirty) {
      this.error.set(
        'Save or discard changes in Result configurations before running an evaluation.',
      );
      return;
    }
    if (!this.changes.mayDiscard()) return;
    const existing = this.experiment();
    if (existing) {
      this.name = existing.name;
      this.description = existing.description;
      this.defaultEnvironment = existing.defaultEnvironment ?? '';
    }
    this.metadataDirty = false;
    this.runDirty = false;
    this.error.set('');
    this.syncDirty();
    const generation = this.generation;
    this.optionsBusy.set(true);
    this.options.set(null);
    this.environment = '';
    this.versions = {};
    this.runName = `Evaluation ${dateTime(new Date().toISOString())}`;
    this.runDescription = '';
    this.runDirty = false;
    this.runError.set('');
    // Flush queued NgModel defaults while hidden, before making the inputs
    // visible. Fast input must never be overwritten by a later default write.
    this.detector.detectChanges();
    await Promise.resolve();
    if (generation !== this.generation) return;
    this.dialog.nativeElement.showModal();
    try {
      const options = await this.api.get<RunOptions>(`/experiments/${this.id}/run-options`);
      if (generation !== this.generation) return;
      this.options.set(options);
      this.environment = options.defaultEnvironment || options.environments[0]?.key || '';
      this.versions = Object.fromEntries(
        options.versions.map((version) => [version.parameter, [...version.values]]),
      );
    } catch (error) {
      if (generation === this.generation) this.runError.set(errorMessage(error));
    } finally {
      if (generation === this.generation) {
        // Environment/version controls are initialized while still disabled.
        this.detector.detectChanges();
        await Promise.resolve();
        if (generation === this.generation) {
          this.optionsBusy.set(false);
          this.detector.detectChanges();
        }
      }
    }
  }
  runUnavailable(): boolean {
    return (
      this.busy() ||
      this.saving() ||
      this.optionsBusy() ||
      this.resultConfigurations()?.loading() === true
    );
  }
  runChanged(): void {
    this.runDirty = true;
    this.syncDirty();
  }
  cancelRun(event?: Event): void {
    event?.preventDefault();
    if (
      !this.saving() &&
      (!this.runDirty || window.confirm('Discard this evaluation configuration?'))
    ) {
      this.dialog.nativeElement.close();
      this.runDirty = false;
      this.syncDirty();
    }
  }
  async run(): Promise<void> {
    if (this.saving() || this.optionsBusy() || !this.options() || !this.environment) return;
    const current = this.changes.checkpoint();
    this.saving.set(true);
    this.runError.set('');
    try {
      const evaluation = await this.api.post<Evaluation>(`/experiments/${this.id}/evaluations`, {
        name: this.runName,
        description: this.runDescription,
        environment: this.environment,
        versions: this.versions,
      });
      if (!current()) return;
      this.runDirty = false;
      this.syncDirty();
      this.dialog.nativeElement.close();
      await this.router.navigate(['/evaluations', evaluation.id]);
    } catch (error) {
      if (current()) this.runError.set(errorMessage(error));
    } finally {
      if (current()) this.saving.set(false);
    }
  }
  private async mutate(
    action: (current: () => boolean) => Promise<void>,
    success: string,
  ): Promise<void> {
    if (this.saving()) return;
    const current = this.changes.checkpoint();
    this.saving.set(true);
    this.error.set('');
    this.success.set('');
    try {
      await action(current);
      if (current()) this.success.set(success);
    } catch (error) {
      if (current()) this.error.set(errorMessage(error));
    } finally {
      if (current()) this.saving.set(false);
    }
  }
}
