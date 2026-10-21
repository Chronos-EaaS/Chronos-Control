import { Permissions, Project, Activity } from './models';
import { ParameterGroup, ParameterValues } from './parameter-models';

export interface Member { id: number; username: string; firstName: string; lastName: string; email: string; }
export interface Experiment {
  id: number; internalId: number; name: string; description: string; projectId: number; projectName: string;
  systemId: number; systemName: string; archived: boolean; createdAt: string | null; resultId: string;
  defaultEnvironment: string; permissions: Permissions;
}
export type JobStatus = 'scheduled' | 'setup' | 'running' | 'failed' | 'finished' | 'aborted';
export interface Evaluation {
  id: number; internalId: number; name: string; description: string; experimentId: number; experimentInternalId?: number; experimentName: string;
  projectId: number; projectName: string; systemId: number; systemName: string; archived: boolean; starred: boolean;
  counts: { total: number; finished: number; failed: number; active: number; aborted: number }; progress: number; resultId: string; environment?: string; environments?: string[]; permissions: Permissions & { canAbort?: boolean; canReschedule?: boolean; canDownload?: boolean };
}
export interface Job {
  id: number; internalId: number; name: string; description: string; evaluationId: number; evaluationInternalId?: number; evaluationName: string;
  experimentId: number; experimentInternalId?: number; experimentName: string; projectId: number; projectName: string; systemId: number; systemName?: string;
  status: number; statusName: JobStatus; phases: number; currentPhase: number | null; progress: number | null; client: string; clientStatus: string; lastContactAt: string | null; setupRequired: boolean; owner: Pick<Member, 'id' | 'username' | 'firstName' | 'lastName'>; previousId: number | null; nextId: number | null; createdAt: string | null;
  startedAt: string | null; finishedAt: string | null; updatedAt: string | null; configuration: Record<string, unknown>; environment: string; result: Record<string, unknown>; permissions: Permissions & { canAbort?: boolean; canReschedule?: boolean; canDownload?: boolean };
}
/** Small live row used by evaluations; full configuration/results remain on job detail endpoints. */
export type EvaluationJob = Pick<Job,
  'id' | 'internalId' | 'evaluationId' | 'description' | 'status' | 'statusName' | 'phases' |
  'currentPhase' | 'progress' | 'setupRequired'> & { permissions: { canDownload: boolean } };

export interface RunOptions {
  environments: { key: string; name: string; type: string; nodeCount?: number }[];
  defaultEnvironment: string;
  versions: { parameter: string; label: string; values: string[] }[];
}
export interface ExperimentSchema {
  project: Project; groups: ParameterGroup[]; values: ParameterValues;
  defaults: { runs: number; distribution: string; warmUp: boolean; environment: string };
  maxJobs: number;
}
export interface ProjectActivity { events: Activity[]; }
