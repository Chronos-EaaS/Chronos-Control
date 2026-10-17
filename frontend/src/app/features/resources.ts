import { AfterViewInit, ChangeDetectorRef, Component, DestroyRef, ElementRef, ViewChild, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Api, errorMessage } from '../core/api';
import { allPages } from '../core/collections';
import { ChronosSystem, Metadata, PageData, Project, User } from '../core/models';
import { Session } from '../core/session';
import { UnsavedChanges } from '../core/unsaved';
import { Feedback } from '../shared/feedback';
import { Icon } from '../shared/icon';

@Component({
  imports: [FormsModule, RouterLink, Feedback, Icon],
  styles: [
    '.system-avatar{width:42px;height:42px;object-fit:contain;flex-shrink:0}.resource-name{display:flex;align-items:center;gap:.75rem}.resource-owner{overflow-wrap:anywhere}@media(max-width:767.98px){.resource-actions{display:none}}',
    '.resource-search-heading{display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-bottom:.5rem}.resource-loading{display:inline-flex;align-items:center;gap:.4rem;visibility:hidden;color:#607791;font-size:.75rem;white-space:nowrap}.resource-loading.is-loading{visibility:visible}',
  ],
  template: `<div class="page-heading"><div><span class="eyebrow">{{ kind() === 'projects' ? 'EVALUATION CAMPAIGNS' : 'YOUR EVALUATION SYSTEMS' }}</span><h1>{{ title() }}</h1><p class="text-secondary">{{ kind() === 'projects' ? 'Organize experiments and track your evaluation campaigns.' : 'Manage system definitions, parameters and result configurations.' }}</p></div>@if (kind() === 'projects' || session.isAdmin()) { <button class="btn btn-primary" type="button" (click)="openCreate()"><app-icon name="plus" /> {{ kind() === 'projects' ? 'Create project' : 'Add system' }}</button> }</div>
    <app-feedback [error]="error()" />
    <section class="card"><div class="table-toolbar"><div class="table-filters">
      <div><label for="resource-state" class="form-label">State</label><select id="resource-state" class="form-select" [(ngModel)]="archived" (ngModelChange)="filterChanged()"><option value="false">Current</option><option value="true">Archived</option><option value="all">All states</option></select></div>
      @if (session.isAdmin()) { <div><label for="resource-scope" class="form-label">Visibility</label><select id="resource-scope" class="form-select" [(ngModel)]="all" (ngModelChange)="filterChanged()"><option [ngValue]="false">My {{ kind() }}</option><option [ngValue]="true">All {{ kind() }}</option></select></div> }
    </div><div class="table-search"><div class="resource-search-heading"><label for="resource-search" class="form-label mb-0">Search {{ kind() }}</label><span class="resource-loading" [class.is-loading]="busy()" role="status" [attr.aria-hidden]="!busy()"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Loading…</span></span></div><input id="resource-search" class="form-control" type="search" [ngModel]="search" (ngModelChange)="searchChanged($event)" [placeholder]="kind() === 'projects' ? 'Search name, description, system or owner' : 'Search name, description or owner'" /></div></div>
      <div class="table-responsive" tabindex="0" [attr.aria-label]="title() + ' table'"><table class="table table-hover" [attr.aria-busy]="busy()"><thead><tr><th scope="col"><button class="table-sort" type="button" (click)="sortBy('id')">ID {{ sort === 'id' ? '↑' : sort === '-id' ? '↓' : '' }}</button></th><th scope="col"><button class="table-sort" type="button" (click)="sortBy('name')">Name {{ sort === 'name' ? '↑' : sort === '-name' ? '↓' : '' }}</button></th><th scope="col" [attr.aria-sort]="sortState('description')"><button class="table-sort" type="button" (click)="sortBy('description')">Description {{ sortMark('description') }}</button></th>@if (kind() === 'projects') { <th scope="col" [attr.aria-sort]="sortState('system')"><button class="table-sort" type="button" (click)="sortBy('system')">System {{ sortMark('system') }}</button></th> }<th scope="col" [attr.aria-sort]="sortState('owner')"><button class="table-sort" type="button" (click)="sortBy('owner')">Owner {{ sortMark('owner') }}</button></th><th scope="col" class="resource-actions"><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
        @for (item of items(); track item.id) { <tr><td class="text-secondary">#{{ item.id }}</td><td><div class="resource-name">@if (kind() === 'systems' && !failedLogos().has(item.id)) { <img class="system-avatar rounded" [src]="logo(item.id)" alt="" width="42" height="42" loading="lazy" (error)="hideLogo(item.id)" /> }<div><a [routerLink]="['/', kind(), item.id]" class="fw-semibold text-decoration-none">{{ item.name }}</a>@if (item.archived) { <span class="badge text-bg-secondary ms-2">Archived</span> }</div></div></td><td class="description text-secondary">{{ item.description }}</td>@if (kind() === 'projects') { <td>{{ projectSystem(item) }}</td> }<td class="resource-owner">{{ ownerName(item) }}</td><td class="resource-actions"><a [routerLink]="['/', kind(), item.id]" class="btn btn-sm btn-outline-primary" [attr.aria-label]="'Open ' + item.name">Open <app-icon name="arrow" /></a></td></tr> }
        @if (!busy() && !items().length) { <tr><td [attr.colspan]="kind() === 'projects' ? 6 : 5"><div class="empty-state"><app-icon [name]="kind()" /><h3>No {{ kind() }} found</h3><p>{{ search ? 'Try another search or change the filters.' : 'Create your first ' + (kind() === 'projects' ? 'project to begin an evaluation campaign.' : 'system to configure an evaluation.') }}</p></div></td></tr> }
      </tbody></table></div><p class="form-text px-3 d-md-none">Scroll horizontally to view all columns. Select a name to open its details.</p>
      <div class="table-pagination"><div><label for="page-size" class="me-2">Rows per page</label><select id="page-size" class="form-select form-select-sm d-inline-block w-auto" [(ngModel)]="pageSize" (ngModelChange)="filterChanged()">@for (size of sizes(); track size) { <option [ngValue]="size">{{ size }}</option> }</select></div><span>{{ total() ? (page - 1) * pageSize + 1 : 0 }}–{{ rangeEnd() }} of {{ total() }}</span><div class="d-flex gap-2"><button class="btn btn-sm btn-light" [disabled]="page <= 1 || busy()" (click)="changePage(-1)" aria-label="Previous page">‹</button><button class="btn btn-sm btn-light" [disabled]="page * pageSize >= total() || busy()" (click)="changePage(1)" aria-label="Next page">›</button></div></div>
    </section>
    <dialog #createDialog class="form-dialog" aria-labelledby="create-title" (cancel)="cancelDialog($event)"><form #form="ngForm" (ngSubmit)="create()"><div class="dialog-header"><h2 id="create-title">{{ kind() === 'projects' ? 'Create project' : 'Add system' }}</h2><button class="btn btn-light" type="button" aria-label="Close" (click)="cancelDialog()">×</button></div><div class="dialog-body"><app-feedback [error]="saveError()" /><fieldset [disabled]="saving() || loadingOptions() || !optionsReady()">
      <label class="form-label" for="create-name">Name</label><input id="create-name" class="form-control mb-3" name="name" [(ngModel)]="name" (ngModelChange)="changes.setDirty(true)" required maxlength="250" />
      <label class="form-label" for="create-description">Description</label><textarea id="create-description" class="form-control mb-3" name="description" rows="3" [(ngModel)]="description" (ngModelChange)="changes.setDirty(true)"></textarea>
      @if (kind() === 'projects') { <label class="form-label" for="create-system">System</label><select id="create-system" class="form-select mb-3" name="systemId" [(ngModel)]="systemId" (ngModelChange)="changes.setDirty(true)" required><option [ngValue]="null" disabled>Select a system</option>@for (system of systems(); track system.id) { <option [ngValue]="system.id">{{ system.name }}</option> }</select> }
      @if (session.isAdmin()) { <label class="form-label" for="create-owner">Owner</label><select id="create-owner" class="form-select mb-3" name="ownerId" [(ngModel)]="ownerId" (ngModelChange)="changes.setDirty(true)" required>@for (owner of owners(); track owner.id) { <option [ngValue]="owner.id">{{ owner.firstName }} {{ owner.lastName }} ({{ owner.username }})</option> }</select> }
      @if (kind() === 'systems') { <div class="form-check mb-3"><input id="create-cem" class="form-check-input" type="checkbox" name="cem" [(ngModel)]="cem" (ngModelChange)="changes.setDirty(true)" /><label for="create-cem" class="form-check-label">Supports automated CEM setup</label></div>@if (remoteRepositories()) { <div class="form-check mb-3"><input id="create-remote" class="form-check-input" type="checkbox" name="remote" [(ngModel)]="remote" (ngModelChange)="repositoryChanged()" /><label for="create-remote" class="form-check-label">Clone a remote repository</label></div>@if (remote) { <fieldset class="border rounded p-3"><legend class="float-none w-auto px-1 fs-6">Remote repository</legend><label for="create-repository-type" class="form-label">Repository type</label><select id="create-repository-type" class="form-select mb-3" name="repositoryType" [(ngModel)]="repositoryType" (ngModelChange)="changes.setDirty(true)"><option value="git">Git</option><option value="hg">Mercurial</option></select><label class="form-label" for="create-repository-url">Repository URL</label><input id="create-repository-url" class="form-control mb-3" name="repositoryUrl" [(ngModel)]="repositoryUrl" (ngModelChange)="changes.setDirty(true)" required maxlength="1000" placeholder="https://example.org/owner/system.git" autocomplete="off" /><label class="form-label" for="create-repository-branch">Branch</label><input id="create-repository-branch" class="form-control mb-3" name="repositoryBranch" [(ngModel)]="repositoryBranch" (ngModelChange)="changes.setDirty(true)" required maxlength="200" autocomplete="off" /><label class="form-label" for="create-repository-username">Repository username (optional)</label><input id="create-repository-username" class="form-control mb-3" name="repositoryUsername" [(ngModel)]="repositoryUsername" (ngModelChange)="changes.setDirty(true)" maxlength="256" autocomplete="off" /><label class="form-label" for="create-repository-password">Repository password or token (optional)</label><input id="create-repository-password" type="password" class="form-control" name="repositoryPassword" [(ngModel)]="repositoryPassword" (ngModelChange)="changes.setDirty(true)" maxlength="4096" autocomplete="new-password" /><p class="form-text mb-0">Enter credentials in the separate fields. The URL must not contain an embedded HTTP password.</p></fieldset> } } }
      <app-feedback [busy]="loadingOptions()" />
    </fieldset></div><div class="dialog-footer"><button class="btn btn-light" type="button" [disabled]="saving()" (click)="cancelDialog()">Cancel</button><button class="btn btn-primary" type="submit" [disabled]="form.invalid || saving() || loadingOptions() || !optionsReady()">{{ saving() ? 'Creating…' : 'Create' }}</button></div></form></dialog>`,
})
export class Resources implements AfterViewInit {
  private readonly api = inject(Api); private readonly route = inject(ActivatedRoute); private readonly router = inject(Router); private readonly detector = inject(ChangeDetectorRef);
  readonly session = inject(Session); readonly changes = inject(UnsavedChanges).scope(inject(DestroyRef));
  @ViewChild('createDialog') private dialog!: ElementRef<HTMLDialogElement>;
  readonly kind = signal<'projects' | 'systems'>('projects');
  readonly items = signal<(Project | ChronosSystem)[]>([]); readonly total = signal(0); readonly busy = signal(false); readonly error = signal('');
  readonly saving = signal(false); readonly loadingOptions = signal(false); readonly optionsReady = signal(false); readonly saveError = signal('');
  readonly failedLogos = signal(new Set<number>()); readonly remoteRepositories = signal(false);
  readonly systems = signal<ChronosSystem[]>([]); readonly owners = signal<User[]>([]);
  archived = 'false'; all = false; search = ''; sort = 'id'; page = 1; pageSize = 25;
  remote = false; repositoryType = 'git'; repositoryUrl = ''; repositoryBranch = 'master'; repositoryUsername = ''; repositoryPassword = '';
  name = ''; description = ''; systemId: number | null = null; ownerId: number | null = null; cem = false;
  private timer?: ReturnType<typeof setTimeout>; private sequence = 0; private destroyed = false; private pageSizeChosen = false;
  constructor() {
    inject(DestroyRef).onDestroy(() => { this.destroyed = true; clearTimeout(this.timer); this.repositoryPassword = ''; this.changes.setDirty(false); });
    this.route.data.pipe(takeUntilDestroyed()).subscribe(data => { this.kind.set(data['kind']); this.page = 1; this.search = ''; this.archived = 'false'; this.pageSizeChosen = false; void this.load(); });
  }
  ngAfterViewInit(): void { if (this.route.snapshot.queryParamMap.get('create') === '1' && (this.kind() === 'projects' || this.session.isAdmin())) queueMicrotask(() => { if (!this.destroyed && !this.dialog.nativeElement.open) void this.openCreate(); }); }
  title(): string { return this.kind() === 'projects' ? 'Projects' : 'Systems'; }
  projectSystem(item: Project | ChronosSystem): string { return 'systemName' in item ? item.systemName : ''; }
  ownerName(item: Project | ChronosSystem): string { return 'ownerName' in item && typeof item.ownerName === 'string' ? item.ownerName : `User #${item.ownerId}`; }
  logo(id: number): string { return `/api/v3/systems/${id}/logo`; }
  hideLogo(id: number): void { this.failedLogos.update(previous => new Set([...previous, id])); }
  sortState(key: string): string { return this.sort === key ? 'ascending' : this.sort === `-${key}` ? 'descending' : 'none'; }
  sortMark(key: string): string { return this.sort === key ? '↑' : this.sort === `-${key}` ? '↓' : ''; }
  sizes(): number[] { return [...new Set([5, 10, 25, 50, 100, this.pageSize])].sort((a, b) => a - b); }
  rangeEnd(): number { return Math.min(this.page * this.pageSize, this.total()); }
  async load(): Promise<void> {
    const sequence = ++this.sequence; this.busy.set(true); this.error.set('');
    try {
      const query: Record<string, string | number | boolean> = { archived: this.archived, all: this.all, search: this.search, sort: this.sort, page: this.page };
      if (this.pageSizeChosen) query['pageSize'] = this.pageSize;
      const result = await this.api.get<PageData<Project | ChronosSystem>>(`/${this.kind()}`, query);
      if (sequence !== this.sequence) return;
      this.items.set(result.items); this.total.set(result.total); this.page = result.page; this.pageSize = result.pageSize;
    } catch (error) { if (sequence === this.sequence) this.error.set(errorMessage(error)); }
    finally { if (sequence === this.sequence) this.busy.set(false); }
  }
  filterChanged(): void { this.page = 1; this.pageSizeChosen = true; void this.load(); }
  searchChanged(value: string): void { this.search = value; this.page = 1; clearTimeout(this.timer); this.timer = setTimeout(() => void this.load(), 250); }
  sortBy(key: string): void { this.sort = this.sort === key ? `-${key}` : key; this.page = 1; void this.load(); }
  changePage(delta: number): void { this.page += delta; void this.load(); }
  async openCreate(): Promise<void> {
    if (this.saving() || this.loadingOptions() || this.dialog.nativeElement.open) return;
    this.loadingOptions.set(true); this.optionsReady.set(false); this.systems.set([]); this.owners.set([]);
    this.name = ''; this.description = ''; this.systemId = null; this.ownerId = this.session.user()?.id ?? null; this.cem = false; this.remote = false; this.repositoryType = 'git'; this.repositoryUrl = ''; this.repositoryBranch = 'master'; this.repositoryUsername = ''; this.repositoryPassword = ''; this.remoteRepositories.set(false); this.saveError.set(''); this.changes.setDirty(false);
    // NgModel queues model-to-control writes. Complete those writes while the
    // dialog is hidden, before a user can edit its previously rendered inputs.
    this.detector.detectChanges(); await Promise.resolve();
    if (this.destroyed) return;
    this.dialog.nativeElement.showModal();
    let ready = false;
    try {
      if (this.kind() === 'systems') { const metadata = await this.api.get<Metadata & { features?: { remoteRepositories?: boolean } }>('/meta'); this.remoteRepositories.set(metadata.features?.remoteRepositories === true); }
      if (this.kind() === 'projects') this.systems.set(await allPages<ChronosSystem>(this.api, '/systems', { all: this.session.isAdmin() }));
      if (this.session.isAdmin()) this.owners.set(await allPages<User>(this.api, '/users'));
      ready = true;
    } catch (error) { this.saveError.set(errorMessage(error)); }
    finally {
      if (!this.destroyed) {
        // Newly populated selects/conditional fields also register NgModel
        // controls asynchronously; keep the form disabled until they settle.
        this.detector.detectChanges(); await Promise.resolve();
        if (!this.destroyed) { this.optionsReady.set(ready); this.loadingOptions.set(false); this.detector.detectChanges(); }
      }
    }
  }
  repositoryChanged(): void { if (!this.remote) this.repositoryPassword = ''; this.changes.setDirty(true); }
  cancelDialog(event?: Event): void { event?.preventDefault(); if (!this.saving() && this.changes.mayDiscard()) { this.dialog.nativeElement.close(); this.repositoryPassword = ''; this.changes.setDirty(false); } }
  async create(): Promise<void> {
    if (this.saving() || this.loadingOptions() || !this.optionsReady()) return;
    const current = this.changes.checkpoint();
    this.saving.set(true); this.saveError.set('');
    try {
      const body = this.kind() === 'projects'
        ? { name: this.name, description: this.description, systemId: this.systemId, ...(this.session.isAdmin() ? { ownerId: this.ownerId } : {}) }
        : { name: this.name, description: this.description, ownerId: this.ownerId, cem: this.cem, ...(this.remote && this.remoteRepositories() ? { repository: { type: this.repositoryType, url: this.repositoryUrl, branch: this.repositoryBranch, username: this.repositoryUsername, password: this.repositoryPassword } } : {}) };
      const created = await this.api.post<Project | ChronosSystem>(`/${this.kind()}`, body);
      if (!current()) return;
      this.repositoryPassword = ''; this.changes.setDirty(false); this.dialog.nativeElement.close();
      await this.router.navigate(['/', this.kind(), created.id]);
    } catch (error) { if (current()) this.saveError.set(errorMessage(error)); }
    finally { if (current()) this.saving.set(false); }
  }
}
