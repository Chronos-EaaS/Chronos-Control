import { Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { ApiError, errorMessage } from '../../core/api';
import { LiveSubscription, Realtime } from '../../core/realtime';
import { Evaluation } from '../../core/workflow-models';
import { Feedback } from '../../shared/feedback';
import { ResultsExplorer } from '../results/results-explorer';

@Component({
  imports: [RouterLink, Feedback, ResultsExplorer],
  templateUrl: './evaluation-results.html',
  styleUrl: './workflow-reference.scss',
})
export class EvaluationResults {
  private readonly realtime = inject(Realtime);
  readonly resourceId = signal<number | null>(null);
  readonly evaluation = signal<Evaluation | null>(null);
  readonly busy = signal(true);
  readonly error = signal('');
  private generation = 0;
  private subscription?: LiveSubscription<Evaluation>;

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
    this.evaluation.set(null);
    this.resourceId.set(Number.isSafeInteger(id) && id > 0 ? id : null);
    this.busy.set(true);
    this.error.set('');
    if (this.resourceId() === null) {
      this.error.set('Choose a valid evaluation.');
      this.busy.set(false);
      return;
    }
    try {
      const failed = (error: unknown): void => {
        if (generation !== this.generation) return;
        this.failed(error);
      };
      const subscription = this.realtime.watch<Evaluation>(
        `/evaluations/${id}`,
        {},
        (evaluation) => {
          if (generation !== this.generation) return;
          this.evaluation.set(evaluation);
          this.resourceId.set(id);
          this.error.set('');
        },
        failed,
      );
      this.subscription = subscription;
      await subscription.ready;
    } catch (error) {
      if (generation === this.generation) {
        this.failed(error);
      }
    } finally {
      if (generation === this.generation) this.busy.set(false);
    }
  }

  private failed(error: unknown): void {
    this.error.set(errorMessage(error));
    // A temporary header failure must not destroy already authorized charts or
    // the current raw selection. Missing/auth-denied context still closes it.
    if (
      !this.evaluation() ||
      (error instanceof ApiError && [401, 403, 404].includes(error.status))
    ) {
      this.evaluation.set(null);
      this.resourceId.set(null);
    }
  }
}
