import { Component, DestroyRef, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Api, errorMessage } from '../../core/api';
import { PlotData, ResultChart } from '../../core/results-models';
import { Feedback } from '../../shared/feedback';
import { ResultChartView } from '../results/result-chart';

type Scalar = string | number | boolean | null;
interface PageBlock {
  type: 'text' | 'metrics' | 'table' | 'chart';
  title?: string;
  text?: string;
  items?: { label: string; value: Scalar; unit?: string }[];
  columns?: { key: string; label: string }[];
  rows?: Record<string, Scalar>[];
  renderer?: 'bar' | 'line' | 'pie' | 'boxplot';
  data?: PlotData;
  chart?: ResultChart;
}
interface PageData {
  id: string;
  title: string;
  description: string;
  blocks: PageBlock[];
}

@Component({
  imports: [RouterLink, Feedback, ResultChartView],
  templateUrl: './extension-page.html',
})
export class ExtensionPage {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  readonly data = signal<PageData | null>(null);
  readonly busy = signal(true);
  readonly error = signal('');
  private generation = 0;
  private systemId = 0;
  private pageId = '';
  constructor() {
    this.route.paramMap.pipe(takeUntilDestroyed()).subscribe((params) => {
      this.systemId = Number(params.get('systemId'));
      this.pageId = params.get('pageId') || '';
      this.data.set(null);
      void this.refresh();
    });
    inject(DestroyRef).onDestroy(() => this.generation++);
  }
  value(value: Scalar | undefined): string {
    return value === null || value === undefined ? '—' : String(value);
  }
  async refresh(): Promise<void> {
    const generation = ++this.generation;
    this.busy.set(true);
    this.error.set('');
    try {
      const page = await this.api.get<PageData>(
        `/systems/${this.systemId}/pages/${encodeURIComponent(this.pageId)}`,
      );
      const types = { bar: 'bar-plot', line: 'line-plot', pie: 'pie-plot', boxplot: 'box-plot' };
      page.blocks = page.blocks.map((block, index) =>
        block.type === 'chart' && block.data && block.renderer
          ? {
              ...block,
              chart: {
                id: `extension-${page.id}-${index}`,
                title: block.title || page.title,
                definition: {
                  id: `extension-${index}`,
                  name: block.title || page.title,
                  type: types[block.renderer],
                  parameter: '',
                },
                renderer: block.renderer,
                data: block.data,
                scope: 'all',
                jobIds: [],
              },
            }
          : block,
      );
      if (generation === this.generation) this.data.set(page);
    } catch (error) {
      if (generation === this.generation) this.error.set(errorMessage(error));
    } finally {
      if (generation === this.generation) this.busy.set(false);
    }
  }
}
