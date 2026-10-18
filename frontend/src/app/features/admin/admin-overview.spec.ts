import { TestBed } from '@angular/core/testing';
import { Component, signal } from '@angular/core';
import { Router, provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { ManagedUser } from '../../core/admin-models';
import { Session } from '../../core/session';
import { UnsavedChanges } from '../../core/unsaved';
import { AdminOverview } from './admin-overview';
import { AdminSettings } from './settings';

const target = { id: 3, username: 'member', role: 0, enabled: true, activated: true } as ManagedUser;
describe('administrator account switching with unsaved forms', () => {
  const api = { get: vi.fn(), post: vi.fn() };
  const session = { user: signal({ id: 2, role: 1 }), isAdmin: () => true, isSuperAdmin: () => false, impersonation: signal({ active: false }), refresh: vi.fn() };
  const router = { navigate: vi.fn() };
  beforeEach(() => {
    vi.resetAllMocks(); api.get.mockImplementation(async (path: string) => path === '/meta' ? {} : { items: [], total: 0, page: 1, pageSize: 20 });
    api.post.mockResolvedValue({}); session.refresh.mockResolvedValue(undefined); router.navigate.mockResolvedValue(true);
    TestBed.configureTestingModule({ imports: [AdminOverview], providers: [{ provide: Api, useValue: api }, { provide: Session, useValue: session }, { provide: Router, useValue: router }] });
    TestBed.overrideComponent(AdminOverview, { set: { template: '', imports: [] } });
  });
  afterEach(() => { vi.restoreAllMocks(); TestBed.resetTestingModule(); });
  it('keeps the administrator identity when discard is declined before impersonation', async () => {
    const component = TestBed.createComponent(AdminOverview).componentInstance;
    const changes = TestBed.inject(UnsavedChanges); changes.setDirty(true); component.switchTarget.set(target);
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    await component.switchUser();
    expect(api.post).not.toHaveBeenCalled(); expect(session.refresh).not.toHaveBeenCalled(); expect(router.navigate).not.toHaveBeenCalled(); expect(changes.dirty()).toBe(true);
  });
  it('discards only after a successful identity switch and navigates without a second dirty prompt', async () => {
    const component = TestBed.createComponent(AdminOverview).componentInstance;
    const changes = TestBed.inject(UnsavedChanges); changes.setDirty(true); component.switchTarget.set(target);
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    router.navigate.mockImplementationOnce(async () => { expect(changes.dirty()).toBe(false); return true; });
    await component.switchUser();
    expect(confirm).toHaveBeenCalledOnce(); expect(api.post).toHaveBeenCalledWith('/session/impersonation', { userId: 3, permanent: false });
    expect(session.refresh).toHaveBeenCalledOnce(); expect(router.navigate).toHaveBeenCalledWith(['/']);
  });
  it('retains the drafts if the identity change fails', async () => {
    const component = TestBed.createComponent(AdminOverview).componentInstance;
    const changes = TestBed.inject(UnsavedChanges); changes.setDirty(true); component.switchTarget.set(target);
    vi.spyOn(window, 'confirm').mockReturnValue(true); api.post.mockRejectedValueOnce(new Error('Switch rejected'));
    await component.switchUser();
    expect(changes.dirty()).toBe(true); expect(component.dialogError()).toBe('Switch rejected'); expect(router.navigate).not.toHaveBeenCalled();
  });
});

@Component({ selector: 'app-admin-settings', template: '' })
class EmptyAdminSettings {}

describe('account state labels and accessible vector symbols', () => {
  afterEach(() => TestBed.resetTestingModule());
  it.each([[true, 'Enabled'], [false, 'Disabled']] as const)('renders %s with %s text and a decorative SVG', async (enabled, label) => {
    const user = { ...target, enabled, firstName: 'Fixture', lastName: 'Member', email: 'member@example.test', roleName: 'User' };
    const api = { get: vi.fn(async (path: string) => path === '/users' ? { items: [user], total: 1, page: 1, pageSize: 20 } : path === '/meta' ? {} : { items: [], total: 0, page: 1, pageSize: 20 }) };
    const session = { user: () => ({ id: 2, role: 1 }), isSuperAdmin: () => false, impersonation: () => ({ active: false }) };
    TestBed.configureTestingModule({ imports: [AdminOverview], providers: [provideRouter([]), { provide: Api, useValue: api }, { provide: Session, useValue: session }] });
    TestBed.overrideComponent(AdminOverview, { remove: { imports: [AdminSettings] }, add: { imports: [EmptyAdminSettings] } });
    const fixture = TestBed.createComponent(AdminOverview); fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
    const cells = fixture.nativeElement.querySelectorAll('table')[0].querySelectorAll('tbody td') as NodeListOf<HTMLTableCellElement>;
    const state = Array.from(cells).find(cell => cell.textContent?.trim() === label)!;
    expect(state).toBeDefined(); expect(state.querySelector('svg')?.getAttribute('aria-hidden')).toBe('true');
    expect(state.querySelectorAll('svg path')).toHaveLength(1); expect(state.querySelector('img')).toBeNull();
    const select = fixture.nativeElement.querySelector('#user-state') as HTMLSelectElement;
    expect(Array.from(select.options).map(option => option.text)).toEqual(['Enabled', 'Disabled', 'All accounts']);
    expect(api.get).toHaveBeenCalledWith('/users', expect.objectContaining({ enabled: 'true' }));
    expect(fixture.componentInstance.canSwitch(user)).toBe(enabled);
  });
});
