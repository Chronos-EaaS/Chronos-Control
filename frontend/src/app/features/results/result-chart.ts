import { Icon } from '../../shared/icon';
import {
  Component,
  ElementRef,
  afterRenderEffect,
  computed,
  inject,
  input,
  signal,
  viewChild,
  DestroyRef,
} from '@angular/core';
import type { Chart } from 'chart.js';
import { ResultChart } from '../../core/results-models';
import {
  chartConfiguration,
  chartRenderType,
  chartValue,
  downloadText,
  isBox,
  isPlotType,
  outlierText,
  plotCsv,
  plotRows,
} from './result-state';

@Component({
  selector: 'app-result-chart',
  imports: [Icon],
  styleUrl: './results-reference.scss',
  templateUrl: './result-chart.html',
})
export class ResultChartView {
  readonly chart = input.required<ResultChart>();
  readonly canvas = viewChild<ElementRef<HTMLCanvasElement>>('canvas');
  readonly rows = computed(() => plotRows(this.chart()));
  readonly box = computed(() => chartRenderType(this.chart()) === 'box-plot');
  readonly format = chartValue;
  readonly isBox = isBox;
  readonly outliers = outlierText;
  readonly ready = signal(false);
  readonly renderError = signal('');
  readonly message = computed(() => {
    const chart = this.chart();
    if (chart.error) return chart.error;
    if (!isPlotType(chartRenderType(chart)))
      return 'This custom chart requires a registered v3 renderer. Its definition and raw data are preserved.';
    if (chart.data.notEnoughRuns)
      return 'Box plots require at least three valid observations for each distribution.';
    if (!this.rows().some((row) => row.value !== null))
      return 'No finished observations are available yet. Charts will update as results arrive.';
    if (
      chartRenderType(chart) === 'pie-plot' &&
      this.rows().some((row) => typeof row.value === 'number' && row.value < 0)
    )
      return 'Doughnut charts cannot represent negative values. Review the exact values in the table or use a bar chart.';
    return '';
  });
  private instance: Chart | null = null;
  private generation = 0;
  constructor() {
    afterRenderEffect(() => {
      const canvas = this.canvas()?.nativeElement,
        chart = this.chart(),
        message = this.message();
      void this.render(canvas, chart, message);
    });
    inject(DestroyRef).onDestroy(() => {
      ++this.generation;
      this.instance?.destroy();
    });
  }
  private async render(
    canvas: HTMLCanvasElement | undefined,
    chart: ResultChart,
    message: string,
  ): Promise<void> {
    const generation = ++this.generation;
    this.renderError.set('');
    if (!canvas || message) {
      this.instance?.destroy(); this.instance = null; this.ready.set(false); return;
    }
    try {
      const configuration = chartConfiguration(chart);
      // A pushed observation updates the existing canvas without replaying its
      // initial animation or replacing the user's active chart interaction.
      if (this.instance?.canvas === canvas && 'type' in this.instance.config && this.instance.config.type === configuration.type) {
        this.instance.data = configuration.data;
        this.instance.options = configuration.options ?? {};
        this.instance.update('none'); this.ready.set(true); return;
      }
      this.instance?.destroy(); this.instance = null; this.ready.set(false);
      const [{ Chart, registerables }, { BoxPlotController, BoxAndWiskers }] = await Promise.all([
        import('chart.js'),
        import('@sgratzl/chartjs-chart-boxplot'),
      ]);
      if (generation !== this.generation) return;
      if (!canvas.getContext('2d')) throw new Error('Canvas is unavailable.');
      Chart.register(...registerables, BoxPlotController, BoxAndWiskers);
      this.instance = new Chart(canvas, configuration);
      this.ready.set(true);
    } catch {
      if (generation === this.generation) {
        this.instance?.destroy(); this.instance = null; this.ready.set(false);
        this.renderError.set('The chart could not be drawn in this browser.');
      }
    }
  }
  csv(): void {
    downloadText(
      `chart-${this.chart().id.replace(/[^a-zA-Z0-9_-]/g, '_')}.csv`,
      plotCsv(this.chart()),
    );
  }
  png(): void {
    if (!this.instance) return;
    const link = document.createElement('a');
    link.download = `chart-${this.chart().id.replace(/[^a-zA-Z0-9_-]/g, '_')}.png`;
    link.href = this.instance.toBase64Image();
    link.click();
  }
}
