import { Component, input } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { Realtime } from '../../core/realtime';
import { provideRealtimeMock, RealtimeMock } from '../../core/realtime-testing';
import { EvaluationResults, ResultChart } from '../../core/results-models';
import { ResultChartView } from './result-chart';
import { ResultsExplorer } from './results-explorer';

@Component({ selector: 'app-result-chart', template: '{{ chart().title }}' })
class ChartStub {
  readonly chart = input.required<ResultChart>();
}
const chart: ResultChart = {
  id: 'one',
  title: 'Latency',
  definition: { id: 'one', name: 'Latency', type: 'bar-plot', parameter: 'latency' },
  scope: 'all',
  jobIds: [1],
  data: { labels: ['All'], datasets: [{ data: [0] }] },
};
const results = (): EvaluationResults => ({
  configurationId: 'system-1',
  experimentId: 3,
  configurations: [{ id: 'system-1', name: 'Default' }],
  finishedJobs: 1,
  totalJobs: 1,
  activeJobs: 0,
  charts: [structuredClone(chart)],
  jobs: [],
  warnings: [],
});
const ready = async (fixture: ReturnType<typeof TestBed.createComponent>) => {
  fixture.detectChanges();
  await fixture.whenStable();
  fixture.detectChanges();
};

describe('separate on-demand raw results', () => {
  let api: { get: ReturnType<typeof vi.fn> };
  beforeEach(async () => {
    api = { get: vi.fn(async () => results()) };
    await TestBed.configureTestingModule({
      imports: [ResultsExplorer],
      providers: [{ provide: Api, useValue: api }, provideRealtimeMock(api), provideRouter([])],
    })
      .overrideComponent(ResultsExplorer, {
        remove: { imports: [ResultChartView] },
        add: { imports: [ChartStub] },
      })
      .compileComponents();
  });

  it.each(['evaluations', 'jobs'])(
    'requests no raw data or DOM until the explicit %s Raw results button is used',
    async (type) => {
      const fixture = TestBed.createComponent(ResultsExplorer);
      fixture.componentRef.setInput('resourceType', type);
      fixture.componentRef.setInput('resourceId', 8);
      await ready(fixture);
      expect(api.get).toHaveBeenCalledExactlyOnceWith(`/${type}/8/results`, { view: 'charts' });
      expect(fixture.nativeElement.querySelector('.raw-result-table')).toBeNull();
      expect(fixture.componentInstance.rawData()).toBeNull();
      const button = [...fixture.nativeElement.querySelectorAll('button')].find(
        (item: any) => item.textContent.trim() === 'Raw results',
      ) as HTMLButtonElement;
      expect(button.getAttribute('aria-expanded')).toBe('false');
      const selectedCharts = fixture.componentInstance.data()!.charts;
      const chartNode = fixture.nativeElement.querySelector('app-result-chart');
      button.click();
      await ready(fixture);
      expect(api.get).toHaveBeenLastCalledWith(`/${type}/8/results`, {
        view: 'raw',
        page: 1,
        sort: 'id',
      });
      expect(button.getAttribute('aria-expanded')).toBe('true');
      expect(button.textContent?.trim()).toBe('Charts');
      expect(fixture.nativeElement.querySelector('.results-charts').style.display).toBe('none');
      expect(fixture.nativeElement.querySelector('.raw-result-table')).not.toBeNull();
      expect(fixture.componentInstance.data()!.charts).toBe(selectedCharts);
      const realtime = TestBed.inject(Realtime) as unknown as RealtimeMock;
      expect(realtime.listeners.size).toBe(2);
      button.click();
      await ready(fixture);
      expect(realtime.listeners.size).toBe(1);
      expect(button.textContent?.trim()).toBe('Raw results');
      expect(fixture.nativeElement.querySelector('.results-charts').style.display).toBe('');
      expect(fixture.nativeElement.querySelector('app-result-chart')).toBe(chartNode);
      expect(fixture.componentInstance.rawData()).toBeNull();
      expect(fixture.nativeElement.querySelector('.raw-result-table')).toBeNull();
    },
  );

  it('does not subscribe to charts again when raw filters, sorting and pages change', async () => {
    api.get.mockImplementation(async (_path, query) =>
      query.view === 'charts'
        ? results()
        : {
            ...results(),
            charts: [],
            jobPage: {
              total: 200,
              page: query.page,
              pageSize: query.pageSize ?? 20,
              sort: query.sort,
            },
          },
    );
    const fixture = TestBed.createComponent(ResultsExplorer);
    fixture.componentRef.setInput('resourceId', 8);
    await ready(fixture);
    const initialCharts = fixture.componentInstance.data()!.charts;
    fixture.componentInstance.toggleRaw();
    await ready(fixture);
    fixture.componentInstance.setPage(3);
    await ready(fixture);
    fixture.componentInstance.sortBy('result');
    await ready(fixture);
    fixture.componentInstance.changeSearch('latency');
    await vi.waitFor(() =>
      expect(api.get).toHaveBeenLastCalledWith('/evaluations/8/results', {
        view: 'raw',
        page: 1,
        sort: 'result',
        search: 'latency',
      }),
    );
    expect(api.get.mock.calls.filter(([, query]) => query.view === 'charts')).toHaveLength(1);
    expect(fixture.componentInstance.data()!.charts).toBe(initialCharts);
  });

  it('handles independent raw failures and ignores late results after close or resource changes', async () => {
    const fixture = TestBed.createComponent(ResultsExplorer);
    fixture.componentRef.setInput('resourceId', 8);
    await ready(fixture);
    api.get.mockRejectedValueOnce(new Error('Raw unavailable'));
    fixture.componentInstance.toggleRaw();
    await ready(fixture);
    expect(fixture.componentInstance.rawError()).toBe('Raw unavailable');
    expect(fixture.componentInstance.error()).toBe('');
    expect(fixture.componentInstance.data()!.charts).toHaveLength(1);
    const realtime = TestBed.inject(Realtime) as unknown as RealtimeMock,
      old = [...realtime.listeners].find((listener) => listener.query['view'] === 'raw')!;
    fixture.componentInstance.closeRaw();
    old.apply({ ...results(), jobs: [{ id: 99 }] });
    await ready(fixture);
    expect(fixture.componentInstance.rawData()).toBeNull();
    expect(fixture.componentInstance.rawOpen()).toBe(false);
    fixture.componentInstance.toggleRaw();
    await ready(fixture);
    fixture.componentRef.setInput('resourceId', 9);
    await ready(fixture);
    old.apply({ ...results(), jobs: [{ id: 99 }] });
    await ready(fixture);
    expect(fixture.componentInstance.rawOpen()).toBe(false);
    expect(fixture.componentInstance.rawData()).toBeNull();
    expect(fixture.componentInstance.base()).toBe('/api/ui/evaluations/9');
    expect(realtime.listeners.size).toBe(1);
    fixture.destroy();
    expect(realtime.listeners.size).toBe(0);
  });

  it('pauses both projections without reads and offers manual refresh only while paused', async () => {
    const fixture = TestBed.createComponent(ResultsExplorer);
    fixture.componentRef.setInput('resourceId', 8);
    await ready(fixture);
    fixture.componentInstance.toggleRaw();
    await ready(fixture);
    expect(fixture.nativeElement.querySelector('[aria-label="Refresh results"]')).toBeNull();
    const realtime = TestBed.inject(Realtime) as unknown as RealtimeMock;
    expect(realtime.listeners.size).toBe(2);
    api.get.mockClear();
    fixture.componentInstance.automatic.set(false);
    await ready(fixture);
    expect(api.get).not.toHaveBeenCalled();
    expect(realtime.listeners.size).toBe(0);
    (
      fixture.nativeElement.querySelector('[aria-label="Refresh results"]') as HTMLButtonElement
    ).click();
    await ready(fixture);
    expect(api.get).toHaveBeenCalledTimes(2);
    expect(realtime.listeners.size).toBe(0);
    fixture.componentInstance.automatic.set(true);
    await ready(fixture);
    expect(realtime.listeners.size).toBe(2);
    expect(fixture.nativeElement.querySelector('[aria-label="Refresh results"]')).toBeNull();
  });

  it('can disable configuration editing without removing raw views or downloads', async () => {
    const fixture = TestBed.createComponent(ResultsExplorer);
    fixture.componentRef.setInput('resourceId', 8);
    fixture.componentRef.setInput('allowConfigurationManagement', false);
    await ready(fixture);
    expect(
      fixture.nativeElement.querySelector('[aria-label="Manage result configurations"]'),
    ).toBeNull();
    expect(
      fixture.nativeElement.querySelector('[aria-label="Download raw results CSV"]'),
    ).not.toBeNull();
    expect(fixture.nativeElement.textContent).toContain('Raw results');
  });
});
