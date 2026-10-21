import { Component, input } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Api, ApiError } from '../../core/api';
import { Realtime } from '../../core/realtime';
import { RealtimeMock, provideRealtimeMock } from '../../core/realtime-testing';
import { ResultsExplorer } from '../results/results-explorer';
import { EvaluationResults } from './evaluation-results';

@Component({ selector: 'app-results-explorer', template: '<p>Results for {{ resourceId() }}</p>' })
class ResultsStub {
  readonly resourceType = input('evaluations');
  readonly resourceId = input.required<number>();
  readonly allowConfigurationManagement = input(true);
}

describe('RESULT-01 dedicated evaluation results workspace', () => {
  const evaluation = {
    id: 8,
    internalId: 2,
    name: 'Completed evaluation',
    projectId: 3,
    projectName: 'Project',
    experimentId: 4,
    experimentName: 'Experiment',
  };
  let api: { get: ReturnType<typeof vi.fn> };

  beforeEach(() => {
    api = { get: vi.fn(async () => structuredClone(evaluation)) };
    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'evaluations/:id/results', component: EvaluationResults }]),
        { provide: Api, useValue: api },
        provideRealtimeMock(api),
      ],
    });
    TestBed.overrideComponent(EvaluationResults, {
      remove: { imports: [ResultsExplorer] },
      add: { imports: [ResultsStub] },
    });
  });

  it('keeps result viewing separate from evaluation controls with navigation back to its context', async () => {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/evaluations/8/results', EvaluationResults);
    await harness.fixture.whenStable();
    harness.detectChanges();
    const element = harness.routeNativeElement!;
    expect(api.get).toHaveBeenCalledWith('/evaluations/8', {});
    expect(element.querySelector('h1')?.textContent).toBe('Results: Completed evaluation');
    expect(element.querySelector('app-results-explorer')?.textContent).toBe('Results for 8');
    expect(element.querySelector('a[href="/evaluations/8"]')?.textContent).toContain(
      'Completed evaluation',
    );
    expect(element.querySelector('a[href="/projects/3"]')?.textContent).toContain('Project');
    expect(element.querySelector('a[href="/experiments/4"]')?.textContent).toContain('Experiment');
    expect(element.querySelector('form')).toBeNull();
    expect(element.querySelector('app-activity')).toBeNull();
  });

  it('starts the independently authorized explorer before its header and closes it after access denial', async () => {
    let reject!: (error: Error) => void;
    api.get.mockReturnValue(
      new Promise((_resolve, fail) => {
        reject = fail;
      }),
    );
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/evaluations/8/results', EvaluationResults);
    harness.detectChanges();
    expect(harness.routeNativeElement!.querySelector('app-results-explorer')?.textContent).toBe(
      'Results for 8',
    );
    reject(new Error('Access denied.'));
    await harness.fixture.whenStable();
    harness.detectChanges();
    expect(harness.routeNativeElement!.querySelector('app-results-explorer')).toBeNull();
    expect(harness.routeNativeElement!.textContent).toContain('Access denied.');
  });

  it('ignores stale snapshots after route reuse and releases its subscription on destruction', async () => {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/evaluations/8/results', EvaluationResults);
    await harness.fixture.whenStable();
    const realtime = TestBed.inject(Realtime) as unknown as RealtimeMock,
      stale = [...realtime.listeners][0];
    api.get.mockResolvedValue({ ...evaluation, id: 9, name: 'Next evaluation' });
    await harness.navigateByUrl('/evaluations/9/results', EvaluationResults);
    await harness.fixture.whenStable();
    harness.detectChanges();
    stale.apply({ ...evaluation, name: 'Stale evaluation' });
    harness.detectChanges();
    expect(harness.routeNativeElement!.textContent).toContain('Next evaluation');
    expect(harness.routeNativeElement!.textContent).not.toContain('Stale evaluation');
    expect(harness.routeNativeElement!.querySelector('app-results-explorer')?.textContent).toBe(
      'Results for 9',
    );
    expect(realtime.listeners.size).toBe(1);
    harness.fixture.destroy();
    expect(realtime.listeners.size).toBe(0);
    stale.apply(evaluation);
  });

  it.each([0, 500, 503])(
    'retains an authorized context and mounted explorer after transient error %s',
    async (status) => {
      const watch = vi.spyOn(TestBed.inject(Realtime), 'watch');
      const harness = await RouterTestingHarness.create();
      await harness.navigateByUrl('/evaluations/8/results', EvaluationResults);
      await harness.fixture.whenStable();
      harness.detectChanges();
      const child = harness.routeNativeElement!.querySelector('app-results-explorer');
      watch.mock.calls[0][3]!(new ApiError('Temporary header error', status, 'temporary'));
      harness.detectChanges();
      expect(harness.routeNativeElement!.querySelector('app-results-explorer')).toBe(child);
      expect(harness.routeNativeElement!.textContent).toContain('Results: Completed evaluation');
      expect(harness.routeNativeElement!.textContent).toContain('Temporary header error');
      (TestBed.inject(Realtime) as unknown as RealtimeMock).push('/evaluations/8', evaluation);
      harness.detectChanges();
      expect(harness.routeNativeElement!.textContent).not.toContain('Temporary header error');
    },
  );

  it.each([401, 403, 404])(
    'unmounts an authorized explorer immediately after context denial %s',
    async (status) => {
      const watch = vi.spyOn(TestBed.inject(Realtime), 'watch');
      const harness = await RouterTestingHarness.create();
      await harness.navigateByUrl('/evaluations/8/results', EvaluationResults);
      await harness.fixture.whenStable();
      harness.detectChanges();
      expect(harness.routeNativeElement!.querySelector('app-results-explorer')).not.toBeNull();
      watch.mock.calls[0][3]!(new ApiError('Context unavailable', status, 'denied'));
      harness.detectChanges();
      expect(harness.routeNativeElement!.querySelector('app-results-explorer')).toBeNull();
      expect(harness.routeNativeElement!.querySelector('h1')).toBeNull();
    },
  );

  it('does not let an older denied header request clear a newer route context', async () => {
    let reject!: (error: Error) => void;
    api.get.mockImplementation((path: string) =>
      path === '/evaluations/8'
        ? new Promise((_resolve, fail) => {
            reject = fail;
          })
        : Promise.resolve({ ...evaluation, id: 9, name: 'Next evaluation' }),
    );
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/evaluations/8/results', EvaluationResults);
    await harness.navigateByUrl('/evaluations/9/results', EvaluationResults);
    await harness.fixture.whenStable();
    reject(new ApiError('Old context denied', 403, 'forbidden'));
    await harness.fixture.whenStable();
    harness.detectChanges();
    expect(harness.routeNativeElement!.textContent).toContain('Next evaluation');
    expect(harness.routeNativeElement!.textContent).not.toContain('Old context denied');
    expect(harness.routeNativeElement!.querySelector('app-results-explorer')?.textContent).toBe(
      'Results for 9',
    );
  });

  it('does not open a result explorer when the evaluation cannot be read', async () => {
    api.get.mockRejectedValue(new Error('Access denied.'));
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/evaluations/8/results', EvaluationResults);
    await harness.fixture.whenStable();
    harness.detectChanges();
    expect(harness.routeNativeElement!.textContent).toContain('Access denied.');
    expect(harness.routeNativeElement!.querySelector('app-results-explorer')).toBeNull();
  });
});
