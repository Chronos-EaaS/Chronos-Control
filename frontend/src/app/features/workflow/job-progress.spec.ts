import { describe, expect, it } from 'vitest';
import { Job } from '../../core/workflow-models';
import { jobOverallProgress, jobProgressStages } from './job-progress';

type ProgressJob = Pick<
  Job,
  'statusName' | 'phases' | 'currentPhase' | 'progress' | 'setupRequired'
>;
const job: ProgressJob = {
  statusName: 'running',
  phases: 0,
  currentPhase: 3,
  progress: 37,
  setupRequired: true,
};

describe('JOB execution stage progress', () => {
  it('shows a CEM setup stage followed by the five ordered execution stages', () => {
    expect(jobProgressStages(job)).toEqual([
      { key: 'setup', label: 'Setup', phase: null, value: 100, state: 'complete' },
      { key: 'prepare', label: 'Prepare', phase: 1, value: 100, state: 'complete' },
      { key: 'warm-up', label: 'Warm-up', phase: 2, value: 100, state: 'complete' },
      { key: 'execute', label: 'Execute', phase: 3, value: 37, state: 'active' },
      { key: 'analyze', label: 'Analyze', phase: 4, value: 0, state: 'pending' },
      { key: 'clean', label: 'Clean', phase: 5, value: 0, state: 'pending' },
    ]);
    expect(jobProgressStages({ ...job, setupRequired: false }).map((stage) => stage.key)).toEqual([
      'prepare',
      'warm-up',
      'execute',
      'analyze',
      'clean',
    ]);
    expect(jobProgressStages(null)).toEqual([]);
  });

  it.each([
    [1, 'prepare'],
    [2, 'warm-up'],
    [4, 'execute'],
    [8, 'analyze'],
    [16, 'clean'],
  ] as const)('omits only the stage excluded by bit %i', (mask, excluded) => {
    const stages = jobProgressStages({ ...job, phases: mask });
    expect(stages).toHaveLength(5);
    expect(stages.map((stage) => stage.key)).not.toContain(excluded);
    expect(stages.find((stage) => stage.key === 'setup')?.value).toBe(100);
  });

  it('combines exclusions without shifting the agent phase numbers', () => {
    const stages = jobProgressStages({ ...job, phases: 1 | 2 | 16 });
    expect(stages.map((stage) => [stage.key, stage.value])).toEqual([
      ['setup', 100],
      ['execute', 37],
      ['analyze', 0],
    ]);
    expect(jobProgressStages({ ...job, phases: 31, setupRequired: false })).toEqual([]);
  });

  it.each([
    ['scheduled', null, 0, [0, 0, 0, 0, 0, 0]],
    ['scheduled', 3, 37, [0, 0, 0, 0, 0, 0]],
    ['setup', null, 26, [26, 0, 0, 0, 0, 0]],
    ['setup', 3, 26, [26, 0, 0, 0, 0, 0]],
    ['running', 1, 0, [100, 0, 0, 0, 0, 0]],
    ['running', 3, 37, [100, 100, 100, 37, 0, 0]],
    ['running', 4, 8, [100, 100, 100, 100, 8, 0]],
    ['finished', 5, 100, [100, 100, 100, 100, 100, 100]],
    ['finished', null, 0, [100, 100, 100, 100, 100, 100]],
    ['failed', 3, 37, [100, 100, 100, 37, 0, 0]],
    ['aborted', 3, 37, [100, 100, 100, 37, 0, 0]],
    ['failed', null, 26, [26, 0, 0, 0, 0, 0]],
    ['aborted', null, 26, [26, 0, 0, 0, 0, 0]],
  ] as const)(
    'retains measured progress for %s at phase %s',
    (statusName, currentPhase, progress, expected) => {
      expect(
        jobProgressStages({ ...job, statusName, currentPhase, progress }).map(
          (stage) => stage.value,
        ),
      ).toEqual(expected);
    },
  );

  it.each(['running', 'failed', 'aborted'] as const)(
    'distinguishes completed predecessors from the current %s stage',
    (statusName) => {
      const stages = jobProgressStages({ ...job, statusName });
      expect(stages.map((stage) => stage.state)).toEqual([
        'complete',
        'complete',
        'complete',
        statusName === 'running' ? 'active' : statusName,
        'pending',
        'pending',
      ]);
    },
  );

  it.each([
    [null, 0],
    [Number.NaN, 0],
    [Number.POSITIVE_INFINITY, 0],
    [Number.NEGATIVE_INFINITY, 0],
    [-20, 0],
    [120, 100],
    [37.5, 37.5],
  ])('bounds stage progress %s to a finite percentage', (progress, expected) => {
    expect(jobProgressStages({ ...job, progress })[3].value).toBe(expected);
    expect(
      jobProgressStages({ ...job, statusName: 'setup', currentPhase: null, progress })[0].value,
    ).toBe(expected);
  });

  it.each([null, 0, -1, 6, 1.5, Number.NaN, Number.POSITIVE_INFINITY])(
    'does not invent completed execution stages for invalid phase %s',
    (currentPhase) => {
      const stages = jobProgressStages({ ...job, currentPhase, progress: 88 });
      expect(stages.every((stage) => stage.value === 0 && stage.state === 'pending')).toBe(true);
    },
  );

  it('keeps the current stage active at 100 until the agent advances or finishes', () => {
    expect(jobProgressStages({ ...job, progress: 100 })[3]).toMatchObject({
      value: 100,
      state: 'active',
    });
  });
});


describe('evaluation job overall progress', () => {
  it.each([
    ['finished', null, 0, 0, false, 100],
    ['finished', null, 0, 31, false, 100],
    ['scheduled', 4, 80, 0, true, 0],
    ['running', 4, 0, 0, false, 60],
    ['running', 3, 40, 0, true, 57],
    ['running', 3, 50, 3, false, 17],
    ['setup', null, 30, 0, true, 5],
    ['failed', 3, 50, 0, false, 50],
    ['aborted', 3, 50, 0, false, 50],
    ['running', null, 80, 0, false, 0],
    ['running', 3, 80, 31, false, 0],
  ] as const)('shows achieved work for %s at phase %s with raw %s', (statusName, currentPhase, progress, phases, setupRequired, expected) => {
    expect(jobOverallProgress({ statusName, currentPhase, progress, phases, setupRequired })).toBe(expected);
  });
});
