export interface BenchmarkContract {
  parameters: Record<string, { type: string; default?: string | number | boolean }>;
  measurement: string;
  lifecycle: { warmUp: 'required' | 'optional' | 'disabled' };
  results: Record<string, { type: string; unit?: string }>;
}

export interface Benchmark {
  id: number;
  key: string;
  version: string;
  name: string;
  contract: BenchmarkContract;
}

export interface BenchmarkBinding {
  id: number;
  version: string;
  systemId: number;
  systemName: string;
  benchmark: Benchmark;
  definition: { systemVersion: string; adapter: string };
}

export interface BenchmarkCatalog {
  page: number;
  pageSize: number;
  nextPage: number | null;
  benchmarks: Benchmark[];
  bindings: BenchmarkBinding[];
  systems: { id: number; name: string }[];
}

export interface ProjectComparison {
  id: number;
  name: string;
  evaluations: {
    id: number;
    name: string;
    systemName: string;
    experimentName: string;
    planned: number;
    finished: number;
  }[];
}
