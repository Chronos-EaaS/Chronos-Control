import { Component, DestroyRef, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../core/api';
import { Metadata, User } from '../core/models';
import { Session } from '../core/session';
import { UnsavedChanges } from '../core/unsaved';
import { Feedback } from '../shared/feedback';

@Component({
  imports: [FormsModule, RouterLink, Feedback],
  templateUrl: './profile.html',
  styleUrl: './profile.scss',
})
export class Profile {
  private readonly api = inject(Api);
  private readonly session = inject(Session);
  private readonly changes = inject(UnsavedChanges).scope(inject(DestroyRef));
  readonly user = signal<User | null>(null);
  readonly metadata = signal<Metadata | null>(null);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal('');
  readonly success = signal('');
  firstName = '';
  lastName = '';
  email = '';
  gender = 0;
  currentPassword = '';
  password = '';
  confirmation = '';
  private identityDirty = false;
  private passwordDirty = false;
  constructor() {
    inject(DestroyRef).onDestroy(() => this.changes.setDirty(false));
    void this.load();
  }
  private async load(): Promise<void> {
    try {
      const [user, metadata] = await Promise.all([
        this.api.get<User>('/profile'),
        this.api.get<Metadata>('/meta'),
      ]);
      this.user.set(user);
      this.metadata.set(metadata);
      this.firstName = user.firstName;
      this.lastName = user.lastName;
      this.email = user.email;
      this.gender = user.gender;
    } catch (error) {
      this.error.set(errorMessage(error));
    } finally {
      this.loading.set(false);
    }
  }
  markIdentity(): void {
    this.identityDirty = true;
    this.changes.setDirty(true);
  }
  markPassword(): void {
    this.passwordDirty = true;
    this.changes.setDirty(true);
  }
  async saveIdentity(): Promise<void> {
    if (this.saving()) return;
    const current = this.changes.checkpoint();
    this.error.set('');
    this.success.set('');
    this.saving.set(true);
    try {
      const user = await this.api.patch<User>('/profile', {
        firstName: this.firstName,
        lastName: this.lastName,
        email: this.email,
        gender: this.gender,
      });
      if (!current()) return;
      this.user.set(user);
      await this.session.refresh();
      if (!current()) return;
      this.identityDirty = false;
      this.changes.setDirty(this.passwordDirty);
      this.success.set('Your profile has been updated.');
    } catch (error) {
      if (current()) this.error.set(errorMessage(error));
    } finally {
      if (current()) this.saving.set(false);
    }
  }
  async savePassword(): Promise<void> {
    if (this.saving()) return;
    const current = this.changes.checkpoint();
    this.error.set('');
    this.success.set('');
    if (this.password !== this.confirmation) {
      this.error.set('The new passwords do not match.');
      return;
    }
    this.saving.set(true);
    try {
      await this.api.patch<User>('/profile', {
        currentPassword: this.currentPassword,
        password: this.password,
        passwordConfirmation: this.confirmation,
      });
      if (!current()) return;
      this.currentPassword = '';
      this.password = '';
      this.confirmation = '';
      this.passwordDirty = false;
      this.changes.setDirty(this.identityDirty);
      this.success.set('Your password has been updated.');
    } catch (error) {
      if (current()) this.error.set(errorMessage(error));
    } finally {
      if (current()) this.saving.set(false);
    }
  }
}
