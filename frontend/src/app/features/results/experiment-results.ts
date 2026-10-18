import {
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  untracked,
} from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { Api, ApiError, errorMessage } from '../../core/api';
import {
  ResultConfiguration,
  ResultConfigurationDocument,
  PlotTypeAliases,
} from '../../core/results-models';
import { Feedback } from '../../shared/feedback';
import { domNamespace, newParameterId } from '../parameters/parameter-state';
import { ResultConfigurationEditor } from './result-configuration-editor';

@Component({
  selector: 'app-experiment-results',
  imports: [Feedback, ResultConfigurationEditor],
  templateUrl: './experiment-results.html',
  styleUrl: './results-reference.scss',
})
export class ExperimentResults {
  readonly experimentId = input.required<number>();
  readonly systemId = input.required<number>();
  readonly resultId = input('');
  readonly disabled = input(false);
  readonly configurationId = input('');
  readonly initialScope = input<'all' | 'job' | ''>('');
  readonly resultIdChange = output<string>();
  readonly dirtyChange = output<boolean>();
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute, { optional: true });
  private readonly queryConfiguration = signal('');
  private readonly queryScope = signal<'all' | 'job' | ''>('');
  private readonly queryTab = signal('');
  readonly requestedConfiguration = computed(
    () => this.configurationId() || this.queryConfiguration(),
  );
  readonly requestedScope = computed(() => this.initialScope() || this.queryScope());
  readonly namespace = domNamespace();
  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly loaded = signal(false);
  readonly dirty = signal(false);
  readonly valid = signal(true);
  readonly error = signal('');
  readonly success = signal('');
  readonly selection = signal('');
  readonly synchronizationFailed = signal(false);
  readonly aliases = signal<PlotTypeAliases>({});
  readonly saved = signal<ResultConfigurationDocument>({ version: '1.0', elements: {} });
  readonly editable = signal<ResultConfigurationDocument>({ version: '1.0', elements: {} });
  readonly prefix = computed(() => `experiment-${this.experimentId()}`);
  readonly savedEntries = computed(() => Object.entries(this.saved().elements));
  readonly systemEntries = computed(() =>
    this.savedEntries().filter(([id]) => id.startsWith('system-')),
  );
  readonly editorOpen = signal(false);
  readonly editingId = signal('');
  readonly editingScope = signal<'all' | 'job' | ''>('');
  editConfiguration(id: string, scope: 'all' | 'job'): void {
    this.editingId.set(id);
    this.editingScope.set(scope);
    this.editorOpen.set(true);
  }
  private generation = 0;
  constructor() {
    effect(() => {
      const id = this.experimentId();
      untracked(() => {
        void this.load(id);
      });
    });
    const querySubscription = this.route?.queryParamMap.subscribe((query) => {
      this.queryConfiguration.set(query.get('configuration') ?? '');
      this.queryTab.set(query.get('tab') ?? '');
      const scope = query.get('scope');
      this.queryScope.set(scope === 'all' || scope === 'job' ? scope : '');
    });
    effect(() => {
      this.experimentId();
      const configuration = this.requestedConfiguration();
      const scope = this.requestedScope();
      const tab = this.queryTab();
      // Navigation replaces the local row selection, never the unsaved document.
      // Keep native disclosure toggles independent of a persistent query or draft.
      untracked(() => {
        this.editingId.set('');
        this.editingScope.set('');
        if (configuration || scope || tab === 'results') this.editorOpen.set(true);
      });
    });
    inject(DestroyRef).onDestroy(() => {
      querySubscription?.unsubscribe();
      ++this.generation;
    });
    effect(() => {
      this.selection.set(this.resultId());
    });
    effect(() => {
      this.dirtyChange.emit(this.dirty());
    });
  }
  async load(id = this.experimentId()): Promise<void> {
    const generation = ++this.generation;
    this.loading.set(true);
    this.error.set('');
    try {
      const [value, registry] = await Promise.all([
        this.api.get<ResultConfigurationDocument>(`/experiments/${id}/result-configurations`),
        this.api
          .get<{ aliases?: { plots?: PlotTypeAliases } }>(`/systems/${this.systemId()}/extensions`)
          .catch(() => null),
      ]);
      if (generation !== this.generation) return;
      this.aliases.set(registry?.aliases?.plots ?? {});
      this.saved.set(value);
      this.resetDraft();
      this.loaded.set(true);
    } catch (error) {
      if (generation === this.generation) this.error.set(errorMessage(error));
    } finally {
      if (generation === this.generation) this.loading.set(false);
    }
  }
  private resetDraft(): void {
    this.editable.set({
      ...structuredClone(this.saved()),
      elements: Object.fromEntries(
        Object.entries(this.saved().elements).filter(([id]) => id.startsWith(`${this.prefix()}-`)),
      ),
    });
    this.dirty.set(false);
  }
  update(value: ResultConfigurationDocument): void {
    this.editable.set(value);
    this.dirty.set(true);
    this.editorOpen.set(true);
  }
  copySystem(configuration: ResultConfiguration): void {
    if (this.disabled() || this.saving()) return;
    const id = newParameterId(this.prefix()).slice(0, 50);
    this.update({
      ...this.editable(),
      elements: {
        ...this.editable().elements,
        [id]: { ...structuredClone(configuration), name: `${configuration.name} (copy)` },
      },
    });
    this.editingId.set(id);
    this.editorOpen.set(true);
  }
  async save(): Promise<void> {
    if (this.disabled() || this.saving() || !this.valid()) return;
    this.saving.set(true);
    this.error.set('');
    this.success.set('');
    try {
      const document = {
        ...this.editable(),
        elements: { ...Object.fromEntries(this.systemEntries()), ...this.editable().elements },
      };
      this.saved.set(
        await this.api.request<ResultConfigurationDocument>(
          'PUT',
          `/experiments/${this.experimentId()}/result-configurations`,
          document,
        ),
      );
      this.resetDraft();
      this.synchronizationFailed.set(false);
      this.success.set('Experiment result configurations saved.');
    } catch (error) {
      if (error instanceof ApiError && error.code === 'repository_sync_failed') {
        this.synchronizationFailed.set(true);
        await this.load();
      }
      this.error.set(errorMessage(error));
    } finally {
      this.saving.set(false);
    }
  }
  async retrySynchronization(): Promise<void> {
    if (this.disabled() || this.saving()) return;
    this.saving.set(true);
    this.error.set('');
    this.success.set('');
    try {
      await this.api.post(`/experiments/${this.experimentId()}/result-configurations/push`, {});
      this.synchronizationFailed.set(false);
      this.success.set('Committed experiment result configuration synchronized.');
    } catch (error) {
      this.error.set(errorMessage(error));
    } finally {
      this.saving.set(false);
    }
  }
  async select(): Promise<void> {
    if (this.disabled() || this.saving() || !this.selection()) return;
    this.saving.set(true);
    this.error.set('');
    this.success.set('');
    try {
      await this.api.patch(`/experiments/${this.experimentId()}`, { resultId: this.selection() });
      this.resultIdChange.emit(this.selection());
      this.success.set('The selected result configuration is now used by this experiment.');
    } catch (error) {
      this.error.set(errorMessage(error));
    } finally {
      this.saving.set(false);
    }
  }
  discard(): void {
    if (!this.disabled() && !this.saving()) this.resetDraft();
  }
}
