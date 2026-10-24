import type { ChartConfiguration } from 'chart.js';
import { BoxSummary, PLOT_TYPES, PlotValue, ResultChart, ResultConfigurationDocument, PlotTypeAliases } from '../../core/results-models';

export const germanNumber = new Intl.NumberFormat('de-DE', { maximumSignificantDigits: 15 });
const scientificNumber = new Intl.NumberFormat('de-DE', { notation: 'scientific', maximumSignificantDigits: 15 });
export const formatNumber = (value: number): string => (value !== 0 && (Math.abs(value) < 0.000001 || Math.abs(value) >= 1e12) ? scientificNumber : germanNumber).format(value);
export const isPlotType = (type: string): boolean => PLOT_TYPES.some(kind => kind.type === type);
export function plotRenderType(type: string, aliases: PlotTypeAliases = {}): string {
  return isPlotType(type) ? type : Object.hasOwn(aliases, type) && isPlotType(aliases[type]) ? aliases[type] : type;
}
/** baseType is a response-only server registry projection, never saved into a schema. */
export function chartRenderType(chart: ResultChart): string {
  return isPlotType(chart.definition.type) ? chart.definition.type : typeof chart.definition['baseType'] === 'string' && isPlotType(chart.definition['baseType']) ? chart.definition['baseType'] : chart.definition.type;
}
const color = ['#1f5b99', '#008c95', '#8a56a8', '#d07819', '#b53f61', '#4e7890', '#627e25', '#6657bd'];
export const chartValue = (value: unknown): string => {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'number') return Number.isFinite(value) ? formatNumber(value) : '—';
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
};
export const isBox = (value: unknown): value is BoxSummary => typeof value === 'object' && value !== null && 'median' in value;
export const outlierText = (value: BoxSummary): string => Array.isArray(value.outliers) ? value.outliers.map(chartValue).join(', ') || 'None' : 'Not provided';

export function tooltipLabel(context: { dataset: { label?: string }; raw: unknown; formattedValue?: unknown }): string {
  const prefix = context.dataset.label ? `${context.dataset.label}: ` : '';
  if (isBox(context.raw)) {
    const formatted = context.formattedValue;
    const hovered = typeof formatted === 'object' && formatted !== null && 'hoveredOutlierIndex' in formatted ? formatted.hoveredOutlierIndex : -1;
    if (typeof hovered === 'number' && hovered >= 0 && context.raw.outliers?.[hovered] !== undefined) return `${prefix}Outlier ${formatNumber(context.raw.outliers[hovered])}`;
    return `${prefix}Median ${formatNumber(context.raw.median)}; Q1 ${formatNumber(context.raw.q1)}; Q3 ${formatNumber(context.raw.q3)}; whiskers ${formatNumber(context.raw.whiskerMin)}–${formatNumber(context.raw.whiskerMax)}`;
  }
  return `${prefix}${chartValue(context.raw)}`;
}

export interface ResultConfigurationIssue {
  message: string;
  configurationId?: string;
  scope?: 'all' | 'job';
  index?: number;
  field?: 'version' | 'configurationId' | 'configurationName' | 'id' | 'type' | 'name' | 'parameter' | 'plotting' | 'orientation';
}

export function resultConfigurationIssues(document: ResultConfigurationDocument, aliases: PlotTypeAliases = {}): ResultConfigurationIssue[] {
  const issues: ResultConfigurationIssue[] = [], ids = new Set<string>();
  if (document.version !== '1.0') issues.push({ message: 'The result configuration version is unsupported.', field: 'version' });
  for (const [id, configuration] of Object.entries(document.elements)) {
    if (!/^[A-Za-z0-9][A-Za-z0-9_-]{0,49}$/.test(id)) issues.push({ message: 'Each configuration needs a stable ID of at most 50 characters.', configurationId: id, field: 'configurationId' });
    if (!configuration.name.trim()) issues.push({ message: 'Each configuration needs a name.', configurationId: id, field: 'configurationName' });
    for (const scope of ['all', 'job'] as const) {
      ids.clear();
      for (const [index, plot] of configuration[scope].entries()) {
        const issue = (message: string, field: ResultConfigurationIssue['field']): void => {
          issues.push({ message, configurationId: id, scope, index, field });
        };
        if (!/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/.test(plot.id) || ids.has(plot.id)) issue('Each plot needs a unique stable ID within its scope.', 'id');
        ids.add(plot.id);
        const type = plotRenderType(plot.type, aliases);
        if (!isPlotType(type)) issue(`Unsupported plot type “${plot.type}”. Its data is preserved; a registered editor and renderer are required before saving.`, 'type');
        if (!plot.name.trim()) issue('Each plot needs a title.', 'name');
        if (!plot.parameter.split(',').every(name => name.trim())) issue(`“${plot.name || 'Untitled plot'}” needs metric or configuration parameter names.`, 'parameter');
        if (['line-plot-multi', 'box-plot'].includes(type) && !plot.plotting?.trim()) issue(`“${plot.name || 'Untitled plot'}” needs a result metric to plot.`, 'plotting');
        if (type === 'box-plot' && plot.orientation !== undefined && !['horizontal', 'vertical'].includes(plot.orientation)) issue('Choose a valid box plot orientation.', 'orientation');
      }
    }
  }
  return issues;
}

export function validateResultConfigurations(document: ResultConfigurationDocument, aliases: PlotTypeAliases = {}): string[] {
  return resultConfigurationIssues(document, aliases).map(issue => issue.message);
}

/** Display adapter only. All means, quartiles and grouping are calculated by PHP. */
export function chartConfiguration(chart: ResultChart): ChartConfiguration {
  const baseType = chartRenderType(chart);
  const type = baseType === 'pie-plot' ? 'doughnut' : baseType === 'box-plot' ? 'boxplot' : baseType === 'bar-plot' ? 'bar' : 'line';
  const horizontal = type === 'boxplot' && chart.definition.orientation === 'horizontal';
  const datasets = chart.data.datasets.map((dataset, index) => ({
    label: String(dataset.label ?? chart.title), data: structuredClone(dataset.data ?? []),
    backgroundColor: type === 'doughnut' ? (dataset.data ?? []).map((_, i) => color[i % color.length]) : `${color[index % color.length]}66`,
    borderColor: type === 'doughnut' ? '#ffffff' : color[index % color.length], borderWidth: 2,
    pointRadius: 2, pointHitRadius: 12, fill: false, spanGaps: false,
    // PHP sends the chosen Tukey summaries; never ask the plugin to recalculate them.
    itemRadius: 0, outlierRadius: 3,
  }));
  return {
    type, data: { labels: (chart.data.labels ?? []).map(String), datasets },
    options: {
      responsive: true, maintainAspectRatio: false, locale: 'de-DE', animation: false,
      indexAxis: horizontal ? 'y' : 'x',
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'top', labels: { color: '#24364b', boxWidth: 18 } },
        tooltip: { callbacks: { label: tooltipLabel } },
      },
      ...(type === 'doughnut' ? {} : { scales: {
        [horizontal ? 'x' : 'y']: { beginAtZero: type === 'bar', ticks: { callback: (value: string | number) => typeof value === 'number' ? formatNumber(value) : value } },
      } }),
    },
  } as unknown as ChartConfiguration;
}

export function plotRows(chart: ResultChart): { label: string; series: string; value: PlotValue }[] {
  return chart.data.datasets.flatMap((dataset, series) => (dataset.data ?? []).map((value, index) => ({ label: String(chart.data.labels?.[index] ?? index), series: String(dataset.label ?? `Series ${series + 1}`), value })));
}

/** Semicolon/BOM/decimal comma for German spreadsheet settings, formula-safe text. */
export function plotCsv(chart: ResultChart): string {
  const escape = (value: unknown): string => {
    let text = typeof value === 'number' ? String(value).replace('.', ',') : value === null || value === undefined ? '' : String(value);
    if (typeof value !== 'number' && /^[\s]*[=+@-]/u.test(text)) text = `'${text}`;
    return `"${text.replaceAll('"', '""')}"`;
  };
  const rows: unknown[][] = [['Category', 'Series', 'Value', 'Minimum', 'Q1', 'Median', 'Q3', 'Maximum', 'Lower whisker', 'Upper whisker', 'Outliers']];
  for (const row of plotRows(chart)) rows.push(isBox(row.value)
    ? [row.label, row.series, '', row.value.min, row.value.q1, row.value.median, row.value.q3, row.value.max, row.value.whiskerMin, row.value.whiskerMax, JSON.stringify(row.value.outliers ?? [])]
    : [row.label, row.series, row.value, '', '', '', '', '', '', '', '']);
  return `\uFEFF${rows.map(row => row.map(escape).join(';')).join('\r\n')}\r\n`;
}

export function downloadText(filename: string, contents: string, type = 'text/csv;charset=utf-8'): void {
  const url = URL.createObjectURL(new Blob([contents], { type }));
  const anchor = document.createElement('a'); anchor.href = url; anchor.download = filename; anchor.click();
  setTimeout(() => URL.revokeObjectURL(url), 0);
}
