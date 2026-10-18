export const PLOT_TYPES = [
  { type: 'bar-plot', label: 'Bar chart' }, { type: 'line-plot', label: 'Line chart' },
  { type: 'line-plot-multi', label: 'Line chart by configuration parameters' },
  { type: 'pie-plot', label: 'Doughnut chart' }, { type: 'box-plot', label: 'Box plot' },
] as const;

export interface PlotDefinition {
  id: string; type: string; name: string; parameter: string;
  plotting?: string; orientation?: 'vertical' | 'horizontal';
  [key: string]: unknown;
}
export interface ResultConfiguration { name: string; all: PlotDefinition[]; job: PlotDefinition[]; [key: string]: unknown; }
export interface ResultConfigurationDocument { version: '1.0'; elements: Record<string, ResultConfiguration>; [key: string]: unknown; }
export interface BoxSummary {
  min: number; q1: number; median: number; q3: number; max: number;
  whiskerMin: number; whiskerMax: number; outliers: number[];
  lower_outlier?: number; higher_outlier?: number;
}
export type PlotValue = number | BoxSummary | null;
export interface PlotDataset { label?: string | number; data?: PlotValue[]; [key: string]: unknown; }
export interface PlotData { labels?: (string | number)[]; datasets: PlotDataset[]; notEnoughRuns?: boolean; }
export interface ResultChart {
  id: string; title: string; definition: PlotDefinition; data: PlotData;
  renderer?: 'bar' | 'line' | 'pie' | 'boxplot';
  scope: 'all' | 'job'; groupLabel?: string; jobIds: number[]; error?: string;
}
export interface ResultJob {
  id: number; internalId: number; label: string; parameters: Record<string, unknown>;
  result: Record<string, unknown>; status: number; finishedAt: string | null; downloadUrl: string;
}
export interface EvaluationResults {
  configurationId: string; experimentId?: number; configurations: { id: string; name: string }[];
  finishedJobs: number; totalJobs: number; activeJobs?: number;
  charts: ResultChart[]; jobs: ResultJob[]; warnings: string[];
  jobPage?: { total: number; page: number; pageSize: number; sort: string };
}

export type PlotTypeAliases = Readonly<Record<string, string>>;
