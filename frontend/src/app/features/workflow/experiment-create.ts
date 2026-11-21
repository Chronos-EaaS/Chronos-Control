import { Component, DestroyRef, ViewChild, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { combineLatest, distinctUntilChanged, map } from 'rxjs';
import { Api, errorMessage } from '../../core/api';
import { ParameterValues, ParameterTypeAliases } from '../../core/parameter-models';
import { Experiment, ExperimentSchema } from '../../core/workflow-models';
import { UnsavedChanges } from '../../core/unsaved';
import { Feedback } from '../../shared/feedback';
import { ExperimentParameters } from '../parameters/experiment-parameters';
import { ParameterPresets } from '../parameters/parameter-presets';

@Component({
  imports: [FormsModule, RouterLink, Feedback, ExperimentParameters, ParameterPresets],
  templateUrl: './experiment-create.html',
  styleUrl: './workflow-reference.scss',
})
export class ExperimentCreate {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  readonly changes = inject(UnsavedChanges).scope(inject(DestroyRef));
  @ViewChild('parameters') private parameters?: ExperimentParameters;
  readonly schema = signal<ExperimentSchema | null>(null);
  readonly values = signal<ParameterValues>({});
  readonly valid = signal(false);
  readonly parameterAliases = signal<ParameterTypeAliases>({});
  readonly busy = signal(true);
  readonly saving = signal(false);
  readonly error = signal('');
  name = '';
  description = '';
  runs = 1;
  distribution = 'alter';
  warmUp = true;
  copyId = 0;
  private projectId = 0;
  private bindingId = 0;
  private planning = 'generated';
  private generation = 0;
  constructor() {
    inject(DestroyRef).onDestroy(() => {
      ++this.generation;
      this.changes.setDirty(false);
    });
    combineLatest([this.route.paramMap, this.route.queryParamMap])
      .pipe(
        map(([params, query]) => ({
          projectId: Number(params.get('id')),
          copyId: Number(query.get('copy')) || 0,
          bindingId: Number(query.get('bindingId')) || 0,
          planning: query.get('planning') || 'generated',
        })),
        distinctUntilChanged(
          (previous, current) =>
            previous.projectId === current.projectId && previous.copyId === current.copyId &&
            previous.bindingId === current.bindingId && previous.planning === current.planning,
        ),
        takeUntilDestroyed(),
      )
      .subscribe(({ projectId, copyId, bindingId, planning }) => {
        this.projectId = projectId;
        this.copyId = copyId;
        this.bindingId = bindingId;
        this.planning = planning;
        void this.load();
      });
  }
  parameterDraft(): ParameterValues {
    return this.parameters?.draft() ?? {};
  }
  changed(): void {
    this.changes.setDirty(true);
  }
  async load(): Promise<void> {
    const generation = ++this.generation,
      projectId = this.projectId,
      copyId = this.copyId;
    this.changes.reset();
    this.saving.set(false);
    this.busy.set(true);
    this.error.set('');
    this.schema.set(null);
    this.valid.set(false);
    try {
      const schema = await this.api.get<ExperimentSchema>(
        `/projects/${projectId}/experiment-options`,
        copyId ? { copy: copyId } : this.bindingId ? { bindingId: this.bindingId, planning: this.planning } : {},
      );
      if (generation !== this.generation) return;
      const extensions = schema.planning === 'explicit' || schema.parameterAliases !== undefined ? null
        : await this.api.get<{ aliases?: { parameters?: ParameterTypeAliases } }>(
            `/systems/${schema.systemId ?? schema.project.systemId}/extensions`,
          );
      if (generation !== this.generation) return;
      const source = copyId ? await this.api.get<Experiment>(`/experiments/${copyId}`) : null;
      if (generation !== this.generation) return;
      this.parameterAliases.set(schema.parameterAliases ?? extensions?.aliases?.parameters ?? {});
      this.values.set(schema.values);
      this.runs = schema.defaults.runs;
      this.distribution = schema.defaults.distribution;
      this.warmUp = schema.defaults.warmUp;
      this.name = source ? `${Array.from(source.name).slice(0, 43).join('')} (copy)` : '';
      this.description = source?.description ?? '';
      this.changes.setDirty(false);
      // Publish the editable form only after every copied value is final. Its
      // inputs must never race a later default/metadata assignment.
      this.schema.set(schema);
    } catch (error) {
      if (generation === this.generation) this.error.set(errorMessage(error));
    } finally {
      if (generation === this.generation) this.busy.set(false);
    }
  }
  async create(): Promise<void> {
    const schema = this.schema();
    const explicit = schema?.planning === 'explicit';
    if (this.busy() || this.saving() || !schema || (!explicit && (!this.valid() || !this.parameters))) return;
    const current = this.changes.checkpoint();
    this.saving.set(true);
    this.error.set('');
    try {
      const experiment = await this.api.post<Experiment>(
        `/projects/${this.projectId}/experiments`,
        {
          name: this.name,
          description: this.description,
          runs: this.runs,
          distribution: this.distribution,
          warmUp: this.warmUp,
          environment: schema.defaults.environment,
          parameters: this.parameters?.draft() ?? {},
          ...(schema.binding ? { bindingId: schema.binding.id, planning: schema.planning } : {}),
          ...(this.copyId ? { sourceExperimentId: this.copyId } : {}),
        },
      );
      if (!current()) return;
      this.changes.setDirty(false);
      await this.router.navigate(['/experiments', experiment.id]);
    } catch (error) {
      if (current()) this.error.set(errorMessage(error));
    } finally {
      if (current()) this.saving.set(false);
    }
  }
}
