import { NgTemplateOutlet } from '@angular/common';
import {
  AfterViewInit,
  ChangeDetectorRef,
  Component,
  DestroyRef,
  ElementRef,
  ViewChild,
  inject,
  signal,
} from '@angular/core';
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
  imports: [FormsModule, RouterLink, Feedback, Icon, NgTemplateOutlet],
  templateUrl: './resources.html',
  styleUrl: './resources.scss',
})
export class Resources implements AfterViewInit {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly detector = inject(ChangeDetectorRef);
  readonly session = inject(Session);
  readonly changes = inject(UnsavedChanges).scope(inject(DestroyRef));
  @ViewChild('createDialog') private dialog!: ElementRef<HTMLDialogElement>;
  readonly kind = signal<'projects' | 'systems'>('projects');
  readonly items = signal<(Project | ChronosSystem)[]>([]);
  readonly total = signal(0);
  readonly busy = signal(false);
  readonly error = signal('');
  readonly saving = signal(false);
  readonly loadingOptions = signal(false);
  readonly optionsReady = signal(false);
  readonly saveError = signal('');
  readonly failedLogos = signal(new Set<number>());
  readonly remoteRepositories = signal(false);
  readonly systems = signal<ChronosSystem[]>([]);
  readonly owners = signal<User[]>([]);
  archived = 'false';
  all = false;
  search = '';
  sort = 'id';
  page = 1;
  pageSize = 25;
  view: 'cards' | 'table' = 'cards';
  remote = false;
  repositoryType = 'git';
  repositoryUrl = '';
  repositoryBranch = 'master';
  repositoryUsername = '';
  repositoryPassword = '';
  name = '';
  description = '';
  systemId: number | null = null;
  ownerId: number | null = null;
  cem = false;
  private timer?: ReturnType<typeof setTimeout>;
  private sequence = 0;
  private destroyed = false;
  private pageSizeChosen = false;
  constructor() {
    inject(DestroyRef).onDestroy(() => {
      this.destroyed = true;
      clearTimeout(this.timer);
      this.repositoryPassword = '';
      this.changes.setDirty(false);
    });
    this.route.data.pipe(takeUntilDestroyed()).subscribe((data) => {
      this.kind.set(data['kind']);
      this.page = 1;
      this.search = '';
      this.archived = 'false';
      this.view = 'cards';
      this.sort = this.kind() === 'projects' ? '-id' : 'id';
      this.all = this.kind() === 'systems' && this.session.isAdmin();
      this.pageSizeChosen = false;
      void this.load();
    });
  }
  ngAfterViewInit(): void {
    if (
      this.route.snapshot.queryParamMap.get('create') === '1' &&
      (this.kind() === 'projects' || this.session.isAdmin())
    )
      queueMicrotask(() => {
        if (!this.destroyed && !this.dialog.nativeElement.open) void this.openCreate();
      });
  }
  title(): string {
    return this.kind() === 'projects' ? 'Projects' : 'Systems';
  }
  projectSystem(item: Project | ChronosSystem): string {
    return 'systemName' in item ? item.systemName : '';
  }
  ownerName(item: Project | ChronosSystem): string {
    return 'ownerName' in item && typeof item.ownerName === 'string'
      ? item.ownerName
      : `User #${item.ownerId}`;
  }
  logo(id: number): string {
    return `/api/ui/systems/${id}/logo`;
  }
  hideLogo(id: number): void {
    this.failedLogos.update((previous) => new Set([...previous, id]));
  }
  sortState(key: string): string {
    return this.sort === key ? 'ascending' : this.sort === `-${key}` ? 'descending' : 'none';
  }
  sortMark(key: string): string {
    return this.sort === key ? '↑' : this.sort === `-${key}` ? '↓' : '';
  }
  sizes(): number[] {
    return [...new Set([5, 10, 25, 50, 100, this.pageSize])].sort((a, b) => a - b);
  }
  rangeEnd(): number {
    return Math.min(this.page * this.pageSize, this.total());
  }
  openRow(event: MouseEvent, item: Project | ChronosSystem): void {
    if (
      event.defaultPrevented ||
      event.button !== 0 ||
      event.ctrlKey ||
      event.metaKey ||
      event.shiftKey ||
      event.altKey
    )
      return;
    if (
      event.target instanceof Element &&
      event.target.closest(
        'a,button,input,select,textarea,label,summary,[contenteditable],[role="button"],[role="link"]',
      )
    )
      return;
    if (window.getSelection()?.toString()) return;
    void this.router.navigate(['/', this.kind(), item.id]);
  }
  async load(): Promise<void> {
    const sequence = ++this.sequence;
    this.busy.set(true);
    this.error.set('');
    try {
      const query: Record<string, string | number | boolean> = {
        archived: this.archived,
        all: this.all,
        search: this.search,
        sort: this.sort,
        page: this.page,
      };
      if (this.pageSizeChosen) query['pageSize'] = this.pageSize;
      const result = await this.api.get<PageData<Project | ChronosSystem>>(
        `/${this.kind()}`,
        query,
      );
      if (sequence !== this.sequence) return;
      this.items.set(result.items);
      this.total.set(result.total);
      this.page = result.page;
      this.pageSize = result.pageSize;
    } catch (error) {
      if (sequence === this.sequence) this.error.set(errorMessage(error));
    } finally {
      if (sequence === this.sequence) this.busy.set(false);
    }
  }
  filterChanged(): void {
    this.page = 1;
    this.pageSizeChosen = true;
    void this.load();
  }
  searchChanged(value: string): void {
    this.search = value;
    this.page = 1;
    clearTimeout(this.timer);
    this.timer = setTimeout(() => void this.load(), 250);
  }
  sortBy(key: string): void {
    this.sort = this.sort === key ? `-${key}` : key;
    this.page = 1;
    void this.load();
  }
  sortColumn(): string {
    return this.sort.replace(/^-/, '');
  }
  chooseSort(key: string): void {
    this.sort = (this.sort.startsWith('-') ? '-' : '') + key;
    this.page = 1;
    void this.load();
  }
  reverseSort(): void {
    this.sort = this.sort.startsWith('-') ? this.sort.slice(1) : `-${this.sort}`;
    this.page = 1;
    void this.load();
  }
  changePage(delta: number): void {
    this.page += delta;
    void this.load();
  }
  async openCreate(): Promise<void> {
    if (this.saving() || this.loadingOptions() || this.dialog.nativeElement.open) return;
    this.loadingOptions.set(true);
    this.optionsReady.set(false);
    this.systems.set([]);
    this.owners.set([]);
    this.name = '';
    this.description = '';
    this.systemId = null;
    this.ownerId = this.session.user()?.id ?? null;
    this.cem = false;
    this.remote = false;
    this.repositoryType = 'git';
    this.repositoryUrl = '';
    this.repositoryBranch = 'master';
    this.repositoryUsername = '';
    this.repositoryPassword = '';
    this.remoteRepositories.set(false);
    this.saveError.set('');
    this.changes.setDirty(false);
    // NgModel queues model-to-control writes. Complete those writes while the
    // dialog is hidden, before a user can edit its previously rendered inputs.
    this.detector.detectChanges();
    await Promise.resolve();
    if (this.destroyed) return;
    this.dialog.nativeElement.showModal();
    let ready = false;
    try {
      if (this.kind() === 'systems') {
        const metadata = await this.api.get<
          Metadata & { features?: { remoteRepositories?: boolean } }
        >('/meta');
        this.remoteRepositories.set(metadata.features?.remoteRepositories === true);
      }
      if (this.kind() === 'projects')
        this.systems.set(
          await allPages<ChronosSystem>(this.api, '/systems', { all: this.session.isAdmin() }),
        );
      if (this.session.isAdmin()) this.owners.set(await allPages<User>(this.api, '/users'));
      ready = true;
    } catch (error) {
      this.saveError.set(errorMessage(error));
    } finally {
      if (!this.destroyed) {
        // Newly populated selects/conditional fields also register NgModel
        // controls asynchronously; keep the form disabled until they settle.
        this.detector.detectChanges();
        await Promise.resolve();
        if (!this.destroyed) {
          this.optionsReady.set(ready);
          this.loadingOptions.set(false);
          this.detector.detectChanges();
        }
      }
    }
  }
  repositoryChanged(): void {
    if (!this.remote) this.repositoryPassword = '';
    this.changes.setDirty(true);
  }
  cancelDialog(event?: Event): void {
    event?.preventDefault();
    if (!this.saving() && this.changes.mayDiscard()) {
      this.dialog.nativeElement.close();
      this.repositoryPassword = '';
      this.changes.setDirty(false);
    }
  }
  async create(): Promise<void> {
    if (this.saving() || this.loadingOptions() || !this.optionsReady()) return;
    const current = this.changes.checkpoint();
    this.saving.set(true);
    this.saveError.set('');
    try {
      const body =
        this.kind() === 'projects'
          ? {
              name: this.name,
              description: this.description,
              systemId: this.systemId,
              ...(this.session.isAdmin() ? { ownerId: this.ownerId } : {}),
            }
          : {
              name: this.name,
              description: this.description,
              ownerId: this.ownerId,
              cem: this.cem,
              ...(this.remote && this.remoteRepositories()
                ? {
                    repository: {
                      type: this.repositoryType,
                      url: this.repositoryUrl,
                      branch: this.repositoryBranch,
                      username: this.repositoryUsername,
                      password: this.repositoryPassword,
                    },
                  }
                : {}),
            };
      const created = await this.api.post<Project | ChronosSystem>(`/${this.kind()}`, body);
      if (!current()) return;
      this.repositoryPassword = '';
      this.changes.setDirty(false);
      this.dialog.nativeElement.close();
      await this.router.navigate(['/', this.kind(), created.id]);
    } catch (error) {
      if (current()) this.saveError.set(errorMessage(error));
    } finally {
      if (current()) this.saving.set(false);
    }
  }
}
