import { Job } from '../../core/workflow-models';

type ProgressJob = Pick<
  Job,
  'statusName' | 'phases' | 'currentPhase' | 'progress' | 'setupRequired'
>;
type StageState = 'pending' | 'active' | 'complete' | 'failed' | 'aborted';
type ExecutionPhase = 1 | 2 | 3 | 4 | 5;

const phases = [
  { key: 'prepare', label: 'Prepare', phase: 1 },
  { key: 'warm-up', label: 'Warm-up', phase: 2 },
  { key: 'execute', label: 'Execute', phase: 3 },
  { key: 'analyze', label: 'Analyze', phase: 4 },
  { key: 'clean', label: 'Clean', phase: 5 },
] as const;

export interface JobProgressStage {
  key: 'setup' | (typeof phases)[number]['key'];
  label: string;
  phase: ExecutionPhase | null;
  value: number;
  state: StageState;
}

function percentage(value: number | null): number {
  return Number.isFinite(value) ? Math.max(0, Math.min(100, value ?? 0)) : 0;
}

/** The agent reports one stage's percentage, rather than overall job progress. */
export function jobProgressStages(job: ProgressJob | null): JobProgressStage[] {
  if (!job) return [];
  const current = job.currentPhase;
  const validPhase =
    current !== null && Number.isInteger(current) && current >= 1 && current <= phases.length;
  const value = percentage(job.progress);
  const terminal = job.statusName === 'failed' || job.statusName === 'aborted';
  const activeState: StageState =
    job.statusName === 'failed' || job.statusName === 'aborted' ? job.statusName : 'active';
  const stages: JobProgressStage[] = [];

  if (job.setupRequired) {
    let setupValue = 0;
    let state: StageState = 'pending';
    if (job.statusName === 'finished') {
      setupValue = 100;
      state = 'complete';
    } else if (job.statusName === 'setup') {
      setupValue = value;
      state = 'active';
    } else if (job.statusName !== 'scheduled' && validPhase) {
      setupValue = 100;
      state = 'complete';
    } else if (terminal && current === null) {
      // A CEM deployment may fail before the agent has entered any execution phase.
      setupValue = value;
      state = activeState;
    }
    stages.push({ key: 'setup', label: 'Setup', phase: null, value: setupValue, state });
  }

  for (const phase of phases) {
    // Backend JobPhases bits exclude stages; they are not a mask of enabled stages.
    if (job.phases & (1 << (phase.phase - 1))) continue;
    let stageValue = 0;
    let state: StageState = 'pending';
    if (job.statusName === 'finished') {
      stageValue = 100;
      state = 'complete';
    } else if (job.statusName !== 'scheduled' && job.statusName !== 'setup' && validPhase) {
      if (phase.phase < current) {
        stageValue = 100;
        state = 'complete';
      } else if (phase.phase === current) {
        stageValue = value;
        state = activeState;
      }
    }
    stages.push({ ...phase, value: stageValue, state });
  }
  return stages;
}

/** Average achieved work across enabled stages; a finished agent may leave raw progress at zero. */
export function jobOverallProgress(job: ProgressJob | null): number {
  if (!job) return 0;
  if (job.statusName === 'finished') return 100;
  const stages = jobProgressStages(job);
  return stages.length ? Math.round(stages.reduce((sum, stage) => sum + stage.value, 0) / stages.length) : 0;
}
