import { Component, DestroyRef, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Api, errorMessage } from '../../core/api';
import { BenchmarkCatalog, ProjectComparison } from '../../core/benchmark-models';
import { Project } from '../../core/models';
import { Evaluation } from '../../core/workflow-models';
import { localGridRows } from '../../shared/data-grid';
import { Feedback } from '../../shared/feedback';

@Component({
  imports: [FormsModule, RouterLink, Feedback],
  templateUrl: './project-benchmarks.html',
  styleUrl: './workflow-reference.scss',
})
export class ProjectBenchmarks {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  readonly project = signal<Project | null>(null);
  readonly catalog = signal<BenchmarkCatalog | null>(null);
  readonly comparisons = signal<ProjectComparison[]>([]);
  readonly evaluations = signal<Evaluation[]>([]);
  readonly busy = signal(true);
  readonly saving = signal(false);
  readonly error = signal('');
  benchmarkKey = '';
  benchmarkName = '';
  benchmarkVersion = '1';
  contract = JSON.stringify(
    {
      parameters: {},
      measurement: '',
      lifecycle: { warmUp: 'disabled' },
      results: { duration: { type: 'number', unit: 'ms' } },
    },
    null,
    2,
  );
  benchmarkId: number | null = null;
  systemId: number | null = null;
  bindingVersion = '1';
  bindingDefinition = JSON.stringify(
    {
      adapter: 'chronos-command@1',
      systemVersion: '',
      parameterMap: {},
      constants: {},
      artifacts: {
        schemaVersion: 1,
        artifacts: [{ role: 'driver', uri: '', sha256: '', sizeBytes: 0 }],
      },
    },
    null,
    2,
  );
  comparisonName = '';
  evaluationIds: number[] = [];
  private generation = 0;

  constructor() {
    inject(DestroyRef).onDestroy(() => {
      ++this.generation;
    });
    this.route.paramMap.pipe(takeUntilDestroyed()).subscribe((parameters) => {
      this.project.set(null);
      this.catalog.set(null);
      this.comparisons.set([]);
      this.evaluations.set([]);
      this.evaluationIds = [];
      this.saving.set(false);
      void this.load(Number(parameters.get('id')));
    });
  }

  private async load(id: number): Promise<void> {
    const generation = ++this.generation;
    this.busy.set(true);
    this.error.set('');
    try {
      const project = await this.api.get<Project>(`/projects/${id}`);
      if (generation !== this.generation) return;
      const [catalog, comparisons, evaluations] = await Promise.all([
        this.api.get<BenchmarkCatalog>('/benchmarks'),
        this.api.get<{ items: ProjectComparison[] }>(`/projects/${id}/comparisons`),
        localGridRows<Evaluation>(this.api, `/projects/${id}/evaluations`, { archived: 'all' }),
      ]);
      if (generation !== this.generation) return;
      this.project.set(project);
      this.catalog.set(catalog);
      this.comparisons.set(comparisons.items);
      this.evaluations.set(evaluations.rows);
    } catch (error) {
      if (generation === this.generation) this.error.set(errorMessage(error));
    } finally {
      if (generation === this.generation) this.busy.set(false);
    }
  }

  publishBenchmark(): Promise<void> {
    return this.submit(async () => {
      const benchmark = await this.api.post<{ id: number }>('/benchmarks', {
        key: this.benchmarkKey,
        name: this.benchmarkName,
        version: this.benchmarkVersion,
        contract: this.object(this.contract, 'benchmark contract'),
      });
      return () => {
        this.benchmarkId = benchmark.id;
        this.benchmarkName = '';
      };
    });
  }

  publishBinding(): Promise<void> {
    return this.submit(async () => {
      await this.api.post(`/benchmarks/${this.benchmarkId}/bindings`, {
        systemId: this.systemId,
        version: this.bindingVersion,
        definition: this.object(this.bindingDefinition, 'binding definition'),
      });
    });
  }

  createComparison(): Promise<void> {
    return this.submit(async (project) => {
      await this.api.post(`/projects/${project.id}/comparisons`, {
        name: this.comparisonName,
        evaluationIds: this.evaluationIds,
      });
      return () => {
        this.comparisonName = '';
        this.evaluationIds = [];
      };
    });
  }

  private object(input: string, label: string): Record<string, unknown> {
    try {
      const value: unknown = JSON.parse(input);
      if (value && typeof value === 'object' && !Array.isArray(value))
        return value as Record<string, unknown>;
    } catch {
      // Surface a field-specific error below.
    }
    throw new Error(`Enter a valid JSON object for the ${label}.`);
  }

  private async submit(write: (project: Project) => Promise<void | (() => void)>): Promise<void> {
    const project = this.project();
    if (!project || this.saving()) return;
    const generation = this.generation;
    this.saving.set(true);
    this.error.set('');
    try {
      const apply = await write(project);
      if (generation === this.generation) {
        apply?.();
        this.saving.set(false);
        await this.load(project.id);
      }
    } catch (error) {
      if (generation === this.generation) {
        this.error.set(errorMessage(error));
        this.saving.set(false);
      }
    }
  }
}
