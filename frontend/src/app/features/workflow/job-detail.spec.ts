import { Component, input } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, ParamMap, convertToParamMap, provideRouter } from '@angular/router';
import { EMPTY, Subject } from 'rxjs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { Job } from '../../core/workflow-models';
import { ResultsExplorer } from '../results/results-explorer';
import { JobDetail } from './job-detail';

interface Chunk { text: string; offset: number; nextOffset: number; complete: boolean; }
describe('JOB-07 concurrent log polling and explicit clearing', () => {
  let pending: ((chunk: Chunk) => void)[];
  let api: { get: ReturnType<typeof vi.fn>; delete: ReturnType<typeof vi.fn> };
  beforeEach(() => {
    pending = [];
    api = { get: vi.fn(() => new Promise<Chunk>(resolve => pending.push(resolve))), delete: vi.fn(async () => ({})) };
    TestBed.configureTestingModule({ imports: [JobDetail], providers: [{ provide: Api, useValue: api }, { provide: ActivatedRoute, useValue: { paramMap: EMPTY } }] });
    TestBed.overrideComponent(JobDetail, { set: { template: '', imports: [] } });
  });
  afterEach(() => vi.restoreAllMocks());
  it('discards an overlapping poll at an already-consumed byte offset', async () => {
    const component = TestBed.createComponent(JobDetail).componentInstance;
    const first = component.loadLog(); const duplicate = component.loadLog();
    pending[0]({ text: 'first', offset: 0, nextOffset: 5, complete: true }); await first;
    pending[1]({ text: 'first', offset: 0, nextOffset: 5, complete: true }); await duplicate;
    expect(component.log()).toBe('first');
    const next = component.loadLog(); expect(api.get).toHaveBeenLastCalledWith('/jobs/0/log', { offset: 5 });
    pending[2]({ text: 'new', offset: 5, nextOffset: 8, complete: true }); await next;
    expect(component.log()).toBe('firstnew');
  });
  it('does not restore cleared output from an earlier in-flight poll', async () => {
    const component = TestBed.createComponent(JobDetail).componentInstance; vi.spyOn(window, 'confirm').mockReturnValue(true);
    const old = component.loadLog(); await component.clearLog();
    expect(api.delete).toHaveBeenCalledWith('/jobs/0/log');
    pending[0]({ text: 'obsolete', offset: 0, nextOffset: 8, complete: true }); await old;
    expect(component.log()).toBe('');
    const current = component.loadLog(); pending[1]({ text: 'current', offset: 0, nextOffset: 7, complete: true }); await current;
    expect(component.log()).toBe('current');
  });
});

describe('JOB-12/14 compact job details and lazy native results dialog', () => {
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
      { provide: Api, useValue: api }, { provide: ActivatedRoute, useValue: { paramMap: params } }] });
    TestBed.overrideComponent(JobDetail, { remove: { imports: [ResultsExplorer] }, add: { imports: [JobResultsStub] } });
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

  it('mounts results only while open and keeps the unsaved description when closing', () => {
    const fixture = TestBed.createComponent(JobDetail), component = fixture.componentInstance;
    component.job.set(job()); component.busy.set(false); component.description = 'Unsaved draft';
    component.changes.setDirty(true); fixture.detectChanges();
    const dialog = fixture.nativeElement.querySelector('.job-results-dialog') as HTMLDialogElement;
    nativeDialog(dialog);
    expect(fixture.nativeElement.querySelector('app-results-explorer')).toBeNull();
    component.showResults(); fixture.detectChanges();
    expect(dialog.open).toBe(true);
    expect(fixture.nativeElement.querySelector('app-results-explorer')).not.toBeNull();
    // A queued event from an earlier close cannot unmount the new opening.
    component.resultsClosed(); expect(component.resultsOpen()).toBe(true);
    dialog.close(); fixture.detectChanges();
    expect(component.resultsOpen()).toBe(false);
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

  it('closes and discards the old results view when the same route component receives another job', async () => {
    const fixture = TestBed.createComponent(JobDetail), component = fixture.componentInstance;
    fixture.detectChanges();
    const dialog = fixture.nativeElement.querySelector('.job-results-dialog') as HTMLDialogElement;
    nativeDialog(dialog);
    params.next(convertToParamMap({ id: '7' })); await fixture.whenStable(); fixture.detectChanges();
    component.showResults(); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('app-results-explorer')).not.toBeNull();
    params.next(convertToParamMap({ id: '8' })); await fixture.whenStable(); fixture.detectChanges();
    expect(dialog.open).toBe(false); expect(component.resultsOpen()).toBe(false);
    expect(component.job()?.id).toBe(8);
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

@Component({ selector: 'app-results-explorer', template: '' })
class JobResultsStub { resourceType = input('jobs'); resourceId = input(0); }

describe('JOB-06 eligible Abort controls', () => {
  const baseJob = { id: 7, internalId: 1, description: '', environment: 'system-local', status: 3, statusName: 'finished', phases: 0, currentPhase: 5, progress: 100, projectId: 1, experimentId: 1, evaluationId: 1, evaluationName: 'Evaluation', owner: { firstName: 'Synthetic', lastName: 'Member', username: 'member' }, permissions: { canEdit: true, canAbort: false, canReschedule: true, canDownload: false } } as Job;
  let api: { get: ReturnType<typeof vi.fn>; patch: ReturnType<typeof vi.fn> };
  beforeEach(() => {
    api = { get: vi.fn(async (path: string) => path.endsWith('/events') ? { items: [] } : path.endsWith('/log') ? { text: '', offset: 0, nextOffset: 0, complete: true } : structuredClone(baseJob)), patch: vi.fn(async () => ({})) };
    TestBed.configureTestingModule({ imports: [JobDetail], providers: [provideRouter([]), { provide: Api, useValue: api }, { provide: ActivatedRoute, useValue: { paramMap: EMPTY } }] });
    TestBed.overrideComponent(JobDetail, { remove: { imports: [ResultsExplorer] }, add: { imports: [JobResultsStub] } });
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
    await component.save(); finish({ ...baseJob, description: 'Obsolete description' }); await polling;
    expect(component.job()?.description).toBe('Saved during polling');
    expect(component.description).toBe('Saved during polling'); expect(component.changes.dirty()).toBe(false);
    const before = api.get.mock.calls.length; await component.refresh(); expect(api.get.mock.calls.length).toBeGreaterThan(before);
  });
});
