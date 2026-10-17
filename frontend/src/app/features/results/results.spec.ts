import { TestBed } from '@angular/core/testing';
import { Component, input } from '@angular/core';
import { provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api, ApiError } from '../../core/api';
import { BoxSummary, EvaluationResults, ResultChart, ResultConfigurationDocument } from '../../core/results-models';
import { ExperimentResults } from './experiment-results';
import { ResultConfigurationEditor } from './result-configuration-editor';
import { ResultsExplorer } from './results-explorer';
import { ResultChartView } from './result-chart';
import { chartConfiguration, chartValue, plotCsv, plotRows, tooltipLabel, validateResultConfigurations } from './result-state';

const definition = (type = 'line-plot') => ({ id: 'plot-one', type, name: 'Latency <safe>', parameter: 'latency', plotting: 'latency', orientation: 'horizontal' as const, customZero: 0 });
const document = (): ResultConfigurationDocument => ({ version: '1.0', elements: { 'system-1': { name: 'Default', all: [definition()], job: [] } } });
const box: BoxSummary = { min: -8, q1: 0.25, median: 1.5, q3: 2.75, max: 90, whiskerMin: -1, whiskerMax: 5, outliers: [-8, 90] };
const chart = (type = 'line-plot'): ResultChart => ({ id: 'chart-one', title: 'Metric', definition: definition(type), scope: 'all', jobIds: [1, 2], data: { labels: ['=unsafe', 'second'], datasets: [{ label: 'A', data: [0, null] }] } });
const results = (): EvaluationResults => ({ configurationId: 'system-1', configurations: [{ id: 'system-1', name: 'Default' }, { id: 'experiment-8-other', name: 'Custom' }], finishedJobs: 0, totalJobs: 0, charts: [], jobs: [], warnings: [] });
const ready = async (fixture: ReturnType<typeof TestBed.createComponent>) => { fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges(); };
@Component({ selector: 'app-result-chart', template: `{{ chart().title }}` })
class ChartStub { readonly chart = input.required<ResultChart>(); }

describe('result display adapters', () => {
  it('adapts all five chart types and both box orientations without calculating observations', () => {
    expect(['bar-plot', 'line-plot', 'line-plot-multi', 'pie-plot', 'box-plot'].map(type => chartConfiguration(chart(type)).type)).toEqual(['bar', 'line', 'line', 'doughnut', 'boxplot']);
    const value = chart('box-plot'); value.data.datasets[0].data = [box, null];
    const before = JSON.stringify(value), config = chartConfiguration(value);
    expect(config.options?.indexAxis).toBe('y'); expect(config.data.datasets[0].data[0]).toEqual(box);
    expect(JSON.stringify(value)).toBe(before);
    value.definition.orientation = 'vertical'; expect(chartConfiguration(value).options?.indexAxis).toBe('x');
  });
  it('renders a server-registered custom chart using its base type without changing the stored definition', () => {
    const value = chart('custom-box'); value.definition['baseType'] = 'box-plot'; value.renderer = 'boxplot'; value.data.datasets[0].data = [box];
    const before = JSON.stringify(value); expect(chartConfiguration(value).type).toBe('boxplot'); expect(chartConfiguration(value).options?.indexAxis).toBe('y'); expect(JSON.stringify(value)).toBe(before);
    const schema = document(); schema.elements['system-1'].all[0].type = 'custom-line'; expect(validateResultConfigurations(schema, { 'custom-line': 'line-plot' })).toEqual([]);
    expect(validateResultConfigurations(schema, { 'custom-line': 'unregistered-intermediate' }).join()).toContain('Unsupported plot type');
  });
  it('retains zero, missing observations, server ordering and complete array labels', () => {
    const value = chart(); value.data.labels = [0, 1, 2, 3]; value.data.datasets[0].data = [0, null, -2.5, 4];
    expect(plotRows(value).map(row => row.label)).toEqual(['0', '1', '2', '3']);
    const config = chartConfiguration(value); expect(config.data.datasets[0].data).toEqual([0, null, -2.5, 4]);
    expect(config.options?.locale).toBe('de-DE');
    expect(config.data.datasets[0]).toHaveProperty('spanGaps', false);
  });
  it('exports BOM/semicolon German CSV with exact decimal precision and formula-safe labels', () => {
    const value = chart(); value.data.datasets[0].data = [0, -2.75];
    const csv = plotCsv(value);
    expect(csv.startsWith('\uFEFF')).toBe(true); expect(csv).toContain('"\'=unsafe";"A";"0"'); expect(csv).toContain('"-2,75"');
    expect(csv.split('\r\n')).toHaveLength(4);
  });
  it('exports the server-provided fractional quartiles, observed whiskers and outliers', () => {
    const value = chart('box-plot'); value.data.datasets[0].data = [box];
    const csv = plotCsv(value); expect(csv).toContain('"-8";"0,25";"1,5";"2,75";"90";"-1";"5";"[-8,90]"');
  });
  it('validates unknown types, empty metrics and identifiers without dropping extensions', () => {
    const value = document(); expect(validateResultConfigurations(value)).toEqual([]);
    value.elements['system-1'].all[0].type = '<script>unknown</script>'; value.elements['system-1'].all[0].parameter = '';
    expect(validateResultConfigurations(value).length).toBe(2); expect(value.elements['system-1'].all[0]['customZero']).toBe(0);
  });
  it('shows tiny finite observations and the hovered outlier without rounding them to zero', () => {
    expect(chartValue(1e-12)).toBe('1E-12'); expect(chartValue(-2.75)).toBe('-2,75');
    expect(tooltipLabel({ dataset: { label: 'Latency' }, raw: box, formattedValue: { hoveredOutlierIndex: 1 } })).toBe('Latency: Outlier 90');
    expect(tooltipLabel({ dataset: {}, raw: box })).toContain('Q1 0,25');
  });
});

describe('result configuration editor', () => {
  afterEach(() => vi.restoreAllMocks());
  beforeEach(async () => { await TestBed.configureTestingModule({ imports: [ResultConfigurationEditor] }).compileComponents(); });
  it('edits all five types with unique labelled native controls and preserves metadata', async () => {
    const fixture = TestBed.createComponent(ResultConfigurationEditor), value = document();
    value.elements['system-1'].all = ['bar-plot', 'line-plot', 'line-plot-multi', 'pie-plot', 'box-plot'].map((type, index) => ({ ...definition(type), id: `plot-${index}` }));
    fixture.componentRef.setInput('document', value); await ready(fixture);
    const root: HTMLElement = fixture.nativeElement, ids = Array.from(root.querySelectorAll('[id]')).map(node => node.id);
    expect(Array.from(root.querySelectorAll<HTMLSelectElement>('select[id$="-type"]')).map(select => select.value)).toEqual(value.elements['system-1'].all.map(plot => plot.type));
    expect(root.querySelector<HTMLSelectElement>('select[id$="-orientation"]')!.value).toBe('horizontal');
    expect(new Set(ids).size).toBe(ids.length);
    for (const label of Array.from(root.querySelectorAll('label[for]'))) expect(root.querySelector(`[id="${label.getAttribute('for')}"]`)).not.toBeNull();
    fixture.componentInstance.update('all', 0, 'name', 'Changed'); fixture.componentInstance.move('all', 0, 1);
    expect(fixture.componentInstance.selected().all[1]['customZero']).toBe(0); expect(value.elements['system-1'].all[0].name).toBe('Latency <safe>');
    expect(root.querySelector('safe')).toBeNull();
  });
  it('confirms plot draft deletion and keeps the original data and Undo available', async () => {
    const fixture = TestBed.createComponent(ResultConfigurationEditor), original = document(), before = JSON.stringify(original); fixture.componentRef.setInput('document', original); await ready(fixture);
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false); const remove = [...fixture.nativeElement.querySelectorAll('button')].find((item: any) => item.textContent?.trim() === 'Delete plot') as HTMLButtonElement;
    expect(remove.textContent).toContain('Delete plot'); remove.click(); await ready(fixture); expect(JSON.stringify(fixture.componentInstance.document())).toBe(before); expect(fixture.componentInstance.previous()).toBeNull();
    confirm.mockReturnValue(true); remove.click(); await ready(fixture); expect(fixture.componentInstance.selected().all).toEqual([]); expect(JSON.stringify(original)).toBe(before); fixture.componentInstance.undo(); await ready(fixture); expect(JSON.stringify(fixture.componentInstance.document())).toBe(before);
  });
  it('creates namespace-safe experiment IDs that fit legacy storage and isolates undo after reload', async () => {
    const fixture = TestBed.createComponent(ResultConfigurationEditor); fixture.componentRef.setInput('idPrefix', 'experiment-2147483647'); await ready(fixture);
    fixture.componentInstance.add(); await ready(fixture);
    const id = fixture.componentInstance.selectedId(); expect(id.startsWith('experiment-2147483647-')).toBe(true); expect(id.length).toBeLessThanOrEqual(50);
    fixture.componentInstance.rename('Query plans with spaces & café'); expect(fixture.componentInstance.selected().name).toContain('café');
    fixture.componentRef.setInput('document', document()); await ready(fixture); expect(fixture.componentInstance.previous()).toBeNull();
  });
  it('edits registered custom chart aliases while retaining their raw type and metadata', async () => {
    const value = document(); value.elements['system-1'].all[0] = { ...definition('custom-box'), parameter: 'workers' };
    const fixture = TestBed.createComponent(ResultConfigurationEditor); fixture.componentRef.setInput('document', value); fixture.componentRef.setInput('aliases', { 'custom-box': 'box-plot' }); await ready(fixture);
    expect(fixture.componentInstance.issues()).toEqual([]); expect(fixture.nativeElement.textContent).toContain('Orientation'); expect(fixture.nativeElement.textContent).not.toContain('Unsupported plot type');
    fixture.componentInstance.update('all', 0, 'name', 'Custom renamed'); await ready(fixture);
    expect(fixture.componentInstance.document().elements['system-1'].all[0]).toMatchObject({ type: 'custom-box', customZero: 0 }); expect(value.elements['system-1'].all[0].name).toBe('Latency <safe>');
  });
  it('opens a bookmarked configuration and job scope without changing saved definitions', async () => {
    const value = document(); value.elements['experiment-8-bookmark'] = { name: 'Bookmarked', all: [], job: [definition()] }; const original = JSON.stringify(value);
    const fixture = TestBed.createComponent(ResultConfigurationEditor); fixture.componentRef.setInput('document', value); fixture.componentRef.setInput('configurationId', 'experiment-8-bookmark'); fixture.componentRef.setInput('initialScope', 'job'); const changes: ResultConfigurationDocument[] = []; fixture.componentInstance.document.subscribe(value => changes.push(value)); await ready(fixture);
    expect(fixture.componentInstance.selectedId()).toBe('experiment-8-bookmark'); expect(fixture.componentInstance.scopes()[0].key).toBe('job'); expect(fixture.nativeElement.querySelector('select[id$="-configuration"]').value).toBe('experiment-8-bookmark'); expect(changes).toEqual([]); expect(JSON.stringify(value)).toBe(original);
  });
  it('blocks unsupported custom charts visibly while retaining their data', async () => {
    const fixture = TestBed.createComponent(ResultConfigurationEditor), value = document(); value.elements['system-1'].all[0].type = 'custom-chart';
    fixture.componentRef.setInput('document', value); await ready(fixture);
    expect(fixture.componentInstance.issues().join(' ')).toContain('Unsupported plot type'); expect(fixture.nativeElement.textContent).toContain('custom-chart');
    expect(fixture.componentInstance.document().elements['system-1'].all[0]['customZero']).toBe(0);
  });
});

describe('authorized result hosts', () => {
  const api = { get: vi.fn(), request: vi.fn(), patch: vi.fn(), post: vi.fn() };
  beforeEach(async () => { vi.resetAllMocks(); await TestBed.configureTestingModule({ imports: [ResultsExplorer, ExperimentResults], providers: [{ provide: Api, useValue: api }, provideRouter([])] }).overrideComponent(ResultsExplorer, { remove: { imports: [ResultChartView] }, add: { imports: [ChartStub] } }).compileComponents(); });
  it('keeps the selected chart configuration across the reactive loading effect', async () => {
    api.get.mockImplementation(async (_: string, query?: { configurationId: string }) => ({ ...results(), configurationId: query?.configurationId ?? 'system-1' }));
    const fixture = TestBed.createComponent(ResultsExplorer); fixture.componentRef.setInput('resourceId', 8); await ready(fixture);
    fixture.componentInstance.selectConfiguration('experiment-8-other'); await ready(fixture);
    expect(api.get).toHaveBeenLastCalledWith('/evaluations/8/results', { page: 1, sort: 'id', configurationId: 'experiment-8-other' });
    expect(fixture.componentInstance.configuration()).toBe('experiment-8-other'); expect(fixture.componentInstance.data()?.configurationId).toBe('experiment-8-other');
    expect((fixture.nativeElement.querySelector('select[id$="-configuration"]') as HTMLSelectElement).value).toBe('experiment-8-other');
  });
  it('offers an explicit empty choice and a recovery link when no result configuration is selected', async () => {
    api.get.mockImplementation(async (_path: string, query?: { configurationId?: string }) => ({ ...results(), experimentId: 3, configurationId: query?.configurationId ?? '' }));
    const fixture = TestBed.createComponent(ResultsExplorer); fixture.componentRef.setInput('resourceId', 8); await ready(fixture);
    const select = fixture.nativeElement.querySelector('select[id$="-configuration"]') as HTMLSelectElement; expect(select.value).toBe(''); expect(select.options[0].disabled).toBe(true); expect(fixture.nativeElement.textContent).toContain('Choose a result configuration to display charts.');
    expect((fixture.nativeElement.querySelector('a[href="/experiments/3?tab=results"]') as HTMLAnchorElement)).not.toBeNull();
    select.value = 'system-1'; select.dispatchEvent(new Event('change', { bubbles: true })); await ready(fixture); expect(select.value).toBe('system-1'); expect(fixture.componentInstance.data()?.configurationId).toBe('system-1');
  });
  it('keeps raw results available and links to configuration settings when no definitions exist', async () => {
    api.get.mockResolvedValue({ ...results(), configurationId: '', experimentId: 3, configurations: [] }); const fixture = TestBed.createComponent(ResultsExplorer); fixture.componentRef.setInput('resourceId', 8); await ready(fixture);
    expect(fixture.nativeElement.textContent).toContain('No result configuration is available.'); expect(fixture.nativeElement.textContent).toContain('Raw job results'); expect(fixture.nativeElement.querySelector('a[href="/experiments/3?tab=results"]')).not.toBeNull();
  });
  it('retains previous results after a refresh failure and explains incomplete downloads', async () => {
    api.get.mockResolvedValue(results()); const fixture = TestBed.createComponent(ResultsExplorer); fixture.componentRef.setInput('resourceId', 8); await ready(fixture);
    api.get.mockRejectedValue(new Error('Offline')); await fixture.componentInstance.load();
    expect(fixture.componentInstance.data()).not.toBeNull(); expect(fixture.componentInstance.error()).toBe('Offline');
    const event = new Event('click', { cancelable: true }); fixture.componentInstance.blockIncomplete(event); expect(event.defaultPrevented).toBe(true);
  });
  it('continues five-second automatic refresh after completion, notices an external reschedule, and respects off and destruction', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] });
    let value: EvaluationResults = { ...results(), totalJobs: 1, finishedJobs: 1, activeJobs: 0, charts: [chart()] };
    api.get.mockImplementation(async () => structuredClone(value));
    const fixture = TestBed.createComponent(ResultsExplorer); fixture.componentRef.setInput('resourceId', 8);
    try {
      await ready(fixture); expect(fixture.componentInstance.data()?.finishedJobs).toBe(1);
      value = { ...value, finishedJobs: 0, activeJobs: 1, charts: [] };
      await vi.advanceTimersByTimeAsync(4999); expect(api.get).toHaveBeenCalledTimes(1);
      await vi.advanceTimersByTimeAsync(1); await ready(fixture);
      expect(api.get).toHaveBeenCalledTimes(2); expect(fixture.componentInstance.data()?.finishedJobs).toBe(0);
      const updated = { ...chart(), title: 'Results after the rescheduled job finished' };
      value = { ...value, finishedJobs: 1, activeJobs: 0, charts: [updated] };
      await vi.advanceTimersByTimeAsync(5000); await ready(fixture);
      expect(api.get).toHaveBeenCalledTimes(3); expect(fixture.nativeElement.textContent).toContain(updated.title);
      const automatic = fixture.nativeElement.querySelector('input[type="checkbox"]') as HTMLInputElement;
      automatic.checked = false; automatic.dispatchEvent(new Event('change', { bubbles: true })); await ready(fixture);
      await vi.advanceTimersByTimeAsync(10_000); expect(api.get).toHaveBeenCalledTimes(3);
      fixture.componentInstance.automatic.set(true); fixture.destroy();
      await vi.advanceTimersByTimeAsync(10_000); expect(api.get).toHaveBeenCalledTimes(3);
    } finally { fixture.destroy(); vi.useRealTimers(); }
  });
  it('copies a system chart into the experiment scope and submits only visible system/own entries', async () => {
    const value = document(); api.get.mockResolvedValue(value); api.request.mockImplementation(async (_method, _path, body) => body);
    const fixture = TestBed.createComponent(ExperimentResults); fixture.componentRef.setInput('experimentId', 8); fixture.componentRef.setInput('systemId', 1); fixture.componentRef.setInput('resultId', 'system-1'); await ready(fixture); await fixture.componentInstance.load(); await ready(fixture);
    expect(fixture.componentInstance.editable().elements).toEqual({}); fixture.componentInstance.copySystem(value.elements['system-1']); await ready(fixture);
    const ownId = Object.keys(fixture.componentInstance.editable().elements)[0]; expect(ownId.startsWith('experiment-8-')).toBe(true);
    expect(fixture.componentInstance.editable().elements[ownId].all[0]['customZero']).toBe(0);
    await fixture.componentInstance.save(); expect(api.request).toHaveBeenCalledWith('PUT', '/experiments/8/result-configurations', expect.objectContaining({ elements: expect.objectContaining({ 'system-1': value.elements['system-1'], [ownId]: expect.any(Object) }) }));
    expect(fixture.componentInstance.dirty()).toBe(false);
  });
  it('reloads a locally saved configuration after sync failure and offers a scoped push retry', async () => {
    const value = document(); api.get.mockResolvedValue(value);
    const fixture = TestBed.createComponent(ExperimentResults); fixture.componentRef.setInput('experimentId', 8); fixture.componentRef.setInput('systemId', 1); await ready(fixture);
    fixture.componentInstance.copySystem(value.elements['system-1']);
    const saved = { ...value, elements: { ...value.elements, ...fixture.componentInstance.editable().elements } }; api.get.mockResolvedValue(saved);
    api.request.mockRejectedValue(new ApiError('The configuration is saved locally, but synchronization failed.', 409, 'repository_sync_failed'));
    await fixture.componentInstance.save();
    expect(fixture.componentInstance.dirty()).toBe(false); expect(fixture.componentInstance.synchronizationFailed()).toBe(true); expect(fixture.componentInstance.error()).toContain('saved locally');
    api.post.mockResolvedValue({ synchronized: true }); await fixture.componentInstance.retrySynchronization();
    expect(api.post).toHaveBeenCalledWith('/experiments/8/result-configurations/push', {}); expect(fixture.componentInstance.synchronizationFailed()).toBe(false);
  });
  it('uses server result paging, sorting and search while retaining complete chart datasets', async () => {
    const completeChart = chart(); completeChart.data.labels = ['all configurations'];
    api.get.mockImplementation(async (_path: string, query: Record<string, string | number>) => ({ ...results(), activeJobs: 7, charts: [completeChart], jobPage: { total: 55, page: Number(query['page']), pageSize: Number(query['pageSize'] ?? 10), sort: query['sort'] }, jobs: [] }));
    const fixture = TestBed.createComponent(ResultsExplorer); fixture.componentRef.setInput('resourceId', 8); await ready(fixture);
    expect(fixture.componentInstance.pages()).toBe(6); fixture.componentInstance.setPage(3); await ready(fixture);
    expect(api.get).toHaveBeenLastCalledWith('/evaluations/8/results', { page: 3, sort: 'id' });
    expect(fixture.componentInstance.data()?.charts).toEqual([completeChart]);
    fixture.componentInstance.changePageSize('25'); await ready(fixture); expect(api.get).toHaveBeenLastCalledWith('/evaluations/8/results', { page: 1, pageSize: 25, sort: 'id' });
    fixture.componentInstance.sortBy('finishedAt'); await ready(fixture); expect(fixture.componentInstance.ariaSort('finishedAt')).toBe('ascending');
    fixture.componentInstance.changeSearch('zero'); await vi.waitFor(() => expect(api.get).toHaveBeenLastCalledWith('/evaluations/8/results', { page: 1, pageSize: 25, sort: 'finishedAt', search: 'zero' }));
  });
});
