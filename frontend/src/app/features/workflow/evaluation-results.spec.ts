import { Component, input } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { ResultsExplorer } from '../results/results-explorer';
import { EvaluationResults } from './evaluation-results';

@Component({ selector: 'app-results-explorer', template: '<p>Results for {{ resourceId() }}</p>' })
class ResultsStub {
  readonly resourceType = input('evaluations');
  readonly resourceId = input.required<number>();
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
    expect(api.get).toHaveBeenCalledWith('/evaluations/8');
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
