import { TestBed } from '@angular/core/testing';
import { computed, signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../core/api';
import { DashboardData } from '../core/models';
import { Session } from '../core/session';
import { Dashboard } from './dashboard';

const ownerCounts = { projects: 1, experiments: 2, evaluationsRunning: 2, evaluationsCompleted: 2, jobs: 15, activeJobs: 5, failedJobs: 1 };
const allCounts = { projects: 2, experiments: 3, evaluationsRunning: 2, evaluationsCompleted: 3, jobs: 16, activeJobs: 5, failedJobs: 1 };
const emptyCounts = { projects: 0, experiments: 0, evaluationsRunning: 0, evaluationsCompleted: 0, jobs: 0, activeJobs: 0, failedJobs: 0 };
const reply = (counts: DashboardData['counts'], title: string): DashboardData => ({ counts, events: [{ id: 1, title, text: 'Activity text', actorName: 'Owner Fixture', at: '2026-01-15T12:00:00+01:00', resourceType: 'project', resourceId: 1 }] });

describe('dashboard scope and account changes', () => {
  const user = signal({ id: 3, role: 0, firstName: 'Owner', username: 'owner' });
  const api = { get: vi.fn() };
  beforeEach(() => {
    vi.resetAllMocks(); user.set({ id: 3, role: 0, firstName: 'Owner', username: 'owner' });
    api.get.mockResolvedValue(reply(ownerCounts, 'Own project activity'));
    TestBed.configureTestingModule({ imports: [Dashboard], providers: [provideRouter([]), { provide: Api, useValue: api }, { provide: Session, useValue: { user, isAdmin: computed(() => user().role >= 1) } }] });
  });
  afterEach(() => TestBed.resetTestingModule());

  it('defaults a regular account to its own scoped data with no privileged selector and precise German event tooltip', async () => {
    const fixture = TestBed.createComponent(Dashboard); fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
    expect(api.get).toHaveBeenCalledWith('/dashboard', { all: false });
    expect(fixture.nativeElement.querySelector('#dashboard-scope')).toBeNull();
    expect(fixture.nativeElement.querySelector('h1').textContent).toBe('Chronos Control');
    expect(fixture.nativeElement.querySelector('[data-metric="projects"] .info-box-number').textContent).toBe('1');
    expect(fixture.nativeElement.querySelector('time').title).toBe('15.01.2026 12:00:00');
    fixture.componentInstance.scopeChanged(true); await fixture.whenStable();
    expect(api.get).toHaveBeenLastCalledWith('/dashboard', { all: false });
  });

  it('keeps every counter in the legacy reading order and retains project navigation and the activity timeline', async () => {
    const fixture = TestBed.createComponent(Dashboard); fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
    const metrics = Array.from(fixture.nativeElement.querySelectorAll('.dashboard-metrics .info-box')) as HTMLElement[];
    expect(metrics.map(metric => [metric.getAttribute('aria-label'), metric.querySelector('strong')!.textContent])).toEqual([
      ['Projects', '1'], ['Experiments', '2'], ['Completed evaluations', '2'], ['Running evaluations', '2'],
      ['Total jobs', '15'], ['Active jobs', '5'], ['Failed jobs', '1'],
    ]);
    expect(metrics.every(metric => metric.querySelector('.info-box-icon svg[aria-hidden]') || metric.querySelector('.info-box-icon[aria-hidden="true"] svg'))).toBe(true);
    expect(fixture.nativeElement.querySelector('.dashboard-actions a').getAttribute('href')).toBe('/projects');
    expect(fixture.nativeElement.querySelector('.dashboard-activity app-activity').getAttribute('presentation')).toBe('timeline');
    expect(fixture.nativeElement.querySelector('.dashboard-activity').textContent).toContain('Own project activity');
  });

  it('shows an API error without retaining counts from the prior scope and allows the administrator to select another scope', async () => {
    user.set({ id: 2, role: 1, firstName: 'Admin', username: 'admin' });
    const fixture = TestBed.createComponent(Dashboard); fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
    api.get.mockRejectedValueOnce(new Error('Dashboard is temporarily unavailable.'));
    fixture.componentInstance.scopeChanged(true); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.dashboard-metrics')).toBeNull();
    expect(fixture.nativeElement.querySelector('#dashboard-scope').disabled).toBe(true);
    await fixture.whenStable(); fixture.detectChanges(); await fixture.whenStable();
    expect(fixture.nativeElement.querySelector('[role="alert"]').textContent).toContain('Dashboard is temporarily unavailable.');
    expect(fixture.nativeElement.querySelector('.dashboard-metrics')).toBeNull();
    expect(fixture.nativeElement.querySelector('#dashboard-scope').disabled).toBe(false);
    fixture.componentInstance.scopeChanged(false); await fixture.whenStable(); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[role="alert"]')).toBeNull();
    expect(fixture.nativeElement.querySelector('[data-metric="projects"] strong').textContent).toBe('1');
  });

  it('uses a real administrator selector to request all-user counts and activity, then returns to the own workspace', async () => {
    user.set({ id: 2, role: 1, firstName: 'Admin', username: 'admin' });
    api.get.mockImplementation(async (_path: string, query: { all: boolean }) => reply(query.all ? allCounts : emptyCounts, query.all ? 'All project activity' : 'Own account activity'));
    const fixture = TestBed.createComponent(Dashboard); fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
    const select = fixture.nativeElement.querySelector('#dashboard-scope') as HTMLSelectElement;
    expect(select.selectedOptions[0].textContent).toBe('My workspace');
    const choose = async (label: string) => {
      select.value = Array.from(select.options).find(option => option.textContent === label)!.value;
      select.dispatchEvent(new Event('change', { bubbles: true })); await fixture.whenStable(); fixture.detectChanges();
    };
    await choose('All users'); expect(api.get).toHaveBeenLastCalledWith('/dashboard', { all: true });
    expect(fixture.nativeElement.querySelector('h1').textContent).toBe('Chronos Control');
    expect(fixture.nativeElement.querySelector('[data-metric="projects"] .info-box-number').textContent).toBe('2');
    expect(fixture.nativeElement.querySelector('app-activity').textContent).toContain('All project activity');
    await choose('My workspace'); expect(api.get).toHaveBeenLastCalledWith('/dashboard', { all: false });
    expect(fixture.nativeElement.querySelector('[data-metric="projects"] .info-box-number').textContent).toBe('0');
    expect(fixture.nativeElement.querySelector('app-activity').textContent).toContain('Own account activity');
  });

  it('resets scope for a changed account and ignores an older response from the prior identity', async () => {
    const requests: { query: { all: boolean }; resolve: (data: DashboardData) => void }[] = [];
    api.get.mockImplementation((_path: string, query: { all: boolean }) => new Promise<DashboardData>(resolve => requests.push({ query, resolve })));
    const fixture = TestBed.createComponent(Dashboard); fixture.detectChanges();
    await vi.waitFor(() => expect(requests).toHaveLength(1));
    user.set({ id: 2, role: 1, firstName: 'Admin', username: 'admin' }); fixture.detectChanges();
    await vi.waitFor(() => expect(requests).toHaveLength(2)); expect(requests[1].query).toEqual({ all: false });
    requests[1].resolve(reply(emptyCounts, 'New account activity'));
    await vi.waitFor(() => { fixture.detectChanges(); expect(fixture.nativeElement.querySelector('app-activity')?.textContent).toContain('New account activity'); });
    requests[0].resolve(reply(ownerCounts, 'Old account activity')); await fixture.whenStable(); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[data-metric="projects"] .info-box-number').textContent).toBe('0');
    expect(fixture.nativeElement.querySelector('app-activity').textContent).not.toContain('Old account activity');
  });
});
