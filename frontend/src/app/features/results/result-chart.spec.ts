import { TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ResultChart } from '../../core/results-models';
import { ResultChartView } from './result-chart';

const drawing = vi.hoisted(() => ({ configurations: [] as unknown[], destroy: vi.fn(), register: vi.fn(), update: vi.fn() }));
vi.mock('chart.js', () => ({ registerables: [], Chart: class {
  static register = drawing.register;
  data: unknown; options: unknown;
  constructor(readonly canvas: HTMLCanvasElement, readonly config: any) { drawing.configurations.push(config); this.data = config.data; this.options = config.options; }
  update = drawing.update;
  destroy = drawing.destroy;
  toBase64Image(): string { return 'data:image/png;base64,fixture'; }
} }));
vi.mock('@sgratzl/chartjs-chart-boxplot', () => ({ BoxPlotController: class {}, BoxAndWiskers: class {} }));

const chart = (): ResultChart => ({ id: 'fixture', title: 'Latency <safe>', definition: { id: 'plot', name: 'Latency', type: 'box-plot', parameter: 'workers', plotting: 'latency', orientation: 'horizontal' }, scope: 'all', jobIds: [1, 2, 3], data: { labels: ['Batch'], datasets: [{ label: 'Workers: 1', data: [{ min: 0, q1: 0.25, median: 1.5, q3: 2.75, max: 90, whiskerMin: 0, whiskerMax: 4, outliers: [90] }] }] } });
const ready = async (fixture: ReturnType<typeof TestBed.createComponent>) => { fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges(); };

describe('local chart canvas lifecycle', () => {
  beforeEach(async () => { drawing.configurations.length = 0; vi.clearAllMocks(); vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({} as CanvasRenderingContext2D); await TestBed.configureTestingModule({ imports: [ResultChartView] }).compileComponents(); });
  afterEach(() => vi.restoreAllMocks());
  it('draws the precomputed horizontal box values and exposes a full accessible German data table', async () => {
    const fixture = TestBed.createComponent(ResultChartView), value = chart(), original = JSON.stringify(value);
    fixture.componentRef.setInput('chart', value); await ready(fixture); await vi.waitFor(() => expect(fixture.componentInstance.ready()).toBe(true));
    expect(drawing.configurations[0]).toMatchObject({ type: 'boxplot', options: { indexAxis: 'y', locale: 'de-DE' } });
    expect(JSON.stringify(value)).toBe(original); expect(fixture.nativeElement.textContent).toContain('0,25 / 1,5 / 2,75');
    expect(fixture.nativeElement.querySelector('canvas').getAttribute('aria-label')).toContain('data table'); expect(fixture.nativeElement.querySelector('safe')).toBeNull();
  });
  it('starts drawing when enough observations arrive after an insufficient-run state', async () => {
    const fixture = TestBed.createComponent(ResultChartView), value = chart(); value.data.notEnoughRuns = true;
    fixture.componentRef.setInput('chart', value); await ready(fixture);
    expect(fixture.nativeElement.querySelector('canvas')).toBeNull(); expect(fixture.nativeElement.textContent).toContain('at least three');
    fixture.componentRef.setInput('chart', chart()); await ready(fixture); await vi.waitFor(() => expect(fixture.componentInstance.ready()).toBe(true));
    expect(drawing.configurations).toHaveLength(1);
  });
  it('updates a chart in place after live data changes and destroys it when leaving', async () => {
    const fixture = TestBed.createComponent(ResultChartView); fixture.componentRef.setInput('chart', chart()); await ready(fixture); await vi.waitFor(() => expect(fixture.componentInstance.ready()).toBe(true));
    const next = chart(); next.data.labels = ['New label']; fixture.componentRef.setInput('chart', next); await ready(fixture); await vi.waitFor(() => expect(drawing.update).toHaveBeenCalledExactlyOnceWith('none'));
    expect(drawing.configurations).toHaveLength(1); expect(drawing.destroy).not.toHaveBeenCalled(); fixture.destroy(); expect(drawing.destroy).toHaveBeenCalledTimes(1);
  });
  it('explains negative doughnut values and keeps exact values accessible', async () => {
    const fixture = TestBed.createComponent(ResultChartView), value = chart(); value.definition.type = 'pie-plot'; value.data.datasets[0].data = [-2.75, 0]; value.data.labels = ['Negative', 'Zero'];
    fixture.componentRef.setInput('chart', value); await ready(fixture);
    expect(fixture.nativeElement.querySelector('canvas')).toBeNull(); expect(fixture.nativeElement.textContent).toContain('cannot represent negative values'); expect(fixture.nativeElement.textContent).toContain('-2,75'); expect(drawing.configurations).toHaveLength(0);
  });
  it('safely presents a legacy box response that omits optional outlier observations', async () => {
    const fixture = TestBed.createComponent(ResultChartView), value = chart();
    delete (value.data.datasets[0].data![0] as Partial<import('../../core/results-models').BoxSummary>).outliers;
    fixture.componentRef.setInput('chart', value); await ready(fixture); await vi.waitFor(() => expect(fixture.componentInstance.ready()).toBe(true));
    expect(fixture.nativeElement.textContent).toContain('Not provided');
  });
});

describe('pushed chart renderer failures', () => {
  it('reports an update failure without an unhandled rejection and releases the damaged chart', async () => {
    const fixture = TestBed.createComponent(ResultChartView);
    const component = fixture.componentInstance;
    const canvas = document.createElement('canvas');
    const destroy = vi.fn();
    const update = vi.fn(() => { throw new Error('Invalid updated scale'); });
    const chart = { id: 'bar', definition: { type: 'bar-plot', name: 'Test', parameter: 'value' }, data: { labels: ['One'], datasets: [{ label: 'Value', data: [1] }] } } as unknown as ResultChart;
    const renderer = component as unknown as {
      instance: unknown; render(canvas: HTMLCanvasElement, chart: ResultChart, message: string): Promise<void>;
    };
    renderer.instance = { canvas, config: { type: 'bar' }, update, destroy };
    await expect(renderer.render(canvas, chart, '')).resolves.toBeUndefined();
    expect(component.renderError()).toBe('The chart could not be drawn in this browser.');
    expect(update).toHaveBeenCalledExactlyOnceWith('none');
    expect(component.ready()).toBe(false); expect(destroy).toHaveBeenCalledOnce(); expect(renderer.instance).toBeNull();
    fixture.destroy();
  });
});
