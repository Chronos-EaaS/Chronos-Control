import { Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api';
import { Evaluation } from '../../core/workflow-models';
import { Feedback } from '../../shared/feedback';
import { ResultsExplorer } from '../results/results-explorer';

@Component({
  imports: [RouterLink, Feedback, ResultsExplorer],
  templateUrl: './evaluation-results.html',
  styleUrl: './workflow-reference.scss',
})
export class EvaluationResults {
  private readonly api = inject(Api);
  readonly evaluation = signal<Evaluation | null>(null);
  readonly busy = signal(true);
  readonly error = signal('');
  private generation = 0;

  constructor() {
    inject(DestroyRef).onDestroy(() => ++this.generation);
    inject(ActivatedRoute)
      .paramMap.pipe(takeUntilDestroyed())
      .subscribe((params) => {
        void this.load(Number(params.get('id')));
      });
  }

  private async load(id: number): Promise<void> {
    const generation = ++this.generation;
    this.evaluation.set(null);
    this.busy.set(true);
    this.error.set('');
    try {
      const evaluation = await this.api.get<Evaluation>(`/evaluations/${id}`);
      if (generation === this.generation) this.evaluation.set(evaluation);
    } catch (error) {
      if (generation === this.generation) this.error.set(errorMessage(error));
    } finally {
      if (generation === this.generation) this.busy.set(false);
    }
  }
}
