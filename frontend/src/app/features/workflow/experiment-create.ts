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
  template: `<nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a routerLink="/projects">Projects</a></li>@if (schema(); as options) { <li class="breadcrumb-item"><a [routerLink]="['/projects', options.project.id]">{{ options.project.name }}</a></li> }<li class="breadcrumb-item active" aria-current="page">{{ copyId ? 'Copy experiment' : 'Create experiment' }}</li></ol></nav><div class="page-heading"><div><span class="eyebrow">EXPERIMENT CONFIGURATION</span><h1>{{ copyId ? 'Copy experiment' : 'Create experiment' }}</h1><p class="text-secondary">Configure the parameters and repeated runs for your evaluation.</p></div></div><app-feedback [busy]="busy()" [error]="error()" />
    @if (schema(); as options) { <form #form="ngForm" (ngSubmit)="create()"><fieldset [disabled]="saving()"><section class="card mb-4"><div class="card-header"><h2 class="card-title">Experiment details</h2></div><div class="card-body"><div class="row g-3"><div class="col-md-6"><label for="experiment-name" class="form-label">Name</label><input id="experiment-name" class="form-control" name="name" [(ngModel)]="name" (ngModelChange)="changed()" required maxlength="50" /></div><div class="col-md-6"><label for="experiment-description" class="form-label">Description</label><textarea id="experiment-description" class="form-control" name="description" [(ngModel)]="description" (ngModelChange)="changed()" rows="2"></textarea></div><div class="col-md-4"><label for="experiment-runs" class="form-label">Repeated runs</label><input id="experiment-runs" type="number" class="form-control" name="runs" [(ngModel)]="runs" (ngModelChange)="changed()" min="1" [max]="options.maxJobs" step="1" required /></div><div class="col-md-4"><label for="experiment-distribution" class="form-label">Run distribution</label><select id="experiment-distribution" name="distribution" class="form-select" [(ngModel)]="distribution" (ngModelChange)="changed()"><option value="alter">Alternate configurations</option><option value="order">Sequential configurations</option><option value="rand">Randomize configurations</option></select></div><div class="col-md-4 d-flex align-items-end"><div class="form-check pb-2"><input id="experiment-warm-up" class="form-check-input" type="checkbox" name="warmUp" [(ngModel)]="warmUp" (ngModelChange)="changed()" /><label for="experiment-warm-up" class="form-check-label">Include warm-up phase</label></div></div></div><p class="small text-secondary mt-3 mb-0">The server validates the complete configuration and limits each evaluation to {{ options.maxJobs }} jobs.</p></div></section>
    <app-parameter-presets [projectId]="options.project.id" [values]="parameters.draft()" [valid]="valid()" (valuesChange)="values.set($event); changed()" /><app-experiment-parameters #parameters [aliases]="parameterAliases()" [groups]="options.groups" [values]="values()" (valuesChange)="values.set($event); changed()" [maxVersions]="options.maxJobs" [disabled]="saving()" (validity)="valid.set($event)" />
    <div class="d-flex flex-wrap justify-content-end gap-2 mb-4"><a class="btn btn-light" [routerLink]="['/projects', options.project.id]">Cancel</a><button class="btn btn-primary" type="submit" [disabled]="form.invalid || !valid() || saving()">{{ saving() ? 'Creating…' : 'Create experiment' }}</button></div></fieldset></form> }`,
})
export class ExperimentCreate {
  private readonly api = inject(Api); private readonly route = inject(ActivatedRoute); private readonly router = inject(Router);
  readonly changes = inject(UnsavedChanges);
  @ViewChild('parameters') private parameters?: ExperimentParameters;
  readonly schema = signal<ExperimentSchema | null>(null); readonly values = signal<ParameterValues>({}); readonly valid = signal(false);
  readonly parameterAliases = signal<ParameterTypeAliases>({});
  readonly busy = signal(true); readonly saving = signal(false); readonly error = signal('');
  name = ''; description = ''; runs = 1; distribution = 'alter'; warmUp = true; copyId = 0; private projectId = 0; private generation = 0;
  constructor() {
    inject(DestroyRef).onDestroy(() => { ++this.generation; this.changes.dirty.set(false); });
    combineLatest([this.route.paramMap, this.route.queryParamMap]).pipe(
      map(([params, query]) => ({ projectId: Number(params.get('id')), copyId: Number(query.get('copy')) || 0 })),
      distinctUntilChanged((previous, current) => previous.projectId === current.projectId && previous.copyId === current.copyId),
      takeUntilDestroyed(),
    ).subscribe(({ projectId, copyId }) => { this.projectId = projectId; this.copyId = copyId; void this.load(); });
  }
  changed(): void { this.changes.dirty.set(true); }
  async load(): Promise<void> {
    const generation = ++this.generation, projectId = this.projectId, copyId = this.copyId;
    this.busy.set(true); this.error.set(''); this.schema.set(null); this.valid.set(false);
    try {
      const schema = await this.api.get<ExperimentSchema>(`/projects/${projectId}/experiment-options`, copyId ? { copy: copyId } : {});
      if (generation !== this.generation) return;
      const extensions = await this.api.get<{ aliases?: { parameters?: ParameterTypeAliases } }>(`/systems/${schema.project.systemId}/extensions`);
      if (generation !== this.generation) return;
      const source = copyId ? await this.api.get<Experiment>(`/experiments/${copyId}`) : null;
      if (generation !== this.generation) return;
      this.parameterAliases.set(extensions.aliases?.parameters ?? {});
      this.values.set(schema.values); this.runs = schema.defaults.runs; this.distribution = schema.defaults.distribution; this.warmUp = schema.defaults.warmUp;
      this.name = source ? `${Array.from(source.name).slice(0, 43).join('')} (copy)` : ''; this.description = source?.description ?? '';
      this.changes.dirty.set(false);
      // Publish the editable form only after every copied value is final. Its
      // inputs must never race a later default/metadata assignment.
      this.schema.set(schema);
    } catch (error) { if (generation === this.generation) this.error.set(errorMessage(error)); } finally { if (generation === this.generation) this.busy.set(false); }
  }
  async create(): Promise<void> {
    if (this.busy() || this.saving() || !this.valid() || !this.parameters || !this.schema()) return;
    this.saving.set(true); this.error.set('');
    try {
      const experiment = await this.api.post<Experiment>(`/projects/${this.projectId}/experiments`, { name: this.name, description: this.description, runs: this.runs, distribution: this.distribution, warmUp: this.warmUp, environment: this.schema()!.defaults.environment, parameters: this.parameters.draft(), ...(this.copyId ? { sourceExperimentId: this.copyId } : {}) });
      this.changes.dirty.set(false); await this.router.navigate(['/experiments', experiment.id]);
    } catch (error) { this.error.set(errorMessage(error)); } finally { this.saving.set(false); }
  }
}
