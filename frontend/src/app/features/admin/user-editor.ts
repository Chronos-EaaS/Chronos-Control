import { Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api';
import { ManagedUser } from '../../core/admin-models';
import { Metadata } from '../../core/models';
import { Session } from '../../core/session';
import { UnsavedChanges } from '../../core/unsaved';
import { dateTime } from '../../core/time';
import { Feedback } from '../../shared/feedback';

@Component({
  imports: [FormsModule, RouterLink, Feedback],
  template: `<div class="page-heading"><div><span class="eyebrow">USER MANAGEMENT</span><h1>{{ user() ? 'Edit ' + user()?.username : 'Edit user' }}</h1><p class="text-secondary">Manage account identity, access and credentials.</p></div><a routerLink="/admin" class="btn btn-outline-secondary">Back to administration</a></div>
    <app-feedback [busy]="loading()" [error]="error()" [success]="success()" />
    @if (user(); as account) {
      @if (account.id === session.user()?.id) { <div class="alert alert-info">Manage your own account on <a routerLink="/profile">your profile</a>.</div> }
      @else { <div class="row g-4"><div class="col-xl-7"><section class="card"><div class="card-header"><h2 class="card-title">Account information</h2></div><div class="card-body"><form #identity="ngForm" (ngSubmit)="saveIdentity()"><fieldset [disabled]="saving()" (input)="markIdentity()" (change)="markIdentity()">
        <div class="mb-3"><label class="form-label" for="user-username">Username</label><input id="user-username" class="form-control" name="username" [(ngModel)]="username" required maxlength="50" autocomplete="off" /></div>
        <div class="row g-3"><div class="col-sm-6"><label class="form-label" for="user-first-name">First name</label><input id="user-first-name" class="form-control" name="firstName" [(ngModel)]="firstName" required maxlength="50" /></div><div class="col-sm-6"><label class="form-label" for="user-last-name">Last name</label><input id="user-last-name" class="form-control" name="lastName" [(ngModel)]="lastName" required maxlength="50" /></div></div>
        <div class="my-3"><label class="form-label" for="user-email">Email</label><input id="user-email" type="email" class="form-control" name="email" [(ngModel)]="email" required email maxlength="100" /></div>
        <div class="mb-3"><label for="user-gender" class="form-label">Gender</label><select id="user-gender" class="form-select" name="gender" [(ngModel)]="gender"><option [ngValue]="0">Not specified</option><option [ngValue]="1">Male</option><option [ngValue]="2">Female</option></select></div>
        @if (session.isSuperAdmin()) { <div class="mb-3"><label for="user-role" class="form-label">Role</label><select id="user-role" class="form-select" name="role" [(ngModel)]="role"><option [ngValue]="0">User</option><option [ngValue]="1">Administrator</option><option [ngValue]="2">Superadministrator</option></select></div> }
        @else { <p>Role: <strong>{{ account.roleName }}</strong></p> }
        <div class="form-check mb-2"><input id="user-active" class="form-check-input" type="checkbox" name="active" [(ngModel)]="active" /><label class="form-check-label" for="user-active">Account active</label></div><p class="form-text">Deactivating this account ends its sessions and prevents sign-in.</p>
        <div class="form-check"><input id="user-activated" class="form-check-input" type="checkbox" name="activated" [(ngModel)]="activated" /><label class="form-check-label" for="user-activated">Account activated</label></div>
        <button class="btn btn-primary mt-4" [disabled]="identity.invalid || !identityDirty" type="submit">{{ saving() ? 'Saving…' : 'Save account' }}</button>
      </fieldset></form></div></section></div>
      <div class="col-xl-5"><section class="card"><div class="card-header"><h2 class="card-title">Reset password</h2></div><div class="card-body"><p class="text-secondary">The account’s existing sessions will end after a password reset.</p><form #credentials="ngForm" (ngSubmit)="resetPassword()"><fieldset [disabled]="saving()" (input)="markPassword()">
        <div class="mb-3"><label for="reset-password" class="form-label">New password</label><input id="reset-password" type="password" class="form-control" name="password" [(ngModel)]="password" required autocomplete="new-password" [minlength]="metadata()?.password?.minLength ?? 4" [maxlength]="metadata()?.password?.maxLength ?? 30" /><p class="form-text">Use {{ metadata()?.password?.minLength ?? 4 }}–{{ metadata()?.password?.maxLength ?? 30 }} characters.@if (metadata()?.password?.requireComplexity) { Include uppercase and lowercase letters, a number and a special character. }</p></div>
        <div class="mb-3"><label for="reset-confirmation" class="form-label">Confirm password</label><input id="reset-confirmation" type="password" class="form-control" name="passwordConfirmation" [(ngModel)]="confirmation" required autocomplete="new-password" /></div><button class="btn btn-outline-danger" [disabled]="credentials.invalid || !passwordDirty" type="submit">Reset password</button>
      </fieldset></form></div></section><section class="card mt-4"><div class="card-header"><h2 class="card-title">Account activity</h2></div><div class="card-body"><dl class="row mb-0"><dt class="col-sm-5">Created</dt><dd class="col-sm-7">{{ date(account.createdAt) }}</dd><dt class="col-sm-5">Last sign-in</dt><dd class="col-sm-7">{{ date(account.lastLoginAt) }}</dd></dl></div></section></div></div> }
    }`,
})
export class UserEditor {
  private readonly api = inject(Api); readonly session = inject(Session); private readonly changes = inject(UnsavedChanges).scope(inject(DestroyRef));
  readonly user = signal<ManagedUser | null>(null); readonly metadata = signal<Metadata | null>(null); readonly loading = signal(true); readonly saving = signal(false); readonly error = signal(''); readonly success = signal('');
  readonly date = dateTime; username = ''; firstName = ''; lastName = ''; email = ''; gender = 1; role = 0; active = true; activated = true; password = ''; confirmation = '';
  identityDirty = false; passwordDirty = false; private loadId = 0;
  constructor() {
    inject(DestroyRef).onDestroy(() => { ++this.loadId; this.changes.setDirty(false); });
    inject(ActivatedRoute).paramMap.pipe(takeUntilDestroyed()).subscribe(params => { void this.load(params.get('id')); });
  }
  private async load(id: string | null): Promise<void> {
    const request = ++this.loadId; this.changes.reset(); this.saving.set(false); this.loading.set(true); this.error.set(''); this.success.set(''); this.user.set(null);
    if (!id || !/^[1-9]\d*$/.test(id)) { this.error.set('Choose a valid user account.'); this.loading.set(false); return; }
    try {
      const [user, meta] = await Promise.all([this.api.get<ManagedUser>(`/users/${id}`), this.api.get<Metadata>('/meta')]);
      if (request !== this.loadId) return;
      this.user.set(user); this.metadata.set(meta); this.populate(user); this.password = ''; this.confirmation = ''; this.identityDirty = false; this.passwordDirty = false; this.dirty();
    } catch (error) { if (request === this.loadId) this.error.set(errorMessage(error)); } finally { if (request === this.loadId) this.loading.set(false); }
  }
  private populate(user: ManagedUser): void { this.username = user.username; this.firstName = user.firstName; this.lastName = user.lastName; this.email = user.email; this.gender = user.gender; this.role = user.role; this.active = user.active; this.activated = user.activated; }
  private dirty(): void { this.changes.setDirty(this.identityDirty || this.passwordDirty); }
  markIdentity(): void { this.identityDirty = true; this.dirty(); }
  markPassword(): void { this.passwordDirty = true; this.dirty(); }
  async saveIdentity(): Promise<void> {
    const user = this.user(); if (!user || this.saving()) return; const current = this.changes.checkpoint(); this.saving.set(true); this.error.set(''); this.success.set('');
    try {
      const identity = { username: this.username, firstName: this.firstName, lastName: this.lastName, email: this.email, gender: this.gender, active: this.active, activated: this.activated };
      const result = await this.api.patch<ManagedUser>(`/users/${user.id}`, this.session.isSuperAdmin() ? { ...identity, role: this.role } : identity);
      if (!current()) return;
      this.user.set(result); this.populate(result); this.identityDirty = false; this.dirty(); this.success.set('The account was updated.');
    } catch (error) { if (current()) this.error.set(errorMessage(error)); } finally { if (current()) this.saving.set(false); }
  }
  async resetPassword(): Promise<void> {
    const user = this.user(); if (!user || this.saving()) return; const current = this.changes.checkpoint(); this.error.set(''); this.success.set('');
    if (this.password !== this.confirmation) { this.error.set('The passwords do not match.'); return; }
    this.saving.set(true);
    try { const result = await this.api.patch<ManagedUser>(`/users/${user.id}`, { password: this.password, passwordConfirmation: this.confirmation }); if (!current()) return; this.user.set(result); this.password = ''; this.confirmation = ''; this.passwordDirty = false; this.dirty(); this.success.set('The password was reset.'); }
    catch (error) { if (current()) this.error.set(errorMessage(error)); } finally { if (current()) this.saving.set(false); }
  }
}
