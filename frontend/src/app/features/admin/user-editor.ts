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
  templateUrl: './user-editor.html',
})
export class UserEditor {
  private readonly api = inject(Api); readonly session = inject(Session); private readonly changes = inject(UnsavedChanges).scope(inject(DestroyRef));
  readonly user = signal<ManagedUser | null>(null); readonly metadata = signal<Metadata | null>(null); readonly loading = signal(true); readonly saving = signal(false); readonly error = signal(''); readonly success = signal('');
  readonly date = dateTime; username = ''; firstName = ''; lastName = ''; email = ''; gender = 1; role = 0; enabled = true; activated = true; password = ''; confirmation = '';
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
  private populate(user: ManagedUser): void { this.username = user.username; this.firstName = user.firstName; this.lastName = user.lastName; this.email = user.email; this.gender = user.gender; this.role = user.role; this.enabled = user.enabled; this.activated = user.activated; }
  private dirty(): void { this.changes.setDirty(this.identityDirty || this.passwordDirty); }
  markIdentity(): void { this.identityDirty = true; this.dirty(); }
  markPassword(): void { this.passwordDirty = true; this.dirty(); }
  async saveIdentity(): Promise<void> {
    const user = this.user(); if (!user || this.saving()) return; const current = this.changes.checkpoint(); this.saving.set(true); this.error.set(''); this.success.set('');
    try {
      const identity = { username: this.username, firstName: this.firstName, lastName: this.lastName, email: this.email, gender: this.gender, enabled: this.enabled, activated: this.activated };
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
