import { Component, DestroyRef, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api';
import { CemEnvironment, SettingSection, StorageStatus, formatBytes } from '../../core/admin-models';
import { AdminDrafts } from './admin-drafts';
import { dateTime } from '../../core/time';
import { Feedback } from '../../shared/feedback';

@Component({
  selector: 'app-admin-settings', imports: [FormsModule, RouterLink, Feedback],
  templateUrl: './settings.html',
  styleUrl: './settings.scss',
})
export class AdminSettings {
  private readonly api = inject(Api); private readonly drafts = inject(AdminDrafts);
  readonly sections = signal<SettingSection[]>([]); readonly environments = signal<CemEnvironment[]>([]);
  readonly storage = signal<StorageStatus | null>(null); readonly loading = signal(true); readonly saving = signal(false);
  readonly storageBusy = signal(false); readonly error = signal(''); readonly success = signal('');
  values: Record<string, Record<string, string>> = {}; cleared: Record<string, Record<string, boolean>> = {};
  readonly changed = new Set<string>(); environmentName = ''; private environmentDirty = false;
  readonly bytes = formatBytes; readonly date = dateTime;
  constructor() { inject(DestroyRef).onDestroy(() => this.drafts.set('settings', false)); void this.load(); }
  title(value: string): string { return value.replace(/([a-z])([A-Z])/g, '$1 $2').replace(/[_-]/g, ' ').replace(/^./, letter => letter.toUpperCase()).replace(/\bcem\b/gi, 'CEM'); }
  fieldId(section: string, key: string): string { return `setting-${encodeURIComponent(section)}-${encodeURIComponent(key)}`; }
  private populate(section: SettingSection): void {
    this.values[section.name] = Object.fromEntries(section.settings.map(item => [item.key, item.value ?? '']));
    this.cleared[section.name] = Object.fromEntries(section.settings.map(item => [item.key, false]));
  }
  private dirty(): void { this.drafts.set('settings', this.environmentDirty || this.changed.size > 0); }
  markEnvironment(): void { this.environmentDirty = this.environmentName !== ''; this.dirty(); }
  markSection(name: string): void { this.changed.add(name); this.dirty(); }
  private async load(): Promise<void> {
    try {
      const [settings, environments, storage] = await Promise.all([this.api.get<{sections: SettingSection[]}>('/admin/settings'), this.api.get<{items: CemEnvironment[]}>('/admin/environments'), this.api.get<StorageStatus>('/admin/storage')]);
      settings.sections.forEach(section => this.populate(section)); this.sections.set(settings.sections); this.environments.set(environments.items); this.storage.set(storage);
    } catch (error) { this.error.set(errorMessage(error)); } finally { this.loading.set(false); }
  }
  async refreshStorage(): Promise<void> {
    if (this.loading() || this.saving() || this.storageBusy()) return;
    this.storageBusy.set(true); this.error.set('');
    try { this.storage.set(await this.api.get<StorageStatus>('/admin/storage')); } catch (error) { this.error.set(errorMessage(error)); } finally { this.storageBusy.set(false); }
  }
  async saveSection(section: SettingSection): Promise<void> {
    if (this.loading() || this.saving()) return;
    const values: Record<string, string> = {};
    for (const setting of section.settings) {
      const value = this.values[section.name][setting.key];
      if (setting.secret) { if (this.cleared[section.name][setting.key]) values[setting.key] = ''; else if (value !== '') values[setting.key] = value; }
      else if (value !== setting.value) values[setting.key] = value;
    }
    if (Object.keys(values).length === 0) { this.changed.delete(section.name); this.dirty(); return; }
    this.saving.set(true); this.error.set(''); this.success.set('');
    try {
      const saved = await this.api.patch<SettingSection>(`/admin/settings/${encodeURIComponent(section.name)}`, { revision: section.revision, values });
      this.populate(saved); this.sections.update(items => items.map(item => item.name === saved.name ? saved : item)); this.changed.delete(section.name); this.dirty(); this.success.set(`${this.title(section.name)} settings saved.`);
    } catch (error) { this.error.set(errorMessage(error)); } finally { this.saving.set(false); }
  }
  async addEnvironment(): Promise<void> {
    if (this.loading() || this.saving() || !this.environmentName.trim()) return;
    this.saving.set(true); this.error.set(''); this.success.set('');
    try {
      const saved = await this.api.post<{items: CemEnvironment[]}>('/admin/environments', { name: this.environmentName.trim() });
      this.environments.set(saved.items); this.environmentName = ''; this.environmentDirty = false; this.dirty(); this.success.set('The CEM environment was added.');
    } catch (error) { this.error.set(errorMessage(error)); } finally { this.saving.set(false); }
  }
  async removeEnvironment(environment: CemEnvironment): Promise<void> {
    if (this.loading() || this.saving() || !window.confirm(`Remove the CEM environment “${environment.name}”?`)) return;
    this.saving.set(true); this.error.set(''); this.success.set('');
    try { this.environments.set((await this.api.delete<{items: CemEnvironment[]}>(`/admin/environments/${encodeURIComponent(environment.name)}`)).items); this.success.set('The CEM environment was removed.'); }
    catch (error) { this.error.set(errorMessage(error)); } finally { this.saving.set(false); }
  }
}
