import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { Router } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { ManagedUser } from '../../core/admin-models';
import { Session } from '../../core/session';
import { UnsavedChanges } from '../../core/unsaved';
import { AdminOverview } from './admin-overview';

const target = { id: 3, username: 'member', role: 0, active: true, activated: true } as ManagedUser;
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
