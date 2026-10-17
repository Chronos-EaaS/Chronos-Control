import { Component, DestroyRef, ElementRef, ViewChild, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api';
import { ManagedUser } from '../../core/admin-models';
import { ChronosSystem, Metadata, PageData } from '../../core/models';
import { Session } from '../../core/session';
import { Feedback } from '../../shared/feedback';
import { AdminSettings } from './settings';
import { AdminMaintenance } from './maintenance';
import { AdminDrafts } from './admin-drafts';

@Component({
  providers: [AdminDrafts],
  imports: [FormsModule, RouterLink, Feedback, AdminSettings, AdminMaintenance],
  template: `<div class="page-heading"><div><span class="eyebrow">CONTROL CENTER</span><h1>Administration</h1><p class="text-secondary">Manage users, shared settings and execution environments.</p></div><button class="btn btn-primary" (click)="openCreate()">Create user</button></div>
    <app-feedback [error]="error()" [success]="success()" />
    <section class="card mb-4"><div class="card-header"><h2 class="card-title">Users</h2></div><div class="card-body">
      <form class="table-toolbar" (ngSubmit)="page = 1; loadUsers()"><div class="table-search"><label for="user-search" class="visually-hidden">Search users</label><input id="user-search" class="form-control" name="search" [(ngModel)]="search" placeholder="Search users…" type="search" /></div><div class="d-flex gap-2 align-items-center"><label for="user-state">Accounts</label><select id="user-state" name="active" class="form-select" [(ngModel)]="active" (ngModelChange)="page = 1; loadUsers()"><option value="true">Active</option><option value="false">Inactive</option><option value="all">All accounts</option></select><button class="btn btn-outline-primary">Search</button></div></form>
      <app-feedback [busy]="loadingUsers()" />
      <div class="table-responsive"><table class="table align-middle"><caption class="visually-hidden">User accounts</caption><thead><tr><th scope="col"><button class="table-sort" (click)="sortBy('username')">Username {{ sortMark('username') }}</button></th><th scope="col"><button class="table-sort" (click)="sortBy('name')">Name {{ sortMark('name') }}</button></th><th scope="col">Role</th><th scope="col">State</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
        @for (user of users(); track user.id) { <tr><th scope="row">{{ user.username }}</th><td>{{ user.firstName }} {{ user.lastName }}<small class="d-block text-secondary">{{ user.email }}</small></td><td><span class="badge" [class.text-bg-primary]="user.role > 0" [class.text-bg-secondary]="user.role === 0">{{ user.roleName }}</span></td><td><span [class.text-danger]="!user.active">{{ user.active ? 'Active' : 'Inactive' }}</span>@if (!user.activated) { <small class="d-block text-warning-emphasis">Not activated</small> }</td><td><div class="d-flex justify-content-end gap-2 flex-wrap">
          @if (user.id === session.user()?.id) { <a class="btn btn-sm btn-outline-primary" routerLink="/profile">Your profile</a> }
          @else if (user.role !== 2 || session.isSuperAdmin()) { <a class="btn btn-sm btn-outline-primary" [routerLink]="['/admin/users', user.id]">Edit<span class="visually-hidden"> {{ user.username }}</span></a> }
          @if (canSwitch(user)) { <button class="btn btn-sm btn-outline-secondary" (click)="openSwitch(user)">Switch<span class="visually-hidden"> to {{ user.username }}</span></button> }
        </div></td></tr> }
        @empty { @if (!loadingUsers()) { <tr><td colspan="5" class="empty-state">No users match your filters.</td></tr> } }
      </tbody></table></div>
      <div class="table-pagination"><span>{{ total() }} accounts · Page {{ page }} of {{ pages() }}</span><div class="d-flex gap-2"><button class="btn btn-outline-secondary" [disabled]="page <= 1 || loadingUsers()" (click)="page = page - 1; loadUsers()">Previous</button><button class="btn btn-outline-secondary" [disabled]="page >= pages() || loadingUsers()" (click)="page = page + 1; loadUsers()">Next</button></div></div>
    </div></section>
    <app-admin-settings />
    @if (session.isSuperAdmin()) { <div class="mt-4"><app-admin-maintenance /></div> }
    <section class="card mt-4"><div class="card-header"><h2 class="card-title">Systems</h2></div><div class="card-body"><p class="text-secondary">System definitions and integration identifiers.</p><div class="table-responsive"><table class="table"><caption class="visually-hidden">Administration systems</caption><thead><tr><th scope="col">Name</th><th scope="col">Identifier</th><th scope="col">State</th></tr></thead><tbody>@for (system of systems(); track system.id) { <tr><th scope="row"><a [routerLink]="['/systems', system.id]">{{ system.name }}</a></th><td class="text-break"><code>{{ system.identifier }}</code></td><td>{{ system.archived ? 'Archived' : 'Active' }}</td></tr> } @empty { <tr><td colspan="3">No systems available.</td></tr> }</tbody></table></div></div></section>
    <dialog #createDialog class="form-dialog" aria-labelledby="create-user-title" (cancel)="cancelCreate($event)"><form #createForm="ngForm" (ngSubmit)="createUser()"><div class="dialog-header"><h2 id="create-user-title">Create user</h2><button type="button" class="btn-close" aria-label="Close create user" [disabled]="saving()" (click)="closeCreate()"></button></div><div class="dialog-body"><app-feedback [error]="dialogError()" /><fieldset [disabled]="saving()" (input)="markCreate()" (change)="markCreate()">
      <div class="mb-3"><label for="create-username" class="form-label">Username</label><input id="create-username" class="form-control" name="username" [(ngModel)]="draft.username" required minlength="2" maxlength="50" autocomplete="off" /></div>
      <div class="row g-3"><div class="col-sm-6"><label for="create-first-name" class="form-label">First name</label><input id="create-first-name" class="form-control" name="firstName" [(ngModel)]="draft.firstName" required maxlength="50" /></div><div class="col-sm-6"><label for="create-last-name" class="form-label">Last name</label><input id="create-last-name" class="form-control" name="lastName" [(ngModel)]="draft.lastName" required maxlength="50" /></div></div>
      <div class="my-3"><label for="create-email" class="form-label">Email</label><input id="create-email" type="email" class="form-control" name="email" [(ngModel)]="draft.email" required email maxlength="100" /></div>
      <div class="mb-3"><label for="create-gender" class="form-label">Gender</label><select id="create-gender" class="form-select" name="gender" [(ngModel)]="draft.gender" required><option [ngValue]="1">Male</option><option [ngValue]="2">Female</option></select></div>
      @if (session.isSuperAdmin()) { <div class="mb-3"><label for="create-role" class="form-label">Role</label><select id="create-role" class="form-select" name="role" [(ngModel)]="draft.role"><option [ngValue]="0">User</option><option [ngValue]="1">Administrator</option><option [ngValue]="2">Superadministrator</option></select></div> }
      <div class="mb-3"><label for="create-password" class="form-label">Password</label><input id="create-password" type="password" class="form-control" name="password" [(ngModel)]="draft.password" required autocomplete="new-password" [minlength]="metadata()?.password?.minLength ?? 4" [maxlength]="metadata()?.password?.maxLength ?? 30" /><p class="form-text">Use {{ metadata()?.password?.minLength ?? 4 }}–{{ metadata()?.password?.maxLength ?? 30 }} characters.@if (metadata()?.password?.requireComplexity) { Include uppercase and lowercase letters, a number and a special character. }</p></div>
      <div class="mb-3"><label for="create-confirmation" class="form-label">Confirm password</label><input id="create-confirmation" type="password" class="form-control" name="passwordConfirmation" [(ngModel)]="draft.passwordConfirmation" required autocomplete="new-password" /></div>
    </fieldset></div><div class="dialog-footer"><button type="button" class="btn btn-outline-secondary" [disabled]="saving()" (click)="closeCreate()">Cancel</button><button class="btn btn-primary" [disabled]="saving() || createForm.invalid">{{ saving() ? 'Creating…' : 'Create user' }}</button></div></form></dialog>
    <dialog #switchDialog class="form-dialog" aria-labelledby="switch-title" (cancel)="cancelSwitch($event)"><div class="dialog-header"><h2 id="switch-title">Switch account</h2><button class="btn-close" aria-label="Close switch account" [disabled]="saving()" (click)="closeSwitch()"></button></div><div class="dialog-body"><app-feedback [error]="dialogError()" /><p>Continue as <strong>{{ switchTarget()?.username }}</strong>?</p><p>Your actions will use this account’s permissions. A temporary switch lets you return to your administrator account by signing out.</p><div class="form-check"><input id="permanent-switch" class="form-check-input" type="checkbox" [(ngModel)]="permanentSwitch" [disabled]="saving()" /><label for="permanent-switch" class="form-check-label">End my administrator session permanently</label></div>@if (permanentSwitch) { <p class="text-danger mt-3 mb-0">You will need to sign in again to regain administrator access.</p> }</div><div class="dialog-footer"><button class="btn btn-outline-secondary" [disabled]="saving()" (click)="closeSwitch()">Cancel</button><button class="btn btn-primary" [disabled]="saving()" (click)="switchUser()">{{ saving() ? 'Switching…' : 'Switch account' }}</button></div></dialog>`,
})
export class AdminOverview {
  private readonly api = inject(Api); readonly session = inject(Session); private readonly router = inject(Router); private readonly drafts = inject(AdminDrafts);
  @ViewChild('createDialog') private createDialog?: ElementRef<HTMLDialogElement>; @ViewChild('switchDialog') private switchDialog?: ElementRef<HTMLDialogElement>;
  readonly users = signal<ManagedUser[]>([]); readonly systems = signal<ChronosSystem[]>([]); readonly metadata = signal<Metadata | null>(null);
  readonly loadingUsers = signal(true); readonly saving = signal(false); readonly error = signal(''); readonly success = signal(''); readonly dialogError = signal(''); readonly total = signal(0); readonly switchTarget = signal<ManagedUser | null>(null);
  search = ''; active = 'true'; page = 1; pageSize = 20; sort = 'id'; permanentSwitch = false; private loadId = 0; private createDirty = false;
  draft = this.emptyDraft();
  constructor() { inject(DestroyRef).onDestroy(() => ++this.loadId); void this.loadUsers(); void this.loadExtras(); }
  private emptyDraft() { return { username: '', firstName: '', lastName: '', email: '', gender: 1, role: 0, password: '', passwordConfirmation: '' }; }
  pages(): number { return Math.max(1, Math.ceil(this.total() / this.pageSize)); }
  sortMark(key: string): string { return this.sort === key ? '↑' : this.sort === `-${key}` ? '↓' : ''; }
  sortBy(key: string): void { this.sort = this.sort === key ? `-${key}` : key; this.page = 1; void this.loadUsers(); }
  async loadUsers(): Promise<void> {
    const request = ++this.loadId; this.loadingUsers.set(true); this.error.set('');
    try { const result = await this.api.get<PageData<ManagedUser>>('/users', { search: this.search, active: this.active, page: this.page, pageSize: this.pageSize, sort: this.sort }); if (request === this.loadId) { this.users.set(result.items); this.total.set(result.total); this.pageSize = result.pageSize; } }
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
  canSwitch(user: ManagedUser): boolean { return !this.session.impersonation().active && user.id !== this.session.user()?.id && user.role !== 2 && user.active && user.activated; }
  openSwitch(user: ManagedUser): void { this.switchTarget.set(user); this.permanentSwitch = false; this.dialogError.set(''); this.switchDialog?.nativeElement.showModal(); }
  cancelSwitch(event: Event): void { event.preventDefault(); this.closeSwitch(); }
  closeSwitch(): void { if (!this.saving()) this.switchDialog?.nativeElement.close(); }
  async switchUser(): Promise<void> {
    const user = this.switchTarget(); if (!user || this.saving()) return; this.saving.set(true); this.dialogError.set('');
    try { await this.api.post('/session/impersonation', { userId: user.id, permanent: this.permanentSwitch }); await this.session.refresh(); this.switchDialog?.nativeElement.close(); await this.router.navigate(['/']); }
    catch (error) { this.dialogError.set(errorMessage(error)); } finally { this.saving.set(false); }
  }
}
