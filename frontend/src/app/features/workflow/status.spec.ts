import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { Session } from '../../core/session';
import { Status } from './status';

const evaluation = {
  id: 1,
  internalId: 3,
  experimentInternalId: 2,
  name: 'Fixture evaluation',
  projectId: 1,
  projectName: 'Fixture project',
  experimentId: 1,
  experimentName: 'Fixture experiment',
  systemId: 1,
  systemName: 'Fixture system',
  counts: { total: 6, finished: 2, active: 3, failed: 1 },
};
const job = {
  id: 7,
  internalId: 1,
  description: 'Fixture job',
  evaluationId: 1,
  evaluationName: 'Fixture evaluation',
  experimentId: 1,
  experimentName: 'Fixture experiment',
  projectId: 1,
  projectName: 'Fixture project',
  systemId: 1,
  systemName: 'Fixture system',
  environment: 'cem-fixture',
  owner: { firstName: 'Fixture', lastName: 'Owner', username: 'owner' },
  statusName: 'running',
  progress: 25,
};

describe('JOB-01/02 and EVALUATION-01 status table context and controls', () => {
  let api: { get: ReturnType<typeof vi.fn> };
  beforeEach(() => {
    api = {
      get: vi.fn(async (path: string) => ({
        items: path === '/jobs' ? [job] : [evaluation],
        page: 1,
        pageSize: 7,
        total: 1,
      })),
    };
    TestBed.configureTestingModule({
      imports: [Status],
      providers: [
        provideRouter([{ path: 'status', component: Status }]),
        { provide: Api, useValue: api },
        { provide: Session, useValue: { isAdmin: () => true } },
      ],
    });
  });
  afterEach(() => vi.restoreAllMocks());
  async function setup() {
    const fixture = TestBed.createComponent(Status);
    await fixture.whenStable();
    fixture.detectChanges();
    return fixture;
  }
  it('shows hierarchical evaluation ID and links to experiment, project and system', async () => {
    const fixture = await setup();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('tbody')?.textContent).toContain('2.3');
    expect(
      [...element.querySelectorAll('tbody a')].map((link) => link.getAttribute('href')),
    ).toEqual(['/evaluations/1', '/evaluations/1', '/experiments/1', '/projects/1', '/systems/1']);
    expect(fixture.componentInstance.pageSize).toBe(7);
    expect(api.get).toHaveBeenCalledWith(
      '/evaluations',
      expect.not.objectContaining({ pageSize: expect.anything() }),
    );
  });
  it('preserves owner, execution type, evaluation and system context in the job view and sorts all columns', async () => {
    const fixture = await setup();
    const element = fixture.nativeElement as HTMLElement;
    [...element.querySelectorAll('button')]
      .find((button) => button.textContent?.trim() === 'Jobs')!
      .click();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(element.querySelector('tbody')?.textContent).toContain('Fixture Owner (owner)');
    expect(element.querySelector('tbody')?.textContent).toContain('CEM');
    expect(element.querySelector('tbody')?.textContent).toContain('Fixture system');
    const sort = element.querySelector<HTMLButtonElement>('button[aria-label="Sort by User"]')!;
    sort.click();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(api.get).toHaveBeenLastCalledWith(
      '/jobs',
      expect.objectContaining({ sort: 'owner', page: 1, status: 'active' }),
    );
    expect(sort.closest('th')?.getAttribute('aria-sort')).toBe('ascending');
    sort.click();
    await fixture.whenStable();
    expect(api.get).toHaveBeenLastCalledWith('/jobs', expect.objectContaining({ sort: '-owner' }));
  });
  it('ignores an outdated response after switching views', async () => {
    const fixture = await setup();
    const component = fixture.componentInstance;
    let finish!: (value: unknown) => void;
    api.get.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          finish = resolve;
        }),
    );
    const oldLoad = component.load();
    component.switchView('jobs');
    await fixture.whenStable();
    finish({
      items: [{ ...evaluation, name: 'Stale response' }],
      page: 1,
      pageSize: 99,
      total: 99,
    });
    await oldLoad;
    fixture.detectChanges();
    expect(component.jobs()).toEqual([job]);
    expect(component.total()).toBe(1);
    expect(component.pageSize).toBe(7);
  });
  it('honors legacy view=jobs links without first loading the evaluation view', async () => {
    const harness = await RouterTestingHarness.create('/status?view=jobs');
    await harness.fixture.whenStable();
    harness.detectChanges();
    expect(harness.routeNativeElement!.querySelector('caption')?.textContent?.trim()).toBe(
      'Job status',
    );
    expect(api.get).toHaveBeenCalledWith(
      '/jobs',
      expect.objectContaining({ status: 'active', sort: 'id' }),
    );
    expect(api.get.mock.calls.some(([path]) => path === '/evaluations')).toBe(false);
  });
});
