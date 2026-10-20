import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api, ApiError } from '../core/api';
import { Session } from '../core/session';
import { UnsavedChanges } from '../core/unsaved';
import { Profile } from './profile';

const user = { id: 1, username: 'owner', firstName: 'Owner', lastName: 'Fixture', email: 'owner@example.test', gender: 0, role: 0, roleName: 'User', enabled: true, activated: true };
describe('profile password identity transition', () => {
  const api = { get: vi.fn(), patch: vi.fn() };
  const session = { withIdentityChange: vi.fn(), refresh: vi.fn() };
  let suspended = false;
  beforeEach(() => {
    vi.resetAllMocks(); suspended = false;
    api.get.mockImplementation(async (path: string) => path === '/profile' ? user : { password: { minLength: 4, maxLength: 50, requireComplexity: false } });
    api.patch.mockImplementation(async () => { expect(suspended).toBe(true); return user; });
    session.withIdentityChange.mockImplementation(async (action: () => Promise<unknown>) => {
      suspended = true; try { return await action(); } finally { suspended = false; }
    });
    TestBed.configureTestingModule({ imports: [Profile], providers: [provideRouter([]), { provide: Api, useValue: api }, { provide: Session, useValue: session }] });
  });
  afterEach(() => TestBed.resetTestingModule());
  async function setup() {
    const fixture = TestBed.createComponent(Profile); fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
    const component = fixture.componentInstance;
    component.currentPassword = 'Old-password-2026!'; component.password = component.confirmation = 'New-password-2026!'; component.markPassword();
    return { fixture, component };
  }
  it('suspends the old live lease before the password PATCH and clears only the saved password draft', async () => {
    const { fixture, component } = await setup(); await component.savePassword(); fixture.detectChanges();
    expect(session.withIdentityChange).toHaveBeenCalledOnce();
    expect(api.patch).toHaveBeenCalledWith('/profile', { currentPassword: 'Old-password-2026!', password: 'New-password-2026!', passwordConfirmation: 'New-password-2026!' });
    expect(component.currentPassword).toBe(''); expect(component.password).toBe(''); expect(component.confirmation).toBe('');
    expect(fixture.nativeElement.textContent).toContain('Your password has been updated.');
    expect(TestBed.inject(UnsavedChanges).dirty()).toBe(false);
  });
  it('retains rejected password fields and their original feedback so the user can retry', async () => {
    const { fixture, component } = await setup();
    api.patch.mockRejectedValue(new ApiError('The current password is incorrect.', 422, 'validation_failed'));
    await component.savePassword(); fixture.detectChanges();
    expect(component.error()).toBe('The current password is incorrect.'); expect(component.success()).toBe('');
    expect(component.currentPassword).toBe('Old-password-2026!'); expect(component.password).toBe('New-password-2026!');
    expect(component.saving()).toBe(false); expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
  });
  it('does not interrupt the live lease when password confirmation fails locally', async () => {
    const { component } = await setup(); component.confirmation = 'Mismatch'; await component.savePassword();
    expect(session.withIdentityChange).not.toHaveBeenCalled(); expect(api.patch).not.toHaveBeenCalled();
    expect(component.error()).toBe('The new passwords do not match.');
  });
});
