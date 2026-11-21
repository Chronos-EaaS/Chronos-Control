import * as z from 'zod/v4';
import { positiveWideInteger, safeNumericInput, wideInteger } from './integer.js';

const key = z.string().regex(/^[A-Za-z_][A-Za-z0-9_.-]{0,99}$/).refine(value => value !== 'run');
const sha256 = z.string().regex(/^[a-f0-9]{64}$/);
const types = {
  string: z.string().max(16_384),
  integer: z.union([z.number().int(), wideInteger]),
  number: z.union([safeNumericInput, wideInteger]),
  boolean: z.boolean(),
};
const scalar = z.union([types.string, types.number, types.boolean]);

function map(value) {
  return z.record(key, value).refine(entries => Object.keys(entries).length <= 200, 'Use at most 200 entries.');
}

const inputSchema = z.discriminatedUnion('type', [
  z.strictObject({ type: z.literal('string'), default: types.string.optional() }),
  z.strictObject({ type: z.literal('integer'), default: types.integer.optional() }),
  z.strictObject({ type: z.literal('number'), default: types.number.optional() }),
  z.strictObject({ type: z.literal('boolean'), default: types.boolean.optional() }),
]);
const artifact = z.strictObject({
  role: key,
  uri: z.string().trim().min(1).max(2048),
  sha256,
  sizeBytes: z.union([z.number().int().nonnegative(), positiveWideInteger]),
  sourceRevision: z.string().trim().min(1).max(256).optional(),
  buildId: z.string().trim().min(1).max(256).optional(),
});

export const revision = z.string().regex(/^[A-Za-z0-9][A-Za-z0-9_.+-]{0,99}$/);
export const planningMode = z.enum(['explicit', 'generated']);
export const explicitJobs = z.array(z.strictObject({ parameters: map(scalar) })).min(1).max(100_000);
export const contract = z.strictObject({
  parameters: map(inputSchema).optional(),
  measurement: z.string().trim().min(1).max(4000),
  lifecycle: z.strictObject({ warmUp: z.enum(['required', 'optional', 'disabled']) }),
  results: map(z.strictObject({
    type: z.enum(['string', 'integer', 'number', 'boolean']),
    unit: z.string().trim().min(1).max(100).optional(),
  })).refine(entries => Object.keys(entries).length > 0, 'Declare at least one measured result.'),
});
export const bindingDefinition = z.strictObject({
  systemVersion: z.string().trim().min(1).max(100),
  adapter: z.literal('chronos-command@1'),
  parameterMap: map(key).optional(),
  constants: map(scalar).optional(),
  artifacts: z.strictObject({
    schemaVersion: z.literal(1),
    artifacts: z.array(artifact).min(1).max(30).describe('Artifact identities, including a driver role.'),
    sourceRevision: z.string().trim().min(1).max(250).optional(),
    buildId: z.string().trim().min(1).max(250).optional(),
  }),
});
