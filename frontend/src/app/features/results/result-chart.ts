import { Component, ElementRef, afterRenderEffect, computed, inject, input, signal, viewChild, DestroyRef } from '@angular/core';
import type { Chart } from 'chart.js';
import { ResultChart } from '../../core/results-models';
import { chartConfiguration, chartRenderType, chartValue, downloadText, isBox, isPlotType, outlierText, plotCsv, plotRows } from './result-state';

@Component({
  selector: 'app-result-chart',
  styles: [`:host{display:block;min-width:0}.chart-container{position:relative;height:clamp(18rem,45vh,32rem);width:100%;min-width:0}.chart-table{max-height:26rem;overflow:auto}canvas{max-width:100%}`],
  template: `
    <article class="card h-100"><div class="card-header d-flex flex-wrap gap-2 align-items-center"><h3 class="card-title fs-5 me-auto">{{ chart().title }}</h3><button type="button" class="btn btn-sm btn-outline-secondary" (click)="csv()" [disabled]="!rows().length">Download chart CSV</button><button type="button" class="btn btn-sm btn-outline-secondary" (click)="png()" [disabled]="!ready()">Download PNG</button></div>
      <div class="card-body">
        @if (chart().groupLabel) { <p class="text-secondary">{{ chart().groupLabel }}</p> }
        @if (message()) { <div class="alert alert-info" role="status">{{ message() }}</div> }
        @if (renderError()) { <div class="alert alert-warning" role="status">{{ renderError() }} The data table remains available.</div> }
        @if (!message()) { <div class="chart-container"><canvas #canvas role="img" [attr.aria-label]="chart().title + '. A data table follows.'"></canvas></div> }
        <details class="mt-3" [open]="!!message() || !!renderError()"><summary>View accessible chart data ({{ rows().length }} values)</summary><div class="chart-table table-responsive mt-2"><table class="table table-sm table-striped"><caption>{{ chart().title }} — exact chart values</caption><thead><tr><th scope="col">Category</th><th scope="col">Series</th><th scope="col">{{ box() ? 'Minimum / Q1 / Median / Q3 / Maximum' : 'Value' }}</th>@if (box()) { <th scope="col">Whiskers</th><th scope="col">Outliers</th> }</tr></thead><tbody>@for (row of rows(); track $index) { <tr><th scope="row">{{ row.label }}</th><td>{{ row.series }}</td>@if (isBox(row.value)) { <td>{{ format(row.value.min) }} / {{ format(row.value.q1) }} / {{ format(row.value.median) }} / {{ format(row.value.q3) }} / {{ format(row.value.max) }}</td><td>{{ format(row.value.whiskerMin) }} – {{ format(row.value.whiskerMax) }}</td><td>{{ outliers(row.value) }}</td> } @else { <td>{{ format(row.value) }}</td>@if (box()) { <td>—</td><td>—</td> } }</tr> } @empty { <tr><td [attr.colspan]="box() ? 5 : 3">No observations available.</td></tr> }</tbody></table></div></details>
      </div>
    </article>
  `,
})
export class ResultChartView {
  readonly chart = input.required<ResultChart>();
  readonly canvas = viewChild<ElementRef<HTMLCanvasElement>>('canvas');
  readonly rows = computed(() => plotRows(this.chart()));
  readonly box = computed(() => chartRenderType(this.chart()) === 'box-plot');
  readonly format = chartValue; readonly isBox = isBox; readonly outliers = outlierText;
  readonly ready = signal(false); readonly renderError = signal('');
  readonly message = computed(() => {
    const chart = this.chart();
    if (chart.error) return chart.error;
    if (!isPlotType(chartRenderType(chart))) return 'This custom chart requires a registered v3 renderer. Its definition and raw data are preserved.';
    if (chart.data.notEnoughRuns) return 'Box plots require at least three valid observations for each distribution.';
    if (!this.rows().some(row => row.value !== null)) return 'No finished observations are available yet. Charts will update as results arrive.';
    if (chartRenderType(chart) === 'pie-plot' && this.rows().some(row => typeof row.value === 'number' && row.value < 0)) return 'Doughnut charts cannot represent negative values. Review the exact values in the table or use a bar chart.';
    return '';
  });
  private instance: Chart | null = null; private generation = 0;
  constructor() {
    afterRenderEffect(() => { const canvas = this.canvas()?.nativeElement, chart = this.chart(), message = this.message(); void this.render(canvas, chart, message); });
    inject(DestroyRef).onDestroy(() => { ++this.generation; this.instance?.destroy(); });
  }
  private async render(canvas: HTMLCanvasElement | undefined, chart: ResultChart, message: string): Promise<void> {
    const generation = ++this.generation;
    this.instance?.destroy(); this.instance = null; this.ready.set(false); this.renderError.set('');
    if (!canvas || message) return;
    try {
      const [{ Chart, registerables }, { BoxPlotController, BoxAndWiskers }] = await Promise.all([import('chart.js'), import('@sgratzl/chartjs-chart-boxplot')]);
      if (generation !== this.generation) return;
      if (!canvas.getContext('2d')) throw new Error('Canvas is unavailable.');
      Chart.register(...registerables, BoxPlotController, BoxAndWiskers);
      this.instance = new Chart(canvas, chartConfiguration(chart)); this.ready.set(true);
    } catch { if (generation === this.generation) this.renderError.set('The chart could not be drawn in this browser.'); }
  }
  csv(): void { downloadText(`chart-${this.chart().id.replace(/[^a-zA-Z0-9_-]/g, '_')}.csv`, plotCsv(this.chart())); }
  png(): void {
    if (!this.instance) return;
    const link = document.createElement('a'); link.download = `chart-${this.chart().id.replace(/[^a-zA-Z0-9_-]/g, '_')}.png`; link.href = this.instance.toBase64Image(); link.click();
  }
}
