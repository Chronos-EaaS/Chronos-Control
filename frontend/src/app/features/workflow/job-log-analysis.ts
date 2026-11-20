import { Component, DestroyRef, OnChanges, computed, inject, input, signal } from '@angular/core';
import { Api, errorMessage } from '../../core/api';
import { Feedback } from '../../shared/feedback';

interface LogFinding {
  detectorVersion: string;
  epoch: string;
  rule: string;
  severity: 'error' | 'critical';
  reason: string;
  byteStart: number;
  byteEnd: number;
  excerpt: string;
}

interface LogAnalysisPage {
  detectorVersion: string;
  epoch: string;
  available: boolean;
  reset: boolean;
  offset: number;
  nextOffset: number;
  size: number;
  hasMore: boolean;
  pendingLine: boolean;
  skippedLongLines: number;
  findings: LogFinding[];
  startCursor: string;
  cursor: string;
}

@Component({
  selector: 'app-job-log-analysis',
  imports: [Feedback],
  templateUrl: './job-log-analysis.html',
  styles: `
    :host { display: block; }
    .finding-excerpt { white-space: pre-wrap; overflow-wrap: anywhere; }
  `,
})
export class JobLogAnalysis implements OnChanges {
  private readonly api = inject(Api);
  readonly jobId = input.required<number>();
  readonly attemptId = input<number>();
  readonly headingId = computed(() => `job-${this.jobId()}-log-analysis-${this.attemptId() ?? 'current'}`);
  readonly page = signal<LogAnalysisPage | null>(null);
  readonly busy = signal(false);
  readonly error = signal('');
  readonly notice = signal('');
  readonly hasPrevious = signal(false);
  private history: string[] = [];
  private nextCursor?: string;
  private requestSequence = 0;

  constructor() {
    inject(DestroyRef).onDestroy(() => ++this.requestSequence);
  }

  ngOnChanges(): void {
    ++this.requestSequence;
    this.page.set(null);
    this.busy.set(false);
    this.error.set('');
    this.notice.set('');
    this.setHistory([]);
    this.nextCursor = undefined;
  }

  async scan(direction: 'start' | 'previous' | 'next'): Promise<void> {
    if (this.busy()) return;
    const sequence = ++this.requestSequence;
    const page = this.page();
    const cursor = direction === 'next' ? this.nextCursor
      : direction === 'previous' ? this.history.at(-1) : undefined;

    this.busy.set(true);
    this.error.set('');
    this.notice.set('');
    try {
      const next = await this.api.get<LogAnalysisPage>(`/jobs/${this.jobId()}/log/analysis`, { ...(cursor ? { cursor } : {}), ...(this.attemptId() ? { attemptId: this.attemptId() } : {}) });
      if (sequence !== this.requestSequence) return;
      this.nextCursor = next.cursor;
      if (next.reset) {
        this.setHistory([]);
        this.notice.set('The stored log changed generation or shrank. The scan restarted from byte 0.');
      } else if (direction === 'next' && page) {
        if (!page.hasMore && next.nextOffset === next.offset && page.nextOffset === next.offset) {
          this.notice.set(next.pendingLine
            ? 'Waiting for a complete line at the end of the log.'
            : 'No new complete lines in the stored log.');
          return;
        }
        this.setHistory([...this.history, page.startCursor]);
      } else {
        this.setHistory(direction === 'previous' ? this.history.slice(0, -1) : []);
      }
      this.page.set(next);
    } catch (error) {
      if (sequence === this.requestSequence) this.error.set(errorMessage(error));
    } finally {
      if (sequence === this.requestSequence) this.busy.set(false);
    }
  }

  private setHistory(history: string[]): void {
    this.history = history;
    this.hasPrevious.set(history.length > 0);
  }
}
