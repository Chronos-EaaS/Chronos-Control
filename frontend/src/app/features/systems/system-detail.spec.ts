import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { BehaviorSubject, of } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Api, ApiError } from '../../core/api';
import { Session } from '../../core/session';
import { UnsavedChanges } from '../../core/unsaved';
import { SystemDetail } from './system-detail';

const system = () => ({ id: 1, name: 'Synthetic system', description: '<safe text>', ownerId: 2, archived: false, cem: false, identifier: 'fixture-system', permissions: { canEdit: true }, logoUrl: '/api/v3/systems/1/logo', createdAt: '2026-01-15T12:00:00+01:00', updatedAt: null, hasLegacyResults: false, settings: { general: { timeout: '0', enabled: 'false', empty: '' }, setup: { mode: 'safe' }, defaultValues: { phase_warmUp: 'false', environment: '' } }, environments: ['Fixture with spaces'], repository: { url: '', type: 'git', branch: 'master', username: '', hasPassword: false, canEdit: false, remoteEnabled: false } });
const ready = async (fixture: ReturnType<typeof TestBed.createComponent>) => { fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges(); };
function deferred<T>() { let resolve!: (value: T) => void; const promise = new Promise<T>(done => resolve = done); return { promise, resolve }; }
function editorDocument(kind: 'parameters' | 'results', title: string) {
  return kind === 'parameters' ? { groups: [{ id: 'main', title, depends: '', dependsValue: '', elements: [] }] }
    : { version: '1.0', elements: { 'system-1': { name: title, all: [], job: [] } } };
}
function editorTitle(root: HTMLElement, kind: 'parameters' | 'results'): HTMLInputElement {
  const label = Array.from(root.querySelectorAll('label')).find(item => item.textContent?.trim() === (kind === 'parameters' ? 'Group title' : 'Configuration name'))!;
  return Array.from(root.querySelectorAll('input')).find(input => input.id === label.htmlFor)!;
}

describe('system detail host', () => {
  const api = { get: vi.fn(), patch: vi.fn(), request: vi.fn(), post: vi.fn() };
  beforeEach(async () => {
    vi.resetAllMocks();
    api.get.mockImplementation(async (path: string) => path.endsWith('/parameters') ? { groups: [] } : path.endsWith('/result-configurations') ? { version: '1.0', elements: {} } : system());
    await TestBed.configureTestingModule({ imports: [SystemDetail], providers: [provideRouter([]), { provide: Api, useValue: api }, { provide: Session, useValue: { isAdmin: () => false } }, { provide: ActivatedRoute, useValue: { paramMap: of(convertToParamMap({ id: '1' })) } }] }).compileComponents();
  });
  async function repositoryFixture(options: Partial<ReturnType<typeof system>['repository']> = {}) {
    const record = system(); record.repository = { ...record.repository, url: 'https://repository.example.test/fixture.git', username: 'fixture', hasPassword: true, canEdit: true, remoteEnabled: true, ...options };
    const defaults = api.get.getMockImplementation()!;
    api.get.mockImplementation((path: string) => path === '/systems/1' ? Promise.resolve(record) : defaults(path));
    const fixture = TestBed.createComponent(SystemDetail); await ready(fixture);
    await vi.waitFor(() => { fixture.detectChanges(); expect(fixture.componentInstance.loading()).toBe(false); });
    fixture.componentInstance.tab.set('repository'); await ready(fixture);
    const root = fixture.nativeElement as HTMLElement;
    const save = Array.from(root.querySelectorAll('button')).find(button => button.textContent?.trim() === 'Save repository settings')!;
    return { fixture, record, root, save, password: root.querySelector('#repository-password') as HTMLInputElement, removal: root.querySelector('#repository-remove-password') as HTMLInputElement | null };
  }
  it.each(['', 'Replacement with spaces & punctuation!'])('preserves a saved repository password unless a nonempty replacement is entered (%j)', async replacement => {
    const { fixture, record, root, save, password, removal } = await repositoryFixture();
    expect(password.value).toBe(''); expect(removal?.checked).toBe(false); expect(root.textContent).toContain('Leave this field empty to keep it.');
    const username = root.querySelector('#repository-username') as HTMLInputElement; username.value = 'updated-user'; username.dispatchEvent(new Event('input', { bubbles: true }));
    password.value = replacement; password.dispatchEvent(new Event('input', { bubbles: true })); await ready(fixture);
    api.patch.mockResolvedValue(record); expect(save.disabled).toBe(false); save.click(); await ready(fixture);
    const expected = { url: record.repository.url, type: 'git', branch: 'master', username: 'updated-user', ...(replacement ? { password: replacement } : {}) };
    expect(api.patch).toHaveBeenCalledExactlyOnceWith('/systems/1', { repository: expected });
    expect(fixture.componentInstance.repositoryPassword).toBe(''); expect(fixture.componentInstance.removeRepositoryPassword).toBe(false); expect(fixture.componentInstance.dirty().has('repository')).toBe(false);
  });
  it('explicitly removes a saved repository password, overriding a replacement draft and resetting only after success', async () => {
    const { fixture, record, root, save, password, removal } = await repositoryFixture();
    password.value = 'Replacement discarded by explicit removal'; password.dispatchEvent(new Event('input', { bubbles: true })); await ready(fixture);
    removal!.click(); await ready(fixture);
    expect(removal!.checked).toBe(true); expect(password.value).toBe(''); expect(password.disabled).toBe(true); expect(root.textContent).toContain('will be removed when you save');
    expect(fixture.componentInstance.dirty().has('repository')).toBe(true); expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true); expect(api.patch).not.toHaveBeenCalled();
    const response = deferred<ReturnType<typeof system>>(); api.patch.mockReturnValue(response.promise); save.click(); await ready(fixture);
    expect(api.patch).toHaveBeenCalledExactlyOnceWith('/systems/1', { repository: { url: record.repository.url, type: 'git', branch: 'master', username: 'fixture', password: '' } });
    expect(fixture.componentInstance.removeRepositoryPassword).toBe(true);
    response.resolve({ ...record, repository: { ...record.repository, hasPassword: false } }); await ready(fixture);
    expect(fixture.componentInstance.removeRepositoryPassword).toBe(false); expect(fixture.componentInstance.repositoryPassword).toBe(''); expect(fixture.componentInstance.dirty().has('repository')).toBe(false);
    expect(root.querySelector('#repository-remove-password')).toBeNull(); expect(password.disabled).toBe(false); expect(root.textContent).toContain('No password is saved.');
  });
  it('retains the explicit password-removal draft after a failed save so it can be retried', async () => {
    const { fixture, record, save, removal, password } = await repositoryFixture(); removal!.click(); await ready(fixture);
    api.patch.mockRejectedValue(new Error('Repository save failed')); save.click(); await ready(fixture);
    expect(fixture.componentInstance.error()).toBe('Repository save failed'); expect(fixture.componentInstance.removeRepositoryPassword).toBe(true); expect(removal!.checked).toBe(true); expect(password.disabled).toBe(true); expect(fixture.componentInstance.dirty().has('repository')).toBe(true);
    await vi.waitFor(() => { fixture.detectChanges(); expect(save.matches(':disabled')).toBe(false); });
    api.patch.mockResolvedValue({ ...record, repository: { ...record.repository, hasPassword: false } }); save.click(); await ready(fixture);
    expect(api.patch).toHaveBeenCalledTimes(2); expect(api.patch.mock.calls[1][1].repository.password).toBe(''); expect(fixture.componentInstance.removeRepositoryPassword).toBe(false);
  });
  it('cancels discarding a removal draft, then clears it without saving when discard is confirmed', async () => {
    const { fixture, root, removal, password } = await repositoryFixture(); removal!.click(); await ready(fixture);
    const discard = Array.from(root.querySelectorAll('button')).find(button => button.textContent?.trim() === 'Discard all unsaved changes')!;
    const confirmation = vi.spyOn(window, 'confirm').mockReturnValue(false);
    try {
      discard.click(); await ready(fixture); expect(fixture.componentInstance.removeRepositoryPassword).toBe(true); expect(fixture.componentInstance.dirty().has('repository')).toBe(true);
      confirmation.mockReturnValue(true); discard.click(); await ready(fixture);
      expect(fixture.componentInstance.removeRepositoryPassword).toBe(false); expect(password.disabled).toBe(false); expect(fixture.componentInstance.dirty().size).toBe(0); expect(api.patch).not.toHaveBeenCalled();
      expect((root.querySelector('#repository-remove-password') as HTMLInputElement).checked).toBe(false);
    } finally { confirmation.mockRestore(); }
  });
  it('clears credential drafts immediately on reload and before another system finishes loading', async () => {
    const params = new BehaviorSubject(convertToParamMap({ id: '1' }));
    TestBed.overrideProvider(ActivatedRoute, { useValue: { paramMap: params } });
    const { fixture, record, removal } = await repositoryFixture(); const component = fixture.componentInstance;
    removal!.click(); await ready(fixture); await component.load(); await ready(fixture);
    expect(component.removeRepositoryPassword).toBe(false); expect(component.repositoryPassword).toBe(''); expect(component.dirty().size).toBe(0);
    component.setRemoveRepositoryPassword(true); component.repositoryPassword = 'Sensitive old-system draft';
    const next = deferred<ReturnType<typeof system>>(), defaults = api.get.getMockImplementation()!;
    api.get.mockImplementation((path: string) => path === '/systems/2' ? next.promise : defaults(path)); params.next(convertToParamMap({ id: '2' }));
    expect(component.removeRepositoryPassword).toBe(false); expect(component.repositoryPassword).toBe('');
    next.resolve({ ...record, id: 2, repository: { ...record.repository, hasPassword: false } }); await ready(fixture);
    expect(component.system()?.id).toBe(2); expect(component.dirty().size).toBe(0); expect(fixture.nativeElement.querySelector('#repository-remove-password')).toBeNull(); expect(api.patch).not.toHaveBeenCalled();
  });
  it('shows password removal only for a stored secret and keeps it inside the repository permission boundary', async () => {
    const { root, removal } = await repositoryFixture({ canEdit: false, remoteEnabled: false });
    expect(removal).not.toBeNull(); expect(removal!.matches(':disabled')).toBe(true); expect(root.querySelector('#repository-password')!.matches(':disabled')).toBe(true);
  });
  it('does not apply an old system save or unlock the next system save after a reused route changes ID', async () => {
    const params = new BehaviorSubject(convertToParamMap({ id: '1' }));
    TestBed.overrideProvider(ActivatedRoute, { useValue: { paramMap: params } });
    const defaults = api.get.getMockImplementation()!;
    api.get.mockImplementation((path: string) => path === '/systems/2' ? Promise.resolve({ ...system(), id: 2, name: 'Second system' }) : defaults(path));
    const first = deferred<ReturnType<typeof system>>(), second = deferred<ReturnType<typeof system>>();
    api.patch.mockImplementation((path: string) => path === '/systems/1' ? first.promise : second.promise);
    const fixture = TestBed.createComponent(SystemDetail); await ready(fixture);
    await vi.waitFor(() => { fixture.detectChanges(); expect(fixture.componentInstance.loading()).toBe(false); });
    const component = fixture.componentInstance;
    component.general.name = 'Save first system'; component.mark('general'); const firstSave = component.saveGeneral();
    params.next(convertToParamMap({ id: '2' })); await ready(fixture);
    await vi.waitFor(() => { fixture.detectChanges(); expect(component.loading()).toBe(false); });
    component.general.name = 'Second system draft'; component.mark('general'); const secondSave = component.saveGeneral();
    expect(api.patch).toHaveBeenLastCalledWith('/systems/2', expect.objectContaining({ name: 'Second system draft' }));
    first.resolve({ ...system(), name: 'Save first system' }); await firstSave; await ready(fixture);
    expect(component.system()?.id).toBe(2); expect(component.general.name).toBe('Second system draft'); expect(component.saving()).toBe(true); expect(component.dirty().has('general')).toBe(true); expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
    second.resolve({ ...system(), id: 2, name: 'Second system draft' }); await secondSave; await ready(fixture);
    expect(component.system()?.id).toBe(2); expect(component.saving()).toBe(false); expect(TestBed.inject(UnsavedChanges).dirty()).toBe(false);
  });
  it('keeps full-reload controls read-only until the replacement system values are ready', async () => {
    const fixture = TestBed.createComponent(SystemDetail); await ready(fixture);
    await vi.waitFor(() => { fixture.detectChanges(); expect(fixture.componentInstance.loading()).toBe(false); });
    const delayed = deferred<ReturnType<typeof system>>(), defaults = api.get.getMockImplementation()!;
    api.get.mockImplementation((path: string) => path === '/systems/1' ? delayed.promise : defaults(path));
    const loading = fixture.componentInstance.load(); fixture.detectChanges();
    expect((fixture.nativeElement.querySelector('#system-name') as HTMLInputElement).matches(':disabled')).toBe(true);
    delayed.resolve({ ...system(), name: 'Freshly reloaded system' }); await loading; await ready(fixture);
    const input = fixture.nativeElement.querySelector('#system-name') as HTMLInputElement;
    expect(input.value).toBe('Freshly reloaded system'); expect(input.matches(':disabled')).toBe(false); expect(api.patch).not.toHaveBeenCalled();
  });
  it('does not offer removal when no repository password is saved', async () => {
    const { removal, root } = await repositoryFixture({ hasPassword: false }); expect(removal).toBeNull(); expect(root.textContent).toContain('No password is saved.');
  });
  it('loads all configuration panels independently and renders text and German dates safely', async () => {
    const fixture = TestBed.createComponent(SystemDetail); await ready(fixture);
    expect(fixture.componentInstance.groups()).toEqual([]); expect(fixture.componentInstance.resultsLoaded()).toBe(true);
    expect(fixture.nativeElement.textContent).toContain('15.01.2026'); expect(fixture.nativeElement.querySelector('safe')).toBeNull();
    expect(fixture.componentInstance.base()).toBe('/api/v3/systems/1');
  });
  it('opens legacy bookmark panels and result context without discarding or saving a draft', async () => {
    const query = convertToParamMap({ tab: 'results', configuration: 'system-bookmark', scope: 'job' });
    TestBed.overrideProvider(ActivatedRoute, { useValue: { paramMap: of(convertToParamMap({ id: '1' })), queryParamMap: of(query), snapshot: { queryParamMap: query } } });
    const fixture = TestBed.createComponent(SystemDetail); await ready(fixture);
    expect(fixture.componentInstance.tab()).toBe('results'); expect(fixture.componentInstance.requestedConfiguration()).toBe('system-bookmark'); expect(fixture.componentInstance.requestedScope()).toBe('job'); expect(fixture.componentInstance.dirty().size).toBe(0); expect(api.request).not.toHaveBeenCalled();
  });
  it('saves literal zero, false and empty settings while retaining unsaved edits in another panel', async () => {
    const fixture = TestBed.createComponent(SystemDetail); await ready(fixture);
    fixture.componentInstance.mark('parameters'); fixture.componentInstance.setSetting('general', 0, '0');
    api.request.mockResolvedValue({}); await fixture.componentInstance.saveSettings('general');
    expect(api.request).toHaveBeenCalledWith('PUT', '/systems/1/settings', { general: { timeout: '0', enabled: 'false', empty: '' } });
    expect(fixture.componentInstance.dirty().has('settings-general')).toBe(false); expect(fixture.componentInstance.dirty().has('parameters')).toBe(true);
    expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
  });
  it('keeps failed drafts and their dirty state so the user can retry', async () => {
    const fixture = TestBed.createComponent(SystemDetail); await ready(fixture);
    fixture.componentInstance.newEnvironment = 'New environment with spaces'; fixture.componentInstance.addEnvironment();
    api.request.mockRejectedValue(new Error('Save failed')); await fixture.componentInstance.saveEnvironments();
    expect(fixture.componentInstance.environments()).toContain('New environment with spaces'); expect(fixture.componentInstance.dirty().has('environments')).toBe(true); expect(fixture.componentInstance.error()).toBe('Save failed');
  });
  it('does not submit administrator-only owner changes for regular owners', async () => {
    const fixture = TestBed.createComponent(SystemDetail); await ready(fixture);
    fixture.componentInstance.general.name = 'Query plans with spaces & café'; fixture.componentInstance.mark('general'); api.patch.mockResolvedValue(system());
    await fixture.componentInstance.saveGeneral();
    expect(api.patch).toHaveBeenCalledWith('/systems/1', { name: 'Query plans with spaces & café', description: '<safe text>', archived: false, cem: false });
  });
  it('retains access to system settings when one legacy schema cannot load', async () => {
    api.get.mockImplementation(async (path: string) => { if (path.endsWith('/parameters')) throw new Error('Invalid schema'); return path.endsWith('/result-configurations') ? { version: '1.0', elements: {} } : system(); });
    const fixture = TestBed.createComponent(SystemDetail); await ready(fixture);
    expect(fixture.componentInstance.system()?.name).toBe('Synthetic system'); expect(fixture.componentInstance.parametersLoaded()).toBe(false); expect(fixture.componentInstance.resultsLoaded()).toBe(true); expect(fixture.componentInstance.error()).toBe('Invalid schema');
  });
  it('recognizes a local save after sync failure and keeps other drafts while retrying push', async () => {
    const fixture = TestBed.createComponent(SystemDetail); await ready(fixture);
    fixture.componentInstance.mark('parameters'); fixture.componentInstance.mark('general');
    api.request.mockRejectedValue(new ApiError('The configuration is saved locally, but synchronization failed.', 409, 'repository_sync_failed'));
    await fixture.componentInstance.saveParameters();
    expect(fixture.componentInstance.dirty().has('parameters')).toBe(false); expect(fixture.componentInstance.dirty().has('general')).toBe(true); expect(fixture.componentInstance.error()).toContain('saved locally');
    api.post.mockResolvedValue({ revision: 'fixture', branches: ['main'], history: [], remoteEnabled: true }); await fixture.componentInstance.pushRepository();
    expect(api.post).toHaveBeenCalledWith('/systems/1/repository/push', {}); expect(fixture.componentInstance.dirty().has('general')).toBe(true);
  });
  it('offers named owner lookup and omits an unchanged inactive owner from metadata updates', async () => {
    (TestBed.inject(Session) as unknown as { isAdmin: () => boolean }).isAdmin = () => true;
    api.get.mockImplementation(async (path: string) => path === '/users' ? { items: [{ id: 2, firstName: 'Existing', lastName: 'Owner', username: 'existing', active: false }, { id: 3, firstName: 'Active', lastName: 'Owner', username: 'active', active: true }], total: 2, page: 1, pageSize: 20 } : path.endsWith('/parameters') ? { groups: [] } : path.endsWith('/result-configurations') ? { version: '1.0', elements: {} } : system());
    const fixture = TestBed.createComponent(SystemDetail); await ready(fixture); await vi.waitFor(() => expect(fixture.componentInstance.owners()).toHaveLength(2)); fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('Existing Owner (existing)');
    const select = fixture.nativeElement.querySelector('#system-owner') as HTMLSelectElement;
    expect(Array.from(select.options).find(option => option.textContent?.includes('existing'))?.disabled).toBe(true);
    fixture.componentInstance.general.description = 'Metadata only'; fixture.componentInstance.mark('general'); api.patch.mockResolvedValue(system()); await fixture.componentInstance.saveGeneral();
    expect(api.patch.mock.calls[0][1]).not.toHaveProperty('ownerId');
    fixture.componentInstance.general.ownerId = 3; fixture.componentInstance.mark('general'); await fixture.componentInstance.saveGeneral();
    expect(api.patch.mock.calls[1][1]).toHaveProperty('ownerId', 3);
  });
  it('uses native warm-up and environment selectors while preserving stored false/default variants', async () => {
    const fixture = TestBed.createComponent(SystemDetail); await ready(fixture); fixture.componentInstance.tab.set('settings'); await ready(fixture);
    const warmUp = fixture.nativeElement.querySelector('#value-defaultValues-0') as HTMLSelectElement, environment = fixture.nativeElement.querySelector('#value-defaultValues-1') as HTMLSelectElement;
    expect(warmUp.tagName).toBe('SELECT'); expect(warmUp.value).toBe('false'); expect(environment.tagName).toBe('SELECT'); expect(environment.textContent).toContain('Fixture with spaces');
    warmUp.value = 'unchecked'; warmUp.dispatchEvent(new Event('change', { bubbles: true })); await ready(fixture);
    expect(fixture.componentInstance.settings().defaultValues[0].value).toBe('unchecked'); expect(fixture.componentInstance.tabDirty('settings')).toBe(true);
  });
  it('requests paged searchable sortable repository history without changing form drafts', async () => {
    const fixture = TestBed.createComponent(SystemDetail); await ready(fixture); fixture.componentInstance.mark('general');
    api.get.mockResolvedValue({ revision: 'latest', branches: ['main'], history: [], remoteEnabled: true, total: 55, page: 1, pageSize: 10, sort: '-date' }); await fixture.componentInstance.loadRepository();
    expect(fixture.componentInstance.historyPages()).toBe(6); fixture.componentInstance.setHistorySize('25'); await ready(fixture);
    expect(api.get).toHaveBeenLastCalledWith('/systems/1/repository', { page: 1, pageSize: 25, sort: '-date' });
    fixture.componentInstance.historySearch = 'important'; fixture.componentInstance.sortHistory('author'); await ready(fixture);
    expect(api.get).toHaveBeenLastCalledWith('/systems/1/repository', { page: 1, pageSize: 25, sort: 'author', search: 'important' });
    expect(fixture.componentInstance.dirty().has('general')).toBe(true);
  });
  it.each(['parameters', 'results'] as const)('keeps the newest %s editor and native draft when an older initial load completes late', async kind => {
    const suffix = kind === 'parameters' ? '/parameters' : '/result-configurations';
    const old = deferred<unknown>(), latest = deferred<unknown>();
    const defaults = api.get.getMockImplementation()!; let requests = 0;
    api.get.mockImplementation((path: string) => path.endsWith(suffix) ? (++requests === 1 ? old.promise : latest.promise) : defaults(path));
    const fixture = TestBed.createComponent(SystemDetail), component = fixture.componentInstance;
    await vi.waitFor(() => expect(requests).toBe(1)); component.tab.set(kind); await ready(fixture);
    const load = kind === 'parameters' ? () => component.loadParameters() : () => component.loadResults();
    const button = Array.from(fixture.nativeElement.querySelectorAll('button') as NodeListOf<HTMLButtonElement>).find(item => item.textContent?.trim() === (kind === 'parameters' ? 'Load parameter schema' : 'Load result configurations'))!;
    expect(button.disabled).toBe(true);
    const loading = kind === 'parameters' ? component.parametersLoading : component.resultsLoading;
    expect(loading()).toBe(true);
    const reloading = load(); latest.resolve(editorDocument(kind, 'Newer response')); await reloading; await ready(fixture);
    expect(loading()).toBe(false);
    const title = editorTitle(fixture.nativeElement, kind);
    expect(title.value).toBe('Newer response'); title.value = 'Retained native draft'; title.dispatchEvent(new Event('input', { bubbles: true })); await ready(fixture);
    expect(component.dirty().has(kind)).toBe(true);
    old.resolve(editorDocument(kind, 'Outdated response'));
    await vi.waitFor(() => expect(component.loading()).toBe(false)); await ready(fixture);
    expect(editorTitle(fixture.nativeElement, kind).value).toBe('Retained native draft');
    expect(kind === 'parameters' ? component.groups()[0].title : component.results().elements['system-1'].name).toBe('Retained native draft');
    expect(component.dirty().has(kind)).toBe(true); expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
    await load(); expect(requests).toBe(2); expect(component.error()).toContain('Save or discard');
  });
  it.each(['parameters', 'results'] as const)('does not replace a %s draft edited while the current reload is pending', async kind => {
    const suffix = kind === 'parameters' ? '/parameters' : '/result-configurations';
    const defaults = api.get.getMockImplementation()!;
    api.get.mockImplementation((path: string) => path.endsWith(suffix) ? Promise.resolve(editorDocument(kind, 'Saved title')) : defaults(path));
    const fixture = TestBed.createComponent(SystemDetail), component = fixture.componentInstance; await ready(fixture); component.tab.set(kind); await ready(fixture);
    const pending = deferred<unknown>(); api.get.mockImplementation((path: string) => path.endsWith(suffix) ? pending.promise : defaults(path));
    const reloading = kind === 'parameters' ? component.loadParameters() : component.loadResults();
    const title = editorTitle(fixture.nativeElement, kind); title.value = 'Edited during request'; title.dispatchEvent(new Event('input', { bubbles: true })); await ready(fixture);
    pending.resolve(editorDocument(kind, 'Late saved title')); await reloading; await ready(fixture);
    expect(editorTitle(fixture.nativeElement, kind).value).toBe('Edited during request');
    expect(component.dirty().has(kind)).toBe(true); expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
    expect((kind === 'parameters' ? component.parametersLoading : component.resultsLoading)()).toBe(false);
  });
});
