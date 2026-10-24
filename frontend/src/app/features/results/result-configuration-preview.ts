import { Component, computed, effect, input, signal } from '@angular/core';
import {
  BoxSummary,
  PlotData,
  PlotDefinition,
  PlotTypeAliases,
  ResultChart,
  ResultConfiguration,
} from '../../core/results-models';
import { domNamespace } from '../parameters/parameter-state';
import { ResultChartView } from './result-chart';
import { isPlotType, plotRenderType } from './result-state';

const categories = ['Sample A', 'Sample B', 'Sample C'];
type Scope = 'all' | 'job';
const names = (value: string): string[] =>
  value
    .split(',')
    .map((name) => name.trim())
    .filter(Boolean);

/** Fixed illustrative values only; saved result aggregation remains in the backend. */
function exampleData(plot: PlotDefinition, type: string): PlotData {
  const parameters = names(plot.parameter),
    metrics = parameters.length ? parameters : ['Example metric'];
  if (type === 'line-plot-multi' || type === 'box-plot') {
    const series = [1, 2].map((value) =>
      parameters.length
        ? parameters.map((name) => `${name} = ${value}`).join(', ')
        : `Sample series ${value}`,
    );
    return {
      labels: [...categories],
      datasets: series.map((label, index) => ({
        label,
        data: categories.map((_, category) => {
          const offset = index * 4 + category * 2;
          if (type !== 'box-plot') return 12 + offset * 3;
          // Summary of [2,4,4,6,6,8,8,12,18], shifted for each example series.
          const box: BoxSummary = {
            min: 2 + offset,
            q1: 4 + offset,
            median: 6 + offset,
            q3: 8 + offset,
            max: 18 + offset,
            whiskerMin: 2 + offset,
            whiskerMax: 12 + offset,
            outliers: [18 + offset],
          };
          return box;
        }),
      })),
    };
  }
  if (type === 'line-plot') {
    return {
      labels: [...categories],
      datasets: metrics.map((label, index) => ({
        label,
        data: [12, 21, 17].map((value) => value + index * 6),
      })),
    };
  }
  return {
    labels: categories.flatMap((category) =>
      metrics.map((metric) => (metrics.length > 1 ? `${category} · ${metric}` : category)),
    ),
    datasets: [
      {
        label: metrics.length === 1 ? metrics[0] : 'Example values',
        data: categories.flatMap((_, category) =>
          metrics.map((_, metric) => 12 + category * 7 + metric * 4),
        ),
      },
    ],
  };
}

@Component({
  selector: 'app-result-configuration-preview',
  imports: [ResultChartView],
  templateUrl: './result-configuration-preview.html',
  styleUrl: './result-configuration-preview.scss',
})
export class ResultConfigurationPreview {
  readonly configuration = input<ResultConfiguration | null | undefined>(null);
  readonly aliases = input<PlotTypeAliases>({});
  readonly namespace = domNamespace();
  readonly pageSize = 6;
  readonly pages = signal<Record<Scope, number>>({ all: 1, job: 1 });
  readonly scopes = computed(() =>
    (
      [
        {
          key: 'all',
          label: 'All jobs',
          help: 'Example comparison across finished job configurations.',
        },
        {
          key: 'job',
          label: 'Individual configurations',
          help: 'Example charts for one job configuration and its finished runs.',
        },
      ] as const
    ).map((scope) => {
      const plots = this.configuration()?.[scope.key] ?? [],
        total = plots.length;
      const pageCount = Math.max(1, Math.ceil(total / this.pageSize));
      const page = Math.min(Math.max(1, this.pages()[scope.key]), pageCount);
      const offset = (page - 1) * this.pageSize;
      return {
        ...scope,
        total,
        page,
        pageCount,
        start: total ? offset + 1 : 0,
        end: Math.min(offset + this.pageSize, total),
        pageOptions: Array.from({ length: pageCount }, (_, index) => index + 1),
        charts: plots
          .slice(offset, offset + this.pageSize)
          .map((plot, index) => this.chart(plot, scope.key, offset + index)),
      };
    }),
  );

  constructor() {
    effect(() => {
      this.configuration();
      this.pages.set({ all: 1, job: 1 });
    });
  }

  setPage(scope: Scope, value: string | number): void {
    const requested = Number(value),
      total = this.configuration()?.[scope].length ?? 0;
    const pageCount = Math.max(1, Math.ceil(total / this.pageSize));
    const page = Number.isInteger(requested) ? Math.min(Math.max(requested, 1), pageCount) : 1;
    this.pages.update((pages) => ({ ...pages, [scope]: page }));
  }

  private chart(plot: PlotDefinition, scope: Scope, index: number): ResultChart {
    const type = plotRenderType(plot.type, this.aliases()),
      definition = structuredClone(plot);
    // A saved metadata field must not act as an unregistered renderer alias.
    delete definition['baseType'];
    if (isPlotType(type) && type !== plot.type) definition['baseType'] = type;
    const renderer =
      type === 'bar-plot'
        ? 'bar'
        : type === 'pie-plot'
          ? 'pie'
          : type === 'box-plot'
            ? 'boxplot'
            : ['line-plot', 'line-plot-multi'].includes(type)
              ? 'line'
              : undefined;
    return {
      id: `preview-${scope}-${index}-${plot.id}`,
      title: plot.name || 'Untitled chart',
      definition,
      renderer,
      scope,
      jobIds: [],
      data: exampleData(plot, type),
      ...(type === 'line-plot-multi' || type === 'box-plot'
        ? { groupLabel: `Example result metric: ${plot.plotting?.trim() || 'Example metric'}` }
        : {}),
    };
  }
}
