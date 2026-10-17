import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Component, inject } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Session } from '../../core/session';
import { UnsavedChanges } from '../../core/unsaved';
import { UserEditor } from './user-editor';

@Component({ template: '<label>New page draft<input (input)="changes.dirty.set(true)" /></label>' })
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
    const account = http.expectOne('/api/v3/users/4'), metadata = http.expectOne('/api/v3/meta');
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
});
