import { Component, effect, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../../core/api';
import { ParameterValues } from '../../core/parameter-models';
import { dateTime } from '../../core/time';
import { Feedback } from '../../shared/feedback';

interface Preset { id: string; name: string; values: ParameterValues; schemaHash: string; compatible: boolean; revision: string; updatedAt: string; }
interface Presets { items: Preset[]; schemaHash: string; }
@Component({
  selector: 'app-parameter-presets', imports: [FormsModule, Feedback],
  templateUrl: './parameter-presets.html',
  styleUrl: './parameter-presets.scss',
})
export class ParameterPresets {
  private readonly api = inject(Api);
  readonly projectId = input.required<number>(); readonly values = input.required<ParameterValues>(); readonly valid = input(false);
  readonly valuesChange = output<ParameterValues>(); readonly time = dateTime;
  readonly collection = signal<Presets>({ items: [], schemaHash: '' }); readonly busy = signal(false); readonly error = signal(''); readonly success = signal('');
  selectedId = ''; name = ''; private generation = 0;
  constructor() { effect(() => { void this.refresh(this.projectId()); }); }
  selected(): Preset | undefined { return this.collection().items.find(preset => preset.id === this.selectedId); }
  select(id: string): void { this.selectedId = id; this.name = this.selected()?.name || ''; this.success.set(''); this.error.set(''); }
  reload(): void { void this.refresh(this.projectId()); }
  private async refresh(projectId: number): Promise<void> {
    const generation = ++this.generation; this.busy.set(true); this.error.set('');
    try { const data = await this.api.get<Presets>(`/projects/${projectId}/parameter-presets`); if (generation === this.generation) this.collection.set(data); }
    catch (error) { if (generation === this.generation) this.error.set(errorMessage(error)); }
    finally { if (generation === this.generation) this.busy.set(false); }
  }
  load(): void {
    const preset = this.selected();
    if (!preset || !window.confirm('Replace the current parameter values with this preset? Review the fields before creating the experiment.')) return;
    this.valuesChange.emit(structuredClone(preset.values)); this.success.set('Preset loaded. Review the parameter values before creating the experiment.');
  }
  async save(overwrite: boolean): Promise<void> {
    if (this.busy() || !this.valid()) return;
    const selected = this.selected(); if (overwrite && !selected) return;
    if (overwrite && !window.confirm(`Update the shared preset “${selected!.name}”?`)) return;
    this.busy.set(true); this.error.set(''); this.success.set('');
    try {
      const body = { name: this.name, values: this.values(), schemaHash: this.collection().schemaHash, ...(overwrite ? { revision: selected!.revision } : {}) };
      const path = `/projects/${this.projectId()}/parameter-presets`;
      const saved = overwrite ? await this.api.put<Preset>(`${path}/${selected!.id}`, body) : await this.api.post<Preset>(path, body);
      this.collection.update(value => ({ ...value, items: [...value.items.filter(preset => preset.id !== saved.id), saved].sort((a, b) => a.name.localeCompare(b.name)) }));
      this.select(saved.id); this.success.set('Parameter preset saved.');
    } catch (error) { this.error.set(errorMessage(error)); }
    finally { this.busy.set(false); }
  }
  async remove(): Promise<void> {
    const preset = this.selected(); if (!preset || this.busy() || !window.confirm(`Delete the shared preset “${preset.name}”?`)) return;
    this.busy.set(true); this.error.set(''); this.success.set('');
    try {
      await this.api.request('DELETE', `/projects/${this.projectId()}/parameter-presets/${preset.id}`, { revision: preset.revision });
      this.collection.update(value => ({ ...value, items: value.items.filter(item => item.id !== preset.id) })); this.select(''); this.success.set('Parameter preset deleted.');
    } catch (error) { this.error.set(errorMessage(error)); }
    finally { this.busy.set(false); }
  }
}
