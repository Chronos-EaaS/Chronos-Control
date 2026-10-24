import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Component, input } from '@angular/core';
import { By } from '@angular/platform-browser';
import { beforeEach, describe, expect, it } from 'vitest';
import { BoxSummary, PLOT_TYPES, PlotTypeAliases, ResultChart, ResultConfiguration } from '../../core/results-models';
import { ResultChartView } from './result-chart';
import { ResultConfigurationPreview } from './result-configuration-preview';
import { chartConfiguration, chartRenderType, isPlotType } from './result-state';

@Component({ selector: 'app-result-chart', template: '{{ chart().title }}' })
class ChartStub { readonly chart = input.required<ResultChart>(); }

const configuration = (): ResultConfiguration => {
  const plots = PLOT_TYPES.map(({ type }, index) => ({
    id: `chart-${index}`, type, name: `Example ${type}`,
    parameter: ['line-plot-multi', 'box-plot'].includes(type) ? 'workers, batch' : 'latency, throughput',
    plotting: 'latency', orientation: 'horizontal' as const,
    metadata: { zero: 0, disabled: false, text: '  Zürich <safe>  ' },
  }));
  return { name: 'Configuration <safe>', all: plots, job: structuredClone(plots), custom: { retained: true } };
};
const ready = async (fixture: ComponentFixture<ResultConfigurationPreview>) => {
  fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
};
async function mount(value: ResultConfiguration | null = configuration(), aliases: PlotTypeAliases = {}) {
  const fixture = TestBed.createComponent(ResultConfigurationPreview);
  fixture.componentRef.setInput('configuration', value); fixture.componentRef.setInput('aliases', aliases);
  await ready(fixture);
  const charts = () => fixture.debugElement.queryAll(By.directive(ChartStub)).map(node => (node.componentInstance as ChartStub).chart());
  return { fixture, component: fixture.componentInstance, root: fixture.nativeElement as HTMLElement, charts };
}

describe('result configuration example preview', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [ResultConfigurationPreview] })
      .overrideComponent(ResultConfigurationPreview, { remove: { imports: [ResultChartView] }, add: { imports: [ChartStub] } })
      .compileComponents();
  });

  it('passes all five chart types in both scopes to the shared renderer without changing saved definitions', async () => {
    const value = configuration(), before = JSON.stringify(value), { root, charts } = await mount(value);
    expect(root.querySelectorAll('app-result-chart')).toHaveLength(10);
    expect(charts().map(chart => chartConfiguration(chart).type)).toEqual(['bar', 'line', 'line', 'doughnut', 'boxplot', 'bar', 'line', 'line', 'doughnut', 'boxplot']);
    expect(charts().filter(chart => chart.scope === 'all')).toHaveLength(5);
    expect(charts().filter(chart => chart.scope === 'job')).toHaveLength(5);
    expect(new Set(charts().map(chart => chart.id)).size).toBe(10);
    expect(charts().every(chart => chart.jobIds.length === 0)).toBe(true);
    expect(root.textContent).toContain('Example data only'); expect(root.textContent).toContain('Chart downloads contain the same example data.');
    expect(root.querySelector('safe')).toBeNull(); expect(root.textContent).toContain('Configuration <safe>');
    expect(JSON.stringify(value)).toBe(before);
  });

  it('uses configured metric and iteration labels with aligned scalar values and valid precomputed box summaries', async () => {
    const { charts } = await mount(), values = charts().filter(chart => chart.scope === 'all');
    expect(values[0].data.labels).toContain('Sample A · latency'); expect(values[0].data.labels).toContain('Sample A · throughput');
    expect(values[1].data.datasets.map(dataset => dataset.label)).toEqual(['latency', 'throughput']);
    expect(values[2].data.datasets.map(dataset => dataset.label)).toEqual(['workers = 1, batch = 1', 'workers = 2, batch = 2']);
    expect(values[2].groupLabel).toBe('Example result metric: latency');
    for (const chart of values) for (const dataset of chart.data.datasets) expect(dataset.data).toHaveLength(chart.data.labels!.length);
    const box = values[4]; expect(chartConfiguration(box).options?.indexAxis).toBe('y');
    for (const dataset of box.data.datasets) for (const observation of dataset.data!) {
      const summary = observation as BoxSummary;
      expect(summary.min).toBeLessThanOrEqual(summary.q1); expect(summary.q1).toBeLessThanOrEqual(summary.median);
      expect(summary.median).toBeLessThanOrEqual(summary.q3); expect(summary.q3).toBeLessThanOrEqual(summary.whiskerMax);
      expect(summary.whiskerMin).toBe(summary.min); expect(summary.outliers).toEqual([summary.max]);
      expect(summary.outliers[0]).toBeGreaterThan(summary.q3 + 1.5 * (summary.q3 - summary.q1));
    }
  });

  it('renders a registered alias through its allowed base type without rewriting saved type or metadata', async () => {
    const value = configuration(); value.all = [{ ...value.all[4], type: 'custom-box', baseType: 'pie-plot' }]; value.job = [];
    const before = JSON.stringify(value), { charts } = await mount(value, { 'custom-box': 'box-plot' }), chart = charts()[0];
    expect(chart.definition).not.toBe(value.all[0]);
    expect(chart.definition).toMatchObject({ type: 'custom-box', baseType: 'box-plot', metadata: value.all[0]['metadata'] });
    expect(chartConfiguration(chart).type).toBe('boxplot'); expect(chartConfiguration(chart).options?.indexAxis).toBe('y');
    expect(JSON.stringify(value)).toBe(before);
  });

  it('rejects a saved baseType as a substitute for renderer registration', async () => {
    const value = configuration(); value.all = [{ ...value.all[0], type: 'unknown-chart', baseType: 'bar-plot' }]; value.job = [];
    const before = JSON.stringify(value), { charts } = await mount(value);
    expect(isPlotType(chartRenderType(charts()[0]))).toBe(false);
    expect(charts()[0].definition['baseType']).toBeUndefined();
    expect(JSON.stringify(value)).toBe(before);
  });

  it('updates examples for changed draft labels and box orientation without changing either draft', async () => {
    const original = configuration(); original.all = [original.all[4]]; original.job = [];
    const { fixture, root, charts } = await mount(original);
    const next = structuredClone(original); next.all[0].name = 'Changed title'; next.all[0].parameter = 'clients'; next.all[0].orientation = 'vertical';
    const before = JSON.stringify(next); fixture.componentRef.setInput('configuration', next); await ready(fixture);
    expect(charts()[0].title).toBe('Changed title'); expect(charts()[0].data.datasets[0].label).toBe('clients = 1');
    expect(root.textContent).toContain('Changed title');
    expect(chartConfiguration(charts()[0]).options?.indexAxis).toBe('x'); expect(JSON.stringify(next)).toBe(before);
    expect(original.all[0].name).toBe('Example box-plot'); expect(original.all[0].orientation).toBe('horizontal');
  });

  it('keeps incomplete and empty configurations usable without loading saved evaluation data', async () => {
    const { fixture, root, charts } = await mount(null);
    expect(root.textContent).toContain('Choose or create a result configuration'); expect(charts()).toEqual([]);
    fixture.componentRef.setInput('configuration', { name: '', all: [], job: [] }); await ready(fixture);
    expect(root.querySelectorAll('.preview-empty')).toHaveLength(2);
    fixture.componentRef.setInput('configuration', { name: '', all: [{ id: 'new', type: 'bar-plot', name: '', parameter: '' }], job: [] }); await ready(fixture);
    expect(charts()[0].title).toBe('Untitled chart'); expect(charts()[0].data.datasets[0].label).toBe('Example metric');
    expect(charts()[0].data.datasets[0].data).toHaveLength(3);
  });

  it('bounds a 2000-chart preview to six adapted charts per scope while keeping every page reachable', async () => {
    const observed = new Set<string>(), value: ResultConfiguration = { name: 'Large configuration', all: [], job: [] };
    for (const scope of ['all', 'job'] as const) {
      value[scope] = Array.from({ length: 1000 }, (_, index) => {
        const definition = { id: `${scope}-${index}`, type: 'bar-plot', parameter: 'metric', name: '' };
        Object.defineProperty(definition, 'name', { enumerable: true, get: () => {
          observed.add(`${scope}-${index}`); return `Chart ${scope} ${index}`;
        } });
        return definition;
      });
    }
    const { fixture, component, root, charts } = await mount(value);
    const pager = (scope: string) => root.querySelector<HTMLElement>(`nav[aria-label="Chart preview pages for ${scope}"]`)!;
    const choose = async (scope: string, page: string) => {
      const select = pager(scope).querySelector<HTMLSelectElement>('select')!;
      select.value = page; select.dispatchEvent(new Event('change', { bubbles: true })); await ready(fixture);
    };
    const click = async (scope: string, name: string) => {
      const button = [...pager(scope).querySelectorAll<HTMLButtonElement>('button')].find(button => button.textContent?.trim() === name)!;
      button.click(); await ready(fixture);
    };
    expect(charts()).toHaveLength(12); expect(observed.size).toBe(12);
    expect(pager('All jobs').textContent?.replace(/\s+/g, ' ')).toContain('Showing 1–6 of 1000 charts · Page 1 of 167');
    const originalChildren = fixture.debugElement.queryAll(By.directive(ChartStub)).filter(child => (child.componentInstance as ChartStub).chart().scope === 'all').map(child => child.componentInstance);
    await choose('All jobs', '167');
    expect(charts()).toHaveLength(10); expect(observed.size).toBe(16);
    expect(charts().filter(chart => chart.scope === 'all').map(chart => chart.definition.id)).toEqual(['all-996', 'all-997', 'all-998', 'all-999']);
    expect(charts().find(chart => chart.scope === 'all')!.id).toBe('preview-all-996-all-996');
    expect(fixture.debugElement.queryAll(By.directive(ChartStub)).every(child => !originalChildren.includes(child.componentInstance))).toBe(true);
    expect(pager('All jobs').textContent?.replace(/\s+/g, ' ')).toContain('Showing 997–1000 of 1000 charts · Page 167 of 167');
    expect([...pager('All jobs').querySelectorAll<HTMLButtonElement>('button')].find(button => button.textContent?.trim() === 'Next')!.disabled).toBe(true);
    await click('All jobs', 'Previous');
    expect(charts().filter(chart => chart.scope === 'all').map(chart => chart.definition.id)).toEqual(['all-990', 'all-991', 'all-992', 'all-993', 'all-994', 'all-995']);
    expect(charts()).toHaveLength(12);
    await click('All jobs', 'Next');
    await choose('Individual configurations', '100');
    expect(charts().find(chart => chart.scope === 'job')!.definition.id).toBe('job-594');
    fixture.componentRef.setInput('aliases', { 'custom-box': 'box-plot' }); await ready(fixture);
    expect(component.scopes().map(scope => scope.page)).toEqual([167, 100]);
    component.setPage('job', Number.MAX_SAFE_INTEGER); await ready(fixture);
    expect(component.scopes().find(scope => scope.key === 'job')!.page).toBe(167);
    component.setPage('job', 'not-a-page'); await ready(fixture);
    expect(component.scopes().find(scope => scope.key === 'job')!.page).toBe(1);
    expect(charts().length).toBeLessThanOrEqual(12); expect(observed.size).toBeLessThan(50);
    const replacement = { name: 'Replacement', all: value.all.slice(0, 2), job: value.job.slice(0, 1) };
    fixture.componentRef.setInput('configuration', replacement); await ready(fixture);
    expect(component.scopes().map(scope => scope.page)).toEqual([1, 1]); expect(charts()).toHaveLength(3);
    expect(root.querySelectorAll('select')).toHaveLength(0);
    expect(value.all).toHaveLength(1000); expect(value.job).toHaveLength(1000); expect(value.name).toBe('Large configuration');
  });
});
