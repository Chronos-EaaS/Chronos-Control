import { JobLogAnalysis } from './job-log-analysis';
import { JsonPipe } from '@angular/common';
import { Component, DestroyRef, inject, input, signal } from '@angular/core';
import { Api, errorMessage } from '../../core/api';
import { dateTime } from '../../core/time';
import { Feedback } from '../../shared/feedback';

interface Attempt {
  id: number;
  status: string;
  selected: boolean;
  worker: { id: string; kind: string };
  claimedAt: string;
  startedAt: string | null;
  finishedAt: string | null;
  reason: string | null;
  observedEnvironment: Record<string, unknown> | null;
  observedArtifacts: Record<string, unknown> | null;
  result: Record<string, unknown> | null;
  logUrl: string | null;
  archiveUrl: string | null;
  archive: { sha256: string; sizeBytes: number } | null;
}

interface Execution {
  repetitionId: number;
  specification: Record<string, unknown> | null;
  resultSelection: 'successful-attempt' | 'legacy-job-result';
  selectedAttemptId: number | null;
  attempts: Attempt[];
}

@Component({
  selector: 'app-attempt-evidence',
  imports: [JsonPipe, Feedback, JobLogAnalysis],
  template: `
    <details class="card p-3 mb-3" #panel (toggle)="panel.open && refresh()">
      <summary>Execution evidence</summary>
      @if (panel.open) {
        <div class="mt-3">
          <button
            class="btn btn-sm btn-light mb-2"
            type="button"
            [disabled]="busy()"
            (click)="refresh()"
          >
            Refresh evidence
          </button>
          <app-feedback [busy]="busy()" [error]="error()" />
          @if (value(); as evidence) {
            @if (evidence.resultSelection === 'legacy-job-result') {
              <p>Legacy job result. Attempt history is unavailable.</p>
            } @else {
              <p>
                Repetition {{ evidence.repetitionId }} ·
                {{
                  evidence.selectedAttemptId
                    ? 'Selected attempt ' + evidence.selectedAttemptId
                    : 'No attempt selected'
                }}
              </p>
            }
            @if (evidence.specification) {
              <details class="mb-3">
                <summary>Resolved specification</summary>
                <pre class="small text-wrap">{{ evidence.specification | json }}</pre>
              </details>
            } @else {
              <p>No resolved specification was recorded.</p>
            }
            @for (attempt of evidence.attempts; track attempt.id) {
              <details class="border rounded p-2 mb-2">
                <summary>
                  Attempt {{ attempt.id }} · {{ attempt.status
                  }}{{ attempt.selected ? ' · Selected' : '' }} · {{ attempt.worker.id }}
                </summary>
                <dl class="row mt-2 mb-0">
                  <dt class="col-sm-3">Claimed</dt>
                  <dd class="col-sm-9">{{ formatTime(attempt.claimedAt, true) }}</dd>
                  <dt class="col-sm-3">Started</dt>
                  <dd class="col-sm-9">
                    {{ attempt.startedAt ? formatTime(attempt.startedAt, true) : 'Unavailable' }}
                  </dd>
                  <dt class="col-sm-3">Finished</dt>
                  <dd class="col-sm-9">
                    {{ attempt.finishedAt ? formatTime(attempt.finishedAt, true) : 'Unavailable' }}
                  </dd>
                  @if (attempt.reason) {
                    <dt class="col-sm-3">Reason</dt>
                    <dd class="col-sm-9">{{ attempt.reason }}</dd>
                  }
                  <dt class="col-sm-3">Log</dt>
                  <dd class="col-sm-9">
                    @if (attempt.logUrl) {
                      <a [href]="attempt.logUrl">Download attempt log</a>
                      <app-job-log-analysis [jobId]="jobId()" [attemptId]="attempt.id" />
                    } @else {
                      Unavailable
                    }
                  </dd>
                  <dt class="col-sm-3">Archive</dt>
                  <dd class="col-sm-9">
                    @if (attempt.archiveUrl) {
                      <a [href]="attempt.archiveUrl">Download attempt archive</a> ·
                      {{ attempt.archive?.sizeBytes }} bytes<br />
                      <code class="text-break">{{ attempt.archive?.sha256 }}</code>
                    } @else {
                      Unavailable
                    }
                  </dd>
                  <dt class="col-sm-3">Observed environment</dt>
                  <dd class="col-sm-9">
                    <pre class="small text-wrap">{{
                      attempt.observedEnvironment
                        ? (attempt.observedEnvironment | json)
                        : 'Unavailable'
                    }}</pre>
                  </dd>
                  <dt class="col-sm-3">Observed artifacts</dt>
                  <dd class="col-sm-9">
                    <pre class="small text-wrap">{{
                      attempt.observedArtifacts ? (attempt.observedArtifacts | json) : 'Unavailable'
                    }}</pre>
                  </dd>
                  <dt class="col-sm-3">Result</dt>
                  <dd class="col-sm-9">
                    <pre class="small text-wrap">{{
                      attempt.result ? (attempt.result | json) : 'Unavailable'
                    }}</pre>
                  </dd>
                </dl>
              </details>
            }
          }
        </div>
      }
    </details>
  `,
})
export class AttemptEvidence {
  private readonly api = inject(Api);
  readonly jobId = input.required<number>();
  readonly busy = signal(false);
  readonly error = signal('');
  readonly value = signal<Execution | null>(null);
  readonly formatTime = dateTime;
  private destroyed = false;

  constructor() {
    inject(DestroyRef).onDestroy(() => {
      this.destroyed = true;
    });
  }

  async refresh(): Promise<void> {
    if (this.busy()) return;
    const id = this.jobId();
    this.busy.set(true);
    this.error.set('');
    try {
      const value = await this.api.get<Execution>(`/jobs/${id}/execution`);
      if (!this.destroyed && this.jobId() === id) this.value.set(value);
    } catch (error) {
      if (!this.destroyed && this.jobId() === id) this.error.set(errorMessage(error));
    } finally {
      if (!this.destroyed) this.busy.set(false);
    }
  }
}
