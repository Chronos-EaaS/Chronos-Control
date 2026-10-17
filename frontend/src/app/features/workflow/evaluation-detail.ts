import { Component, DestroyRef, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Api, errorMessage } from '../../core/api';
import { Activity, PageData } from '../../core/models';
import { Evaluation, Job } from '../../core/workflow-models';
import { UnsavedChanges } from '../../core/unsaved';
import { Feedback } from '../../shared/feedback';
import { ActivityList } from '../../shared/activity';
import { ResultsExplorer } from '../results/results-explorer';

@Component({ imports: [FormsModule, RouterLink, Feedback, ActivityList, ResultsExplorer], templateUrl: './evaluation-detail.html' })
export class EvaluationDetail {
  private readonly api = inject(Api); private readonly route = inject(ActivatedRoute);
  readonly changes = inject(UnsavedChanges);
  readonly evaluation = signal<Evaluation | null>(null); readonly jobs = signal<Job[]>([]); readonly total = signal(0); readonly events = signal<Activity[]>([]);
  readonly busy = signal(true); readonly saving = signal(false); readonly error = signal(''); readonly success = signal('');
  name = ''; description = ''; status = 'all'; page = 1; pageSize = 25; sort = 'internalId'; search = ''; private id = 0; private refreshing = false; private generation = 0; private jobsSequence = 0; private pageSizeChosen = false;
  constructor() {
    const timer = setInterval(() => { if (!document.hidden && !this.saving() && !this.refreshing) void this.refresh(false); }, 5000);
    inject(DestroyRef).onDestroy(() => { clearInterval(timer); ++this.generation; ++this.jobsSequence; this.changes.dirty.set(false); });
    this.route.paramMap.pipe(takeUntilDestroyed()).subscribe(params => { this.id = Number(params.get('id')); ++this.generation; ++this.jobsSequence; this.evaluation.set(null); this.jobs.set([]); this.page = 1; this.pageSizeChosen = false; void this.refresh(true); });
  }
  async refresh(initial = false): Promise<void> {
    if (this.refreshing && !initial) return; this.refreshing = true; const generation = this.generation; const id = this.id;
    if (initial) { this.busy.set(true); this.error.set(''); }
    try {
      const evaluation = await this.api.get<Evaluation>(`/evaluations/${id}`); if (generation !== this.generation) return; this.evaluation.set(evaluation);
      if (!this.changes.dirty()) { this.name = evaluation.name; this.description = evaluation.description; }
      await Promise.all([this.loadJobs(), this.loadEvents()]);
    } catch (error) { if (generation === this.generation) this.error.set(errorMessage(error)); }
    finally { if (generation === this.generation) { this.refreshing = false; this.busy.set(false); } }
  }
  async loadJobs(): Promise<void> {
    const generation = this.generation; const sequence = ++this.jobsSequence;
    const query = { status: this.status, page: this.page, sort: this.sort, search: this.search, ...(this.pageSizeChosen ? { pageSize: this.pageSize } : {}) };
    try { const data = await this.api.get<PageData<Job>>(`/evaluations/${this.id}/jobs`, query); if (generation !== this.generation || sequence !== this.jobsSequence) return; this.jobs.set(data.items); this.total.set(data.total); this.page = data.page; this.pageSize = data.pageSize; }
    catch (error) { if (generation === this.generation && sequence === this.jobsSequence) this.error.set(errorMessage(error)); }
  }
  private async loadEvents(): Promise<void> { const generation = this.generation; const data = await this.api.get<PageData<Activity>>(`/evaluations/${this.id}/events`, { pageSize: 20, sort: '-id' }); if (generation === this.generation) this.events.set(data.items); }
  filterChanged(): void { this.page = 1; void this.loadJobs(); }
  sizeChanged(): void { this.pageSizeChosen = true; this.filterChanged(); }
  sizes(): number[] { return [...new Set([5, 10, 25, 50, 100, this.pageSize])].sort((a, b) => a - b); }
  changePage(delta: number): void { this.page += delta; void this.loadJobs(); }
  sortBy(key: string): void { this.sort = this.sort === key ? `-${key}` : key; this.filterChanged(); }
  sortState(key: string): string { return this.sort === key ? 'ascending' : this.sort === `-${key}` ? 'descending' : 'none'; }
  sortMark(key: string): string { return this.sort === key ? '↑' : this.sort === `-${key}` ? '↓' : ''; }
  readonly columns = [{ key: 'internalId', label: 'ID' }, { key: 'description', label: 'Description' }, { key: 'status', label: 'Status' }, { key: 'phase', label: 'Phase' }, { key: 'progress', label: 'Progress' }];
  environmentLabel(evaluation: Evaluation): string {
    const values = evaluation.environments ?? (evaluation.environment ? [evaluation.environment] : []);
    return values.map(value => value.startsWith('cem-') ? `CEM: ${value.slice(4)}` : value.startsWith('system-') ? `System: ${value.slice(7)}` : value).join(', ') || 'No environment recorded';
  }
  progress(value: number | null | undefined): number { return Math.max(0, Math.min(100, value ?? 0)); }
  async save(): Promise<void> { await this.mutate(async () => { this.evaluation.set(await this.api.patch<Evaluation>(`/evaluations/${this.id}`, { name: this.name, description: this.description })); this.changes.dirty.set(false); }, 'Evaluation saved.'); }
  async star(): Promise<void> { const evaluation = this.evaluation(); if (evaluation) await this.mutate(async () => { this.evaluation.set(await this.api.patch<Evaluation>(`/evaluations/${this.id}`, { starred: !evaluation.starred })); }, evaluation.starred ? 'Star removed.' : 'Evaluation starred.'); }
  async bulk(action: 'abort' | 'reschedule'): Promise<void> {
    const prompt = action === 'abort' ? 'Abort the active and failed jobs in this evaluation? Finished results will be retained.' : 'Reschedule all failed and aborted jobs? Their progress and execution phase will be reset.';
    if (!window.confirm(prompt)) return;
    await this.mutate(async () => { await this.api.post(`/evaluations/${this.id}/actions`, { action }); await this.refresh(); }, action === 'abort' ? 'Jobs aborted.' : 'Jobs rescheduled.');
  }
  private async mutate(action: () => Promise<void>, message: string): Promise<void> { if (this.saving()) return; this.saving.set(true); this.error.set(''); this.success.set(''); try { await action(); this.success.set(message); } catch (error) { this.error.set(errorMessage(error)); } finally { this.saving.set(false); } }
}
