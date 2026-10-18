import { Component, DestroyRef, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Api, errorMessage } from '../../core/api';
import { PlotData, ResultChart } from '../../core/results-models';
import { Feedback } from '../../shared/feedback';
import { ResultChartView } from '../results/result-chart';

type Scalar = string | number | boolean | null;
interface PageBlock {
  type: 'text' | 'metrics' | 'table' | 'chart'; title?: string; text?: string;
  items?: { label: string; value: Scalar; unit?: string }[];
  columns?: { key: string; label: string }[]; rows?: Record<string, Scalar>[];
  renderer?: 'bar' | 'line' | 'pie' | 'boxplot'; data?: PlotData; chart?: ResultChart;
}
interface PageData { id: string; title: string; description: string; blocks: PageBlock[]; }

@Component({
  imports: [RouterLink, Feedback, ResultChartView],
  template: `<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a routerLink="/projects">Projects</a></li><li class="breadcrumb-item active" aria-current="page">System extension</li></ol></nav><app-feedback [busy]="busy()" [error]="error()" />
    @if (data(); as page) { <div class="page-heading"><div><span class="eyebrow">SYSTEM EXTENSION</span><h1>{{ page.title }}</h1><p class="text-secondary">{{ page.description }}</p></div><button class="btn btn-outline-primary" type="button" [disabled]="busy()" (click)="refresh()">Refresh page</button></div>
      @for (block of page.blocks; track $index) { @if (block.type === 'chart' && block.chart) { <div class="mb-4"><app-result-chart [chart]="block.chart" /></div> } @else {
        <section class="card mb-4">@if (block.title) { <div class="card-header"><h2 class="card-title">{{ block.title }}</h2></div> }<div class="card-body">
          @switch (block.type) {
            @case ('text') { <p class="text-break mb-0" style="white-space:pre-wrap">{{ block.text }}</p> }
            @case ('metrics') { <dl class="row g-3 mb-0">@for (item of block.items || []; track $index) { <div class="col-12 col-sm-6 col-lg-4"><dt class="text-secondary small">{{ item.label }}</dt><dd class="fs-4 mb-0">{{ value(item.value) }} @if (item.unit) { <span class="small text-secondary">{{ item.unit }}</span> }</dd></div> }</dl> }
            @case ('table') { <div class="table-responsive" tabindex="0"><table class="table table-striped mb-0"><caption>{{ block.title || 'System extension data' }}</caption><thead><tr>@for (column of block.columns || []; track column.key) { <th scope="col">{{ column.label }}</th> }</tr></thead><tbody>@for (row of block.rows || []; track $index) { <tr>@for (column of block.columns || []; track column.key) { <td>{{ value(row[column.key]) }}</td> }</tr> } @empty { <tr><td [attr.colspan]="block.columns?.length || 1">No rows available.</td></tr> }</tbody></table></div> }
          }
        </div></section>
      } }
    } @else if (!busy()) { <button class="btn btn-outline-primary" (click)="refresh()">Retry loading page</button> }`,
})
export class ExtensionPage {
  private readonly api = inject(Api); private readonly route = inject(ActivatedRoute);
  readonly data = signal<PageData | null>(null); readonly busy = signal(true); readonly error = signal('');
  private generation = 0; private systemId = 0; private pageId = '';
  constructor() {
    this.route.paramMap.pipe(takeUntilDestroyed()).subscribe(params => { this.systemId = Number(params.get('systemId')); this.pageId = params.get('pageId') || ''; this.data.set(null); void this.refresh(); });
    inject(DestroyRef).onDestroy(() => this.generation++);
  }
  value(value: Scalar | undefined): string { return value === null || value === undefined ? '—' : String(value); }
  async refresh(): Promise<void> {
    const generation = ++this.generation; this.busy.set(true); this.error.set('');
    try {
      const page = await this.api.get<PageData>(`/systems/${this.systemId}/pages/${encodeURIComponent(this.pageId)}`);
      const types = { bar: 'bar-plot', line: 'line-plot', pie: 'pie-plot', boxplot: 'box-plot' };
      page.blocks = page.blocks.map((block, index) => block.type === 'chart' && block.data && block.renderer ? { ...block, chart: { id: `extension-${page.id}-${index}`, title: block.title || page.title, definition: { id: `extension-${index}`, name: block.title || page.title, type: types[block.renderer], parameter: '' }, renderer: block.renderer, data: block.data, scope: 'all', jobIds: [] } } : block);
      if (generation === this.generation) this.data.set(page);
    } catch (error) { if (generation === this.generation) this.error.set(errorMessage(error)); }
    finally { if (generation === this.generation) this.busy.set(false); }
  }
}
