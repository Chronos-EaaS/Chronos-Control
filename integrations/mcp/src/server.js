import { McpServer } from '@modelcontextprotocol/server';
import * as z from 'zod/v4';
import { ChronosApi, ChronosError } from './api.js';
import { bindingDefinition, contract, explicitJobs, planningMode, revision } from './benchmark-schema.js';
import { exportAttemptEvidence, exportEvidence } from './export.js';

const id = z.number().int().min(1).max(2_147_483_647);
const pageNumber = z.number().int().min(1).max(1_000_000);
const pageSize = z.number().int().min(1).max(100);
const paging = { page: pageNumber.default(1), pageSize: pageSize.default(20) };
const search = z.string().max(250).optional();
const discovery = {
  ...paging,
  search,
  archived: z.enum(['false', 'true', 'all']).optional().describe('Active, archived, or both. Defaults to active.'),
};

const readOnly = { readOnlyHint: true, openWorldHint: true };
const create = { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true };
const abort = { ...create, destructiveHint: true };

const responsePage = z.object({
  total: z.number().int().nonnegative(),
  page: pageNumber,
  pageSize,
});
const scalar = z.union([z.string().max(16_384), z.number(), z.boolean(), z.null()]);
const parameterValues = z.record(z.string().max(256), z.union([scalar, z.array(scalar)]));
const versionOverrides = z.record(z.string().max(256), z.array(z.string().min(1).max(256)).min(1).max(1000));
const evidenceScope = z.enum(['evaluation', 'job']);
const evaluationFields = {
  environment: z.string().trim().min(1).max(100).describe('Use a key returned by get_run_options.'),
  name: z.string().trim().min(1).max(250).optional(),
  description: z.string().max(65_535).optional(),
};

function unsupportedBenchmarks() {
  return new ChronosError('unsupported_capability', 'This Chronos instance does not provide versioned benchmark workflows.', {
    status: 404, capability: 'benchmarkWorkflows',
  });
}

function continuation(value) {
  const parsed = responsePage.safeParse(value);
  if (!parsed.success) {
    throw new ChronosError('invalid_response', 'Chronos did not return valid pagination metadata.');
  }
  const { page, pageSize, total } = parsed.data;
  return page < 1_000_000 && page * pageSize < total ? page + 1 : null;
}

function toolResult(data, isError = false) {
  return {
    content: [{ type: 'text', text: JSON.stringify(data) }],
    structuredContent: data,
    ...(isError && { isError }),
  };
}

export function createServer(config) {
  const api = new ChronosApi(config);
  const server = new McpServer({ name: 'chronos', version: '0.1.0' });
  let benchmarkSupported = false;

  function tool(name, description, shape, handler, annotations = readOnly) {
    server.registerTool(`chronos_${name}`, { description, inputSchema: z.strictObject(shape), annotations },
      async (args, { mcpReq }) => {
        try {
          return toolResult(await handler(args, mcpReq.signal));
        } catch (error) {
          mcpReq.signal.throwIfAborted();
          const detail = error instanceof ChronosError ? error.result : {
            code: 'internal_error', message: 'The Chronos tool could not complete the request.',
          };
          return toolResult({ error: detail }, true);
        }
      });
  }

  async function list(path, query, signal) {
    const data = await api.get(path, { ...query, sort: 'id' }, signal);
    if (!Array.isArray(data.items)) {
      throw new ChronosError('invalid_response', 'Chronos did not return a list of items.');
    }
    return { ...data, nextPage: continuation(data) };
  }

  async function benchmarkCatalog(signal) {
    const data = await api.get('/benchmarks', {}, signal).catch(error => {
      if (error.result?.status === 404) throw unsupportedBenchmarks();
      throw error;
    });
    if (![data.benchmarks, data.bindings, data.systems].every(Array.isArray)) {
      throw new ChronosError('invalid_response', 'Chronos did not return a benchmark catalog.');
    }
    benchmarkSupported = true;
    return data;
  }

  async function hasBenchmarks(signal) {
    if (benchmarkSupported) return true;
    try {
      await benchmarkCatalog(signal);
      return true;
    } catch (error) {
      const code = error.result?.code;
      if (code === 'unsupported_capability') return false;
      // A successful JSON response can exceed the catalog listing's size limit.
      if (code === 'response_too_large') {
        benchmarkSupported = true;
        return true;
      }
      throw error;
    }
  }

  async function withBenchmarks(signal, handler) {
    if (!await hasBenchmarks(signal)) throw unsupportedBenchmarks();
    return handler();
  }

  tool('get_capabilities', 'Check whether this Chronos instance supports versioned benchmarks, bindings, comparisons, and execution attempts.', {},
    async (_args, signal) => ({ benchmarkWorkflows: await hasBenchmarks(signal) }));
  tool('list_benchmarks', 'List published benchmark revisions, visible binding revisions, and eligible systems.', {},
    (_args, signal) => benchmarkCatalog(signal));
  tool('publish_benchmark', 'Publish an immutable benchmark revision with a measurement boundary, warm-up policy, and typed contract.', {
    key: revision, version: revision, name: z.string().trim().min(1).max(250), contract,
  }, (body, signal) => withBenchmarks(signal, () => api.post('/benchmarks', body, signal)), create);
  tool('publish_binding', 'Publish an immutable command adapter binding for a benchmark revision and a system you maintain.', {
    benchmarkRevisionId: id, systemId: id, version: revision, definition: bindingDefinition,
  }, ({ benchmarkRevisionId, ...body }, signal) => withBenchmarks(signal,
    () => api.post(`/benchmarks/${benchmarkRevisionId}/bindings`, body, signal)), create);

  tool('list_systems', 'List systems in the Chronos account’s system catalog.', discovery,
    (query, signal) => list('/systems', query, signal));
  tool('list_projects', 'List projects in the Chronos account’s project scope.', discovery,
    (query, signal) => list('/projects', query, signal));
  tool('get_project', 'Get a project, including the system it uses.', { projectId: id },
    ({ projectId }, signal) => api.get(`/projects/${projectId}`, {}, signal));
  tool('list_experiments', 'List experiments in a project.', { projectId: id, ...discovery },
    ({ projectId, ...query }, signal) => list(`/projects/${projectId}/experiments`, query, signal));
  tool('get_experiment', 'Get a saved experiment definition’s metadata.', { experimentId: id },
    ({ experimentId }, signal) => api.get(`/experiments/${experimentId}`, {}, signal));
  tool('get_experiment_options',
    'Read parameter groups, defaults, and maxJobs. Supply a binding and planning mode for a bound experiment, or sourceExperimentId to read the editable values for copying.',
    { projectId: id, sourceExperimentId: id.optional(), bindingId: id.optional(), planning: planningMode.optional() },
    ({ projectId, sourceExperimentId, bindingId, planning }, signal) => {
      const read = () => api.get(`/projects/${projectId}/experiment-options`, { copy: sourceExperimentId, bindingId, planning }, signal);
      return bindingId !== undefined || planning !== undefined ? withBenchmarks(signal, read) : read();
    });

  tool('create_experiment',
    'Save an experiment. For generated plans, use the flat values from get_experiment_options. An explicit plan requires bindingId and receives its parameters when scheduled.',
    {
      projectId: id,
      name: z.string().trim().min(1).max(50),
      description: z.string().max(65_535).optional(),
      runs: z.number().int().min(1).max(100_000).optional().describe('Runs per setting; total jobs are limited by maxJobs in the project options.'),
      distribution: z.enum(['order', 'alter', 'rand']).optional(),
      warmUp: z.boolean().optional(),
      environment: z.string().trim().min(1).max(100).optional().describe('Default Chronos environment key.'),
      parameters: parameterValues.optional().describe('Flat editable values keyed by Chronos parameter field. For a copy, supply the source values explicitly.'),
      sourceExperimentId: id.optional().describe('Reuse a compatible experiment’s parameter schema.'),
      bindingId: id.optional().describe('A published binding revision; its system is used for the experiment.'),
      planning: planningMode.optional().describe('Defaults to generated. Choose explicit to provide an ordered list when scheduling.'),
    },
    ({ projectId, ...body }, signal) => {
      const write = () => api.post(`/projects/${projectId}/experiments`, body, signal);
      return body.bindingId !== undefined || body.planning !== undefined ? withBenchmarks(signal, write) : write();
    }, create);

  tool('get_run_options', 'Read available environment keys and software version defaults for a saved experiment.',
    { experimentId: id }, ({ experimentId }, signal) => api.get(`/experiments/${experimentId}/run-options`, {}, signal));
  tool('list_evaluations', 'List evaluations of an experiment.', { experimentId: id, ...discovery },
    ({ experimentId, ...query }, signal) => list(`/experiments/${experimentId}/evaluations`, query, signal));
  tool('start_evaluation',
    'Schedule jobs from a generated experiment plan. Each successful call creates a new evaluation.',
    {
      experimentId: id,
      ...evaluationFields,
      versions: versionOverrides.optional().describe('Override lists of versions by software parameter name from get_run_options.'),
    },
    ({ experimentId, ...body }, signal) => api.post(`/experiments/${experimentId}/evaluations`, body, signal), create);
  tool('start_explicit_evaluation',
    'Schedule an ordered list of repetitions for a bound experiment. Each entry becomes a distinct job, including repeated parameter sets.', {
      experimentId: id, ...evaluationFields,
      jobs: explicitJobs.describe('One parameters object per repetition, using the benchmark contract and the configured job limit.'),
    }, ({ experimentId, ...body }, signal) => withBenchmarks(signal,
      () => api.post(`/experiments/${experimentId}/evaluations`, body, signal)), create);
  tool('list_comparisons', 'List saved comparisons in a project.', { projectId: id },
    ({ projectId }, signal) => withBenchmarks(signal, async () => {
      const data = await api.get(`/projects/${projectId}/comparisons`, {}, signal);
      if (!Array.isArray(data.items)) throw new ChronosError('invalid_response', 'Chronos did not return comparison items.');
      return data;
    }));
  tool('create_comparison', 'Save a comparison of two to fifty distinct evaluations from the same project.', {
    projectId: id,
    name: z.string().trim().min(1).max(250),
    evaluationIds: z.array(id).min(2).max(50).refine(ids => new Set(ids).size === ids.length, 'Use distinct evaluations.'),
  }, ({ projectId, ...body }, signal) => withBenchmarks(signal,
    () => api.post(`/projects/${projectId}/comparisons`, body, signal)), create);

  tool('get_evaluation', 'Get current evaluation progress and job counts, including active, failed, finished and aborted jobs.',
    { evaluationId: id }, ({ evaluationId }, signal) => api.get(`/evaluations/${evaluationId}`, {}, signal));
  tool('list_jobs', 'List the jobs of an evaluation with status and progress.',
    {
      evaluationId: id, ...paging,
      status: z.enum(['all', 'active', 'failed', 'aborted', 'scheduled', 'setup', 'running', 'finished']).optional(),
    },
    ({ evaluationId, ...query }, signal) => list(`/evaluations/${evaluationId}/jobs`, { ...query, view: 'summary' }, signal));
  tool('get_job', 'Get current job status and recorded configuration.', { jobId: id },
    ({ jobId }, signal) => api.get(`/jobs/${jobId}`, {}, signal));
  tool('get_job_execution', 'Read a repetition’s frozen specification, attempt history, and selected attempt ID for standard results.',
    { jobId: id }, ({ jobId }, signal) => withBenchmarks(signal, async () => {
      const data = await api.get(`/jobs/${jobId}/execution`, {}, signal);
      if (data.repetitionId !== jobId || !Array.isArray(data.attempts) || !Object.hasOwn(data, 'specification')) {
        throw new ChronosError('invalid_response', 'Chronos did not return execution evidence for this repetition.');
      }
      return data;
    }));
  tool('abort_evaluation', 'Abort eligible jobs of an evaluation, including jobs marked failed.',
    { evaluationId: id },
    ({ evaluationId }, signal) => api.post(`/evaluations/${evaluationId}/actions`, { action: 'abort' }, signal), abort);

  tool('get_job_log',
    'Read a UTF-8 log chunk. Use nextOffset and epoch for the next read, and restart assembly when reset is true.',
    {
      jobId: id,
      offset: z.number().int().min(0).max(2_147_483_647).default(0),
      limit: z.number().int().min(1).max(65_536).default(8192).describe('Maximum chunk size in bytes, subject to UTF-8 character boundaries.'),
      epoch: z.string().regex(/^[a-f0-9]{64}$/).optional(),
    },
    ({ jobId, ...query }, signal) => api.get(`/jobs/${jobId}/log`, query, signal));

  tool('get_results', 'Read one page of raw results for an evaluation or job, including current job status and recorded parameters.',
    { scope: evidenceScope, id, ...paging, search },
    async ({ scope, id, ...query }, signal) => {
      const data = await api.get(`/${scope}s/${id}/results`, { ...query, view: 'raw', sort: 'id' }, signal);
      if (!Array.isArray(data.jobs)) {
        throw new ChronosError('invalid_response', 'Chronos did not return raw result rows.');
      }
      return { ...data, jobPage: { ...data.jobPage, nextPage: continuation(data.jobPage) } };
    });

  tool('export_evidence',
    'Save a Chronos CSV, result ZIP, or full job log to the configured directory on the MCP host. Returns the file path, byte count, and SHA-256 digest.',
    { scope: evidenceScope, id, format: z.enum(['csv', 'zip', 'log']) },
    (args, signal) => exportEvidence(api, config, args, signal), create);
  tool('export_attempt_evidence',
    'Save a specific attempt’s full log or archive to the configured directory on the MCP host. Returns the file path, byte count, and SHA-256 digest.',
    { jobId: id, attemptId: id, format: z.enum(['log', 'zip']) },
    (args, signal) => withBenchmarks(signal, () => exportAttemptEvidence(api, config, args, signal)), create);

  return server;
}
