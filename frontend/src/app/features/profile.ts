import { Component, DestroyRef, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../core/api';
import { Metadata, User } from '../core/models';
import { Session } from '../core/session';
import { UnsavedChanges } from '../core/unsaved';
import { Feedback } from '../shared/feedback';

@Component({
  imports: [FormsModule, Feedback],
  template: `<div class="page-heading"><div><span class="eyebrow">YOUR ACCOUNT</span><h1>Profile</h1><p class="text-secondary">Manage your identity and password.</p></div></div>
    <app-feedback [busy]="loading()" [error]="error()" [success]="success()" />
    @if (user()) {
      <div class="row g-4">
        <div class="col-xl-7"><section class="card"><div class="card-header"><h2 class="card-title">Personal information</h2></div><div class="card-body">
          <form #identity="ngForm" (ngSubmit)="saveIdentity()" (ngModelChange)="markIdentity()">
            <fieldset [disabled]="saving()"><div class="mb-3"><label class="form-label" for="profile-username">Username</label><input id="profile-username" class="form-control" [value]="user()?.username" readonly /></div>
              <div class="row g-3"><div class="col-sm-6"><label class="form-label" for="first-name">First name</label><input id="first-name" class="form-control" name="firstName" [(ngModel)]="firstName" (ngModelChange)="markIdentity()" required maxlength="50" autocomplete="given-name" /></div><div class="col-sm-6"><label class="form-label" for="last-name">Last name</label><input id="last-name" class="form-control" name="lastName" [(ngModel)]="lastName" (ngModelChange)="markIdentity()" required maxlength="50" autocomplete="family-name" /></div></div>
              <div class="mt-3"><label class="form-label" for="email">Email</label><input id="email" class="form-control" type="email" name="email" [(ngModel)]="email" (ngModelChange)="markIdentity()" required email autocomplete="email" /></div>
              <div class="mt-3"><label class="form-label" for="gender">Gender</label><select id="gender" class="form-select" name="gender" [(ngModel)]="gender" (ngModelChange)="markIdentity()"><option [ngValue]="0">Not specified</option><option [ngValue]="1">Male</option><option [ngValue]="2">Female</option></select></div>
              <button class="btn btn-primary mt-4" [disabled]="identity.invalid" type="submit">{{ saving() ? 'Saving…' : 'Save profile' }}</button>
            </fieldset>
          </form>
        </div></section></div>
        <div class="col-xl-5"><section class="card"><div class="card-header"><h2 class="card-title">Change password</h2></div><div class="card-body">
          <form #credentials="ngForm" (ngSubmit)="savePassword()"><fieldset [disabled]="saving()">
            <div class="mb-3"><label for="current-password" class="form-label">Current password</label><input id="current-password" type="password" class="form-control" name="currentPassword" [(ngModel)]="currentPassword" (ngModelChange)="markPassword()" autocomplete="current-password" required /></div>
            <div class="mb-3"><label for="new-password" class="form-label">New password</label><input id="new-password" type="password" class="form-control" name="password" [(ngModel)]="password" (ngModelChange)="markPassword()" autocomplete="new-password" [minlength]="metadata()?.password?.minLength ?? 4" [maxlength]="metadata()?.password?.maxLength ?? 30" required /><p class="form-text">Use {{ metadata()?.password?.minLength ?? 4 }}–{{ metadata()?.password?.maxLength ?? 30 }} characters.@if (metadata()?.password?.requireComplexity) { Include uppercase and lowercase letters and a number. }</p></div>
            <div class="mb-3"><label for="confirm-password" class="form-label">Confirm new password</label><input id="confirm-password" type="password" class="form-control" name="confirmation" [(ngModel)]="confirmation" (ngModelChange)="markPassword()" autocomplete="new-password" required /></div>
            <button class="btn btn-outline-primary" [disabled]="credentials.invalid" type="submit">Update password</button>
          </fieldset></form>
        </div></section></div>
      </div>
    }`,
})
export class Profile {
  private readonly api = inject(Api); private readonly session = inject(Session); private readonly changes = inject(UnsavedChanges).scope(inject(DestroyRef));
  readonly user = signal<User | null>(null); readonly metadata = signal<Metadata | null>(null);
  readonly loading = signal(true); readonly saving = signal(false); readonly error = signal(''); readonly success = signal('');
  firstName = ''; lastName = ''; email = ''; gender = 0;
  currentPassword = ''; password = ''; confirmation = '';
  private identityDirty = false; private passwordDirty = false;
  constructor() { inject(DestroyRef).onDestroy(() => this.changes.setDirty(false)); void this.load(); }
  private async load(): Promise<void> {
    try {
      const [user, metadata] = await Promise.all([this.api.get<User>('/profile'), this.api.get<Metadata>('/meta')]);
      this.user.set(user); this.metadata.set(metadata);
      this.firstName = user.firstName; this.lastName = user.lastName; this.email = user.email; this.gender = user.gender;
    } catch (error) { this.error.set(errorMessage(error)); }
    finally { this.loading.set(false); }
  }
  markIdentity(): void { this.identityDirty = true; this.changes.setDirty(true); }
  markPassword(): void { this.passwordDirty = true; this.changes.setDirty(true); }
  async saveIdentity(): Promise<void> {
    if (this.saving()) return;
    const current = this.changes.checkpoint();
    this.error.set(''); this.success.set(''); this.saving.set(true);
    try {
      const user = await this.api.patch<User>('/profile', { firstName: this.firstName, lastName: this.lastName, email: this.email, gender: this.gender });
      if (!current()) return; this.user.set(user);
      await this.session.refresh(); if (!current()) return; this.identityDirty = false; this.changes.setDirty(this.passwordDirty); this.success.set('Your profile has been updated.');
    } catch (error) { if (current()) this.error.set(errorMessage(error)); }
    finally { if (current()) this.saving.set(false); }
  }
  async savePassword(): Promise<void> {
    if (this.saving()) return;
    const current = this.changes.checkpoint();
    this.error.set(''); this.success.set('');
    if (this.password !== this.confirmation) { this.error.set('The new passwords do not match.'); return; }
    this.saving.set(true);
    try {
      await this.api.patch<User>('/profile', { currentPassword: this.currentPassword, password: this.password, passwordConfirmation: this.confirmation });
      if (!current()) return;
      this.currentPassword = ''; this.password = ''; this.confirmation = ''; this.passwordDirty = false;
      this.changes.setDirty(this.identityDirty); this.success.set('Your password has been updated.');
    } catch (error) { if (current()) this.error.set(errorMessage(error)); }
    finally { if (current()) this.saving.set(false); }
  }
}
