import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Component, inject } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Session } from '../../core/session';
import { UnsavedChanges } from '../../core/unsaved';
import { UserEditor } from './user-editor';

@Component({ template: '<label>New page draft<input (input)="changes.setDirty(true)" /></label>' })
class NextDraft {
  readonly changes = inject(UnsavedChanges);
}

describe('administrator user editor load lifetime', () => {
  let http: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [
      provideHttpClient(), provideHttpClientTesting(),
      provideRouter([{ path: 'admin/users/:id', component: UserEditor }, { path: 'new-draft', component: NextDraft }]),
      { provide: Session, useValue: { user: () => ({ id: 1 }), isSuperAdmin: () => true } },
    ] });
    http = TestBed.inject(HttpTestingController);
  });
  afterEach(() => { http.verify(); TestBed.resetTestingModule(); });

  it('does not clear the next page navigation warning when destroyed editor metadata arrives late', async () => {
    const harness = await RouterTestingHarness.create();
    const editor = await harness.navigateByUrl('/admin/users/4', UserEditor);
    const account = http.expectOne('/api/ui/users/4'), metadata = http.expectOne('/api/ui/meta');
    expect(editor.user()).toBeNull();
    await harness.navigateByUrl('/new-draft', NextDraft);
    const input = harness.routeNativeElement!.querySelector('input')!;
    input.value = 'A new unsaved draft'; input.dispatchEvent(new Event('input', { bubbles: true }));
    expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
    account.flush({ data: { id: 4, username: 'synthetic-member', firstName: 'Fixture', lastName: 'Member', email: 'member@example.test', role: 0, roleName: 'User', active: true, activated: true, gender: 0 } });
    metadata.flush({ data: { password: { minLength: 4, maxLength: 30, requireComplexity: false } } });
    await harness.fixture.whenStable(); harness.detectChanges();
    expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
    expect(harness.routeNativeElement!.querySelector<HTMLInputElement>('input')!.value).toBe('A new unsaved draft');
    expect(editor.user()).toBeNull();
  });
  it('keeps a reused account editor on its new ID when the previous account save completes late', async () => {
    const account = (id: number, firstName = `Account ${id}`) => ({ id, username: `fixture-${id}`, firstName, lastName: 'Fixture', email: `fixture-${id}@example.test`, role: 0, roleName: 'User', active: true, activated: true, gender: 0 });
    const harness = await RouterTestingHarness.create(); const editor = await harness.navigateByUrl('/admin/users/4', UserEditor);
    http.expectOne('/api/ui/users/4').flush({ data: account(4) }); http.expectOne('/api/ui/meta').flush({ data: { password: { minLength: 4, maxLength: 30 } } });
    await harness.fixture.whenStable(); harness.detectChanges();
    const editName = async (name: string) => { await vi.waitFor(() => { harness.detectChanges(); expect(harness.routeNativeElement!.querySelector('#user-first-name')).not.toBeNull(); }); const input = harness.routeNativeElement!.querySelector<HTMLInputElement>('#user-first-name')!; input.value = name; input.dispatchEvent(new Event('input', { bubbles: true })); await harness.fixture.whenStable(); harness.detectChanges(); };
    await editName('Saved old account'); const firstSave = editor.saveIdentity(); const first = http.expectOne('/api/ui/users/4');
    expect(first.request.method).toBe('PATCH');
    expect(await harness.navigateByUrl('/admin/users/5', UserEditor)).toBe(editor);
    http.expectOne('/api/ui/users/5').flush({ data: account(5) }); http.expectOne('/api/ui/meta').flush({ data: { password: { minLength: 4, maxLength: 30 } } });
    await harness.fixture.whenStable(); harness.detectChanges(); await editName('New account draft');
    const secondSave = editor.saveIdentity(); const second = http.expectOne('/api/ui/users/5');
    expect(second.request.body.firstName).toBe('New account draft');
    first.flush({ data: account(4, 'Saved old account') }); await firstSave; harness.detectChanges();
    expect(editor.user()?.id).toBe(5); expect(editor.firstName).toBe('New account draft'); expect(editor.saving()).toBe(true); expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
    second.flush({ data: account(5, 'New account draft') }); await secondSave; harness.detectChanges();
    expect(editor.user()?.id).toBe(5); expect(editor.saving()).toBe(false); expect(TestBed.inject(UnsavedChanges).dirty()).toBe(false);
  });
});
