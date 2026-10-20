import { Component, DestroyRef, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Api, errorMessage } from '../../core/api';
import { LiveSubscription, Realtime } from '../../core/realtime';
import { PageData } from '../../core/models';
import { Evaluation, Job } from '../../core/workflow-models';
import { UnsavedChanges } from '../../core/unsaved';
import { Feedback } from '../../shared/feedback';
import { Icon } from '../../shared/icon';

@Component({
  imports: [FormsModule, RouterLink, Feedback, Icon],
  templateUrl: './evaluation-detail.html',
  styleUrl: './workflow-reference.scss',
})
export class EvaluationDetail {
  private readonly rowRouter = inject(Router, { optional: true });
  private readonly api = inject(Api);
  private readonly realtime = inject(Realtime);
  private readonly route = inject(ActivatedRoute);
  readonly changes = inject(UnsavedChanges).scope(inject(DestroyRef));
  readonly evaluation = signal<Evaluation | null>(null);
  readonly jobs = signal<Job[]>([]);
  readonly total = signal(0);
  readonly busy = signal(true);
  readonly saving = signal(false);
  readonly error = signal('');
  readonly success = signal('');
  name = '';
  description = '';
  status = 'all';
  sort = 'internalId';
  search = '';
  private id = 0;
  private refreshing = false;
  private generation = 0;
  private refreshSequence = 0;
  private jobsSequence = 0;
  private evaluationSubscription?: LiveSubscription<Evaluation>;
  private jobsSubscription?: LiveSubscription<PageData<Job>>;
  constructor() {
    inject(DestroyRef).onDestroy(() => {
      this.stopLive(); ++this.generation; this.changes.setDirty(false);
    });
    this.route.paramMap.pipe(takeUntilDestroyed()).subscribe((params) => {
      this.stopLive();
      this.id = Number(params.get('id'));
      ++this.generation;
      ++this.jobsSequence;
      this.changes.reset();
      this.saving.set(false);
      this.evaluation.set(null);
      this.jobs.set([]);
      void this.refresh(true);
    });
  }
  async refresh(initial = false): Promise<void> {
    if (this.refreshing && !initial) return;
    this.refreshing = true;
    const generation = this.generation;
    const sequence = ++this.refreshSequence;
    const id = this.id;
    if (initial) {
      this.busy.set(true);
      this.error.set('');
    }
    try {
      this.evaluationSubscription?.close();
      this.evaluationSubscription = this.realtime.watch<Evaluation>(`/evaluations/${id}`, {}, evaluation => {
        if (generation !== this.generation || sequence !== this.refreshSequence) return;
        this.evaluation.set(evaluation);
        if (!this.changes.dirty()) { this.name = evaluation.name; this.description = evaluation.description; }
        this.error.set('');
      }, error => { if (generation === this.generation && sequence === this.refreshSequence) this.error.set(errorMessage(error)); });
      await this.evaluationSubscription.ready;
      if (generation !== this.generation || sequence !== this.refreshSequence) return;
      await this.loadJobs();
    } catch (error) {
      if (generation === this.generation && sequence === this.refreshSequence)
        this.error.set(errorMessage(error));
    } finally {
      if (generation === this.generation && sequence === this.refreshSequence) {
        this.refreshing = false;
        this.busy.set(false);
      }
    }
  }
  async loadJobs(): Promise<void> {
    const generation = this.generation;
    const sequence = ++this.jobsSequence;
    const query = {
      status: this.status,
      pageSize: 'all',
      sort: this.sort,
      search: this.search,
    };
    try {
      this.jobsSubscription?.close();
      this.jobsSubscription = this.realtime.watch<PageData<Job>>(`/evaluations/${this.id}/jobs`, query, data => {
        if (generation !== this.generation || sequence !== this.jobsSequence) return;
        this.jobs.set(data.items); this.total.set(data.total);
        this.error.set('');
      }, error => { if (generation === this.generation && sequence === this.jobsSequence) this.error.set(errorMessage(error)); });
      await this.jobsSubscription.ready;
    } catch (error) {
      if (generation === this.generation && sequence === this.jobsSequence)
        this.error.set(errorMessage(error));
    }
  }
  filterChanged(): void {
    void this.loadJobs();
  }
  sortBy(key: string): void {
    this.sort = this.sort === key ? `-${key}` : key;
    this.filterChanged();
  }
  openRow(event: MouseEvent, id: number): void {
    if (
      event.defaultPrevented ||
      event.button !== 0 ||
      event.ctrlKey ||
      event.metaKey ||
      event.shiftKey ||
      event.altKey
    )
      return;
    if (
      event.target instanceof Element &&
      event.target.closest('a,button,input,select,textarea,label,[role="button"]')
    )
      return;
    if (window.getSelection()?.toString()) return;
    void this.rowRouter?.navigate(['/', 'jobs', id]);
  }
  sortState(key: string): string {
    return this.sort === key ? 'ascending' : this.sort === `-${key}` ? 'descending' : 'none';
  }
  sortMark(key: string): string {
    return this.sort === key ? '↑' : this.sort === `-${key}` ? '↓' : '';
  }
  readonly columns = [
    { key: 'internalId', label: 'ID' },
    { key: 'description', label: 'Description' },
    { key: 'progress', label: 'Progress' },
    { key: 'status', label: 'Status' },
  ];
  environmentLabel(evaluation: Evaluation): string {
    const values =
      evaluation.environments ?? (evaluation.environment ? [evaluation.environment] : []);
    return (
      values
        .map((value) =>
          value.startsWith('cem-')
            ? `CEM: ${value.slice(4)}`
            : value.startsWith('system-')
              ? `System: ${value.slice(7)}`
              : value,
        )
        .join(', ') || 'No environment recorded'
    );
  }
  progress(value: number | null | undefined): number {
    return typeof value === 'number' && Number.isFinite(value)
      ? Math.max(0, Math.min(100, value))
      : 0;
  }
  stage(job: Job): string {
    if (job.statusName === 'setup') return 'Setup';
    if (job.statusName === 'scheduled') return 'Not started';
    if (job.statusName === 'finished') return 'Complete';
    const phases = ['Prepare', 'Warm-up', 'Execute', 'Analyze', 'Clean'];
    const current = job.currentPhase;
    if (current !== null && Number.isInteger(current) && current >= 1 && current <= phases.length)
      return phases[current - 1];
    return job.statusName === 'running' ? 'Awaiting stage' : 'No stage recorded';
  }
  async save(): Promise<void> {
    await this.mutate(async (current) => {
      const value = await this.api.patch<Evaluation>(`/evaluations/${this.id}`, {
        name: this.name,
        description: this.description,
      });
      if (!current()) return;
      this.evaluation.set(value);
      this.changes.setDirty(false);
    }, 'Evaluation saved.');
  }
  async star(): Promise<void> {
    const evaluation = this.evaluation();
    if (evaluation)
      await this.mutate(
        async (current) => {
          const value = await this.api.patch<Evaluation>(`/evaluations/${this.id}`, {
            starred: !evaluation.starred,
          });
          if (current()) this.evaluation.set(value);
        },
        evaluation.starred ? 'Star removed.' : 'Evaluation starred.',
      );
  }
  async bulk(action: 'abort' | 'reschedule'): Promise<void> {
    const prompt =
      action === 'abort'
        ? 'Abort the active and failed jobs in this evaluation? Finished results will be retained.'
        : 'Reschedule all failed and aborted jobs? Their progress and execution phase will be reset.';
    if (!window.confirm(prompt)) return;
    await this.mutate(
      async (current) => {
        await this.api.post(`/evaluations/${this.id}/actions`, { action });
        if (current()) await this.refresh();
      },
      action === 'abort' ? 'Jobs aborted.' : 'Jobs rescheduled.',
    );
  }
  private stopLive(): void {
    this.evaluationSubscription?.close(); this.evaluationSubscription = undefined;
    this.jobsSubscription?.close(); this.jobsSubscription = undefined;
    ++this.refreshSequence; ++this.jobsSequence; this.refreshing = false;
  }
  private async mutate(
    action: (current: () => boolean) => Promise<void>,
    message: string,
  ): Promise<void> {
    if (this.saving()) return;
    const current = this.changes.checkpoint();
    // A response requested before this write cannot describe the saved state.
    this.stopLive();
    this.busy.set(false);
    this.saving.set(true);
    this.error.set('');
    this.success.set('');
    try {
      await action(current);
      if (current()) this.success.set(message);
    } catch (error) {
      if (current()) this.error.set(errorMessage(error));
    } finally {
      if (current()) {
        this.saving.set(false);
        if (!this.evaluationSubscription) await this.refresh();
      }
    }
  }
}
