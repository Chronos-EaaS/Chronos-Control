import { Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { ApiError, errorMessage } from '../../core/api';
import { LiveSubscription, Realtime } from '../../core/realtime';
import { Job } from '../../core/workflow-models';
import { Feedback } from '../../shared/feedback';
import { ResultsExplorer } from '../results/results-explorer';

@Component({
  imports: [RouterLink, Feedback, ResultsExplorer],
  templateUrl: './job-results.html',
  styleUrl: './workflow-reference.scss',
})
export class JobResults {
  private readonly realtime = inject(Realtime);
  readonly resourceId = signal<number | null>(null);
  readonly job = signal<Job | null>(null);
  readonly busy = signal(true);
  readonly error = signal('');
  private generation = 0;
  private subscription?: LiveSubscription<Job>;

  constructor() {
    inject(DestroyRef).onDestroy(() => {
      ++this.generation;
      this.subscription?.close();
    });
    inject(ActivatedRoute)
      .paramMap.pipe(takeUntilDestroyed())
      .subscribe((params) => {
        void this.load(Number(params.get('id')));
      });
  }

  private async load(id: number): Promise<void> {
    const generation = ++this.generation;
    this.subscription?.close();
    this.job.set(null);
    this.resourceId.set(Number.isSafeInteger(id) && id > 0 ? id : null);
    this.busy.set(true);
    this.error.set('');
    if (this.resourceId() === null) {
      this.error.set('Choose a valid job.');
      this.busy.set(false);
      return;
    }
    try {
      const failed = (error: unknown): void => {
        if (generation === this.generation) this.failed(error);
      };
      const subscription = this.realtime.watch<Job>(
        `/jobs/${id}`,
        {},
        (job) => {
          if (generation !== this.generation) return;
          this.job.set(job);
          this.resourceId.set(id);
          this.error.set('');
        },
        failed,
      );
      this.subscription = subscription;
      await subscription.ready;
    } catch (error) {
      if (generation === this.generation) this.failed(error);
    } finally {
      if (generation === this.generation) this.busy.set(false);
    }
  }

  private failed(error: unknown): void {
    this.error.set(errorMessage(error));
    // Keep authorized charts and raw selections during a temporary header
    // failure, but remove private content when access is revoked.
    if (!this.job() || (error instanceof ApiError && [401, 403, 404].includes(error.status))) {
      this.job.set(null);
      this.resourceId.set(null);
    }
  }
}
