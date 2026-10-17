import { Component, DestroyRef, computed, effect, inject, input, signal, untracked } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api';
import { EvaluationResults, ResultJob } from '../../core/results-models';
import { dateTime } from '../../core/time';
import { Feedback } from '../../shared/feedback';
import { ResultChartView } from './result-chart';
import { chartValue } from './result-state';
import { domNamespace } from '../parameters/parameter-state';

@Component({
  selector: 'app-results-explorer', imports: [Feedback, ResultChartView, RouterLink],
  template: `
    <app-feedback [busy]="busy()" [error]="error()" label="Loading result data…" />
    <div class="d-flex flex-wrap gap-2 mb-3"><button class="btn btn-outline-primary" type="button" (click)="load()" [disabled]="busy()">Refresh results</button><a class="btn btn-outline-secondary" [href]="base() + '/results.csv'" download>Download raw results CSV</a><a class="btn btn-outline-secondary" [href]="base() + '/download'" download [attr.aria-disabled]="!allFinished()" (click)="blockIncomplete($event)">Download result archives</a><label class="form-check ms-auto"><input class="form-check-input" type="checkbox" [checked]="automatic()" (change)="automatic.set($any($event.target).checked)" /> <span class="form-check-label">Refresh automatically</span></label></div>
    @if (data(); as result) {
      <p class="text-secondary" role="status">{{ result.finishedJobs }} of {{ result.totalJobs }} jobs finished. @if (updatedAt()) { Updated {{ time(updatedAt(), true) }}. }</p>
      @for (warning of result.warnings; track $index) { <div class="alert alert-info">{{ warning }}</div> }
      @if (result.configurations.length) { <div class="mb-3"><label class="form-label" [for]="namespace + '-configuration'">Result configuration</label><select class="form-select" [id]="namespace + '-configuration'" [value]="configuration() || result.configurationId" [disabled]="busy()" (change)="selectConfiguration($any($event.target).value)">@for (entry of result.configurations; track entry.id) { <option [value]="entry.id">{{ entry.name }}</option> }</select></div> }
      <div class="row g-4 mb-4">@for (chart of result.charts; track chart.id) { <div [class]="chart.scope === 'all' ? 'col-12' : 'col-12 col-xl-6'"><app-result-chart [chart]="chart" /></div> } @empty { <div class="col-12"><div class="alert alert-info">No charts are configured for these results. Raw results and downloads remain available.</div></div> }</div>
      <section class="card"><div class="card-header"><h3 class="card-title fs-5">Raw job results</h3></div><div class="card-body"><label class="form-label" [for]="namespace + '-search'">Search results or parameters</label><input class="form-control mb-3" type="search" [id]="namespace + '-search'" [value]="search()" (input)="changeSearch($any($event.target).value)" />
      <div class="d-flex flex-wrap align-items-center gap-2 mb-3"><label class="form-label mb-0" [for]="namespace + '-page-size'">Rows per page</label><select class="form-select form-select-sm w-auto" [id]="namespace + '-page-size'" [value]="effectivePageSize()" (change)="changePageSize($any($event.target).value)">@if (![10,20,25,50,100].includes(effectivePageSize())) { <option [value]="effectivePageSize()">{{ effectivePageSize() }}</option> }@for (size of [10,20,25,50,100]; track size) { <option [value]="size">{{ size }}</option> }</select><span class="text-secondary ms-auto" role="status">Showing {{ rangeStart() }}–{{ rangeEnd() }} of {{ total() }} jobs</span></div><div class="table-responsive"><table class="table table-striped align-middle"><caption>{{ total() }} matching jobs. Structured values retain their original JSON representation.</caption><thead><tr><th scope="col" [attr.aria-sort]="ariaSort('name')"><button class="btn btn-link p-0 text-start fw-semibold" type="button" (click)="sortBy('name')">Job</button></th><th scope="col" [attr.aria-sort]="ariaSort('parameters')"><button class="btn btn-link p-0 text-start fw-semibold" type="button" (click)="sortBy('parameters')">Parameters</button></th><th scope="col" [attr.aria-sort]="ariaSort('result')"><button class="btn btn-link p-0 text-start fw-semibold" type="button" (click)="sortBy('result')">Result metrics</button></th><th scope="col" [attr.aria-sort]="ariaSort('finishedAt')"><button class="btn btn-link p-0 text-start fw-semibold" type="button" (click)="sortBy('finishedAt')">Finished</button></th></tr></thead><tbody>@for (job of pageRows(); track job.id) { <tr><th scope="row"><a [routerLink]="['/jobs', job.id]">{{ job.label || 'Job ' + job.internalId }}</a><div class="text-secondary small">#{{ job.id }} · {{ status(job.status) }}</div></th><td><dl class="mb-0">@for (entry of entries(job.parameters); track entry[0]) { <dt>{{ entry[0] }}</dt><dd class="text-break mb-1">{{ format(entry[1]) }}</dd> }</dl></td><td><dl class="mb-0">@for (entry of entries(job.result); track entry[0]) { <dt>{{ entry[0] }}</dt><dd class="text-break mb-1">{{ format(entry[1]) }}</dd> } @empty { <span class="text-secondary">No result metrics</span> }</dl></td><td class="text-nowrap">{{ time(job.finishedAt, true) }}</td></tr> } @empty { <tr><td colspan="4">No jobs match your search.</td></tr> }</tbody></table></div>
      <nav class="d-flex flex-wrap align-items-center gap-2" aria-label="Result pages"><button class="btn btn-sm btn-outline-secondary" type="button" (click)="setPage(1)" [disabled]="busy() || page() === 1">First</button><button class="btn btn-sm btn-outline-secondary" type="button" (click)="setPage(page() - 1)" [disabled]="busy() || page() === 1">Previous</button>@for (number of pageNumbers(); track number) { <button class="btn btn-sm" [class]="number === page() ? 'btn-primary' : 'btn-outline-secondary'" type="button" [attr.aria-current]="number === page() ? 'page' : null" (click)="setPage(number)" [disabled]="busy()">{{ number }}</button> }<span class="text-secondary">of {{ pages() }}</span><button class="btn btn-sm btn-outline-secondary" type="button" (click)="setPage(page() + 1)" [disabled]="busy() || page() >= pages()">Next</button><button class="btn btn-sm btn-outline-secondary" type="button" (click)="setPage(pages())" [disabled]="busy() || page() >= pages()">Last</button></nav></div></section>
    }
  `,
})
export class ResultsExplorer {
  readonly resourceType = input<'evaluations' | 'jobs'>('evaluations');
  readonly resourceId = input.required<number>();
  private readonly api = inject(Api);
  readonly data = signal<EvaluationResults | null>(null); readonly error = signal(''); readonly busy = signal(false); readonly updatedAt = signal('');
  readonly automatic = signal(true); readonly configuration = signal(''); readonly search = signal(''); readonly page = signal(1); readonly pageSize = signal<number | null>(null); readonly sort = signal('id'); readonly namespace = domNamespace();
  readonly base = computed(() => `/api/v3/${this.resourceType()}/${this.resourceId()}`);
  readonly allFinished = computed(() => !!this.data()?.totalJobs && this.data()?.finishedJobs === this.data()?.totalJobs);
  readonly filtered = computed(() => { if (this.data()?.jobPage) return this.data()!.jobs; const query = this.search().trim().toLocaleLowerCase('en'); return (this.data()?.jobs ?? []).filter(job => !query || JSON.stringify(job).toLocaleLowerCase('en').includes(query)); });
  readonly effectivePageSize = computed(() => this.pageSize() ?? this.data()?.jobPage?.pageSize ?? 20);
  readonly total = computed(() => this.data()?.jobPage?.total ?? this.filtered().length);
  readonly pages = computed(() => Math.max(1, Math.ceil(this.total() / this.effectivePageSize())));
  readonly pageRows = computed(() => this.data()?.jobPage ? this.filtered() : this.filtered().slice((this.page() - 1) * this.effectivePageSize(), this.page() * this.effectivePageSize()));
  readonly pageNumbers = computed(() => { const start = Math.max(1, Math.min(this.page() - 2, this.pages() - 4)); return Array.from({ length: Math.min(5, this.pages()) }, (_, index) => start + index); });
  readonly rangeStart = computed(() => this.total() ? (this.page() - 1) * this.effectivePageSize() + 1 : 0); readonly rangeEnd = computed(() => Math.min(this.page() * this.effectivePageSize(), this.total()));
  readonly time = dateTime; readonly format = chartValue; readonly entries = Object.entries;
  private generation = 0; private timer: ReturnType<typeof setInterval>; private searchTimer?: ReturnType<typeof setTimeout>;
  constructor() {
    effect(() => { const id = this.resourceId(), type = this.resourceType(); this.configuration.set(''); this.data.set(null); this.page.set(1); this.search.set(''); this.sort.set('id'); this.pageSize.set(null); untracked(() => { void this.load(id, type); }); });
    this.timer = setInterval(() => { if (this.automatic() && !document.hidden && !this.busy() && (this.data()?.activeJobs ?? Number(this.data()?.jobs.some(job => job.status >= 0 && job.status < 3) ?? false)) > 0) void this.load(); }, 5000);
    inject(DestroyRef).onDestroy(() => { clearInterval(this.timer); clearTimeout(this.searchTimer); ++this.generation; });
  }
  async load(id = this.resourceId(), type = this.resourceType()): Promise<void> {
    const generation = ++this.generation, configurationId = this.configuration(); this.busy.set(true); this.error.set('');
    try {
      const query: Record<string, string | number> = { page: this.page(), sort: this.sort() };
      if (configurationId) query['configurationId'] = configurationId;
      if (this.search()) query['search'] = this.search(); if (this.pageSize() !== null) query['pageSize'] = this.pageSize()!;
      const value = await this.api.get<EvaluationResults>(`/${type}/${id}/results`, query);
      if (generation !== this.generation) return;
      this.data.set(value); if (value.jobPage) this.page.set(value.jobPage.page); this.updatedAt.set(new Date().toISOString()); if (this.page() > this.pages()) this.page.set(this.pages());
    } catch (error) { if (generation === this.generation) this.error.set(errorMessage(error)); }
    finally { if (generation === this.generation) this.busy.set(false); }
  }
  selectConfiguration(id: string): void { this.configuration.set(id); void this.load(); }
  changeSearch(value: string): void { this.search.set(value); this.page.set(1); clearTimeout(this.searchTimer); this.searchTimer = setTimeout(() => { void this.load(); }, 250); }
  changePageSize(value: string): void { const size = Number(value); if (Number.isInteger(size) && size >= 1 && size <= 100) { this.pageSize.set(size); this.page.set(1); void this.load(); } }
  setPage(value: number): void { this.page.set(Math.max(1, Math.min(this.pages(), value))); void this.load(); }
  sortBy(column: string): void { this.sort.set(this.sort() === column ? `-${column}` : column); this.page.set(1); void this.load(); }
  ariaSort(column: string): string { return this.sort() === column ? 'ascending' : this.sort() === `-${column}` ? 'descending' : 'none'; }
  blockIncomplete(event: Event): void { if (!this.allFinished()) { event.preventDefault(); this.error.set('All jobs must finish before their result archives can be downloaded. Raw results CSV is available while jobs are running.'); } }
  status(value: number): string { return ({ '-2': 'Failed', '-1': 'Aborted', '0': 'Scheduled', '1': 'Setup', '2': 'Running', '3': 'Finished' } as Record<string, string>)[String(value)] ?? 'Unknown'; }
}
