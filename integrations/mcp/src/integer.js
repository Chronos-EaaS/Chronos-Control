// MCP peers use ordinary JSON parsers. Tag wide integer literals before they cross
// stdio, and emit their original numeric token when sending them to Chronos.
import * as z from 'zod/v4';

const min64 = -(2n ** 63n);
const max64 = 2n ** 63n - 1n;
const safe = BigInt(Number.MAX_SAFE_INTEGER);
const decimal = z.string().max(20).regex(/^-?[1-9][0-9]{15,18}$/).refine(value => {
  if (!/^-?[1-9][0-9]{15,18}$/.test(value)) return false;
  const n = BigInt(value);
  return n >= min64 && n <= max64 && (n < -safe || n > safe);
}, 'Use a signed 64-bit integer outside the JavaScript safe range.');

export const wideInteger = z.strictObject({ $integer: decimal })
  .describe('Exact signed 64-bit integer, e.g. {"$integer":"9007199254740993"}.')
  .transform(({ $integer }) => JSON.rawJSON($integer));
export const safeNumericInput = z.number().refine(n => !Number.isInteger(n) || Number.isSafeInteger(n),
  'Use {"$integer":"…"} for exact integers outside the JavaScript safe range.');

export function parseChronosJson(text) {
  return JSON.parse(text, (_key, value, context) =>
    typeof value === 'number' && Number.isInteger(value) && !Number.isSafeInteger(value) && /^-?\d+$/.test(context.source)
      ? { $integer: context.source } : value);
}
