import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink, type ParamMap } from '@angular/router';
import { Api, ApiError, errorMessage } from '../../core/api';
import { ChronosSystem, PageData, User } from '../../core/models';
import { ParameterGroup, ParameterTypeAliases } from '../../core/parameter-models';
import { ResultConfigurationDocument, PlotTypeAliases } from '../../core/results-models';
import { Session } from '../../core/session';
import { dateTime } from '../../core/time';
import { UnsavedChanges } from '../../core/unsaved';
import { Icon } from '../../shared/icon';
import { Feedback } from '../../shared/feedback';
import { ParameterSchemaEditor } from '../parameters/parameter-schema-editor';
import { ResultConfigurationEditor } from '../results/result-configuration-editor';
import { downloadText } from '../results/result-state';
import { SystemExtensions } from '../extensions/system-extensions';

type Section = 'general' | 'setup' | 'defaultValues';
interface SettingRow { key: string; value: string; }
function validXmlSettingKey(key: string): boolean {
  // PHP's XML builder rejects supplementary-plane names even when a browser accepts them.
  if (!key || Array.from(key).length > 50 || key.includes(':') || /[\u{10000}-\u{10FFFF}]/u.test(key)) return false;
  try { document.implementation.createDocument(null, '').createElement(key); return true; }
  catch { return false; }
}
interface SystemDetails extends ChronosSystem {
  ownerName: string; logoUrl: string; createdAt: string | null; updatedAt: string | null; hasLegacyResults: boolean;
  settings: Record<Section, Record<string, string>>; environments: string[];
  repository: { url: string; type: string; branch: string; username: string; hasPassword: boolean; canEdit: boolean; remoteEnabled: boolean };
}
interface RepositoryInfo { revision: string | null; branches: string[]; history: { revision: string; parents: string[]; at: string; author: string; message: string; branches?: string[] }[]; remoteEnabled: boolean; total?: number; page?: number; pageSize?: number; sort?: string; }

@Component({
  imports: [FormsModule, NgTemplateOutlet, Icon, RouterLink, Feedback, ParameterSchemaEditor, ResultConfigurationEditor, SystemExtensions],
  templateUrl: './system-detail.html',
  styleUrl: './system-detail.scss',
})
export class SystemDetail {
  sectionKey(section: { key: Section }): Section { return section.key; }

  private readonly api = inject(Api); private readonly route = inject(ActivatedRoute); private readonly changes = inject(UnsavedChanges).scope(inject(DestroyRef));
  readonly parameterAliases = signal<ParameterTypeAliases>({}); readonly plotAliases = signal<PlotTypeAliases>({});
  readonly session = inject(Session); readonly system = signal<SystemDetails | null>(null); readonly loading = signal(false); readonly saving = signal(false); readonly error = signal(''); readonly success = signal('');
  readonly requestedConfiguration = signal(''); readonly requestedScope = signal<'all' | 'job' | ''>('');
  readonly tab = signal('general'); readonly dirty = signal(new Set<string>()); readonly groups = signal<ParameterGroup[]>([]); readonly parametersLoaded = signal(false); readonly parametersValid = signal(false);
  readonly results = signal<ResultConfigurationDocument>({ version: '1.0', elements: {} }); readonly resultsLoaded = signal(false); readonly resultsValid = signal(false);
  readonly parametersLoading = signal(false); readonly resultsLoading = signal(false); private parametersSequence = 0; private resultsSequence = 0;
  readonly settings = signal<Record<Section, SettingRow[]>>({ general: [], setup: [], defaultValues: [] }); readonly environments = signal<string[]>([]); readonly repository = signal<RepositoryInfo | null>(null);
  private readonly settingErrors = computed(() => Object.fromEntries((['general', 'setup'] as const).map(section => {
    const invalid = this.settings()[section].find(row => !validXmlSettingKey(row.key));
    return [section, invalid ? `Setting key “${invalid.key}” is not a valid XML name. Use 1–50 characters without spaces or colons; remove this entry and add a valid name before saving.` : ''];
  })) as Partial<Record<Section, string>>);
  settingKeyError(section: Section): string { return this.settingErrors()[section] ?? ''; }

  readonly owners = signal<User[]>([]); readonly ownersLoading = signal(false); readonly ownerLookupError = signal(''); ownerSearch = ''; private ownerSearchTimer?: ReturnType<typeof setTimeout>; private ownerLookupGeneration = 0; private readonly ownerNames = new Map<number, string>();
  readonly historyPage = signal(1); readonly historySize = signal<number | null>(null); readonly historySort = signal('-date'); readonly historyLoading = signal(false); historySearch = ''; private historySearchTimer?: ReturnType<typeof setTimeout>; private historyGeneration = 0;
  readonly historyPageSize = computed(() => this.historySize() ?? this.repository()?.pageSize ?? 20); readonly historyTotal = computed(() => this.repository()?.total ?? this.repository()?.history.length ?? 0);
  readonly historyPages = computed(() => Math.max(1, Math.ceil(this.historyTotal() / this.historyPageSize())));
  readonly historyPageNumbers = computed(() => { const start = Math.max(1, Math.min(this.historyPage() - 2, this.historyPages() - 4)); return Array.from({ length: Math.min(5, this.historyPages()) }, (_, index) => start + index); });
  readonly logoPreview = signal(''); readonly importFile = signal<File | null>(null); readonly time = dateTime; readonly base = computed(() => `/api/ui/systems/${this.system()?.id ?? 0}`);
  readonly tabs = [{ key: 'general', label: 'General' }, { key: 'parameters', label: 'Parameters' }, { key: 'results', label: 'Results' }, { key: 'settings', label: 'Settings and defaults' }, { key: 'environments', label: 'Environments' }, { key: 'files', label: 'Logo and files' }, { key: 'repository', label: 'Repository and history' }];
  readonly sectionDefinitions: { key: Section; label: string }[] = [{ key: 'general', label: 'Evaluation settings' }, { key: 'setup', label: 'Setup options' }, { key: 'defaultValues', label: 'Experiment defaults' }];
  general = { name: '', description: '', ownerId: 0, archived: false, cem: false }; repositoryFormValue = { url: '', type: 'git', branch: '', username: '' }; repositoryPassword = ''; removeRepositoryPassword = false;
  newKeys: Record<Section, string> = { general: '', setup: '', defaultValues: '' }; newEnvironment = ''; private id = 0; private generation = 0;
  constructor() {
    const subscription = this.route.paramMap.subscribe(params => { const id = Number(params.get('id')); if (Number.isSafeInteger(id) && id > 0) { this.id = id; this.applyQuery(this.route.snapshot?.queryParamMap); void this.load(); } else this.error.set('The system ID is invalid.'); });
    const querySubscription = this.route.queryParamMap?.subscribe(query => this.applyQuery(query));
    inject(DestroyRef).onDestroy(() => { subscription.unsubscribe(); querySubscription?.unsubscribe(); ++this.generation; ++this.ownerLookupGeneration; ++this.historyGeneration; clearTimeout(this.ownerSearchTimer); clearTimeout(this.historySearchTimer); this.changes.setDirty(false); });
  }
  private applyQuery(query: ParamMap | undefined): void {
    const tab = query?.get('tab'); this.tab.set(tab && this.tabs.some(item => item.key === tab) ? tab : 'general');
    this.requestedConfiguration.set(query?.get('configuration') ?? '');
    const scope = query?.get('scope'); this.requestedScope.set(scope === 'all' || scope === 'job' ? scope : '');
  }
  async load(): Promise<void> {
    const generation = ++this.generation; this.changes.reset(); this.saving.set(false); ++this.historyGeneration; this.loading.set(true); this.error.set(''); this.success.set(''); this.resetRepositoryPasswordDraft();
    ++this.ownerLookupGeneration; clearTimeout(this.ownerSearchTimer); this.owners.set([]); this.ownerNames.clear(); this.ownerSearch = ''; this.ownersLoading.set(false); this.ownerLookupError.set('');
    ++this.parametersSequence; ++this.resultsSequence; this.parametersLoading.set(false); this.resultsLoading.set(false);
    try {
      const value = await this.api.get<SystemDetails>(`/systems/${this.id}`); if (generation !== this.generation) return;
      this.system.set(value); this.general = { name: value.name, description: value.description, ownerId: value.ownerId, archived: value.archived, cem: value.cem };
      this.settings.set(Object.fromEntries(this.sectionDefinitions.map(section => [section.key, Object.entries(section.key === 'defaultValues' ? { phase_warmUp: 'unchecked', environment: '', ...value.settings[section.key] } : value.settings[section.key]).map(([key, value]) => ({ key, value }))])) as Record<Section, SettingRow[]>);
      this.environments.set([...value.environments]); const { url, type, branch, username } = value.repository; this.repositoryFormValue = { url, type, branch, username };
      this.dirty.set(new Set()); this.changes.setDirty(false); this.logoPreview.set(''); this.importFile.set(null); this.parametersLoaded.set(false); this.resultsLoaded.set(false); this.repository.set(null); this.parameterAliases.set({}); this.plotAliases.set({});
      if (this.session.isAdmin()) void this.loadOwners();
      // Load independently so one invalid legacy configuration does not hide system settings.
      await Promise.allSettled([this.loadParameters(generation), this.loadResults(generation), this.loadAliases(generation)]);
    } catch (error) { if (generation === this.generation) { this.system.set(null); this.error.set(errorMessage(error)); } }
    finally { if (generation === this.generation) this.loading.set(false); }
  }
  mark(section: string): void { this.dirty.update(current => new Set([...current, section])); this.changes.setDirty(true); }
  tabDirty(section: string): boolean { return this.dirty().has(section) || (section === 'settings' && [...this.dirty()].some(key => key.startsWith('settings-'))); }
  private clean(section: string): void { this.dirty.update(current => { const next = new Set(current); next.delete(section); return next; }); this.changes.setDirty(this.dirty().size > 0); }
  private async run(operation: (current: () => boolean) => Promise<void>, message: string, locallySaved?: () => Promise<void>): Promise<void> { if (this.saving()) return; const current = this.changes.checkpoint(); this.saving.set(true); this.error.set(''); this.success.set(''); try { await operation(current); if (current()) this.success.set(message); } catch (error) { if (!current()) return; if (error instanceof ApiError && error.code === 'repository_sync_failed' && locallySaved) await locallySaved(); if (current()) this.error.set(errorMessage(error)); } finally { if (current()) this.saving.set(false); } }
  async loadAliases(generation = this.generation): Promise<void> {
    try { const value = await this.api.get<{ aliases?: { parameters?: ParameterTypeAliases; plots?: PlotTypeAliases } }>(`/systems/${this.id}/extensions`); if (generation === this.generation) { this.parameterAliases.set(value?.aliases?.parameters ?? {}); this.plotAliases.set(value?.aliases?.plots ?? {}); } }
    catch (error) { if (generation === this.generation) this.error.set(errorMessage(error)); }
  }
  async loadParameters(generation = this.generation): Promise<void> {
    if (generation !== this.generation) return;
    if (this.dirty().has('parameters')) { this.error.set('Save or discard parameter changes before reloading.'); return; }
    const sequence = ++this.parametersSequence; this.parametersLoading.set(true);
    try {
      const value = await this.api.get<{ groups: ParameterGroup[] }>(`/systems/${this.id}/parameters`);
      if (generation === this.generation && sequence === this.parametersSequence && !this.dirty().has('parameters')) { this.groups.set(value.groups); this.parametersLoaded.set(true); }
    } catch (error) { if (generation === this.generation && sequence === this.parametersSequence) this.error.set(errorMessage(error)); }
    finally { if (generation === this.generation && sequence === this.parametersSequence) this.parametersLoading.set(false); }
  }
  async loadResults(generation = this.generation): Promise<void> {
    if (generation !== this.generation) return;
    if (this.dirty().has('results')) { this.error.set('Save or discard result configuration changes before reloading.'); return; }
    const sequence = ++this.resultsSequence; this.resultsLoading.set(true);
    try {
      const value = await this.api.get<ResultConfigurationDocument>(`/systems/${this.id}/result-configurations`);
      if (generation === this.generation && sequence === this.resultsSequence && !this.dirty().has('results')) { this.results.set(value); this.resultsLoaded.set(true); }
    } catch (error) { if (generation === this.generation && sequence === this.resultsSequence) this.error.set(errorMessage(error)); }
    finally { if (generation === this.generation && sequence === this.resultsSequence) this.resultsLoading.set(false); }
  }
  saveGeneral(): Promise<void> { return this.run(async current => { const { ownerId, ...fields } = this.general; const body = { ...fields, ...(this.session.isAdmin() && ownerId !== this.system()?.ownerId ? { ownerId } : {}) }; const value = await this.api.patch<SystemDetails>(`/systems/${this.id}`, body); if (!current()) return; this.system.set(value); this.clean('general'); }, 'System settings saved.'); }
  ownerListed(): boolean { return this.owners().some(owner => owner.id === this.general.ownerId); }
  ownerName(owner: User): string { const name = [owner.firstName, owner.lastName].filter(Boolean).join(' ').trim(); return name ? `${name} (${owner.username})` : owner.username; }
  selectedOwnerName(): string { const known = this.ownerNames.get(this.general.ownerId); if (known) return known; const current = this.system(); return current?.ownerId === this.general.ownerId && current.ownerName && !/^User #\d+$/.test(current.ownerName) ? current.ownerName : 'Owner unavailable'; }
  searchOwners(): void { clearTimeout(this.ownerSearchTimer); this.ownerSearchTimer = setTimeout(() => { void this.loadOwners(); }, 250); }
  private async loadOwners(): Promise<void> { const generation = ++this.ownerLookupGeneration; this.ownersLoading.set(true); this.ownerLookupError.set(''); try { const value = await this.api.get<PageData<User>>('/users', { page: 1, pageSize: 20, sort: 'name', enabled: 'all', search: this.ownerSearch }); if (generation === this.ownerLookupGeneration) { if (!Array.isArray(value.items)) throw new Error('Owner search could not be loaded.'); for (const owner of value.items) this.ownerNames.set(owner.id, this.ownerName(owner)); this.owners.set(value.items); } } catch (error) { if (generation === this.ownerLookupGeneration) this.ownerLookupError.set(errorMessage(error)); } finally { if (generation === this.ownerLookupGeneration) this.ownersLoading.set(false); } }
  warmUpEnabled(value: string): boolean { return !['0', 'false', 'off', '', 'unchecked'].includes(value.trim().toLowerCase()); }
  saveParameters(): Promise<void> { return this.run(async current => { const value = await this.api.request<{ groups: ParameterGroup[] }>('PUT', `/systems/${this.id}/parameters`, { groups: this.groups() }); if (!current()) return; this.groups.set(value.groups); this.clean('parameters'); }, 'Experiment parameters saved.', async () => { this.clean('parameters'); await this.loadParameters(); }); }
  saveResults(): Promise<void> { return this.run(async current => { const value = await this.api.request<ResultConfigurationDocument>('PUT', `/systems/${this.id}/result-configurations`, this.results()); if (!current()) return; this.results.set(value); this.system.update(current => current ? { ...current, hasLegacyResults: false } : current); this.clean('results'); }, 'Result configurations saved.', async () => { this.clean('results'); this.system.update(current => current ? { ...current, hasLegacyResults: false } : current); await this.loadResults(); }); }
  migrateResults(): Promise<void> { return this.run(async current => { await this.api.post(`/systems/${this.id}/result-configurations/migrate`, {}); if (!current()) return; this.system.update(current => current ? { ...current, hasLegacyResults: false } : current); await this.loadResults(); }, 'Legacy configuration migrated. Original files retained.', async () => { this.system.update(current => current ? { ...current, hasLegacyResults: false } : current); await this.loadResults(); }); }
  setSetting(section: Section, index: number, value: string): void { this.settings.update(current => ({ ...current, [section]: current[section].map((row, i) => i === index ? { ...row, value } : row) })); this.mark(`settings-${section}`); }
  addSetting(section: Section): void { const key = section === 'defaultValues' ? this.newKeys[section].trim() : this.newKeys[section]; if (section !== 'defaultValues' && !validXmlSettingKey(key)) { this.error.set('Enter a valid XML setting key of 1–50 characters, without spaces or colons. The entered key has been retained for correction.'); return; } if (!key || this.settings()[section].some(row => row.key === key)) { this.error.set('Enter a new, unique setting key.'); return; } this.error.set(''); this.settings.update(current => ({ ...current, [section]: [...current[section], { key, value: '' }] })); this.newKeys[section] = ''; this.mark(`settings-${section}`); }
  removeSetting(section: Section, index: number): void { this.settings.update(current => ({ ...current, [section]: current[section].filter((_, i) => i !== index) })); this.mark(`settings-${section}`); }
  saveSettings(section: Section): Promise<void> { const error = this.settingKeyError(section); if (error) { this.error.set(error); return Promise.resolve(); } return this.run(async current => { await this.api.request('PUT', `/systems/${this.id}/settings`, { [section]: Object.fromEntries(this.settings()[section].map(row => [row.key, row.value])) }); if (current()) this.clean(`settings-${section}`); }, 'System settings saved.'); }
  addEnvironment(): void { const name = this.newEnvironment.trim(); if (!name || this.environments().includes(name)) { this.error.set('Enter a new, unique environment name.'); return; } this.environments.update(current => [...current, name]); this.newEnvironment = ''; this.mark('environments'); }
  removeEnvironment(index: number): void { this.environments.update(current => current.filter((_, i) => i !== index)); this.mark('environments'); }
  saveEnvironments(): Promise<void> { return this.run(async current => { const value = await this.api.request<{ environments: string[] }>('PUT', `/systems/${this.id}/environments`, { environments: this.environments() }); if (!current()) return; this.environments.set(value.environments); this.clean('environments'); }, 'Environments saved.'); }
  async selectLogo(event: Event): Promise<void> { const file = (event.target as HTMLInputElement).files?.[0]; if (!file) return; if (!['image/png', 'image/jpeg', 'image/gif'].includes(file.type) || file.size > 524288) { this.error.set('Choose a PNG, JPEG or GIF of at most 512 KiB.'); return; } const current = this.changes.checkpoint(); try { const contents = await new Promise<string>((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(String(reader.result)); reader.onerror = () => reject(new Error('The image could not be read.')); reader.readAsDataURL(file); }); if (!current()) return; this.logoPreview.set(contents); this.mark('files'); } catch (error) { if (current()) this.error.set(errorMessage(error)); } }
  private logoSaved(): void { this.system.update(current => current ? { ...current, logoUrl: `/api/ui/systems/${this.id}/logo?version=${Date.now()}` } : current); this.logoPreview.set(''); this.clean('files'); }
  saveLogo(): Promise<void> { return this.run(async current => { await this.api.request('PUT', `/systems/${this.id}/logo`, { dataUrl: this.logoPreview() }); if (current()) this.logoSaved(); }, 'System logo saved.', async () => { this.logoSaved(); }); }
  selectImport(event: Event): void { const file = (event.target as HTMLInputElement).files?.[0]; if (file && ['parameters.json', 'resultConfigurations.json'].includes(file.name) && file.size <= 1048576) this.importFile.set(file); else { this.importFile.set(null); this.error.set('Choose parameters.json or resultConfigurations.json of at most 1 MiB.'); } }
  importConfiguration(): Promise<void> { return this.run(async current => { const file = this.importFile(); if (!file) throw new Error('Choose a configuration file.'); if (this.dirty().size) throw new Error('Save or discard your unsaved changes before importing a configuration.'); const content = await file.text(); if (!current()) return; await this.api.post(`/systems/${this.id}/import`, { filename: file.name, content }); if (!current()) return; this.importFile.set(null); await Promise.all([this.loadParameters(), this.loadResults()]); }, 'Configuration validated and imported.', async () => { this.importFile.set(null); await Promise.all([this.loadParameters(), this.loadResults()]); }); }
  exportParameters(): void { downloadText('parameters.json', JSON.stringify(this.groups(), null, 2), 'application/json'); }
  exportResults(): void { downloadText('resultConfigurations.json', JSON.stringify(this.results(), null, 2), 'application/json'); }
  private applyRepository(value: RepositoryInfo): void { this.repository.set(value); this.historyPage.set(value.page ?? 1); }
  async loadRepository(): Promise<void> { const generation = ++this.historyGeneration; this.historyLoading.set(true); this.error.set(''); const query: Record<string, string | number> = { page: this.historyPage(), sort: this.historySort() }; if (this.historySize() !== null) query['pageSize'] = this.historySize()!; if (this.historySearch) query['search'] = this.historySearch; try { const value = await this.api.get<RepositoryInfo>(`/systems/${this.id}/repository`, query); if (generation === this.historyGeneration) this.applyRepository(value); } catch (error) { if (generation === this.historyGeneration) this.error.set(errorMessage(error)); } finally { if (generation === this.historyGeneration) this.historyLoading.set(false); } }
  searchHistory(): void { this.historyPage.set(1); clearTimeout(this.historySearchTimer); this.historySearchTimer = setTimeout(() => { void this.loadRepository(); }, 250); }
  setHistoryPage(value: number): void { this.historyPage.set(Math.max(1, Math.min(this.historyPages(), value))); void this.loadRepository(); }
  setHistorySize(value: string): void { const size = Number(value); if (Number.isInteger(size) && size >= 1 && size <= 100) { this.historySize.set(size); this.historyPage.set(1); void this.loadRepository(); } }
  sortHistory(column: string): void { this.historySort.set(this.historySort() === column ? `-${column}` : column); this.historyPage.set(1); void this.loadRepository(); }
  historyAriaSort(column: string): string { return this.historySort() === column ? 'ascending' : this.historySort() === `-${column}` ? 'descending' : 'none'; }
  private resetRepositoryPasswordDraft(): void { this.repositoryPassword = ''; this.removeRepositoryPassword = false; }
  setRemoveRepositoryPassword(value: boolean): void { this.removeRepositoryPassword = value; if (value) this.repositoryPassword = ''; this.mark('repository'); }
  saveRepository(): Promise<void> { return this.run(async current => { const repository = { ...this.repositoryFormValue, ...(this.removeRepositoryPassword ? { password: '' } : this.repositoryPassword ? { password: this.repositoryPassword } : {}) }; const value = await this.api.patch<SystemDetails>(`/systems/${this.id}`, { repository }); if (!current()) return; this.system.set(value); this.resetRepositoryPasswordDraft(); this.clean('repository'); }, 'Repository settings saved.'); }
  updateRepository(): Promise<void> { return this.run(async current => { if (this.dirty().size) throw new Error('Save or discard unsaved changes before updating system files.'); const value = await this.api.post<RepositoryInfo>(`/systems/${this.id}/repository/update`, {}); if (!current()) return; this.applyRepository(value); await Promise.all([this.loadParameters(), this.loadResults()]); }, 'System repository updated.'); }
  pushRepository(): Promise<void> { return this.run(async current => { const value = await this.api.post<RepositoryInfo>(`/systems/${this.id}/repository/push`, {}); if (current()) this.applyRepository(value); }, 'Committed system changes synchronized with the configured remote branch.'); }
  discard(): void { if (this.changes.mayDiscard()) void this.load(); }
}
