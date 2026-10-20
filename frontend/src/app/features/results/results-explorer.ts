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
import { errorMessage } from '../../core/api';
import { LiveSubscription, Realtime } from '../../core/realtime';
import { EvaluationResults, ResultJob } from '../../core/results-models';
import { dateTime } from '../../core/time';
import { Feedback } from '../../shared/feedback';
import { Icon } from '../../shared/icon';
import { ResultChartView } from './result-chart';
import { chartValue } from './result-state';
import { domNamespace } from '../parameters/parameter-state';

@Component({
  selector: 'app-results-explorer',
  imports: [Feedback, ResultChartView, RouterLink, Icon],
  templateUrl: './results-explorer.html',
  styleUrl: './results-reference.scss',
})
export class ResultsExplorer {
  readonly resourceType = input<'evaluations' | 'jobs'>('evaluations');
  readonly resourceId = input.required<number>();
  private readonly realtime = inject(Realtime);
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
  private subscription?: LiveSubscription<EvaluationResults>;
  private resource = "";
  private searchTimer?: ReturnType<typeof setTimeout>;
  constructor() {
    effect(() => {
      const id = this.resourceId(), type = this.resourceType(), automatic = this.automatic();
      untracked(() => {
        const resource = `${type}/${id}`;
        if (this.resource !== resource) {
          this.resource = resource;
          this.configuration.set(''); this.data.set(null); this.page.set(1);
          this.search.set(''); this.sort.set('id'); this.pageSize.set(null);
        } else if (!automatic) {
          this.subscription?.close(); this.subscription = undefined;
          ++this.generation; this.busy.set(false); return;
        }
        void this.load(id, type);
      });
    });
    inject(DestroyRef).onDestroy(() => {
      this.subscription?.close(); clearTimeout(this.searchTimer); ++this.generation;
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
      this.subscription?.close(); this.subscription = undefined;
      const apply = (value: EvaluationResults): void => {
        if (generation !== this.generation) return;
        const previous = this.data();
        // Preserve chart inputs when only a job row or progress count changed.
        value = { ...value, charts: value.charts.map(chart => {
          const old = previous?.charts.find(item => item.id === chart.id);
          return old && JSON.stringify(old) === JSON.stringify(chart) ? old : chart;
        }) };
        if (JSON.stringify(previous) !== JSON.stringify(value)) {
          this.data.set(value); this.updatedAt.set(new Date().toISOString());
        }
        this.error.set('');
        if (value.jobPage) this.page.set(value.jobPage.page);
        if (this.page() > this.pages()) this.page.set(this.pages());
      };
      if (this.automatic()) {
        const subscription = this.realtime.watch<EvaluationResults>(`/${type}/${id}/results`, query, apply,
          error => { if (generation === this.generation) this.error.set(errorMessage(error)); });
        this.subscription = subscription;
        await subscription.ready;
      } else apply(await this.realtime.read<EvaluationResults>(`/${type}/${id}/results`, query));
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
