import { Component, DestroyRef, ElementRef, ViewChild, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api';
import { ManagedUser } from '../../core/admin-models';
import { ChronosSystem, Metadata, PageData } from '../../core/models';
import { Session } from '../../core/session';
import { UnsavedChanges } from '../../core/unsaved';
import { Feedback } from '../../shared/feedback';
import { Icon } from '../../shared/icon';
import { AdminSettings } from './settings';
import { AdminMaintenance } from './maintenance';
import { AdminDrafts } from './admin-drafts';

@Component({
  providers: [AdminDrafts],
  imports: [FormsModule, RouterLink, Feedback, Icon, AdminSettings, AdminMaintenance],
  templateUrl: './admin-overview.html',
  styleUrl: './admin-overview.scss',
})
export class AdminOverview {
  private readonly api = inject(Api); readonly session = inject(Session); private readonly router = inject(Router); private readonly drafts = inject(AdminDrafts); private readonly changes = inject(UnsavedChanges);
  @ViewChild('createDialog') private createDialog?: ElementRef<HTMLDialogElement>; @ViewChild('switchDialog') private switchDialog?: ElementRef<HTMLDialogElement>;
  readonly users = signal<ManagedUser[]>([]); readonly systems = signal<ChronosSystem[]>([]); readonly metadata = signal<Metadata | null>(null);
  readonly loadingUsers = signal(true); readonly saving = signal(false); readonly error = signal(''); readonly success = signal(''); readonly dialogError = signal(''); readonly total = signal(0); readonly switchTarget = signal<ManagedUser | null>(null);
  search = ''; enabled = 'true'; page = 1; pageSize = 20; sort = 'id'; permanentSwitch = false; private loadId = 0; private createDirty = false;
  draft = this.emptyDraft();
  constructor() { inject(DestroyRef).onDestroy(() => ++this.loadId); void this.loadUsers(); void this.loadExtras(); }
  private emptyDraft() { return { username: '', firstName: '', lastName: '', email: '', gender: 1, role: 0, password: '', passwordConfirmation: '' }; }
  pages(): number { return Math.max(1, Math.ceil(this.total() / this.pageSize)); }
  sortMark(key: string): string { return this.sort === key ? '↑' : this.sort === `-${key}` ? '↓' : ''; }
  sortBy(key: string): void { this.sort = this.sort === key ? `-${key}` : key; this.page = 1; void this.loadUsers(); }
  async loadUsers(): Promise<void> {
    const request = ++this.loadId; this.loadingUsers.set(true); this.error.set('');
    try { const result = await this.api.get<PageData<ManagedUser>>('/users', { search: this.search, enabled: this.enabled, page: this.page, pageSize: this.pageSize, sort: this.sort }); if (request === this.loadId) { this.users.set(result.items); this.total.set(result.total); this.page = result.page; this.pageSize = result.pageSize; } }
    catch (error) { if (request === this.loadId) this.error.set(errorMessage(error)); } finally { if (request === this.loadId) this.loadingUsers.set(false); }
  }
  private async loadExtras(): Promise<void> {
    try {
      this.metadata.set(await this.api.get<Metadata>('/meta'));
      const all: ChronosSystem[] = []; let page = 1; let total = 0;
      do { const result = await this.api.get<PageData<ChronosSystem>>('/systems', { all: true, archived: 'all', page, pageSize: 100 }); all.push(...result.items); total = result.total; page++; } while (all.length < total);
      this.systems.set(all);
    } catch (error) { this.error.set(errorMessage(error)); }
  }
  markCreate(): void { this.createDirty = true; this.drafts.set('create-user', true); }
  openCreate(): void { this.draft = this.emptyDraft(); this.dialogError.set(''); this.createDirty = false; this.createDialog?.nativeElement.showModal(); }
  cancelCreate(event: Event): void { event.preventDefault(); this.closeCreate(); }
  closeCreate(): void { if (this.saving() || (this.createDirty && !window.confirm('Discard this new user?'))) return; this.createDialog?.nativeElement.close(); this.draft = this.emptyDraft(); this.createDirty = false; this.drafts.set('create-user', false); }
  async createUser(): Promise<void> {
    if (this.saving()) return; this.dialogError.set('');
    if (this.draft.password !== this.draft.passwordConfirmation) { this.dialogError.set('The passwords do not match.'); return; }
    this.saving.set(true);
    try {
      const { role, ...draft } = this.draft; const body = this.session.isSuperAdmin() ? { ...draft, role } : draft;
      const created = await this.api.post<ManagedUser>('/users', body); this.createDialog?.nativeElement.close(); this.draft = this.emptyDraft(); this.createDirty = false; this.drafts.set('create-user', false); this.success.set(`User “${created.username}” was created.`); this.page = 1; await this.loadUsers();
    } catch (error) { this.dialogError.set(errorMessage(error)); } finally { this.saving.set(false); }
  }
  canSwitch(user: ManagedUser): boolean { return !this.session.impersonation().active && user.id !== this.session.user()?.id && user.role !== 2 && user.enabled && user.activated; }
  openSwitch(user: ManagedUser): void { this.switchTarget.set(user); this.permanentSwitch = false; this.dialogError.set(''); this.switchDialog?.nativeElement.showModal(); }
  cancelSwitch(event: Event): void { event.preventDefault(); this.closeSwitch(); }
  closeSwitch(): void { if (!this.saving()) this.switchDialog?.nativeElement.close(); }
  async switchUser(): Promise<void> {
    const user = this.switchTarget(); if (!user || this.saving() || !this.changes.mayDiscard()) return; this.saving.set(true); this.dialogError.set('');
    try { await this.session.withIdentityChange(() => this.api.post('/session/impersonation', { userId: user.id, permanent: this.permanentSwitch })); this.changes.clear(); this.switchDialog?.nativeElement.close(); await this.router.navigate(['/']); }
    catch (error) { this.dialogError.set(errorMessage(error)); } finally { this.saving.set(false); }
  }
}
