import { provideRealtimeMock, RealtimeMock } from '../../core/realtime-testing';
import { Realtime } from '../../core/realtime';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, ParamMap, convertToParamMap, provideRouter } from '@angular/router';
import { EMPTY, Subject } from 'rxjs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { Job } from '../../core/workflow-models';
import { JobDetail } from './job-detail';

describe('JOB-07 pushed log cursors, reset epochs and explicit clearing', () => {
  let api: { get: ReturnType<typeof vi.fn>; delete: ReturnType<typeof vi.fn> };
  beforeEach(() => {
    api = { get: vi.fn(async (path: string) => path.endsWith('/log')
      ? { text: '', offset: 0, nextOffset: 0, complete: false, epoch: 'current' }
      : path.endsWith('/events') ? { items: [] } : { description: 'Fixture' }),
      delete: vi.fn(async () => ({})) };
    TestBed.configureTestingModule({ imports: [JobDetail], providers: [{ provide: Api, useValue: api }, provideRealtimeMock(api), { provide: ActivatedRoute, useValue: { paramMap: EMPTY } }] });
    TestBed.overrideComponent(JobDetail, { set: { template: '', imports: [] } });
  });
  afterEach(() => vi.restoreAllMocks());
  it('appends each pushed byte range once without another read and accepts a server reset epoch', async () => {
    const component = TestBed.createComponent(JobDetail).componentInstance;
    await component.loadLog();
    const realtime = TestBed.inject(Realtime) as unknown as RealtimeMock;
    realtime.push('/jobs/0/log', { text: 'first', offset: 0, nextOffset: 5, epoch: 'current' });
    realtime.push('/jobs/0/log', { text: 'first', offset: 0, nextOffset: 5, epoch: 'current' });
    realtime.push('/jobs/0/log', { text: 'new', offset: 5, nextOffset: 8, epoch: 'current' });
    expect(component.log()).toBe('firstnew'); expect(api.get).toHaveBeenCalledTimes(1);
    realtime.push('/jobs/0/log', { text: 'reset', offset: 0, nextOffset: 5, epoch: 'replacement', reset: true });
    expect(component.log()).toBe('reset');
    await component.loadLog();
    expect(api.get).toHaveBeenLastCalledWith('/jobs/0/log', { offset: 5, epoch: 'replacement' });
  });
  it('rejects pre-clear frames and resumes at zero with a new subscription', async () => {
    const component = TestBed.createComponent(JobDetail).componentInstance;
    vi.spyOn(window, 'confirm').mockReturnValue(true); await component.loadLog();
    const realtime = TestBed.inject(Realtime) as unknown as RealtimeMock;
    const old = [...realtime.listeners][0];
    realtime.push('/jobs/0/log', { text: 'before', offset: 0, nextOffset: 6, epoch: 'current' });
    await component.clearLog();
    expect(api.delete).toHaveBeenCalledWith('/jobs/0/log');
    old.apply({ text: 'obsolete', offset: 0, nextOffset: 8, epoch: 'current' });
    expect(component.log()).toBe('');
    realtime.push('/jobs/0/log', { text: 'current', offset: 0, nextOffset: 7, epoch: 'after-clear', reset: true });
    expect(component.log()).toBe('current');
  });
  it('pauses only the log stream, resumes with its cursor and bounds visible output', async () => {
    const fixture = TestBed.createComponent(JobDetail), component = fixture.componentInstance;
    await component.loadLog(); const realtime = TestBed.inject(Realtime) as unknown as RealtimeMock;
    realtime.push('/jobs/0/log', { text: 'x'.repeat(500_001), offset: 0, nextOffset: 500_001, epoch: 'current' });
    expect(component.log().length).toBeLessThan(500_000);
    component.autoUpdateLog = false; component.logAutomaticChanged();
    expect(realtime.listeners.size).toBe(0);
    component.autoUpdateLog = true; component.logAutomaticChanged(); await fixture.whenStable();
    expect(api.get).toHaveBeenLastCalledWith('/jobs/0/log', { offset: 500_001, epoch: 'current' });
    fixture.destroy(); expect(realtime.listeners.size).toBe(0);
  });
});

describe('JOB-12/14 compact job details and dedicated results navigation', () => {
  const job = (id = 7) => ({
    id, internalId: id, description: 'Original description', environment: 'cem-fixture',
    status: 3, statusName: 'finished', phases: 0, currentPhase: 5, progress: 100,
    projectId: 1, experimentId: 1, evaluationId: 1, evaluationName: 'Evaluation',
    setupRequired: true, client: 'Fixture agent', clientStatus: 'online',
    createdAt: '2026-01-15T12:00:00+01:00', startedAt: null, finishedAt: null,
    owner: { firstName: 'Synthetic', lastName: 'Member', username: 'member' },
    permissions: { canEdit: true, canAbort: false, canReschedule: true, canDownload: true },
  }) as Job;
  let params: Subject<ParamMap>;
  let api: { get: ReturnType<typeof vi.fn> };
  beforeEach(() => {
    params = new Subject<ParamMap>();
    api = { get: vi.fn(async (path: string) => path.endsWith('/events') ? { items: [] }
      : path.endsWith('/log') ? { text: '', offset: 0, nextOffset: 0, complete: true }
      : job(Number(path.split('/')[2]))) };
    TestBed.configureTestingModule({ imports: [JobDetail], providers: [provideRouter([]),
      { provide: Api, useValue: api }, provideRealtimeMock(api), { provide: ActivatedRoute, useValue: { paramMap: params } }] });
  });
  afterEach(() => vi.restoreAllMocks());

  function nativeDialog(element: HTMLDialogElement): void {
    Object.defineProperties(element, {
      showModal: { configurable: true, value: vi.fn(() => element.setAttribute('open', '')) },
      close: { configurable: true, value: vi.fn(() => {
        element.removeAttribute('open'); element.dispatchEvent(new Event('close'));
      }) },
    });
  }

  it('links to a dedicated results page without loading charts or changing a draft', () => {
    const fixture = TestBed.createComponent(JobDetail), component = fixture.componentInstance;
    component.job.set(job()); component.busy.set(false); component.description = 'Unsaved draft';
    component.changes.setDirty(true); fixture.detectChanges();
    const link = fixture.nativeElement.querySelector('a[href="/jobs/7/results"]') as HTMLAnchorElement;
    expect(link.textContent?.trim()).toBe('Results');
    expect(link.hasAttribute('aria-haspopup')).toBe(false);
    expect(fixture.nativeElement.querySelector('.job-results-dialog')).toBeNull();
    expect(fixture.nativeElement.querySelector('app-results-explorer')).toBeNull();
    expect(component.description).toBe('Unsaved draft'); expect(component.changes.dirty()).toBe(true);
  });

  it('keeps CEM and execution metadata between the progress overview and log without a separate details box', () => {
    const fixture = TestBed.createComponent(JobDetail);
    fixture.componentInstance.job.set(job()); fixture.componentInstance.busy.set(false); fixture.detectChanges();
    const root = fixture.nativeElement as HTMLElement;
    expect(root.textContent).not.toContain('Execution details');
    const general = root.querySelector('[aria-labelledby="job-general-title"]')!;
    expect(general.querySelector('dl')?.textContent).toContain('Fixture agent');
    const notice = general.nextElementSibling!;
    expect(notice.textContent).toContain('Chronos Environment Management');
    expect(notice.nextElementSibling?.textContent).toContain('Job log');
    expect(root.querySelectorAll('.progress-phase .progress')).toHaveLength(6);
    expect(root.querySelectorAll('progress')).toHaveLength(0);
  });

  it('updates the result destination and closes the old CDL dialog when the job changes', async () => {
    const fixture = TestBed.createComponent(JobDetail), component = fixture.componentInstance;
    fixture.detectChanges();
    const dialog = fixture.nativeElement.querySelector('.cdl-dialog') as HTMLDialogElement;
    nativeDialog(dialog);
    params.next(convertToParamMap({ id: '7' })); await fixture.whenStable(); fixture.detectChanges();
    dialog.showModal();
    expect(fixture.nativeElement.querySelector('a[href="/jobs/7/results"]')).not.toBeNull();
    params.next(convertToParamMap({ id: '8' })); await fixture.whenStable(); fixture.detectChanges();
    expect(dialog.open).toBe(false);
    expect(component.job()?.id).toBe(8);
    expect(fixture.nativeElement.querySelector('a[href="/jobs/7/results"]')).toBeNull();
    expect(fixture.nativeElement.querySelector('a[href="/jobs/8/results"]')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('app-results-explorer')).toBeNull();
  });

  it('ignores an older CDL response after closing and reopening the dialog', async () => {
    const fixture = TestBed.createComponent(JobDetail), component = fixture.componentInstance;
    component.job.set(job()); fixture.detectChanges();
    const dialog = fixture.nativeElement.querySelector('.cdl-dialog') as HTMLDialogElement;
    nativeDialog(dialog);
    const pending: ((value: { xml: string }) => void)[] = [];
    api.get.mockImplementation(() => new Promise(resolve => pending.push(resolve)));
    const old = component.showCdl(); dialog.close(); const current = component.showCdl();
    pending[1]({ xml: '<current />' }); await current;
    pending[0]({ xml: '<obsolete />' }); await old;
    expect(component.cdl()).toBe('<current />'); expect(component.cdlBusy()).toBe(false);
  });
});

describe('JOB-06 eligible Abort controls', () => {
  const baseJob = { id: 7, internalId: 1, description: '', environment: 'system-local', status: 3, statusName: 'finished', phases: 0, currentPhase: 5, progress: 100, projectId: 1, experimentId: 1, evaluationId: 1, evaluationName: 'Evaluation', owner: { firstName: 'Synthetic', lastName: 'Member', username: 'member' }, permissions: { canEdit: true, canAbort: false, canReschedule: true, canDownload: false } } as Job;
  let api: { get: ReturnType<typeof vi.fn>; patch: ReturnType<typeof vi.fn> };
  beforeEach(() => {
    api = { get: vi.fn(async (path: string) => path.endsWith('/events') ? { items: [] } : path.endsWith('/log') ? { text: '', offset: 0, nextOffset: 0, complete: true } : structuredClone(baseJob)), patch: vi.fn(async () => ({})) };
    TestBed.configureTestingModule({ imports: [JobDetail], providers: [provideRouter([]), { provide: Api, useValue: api }, provideRealtimeMock(api), { provide: ActivatedRoute, useValue: { paramMap: EMPTY } }] });
  });
  afterEach(() => vi.restoreAllMocks());
  it('keeps rescheduling available while hiding Abort for finished and aborted jobs', async () => {
    const fixture = TestBed.createComponent(JobDetail);
    for (const [status, statusName] of [[3, 'finished'], [-1, 'aborted']] as const) {
      fixture.componentInstance.job.set({ ...baseJob, status, statusName }); fixture.componentInstance.busy.set(false); fixture.detectChanges(); await fixture.whenStable();
      const buttons = [...fixture.nativeElement.querySelectorAll('button')] as HTMLButtonElement[];
      expect(buttons.some(button => button.textContent?.trim() === 'Reschedule')).toBe(true);
      expect(buttons.some(button => button.textContent?.trim() === 'Abort job')).toBe(false);
    }
  });
  it('submits the confirmed abort from the eligible native button', async () => {
    const fixture = TestBed.createComponent(JobDetail); fixture.componentInstance.job.set({ ...baseJob, status: 2, statusName: 'running', permissions: { ...baseJob.permissions, canAbort: true } }); fixture.componentInstance.busy.set(false); fixture.detectChanges(); await fixture.whenStable();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const abort = [...fixture.nativeElement.querySelectorAll('button')].find((button: any) => button.textContent?.trim() === 'Abort job') as HTMLButtonElement;
    expect(abort).toBeTruthy(); abort.click(); await fixture.whenStable();
    expect(api.patch).toHaveBeenCalledWith('/jobs/0', { status: -1 });
  });
  it('ignores a stale poll after saving a new description and resumes subsequent refreshes', async () => {
    const fixture = TestBed.createComponent(JobDetail); const component = fixture.componentInstance;
    component.job.set(structuredClone(baseJob));
    let finish!: (value: unknown) => void;
    api.get.mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
    const polling = component.refresh();
    component.description = 'Saved during polling'; component.changes.setDirty(true);
    api.patch.mockResolvedValueOnce({ ...baseJob, description: component.description });
    api.get.mockImplementation(async (path: string) => path.endsWith('/events') ? { items: [] } : path.endsWith('/log') ? { text: '', offset: 0, nextOffset: 0, complete: true } : { ...baseJob, description: component.description });
    await component.save(); finish({ ...baseJob, description: 'Obsolete description' }); await polling;
    expect(component.job()?.description).toBe('Saved during polling');
    expect(component.description).toBe('Saved during polling'); expect(component.changes.dirty()).toBe(false);
    const before = api.get.mock.calls.length; await component.refresh(); expect(api.get.mock.calls.length).toBeGreaterThan(before);
  });
});
