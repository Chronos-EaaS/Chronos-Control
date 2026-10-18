import {
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api';
import { EvaluationResults, ResultJob } from '../../core/results-models';
import { dateTime } from '../../core/time';
import { Feedback } from '../../shared/feedback';
import { ResultChartView } from './result-chart';
import { chartValue } from './result-state';
import { domNamespace } from '../parameters/parameter-state';

@Component({
  selector: 'app-results-explorer',
  imports: [Feedback, ResultChartView, RouterLink],
  templateUrl: './results-explorer.html',
  styleUrl: './results-reference.scss',
})
export class ResultsExplorer {
  readonly resourceType = input<'evaluations' | 'jobs'>('evaluations');
  readonly resourceId = input.required<number>();
  private readonly api = inject(Api);
  readonly data = signal<EvaluationResults | null>(null);
  readonly error = signal('');
  readonly busy = signal(false);
  readonly updatedAt = signal('');
  readonly automatic = signal(true);
  readonly configuration = signal('');
  readonly search = signal('');
  readonly page = signal(1);
  readonly pageSize = signal<number | null>(null);
  readonly sort = signal('id');
  readonly namespace = domNamespace();
  readonly base = computed(() => `/api/ui/${this.resourceType()}/${this.resourceId()}`);
  readonly allFinished = computed(
    () => !!this.data()?.totalJobs && this.data()?.finishedJobs === this.data()?.totalJobs,
  );
  readonly filtered = computed(() => {
    if (this.data()?.jobPage) return this.data()!.jobs;
    const query = this.search().trim().toLocaleLowerCase('en');
    return (this.data()?.jobs ?? []).filter(
      (job) => !query || JSON.stringify(job).toLocaleLowerCase('en').includes(query),
    );
  });
  readonly effectivePageSize = computed(
    () => this.pageSize() ?? this.data()?.jobPage?.pageSize ?? 20,
  );
  readonly total = computed(() => this.data()?.jobPage?.total ?? this.filtered().length);
  readonly pages = computed(() => Math.max(1, Math.ceil(this.total() / this.effectivePageSize())));
  readonly pageRows = computed(() =>
    this.data()?.jobPage
      ? this.filtered()
      : this.filtered().slice(
          (this.page() - 1) * this.effectivePageSize(),
          this.page() * this.effectivePageSize(),
        ),
  );
  readonly pageNumbers = computed(() => {
    const start = Math.max(1, Math.min(this.page() - 2, this.pages() - 4));
    return Array.from({ length: Math.min(5, this.pages()) }, (_, index) => start + index);
  });
  readonly rangeStart = computed(() =>
    this.total() ? (this.page() - 1) * this.effectivePageSize() + 1 : 0,
  );
  readonly rangeEnd = computed(() =>
    Math.min(this.page() * this.effectivePageSize(), this.total()),
  );
  readonly time = dateTime;
  readonly format = chartValue;
  readonly entries = Object.entries;
  private generation = 0;
  private timer: ReturnType<typeof setInterval>;
  private searchTimer?: ReturnType<typeof setTimeout>;
  constructor() {
    effect(() => {
      const id = this.resourceId(),
        type = this.resourceType();
      this.configuration.set('');
      this.data.set(null);
      this.page.set(1);
      this.search.set('');
      this.sort.set('id');
      this.pageSize.set(null);
      untracked(() => {
        void this.load(id, type);
      });
    });
    // Completed jobs can be rescheduled from another tab or session. Keep the
    // enabled view current even when its previous response had no active jobs.
    this.timer = setInterval(() => {
      if (this.automatic() && !document.hidden && !this.busy()) void this.load();
    }, 5000);
    inject(DestroyRef).onDestroy(() => {
      clearInterval(this.timer);
      clearTimeout(this.searchTimer);
      ++this.generation;
    });
  }
  async load(id = this.resourceId(), type = this.resourceType()): Promise<void> {
    const generation = ++this.generation,
      configurationId = this.configuration();
    this.busy.set(true);
    this.error.set('');
    try {
      const query: Record<string, string | number> = { page: this.page(), sort: this.sort() };
      if (configurationId) query['configurationId'] = configurationId;
      if (this.search()) query['search'] = this.search();
      if (this.pageSize() !== null) query['pageSize'] = this.pageSize()!;
      const value = await this.api.get<EvaluationResults>(`/${type}/${id}/results`, query);
      if (generation !== this.generation) return;
      this.data.set(value);
      if (value.jobPage) this.page.set(value.jobPage.page);
      this.updatedAt.set(new Date().toISOString());
      if (this.page() > this.pages()) this.page.set(this.pages());
    } catch (error) {
      if (generation === this.generation) this.error.set(errorMessage(error));
    } finally {
      if (generation === this.generation) this.busy.set(false);
    }
  }
  selectConfiguration(id: string): void {
    this.configuration.set(id);
    void this.load();
  }
  changeSearch(value: string): void {
    this.search.set(value);
    this.page.set(1);
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => {
      void this.load();
    }, 250);
  }
  changePageSize(value: string): void {
    const size = Number(value);
    if (Number.isInteger(size) && size >= 1 && size <= 100) {
      this.pageSize.set(size);
      this.page.set(1);
      void this.load();
    }
  }
  setPage(value: number): void {
    this.page.set(Math.max(1, Math.min(this.pages(), value)));
    void this.load();
  }
  sortBy(column: string): void {
    this.sort.set(this.sort() === column ? `-${column}` : column);
    this.page.set(1);
    void this.load();
  }
  ariaSort(column: string): string {
    return this.sort() === column
      ? 'ascending'
      : this.sort() === `-${column}`
        ? 'descending'
        : 'none';
  }
  blockIncomplete(event: Event): void {
    if (!this.allFinished()) {
      event.preventDefault();
      this.error.set(
        'All jobs must finish before their result archives can be downloaded. Raw results CSV is available while jobs are running.',
      );
    }
  }
  status(value: number): string {
    return (
      (
        {
          '-2': 'Failed',
          '-1': 'Aborted',
          '0': 'Scheduled',
          '1': 'Setup',
          '2': 'Running',
          '3': 'Finished',
        } as Record<string, string>
      )[String(value)] ?? 'Unknown'
    );
  }
}
