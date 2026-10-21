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
import { EvaluationResults } from '../../core/results-models';
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
  readonly allowConfigurationManagement = input(true);
  private readonly realtime = inject(Realtime);
  readonly data = signal<EvaluationResults | null>(null);
  readonly rawData = signal<EvaluationResults | null>(null);
  readonly rawOpen = signal(false);
  readonly rawBusy = signal(false);
  readonly rawError = signal('');
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
    if (this.rawData()?.jobPage) return this.rawData()!.jobs;
    const query = this.search().trim().toLocaleLowerCase('en');
    return (this.rawData()?.jobs ?? []).filter(
      (job) => !query || JSON.stringify(job).toLocaleLowerCase('en').includes(query),
    );
  });
  readonly effectivePageSize = computed(
    () => this.pageSize() ?? this.rawData()?.jobPage?.pageSize ?? 20,
  );
  readonly total = computed(() => this.rawData()?.jobPage?.total ?? this.filtered().length);
  readonly pages = computed(() => Math.max(1, Math.ceil(this.total() / this.effectivePageSize())));
  readonly pageRows = computed(() =>
    this.rawData()?.jobPage
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
  private rawSubscription?: LiveSubscription<EvaluationResults>;
  private rawGeneration = 0;
  private chartFingerprints = new Map<string, string>();
  private resource = '';
  private searchTimer?: ReturnType<typeof setTimeout>;
  constructor() {
    effect(() => {
      const id = this.resourceId(),
        type = this.resourceType(),
        automatic = this.automatic();
      untracked(() => {
        const resource = `${type}/${id}`;
        if (this.resource !== resource) {
          this.resource = resource;
          this.configuration.set('');
          this.data.set(null);
          this.page.set(1);
          this.closeRaw();
          this.chartFingerprints.clear();
          this.search.set('');
          this.sort.set('id');
          this.pageSize.set(null);
        } else if (!automatic) {
          this.subscription?.close();
          this.subscription = undefined;
          ++this.generation;
          this.busy.set(false);
          this.rawSubscription?.close();
          this.rawSubscription = undefined;
          ++this.rawGeneration;
          this.rawBusy.set(false);
          return;
        }
        void this.load(id, type);
      });
    });
    inject(DestroyRef).onDestroy(() => {
      this.subscription?.close();
      this.rawSubscription?.close();
      clearTimeout(this.searchTimer);
      ++this.generation;
      ++this.rawGeneration;
    });
  }
  async load(id = this.resourceId(), type = this.resourceType()): Promise<void> {
    await Promise.all([
      this.loadCharts(id, type),
      this.rawOpen() ? this.loadRaw(id, type) : Promise.resolve(),
    ]);
  }
  private async loadCharts(id = this.resourceId(), type = this.resourceType()): Promise<void> {
    const generation = ++this.generation,
      configurationId = this.configuration();
    this.busy.set(true);
    this.error.set('');
    const query: Record<string, string | number> = { view: 'charts' };
    if (configurationId) query['configurationId'] = configurationId;
    this.subscription?.close();
    this.subscription = undefined;
    try {
      const apply = (value: EvaluationResults): void => {
        if (generation !== this.generation) return;
        const previous = new Map(this.data()?.charts.map((chart) => [chart.id, chart]) ?? []);
        const fingerprints = new Map<string, string>();
        const charts = value.charts.map((chart) => {
          const fingerprint = JSON.stringify(chart);
          fingerprints.set(chart.id, fingerprint);
          return fingerprint === this.chartFingerprints.get(chart.id)
            ? previous.get(chart.id)!
            : chart;
        });
        // Keep chart inputs stable when only progress changes. Each chart is
        // serialized once, avoiding quadratic searches and whole-snapshot copies.
        this.chartFingerprints = fingerprints;
        this.data.set({ ...value, charts });
        this.updatedAt.set(new Date().toISOString());
        this.error.set('');
      };
      if (this.automatic()) {
        const subscription = this.realtime.watch<EvaluationResults>(
          `/${type}/${id}/results`,
          query,
          apply,
          (error) => {
            if (generation === this.generation) this.error.set(errorMessage(error));
          },
        );
        this.subscription = subscription;
        await subscription.ready;
      } else apply(await this.realtime.read<EvaluationResults>(`/${type}/${id}/results`, query));
    } catch (error) {
      if (generation === this.generation) this.error.set(errorMessage(error));
    } finally {
      if (generation === this.generation) this.busy.set(false);
    }
  }
  toggleRaw(): void {
    if (this.rawOpen()) this.closeRaw();
    else {
      this.rawOpen.set(true);
      void this.loadRaw();
    }
  }
  closeRaw(): void {
    this.rawOpen.set(false);
    this.rawSubscription?.close();
    this.rawSubscription = undefined;
    clearTimeout(this.searchTimer);
    ++this.rawGeneration;
    this.rawData.set(null);
    this.rawBusy.set(false);
    this.rawError.set('');
  }
  private async loadRaw(id = this.resourceId(), type = this.resourceType()): Promise<void> {
    if (!this.rawOpen()) return;
    const generation = ++this.rawGeneration;
    this.rawBusy.set(true);
    this.rawError.set('');
    const query: Record<string, string | number> = {
      view: 'raw',
      page: this.page(),
      sort: this.sort(),
    };
    if (this.search()) query['search'] = this.search();
    if (this.pageSize() !== null) query['pageSize'] = this.pageSize()!;
    this.rawSubscription?.close();
    this.rawSubscription = undefined;
    try {
      const apply = (value: EvaluationResults): void => {
        if (generation !== this.rawGeneration || !this.rawOpen()) return;
        this.rawData.set(value);
        this.rawError.set('');
        if (value.jobPage) this.page.set(value.jobPage.page);
        if (this.page() > this.pages()) this.page.set(this.pages());
      };
      if (this.automatic()) {
        const subscription = this.realtime.watch<EvaluationResults>(
          `/${type}/${id}/results`,
          query,
          apply,
          (error) => {
            if (generation === this.rawGeneration) this.rawError.set(errorMessage(error));
          },
        );
        this.rawSubscription = subscription;
        await subscription.ready;
      } else apply(await this.realtime.read<EvaluationResults>(`/${type}/${id}/results`, query));
    } catch (error) {
      if (generation === this.rawGeneration) this.rawError.set(errorMessage(error));
    } finally {
      if (generation === this.rawGeneration) this.rawBusy.set(false);
    }
  }
  selectConfiguration(id: string): void {
    this.configuration.set(id);
    void this.loadCharts();
  }
  changeSearch(value: string): void {
    this.search.set(value);
    this.page.set(1);
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => {
      void this.loadRaw();
    }, 250);
  }
  changePageSize(value: string): void {
    const size = Number(value);
    if (Number.isInteger(size) && size >= 1 && size <= 100) {
      this.pageSize.set(size);
      this.page.set(1);
      void this.loadRaw();
    }
  }
  setPage(value: number): void {
    this.page.set(Math.max(1, Math.min(this.pages(), value)));
    void this.loadRaw();
  }
  sortBy(column: string): void {
    this.sort.set(this.sort() === column ? `-${column}` : column);
    this.page.set(1);
    void this.loadRaw();
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
