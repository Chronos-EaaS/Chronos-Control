import { describe, expect, it } from 'vitest';
import { dateTime } from './time';

describe('German date and time display in Europe/Zurich', () => {
  it('formats winter and summer offsets explicitly', () => {
    expect(dateTime('2026-01-15T11:05:06Z', true)).toBe('15.01.2026 12:05:06');
    expect(dateTime('2026-07-15T11:05:06Z')).toBe('15.07.2026 13:05');
  });
  it('handles the spring DST gap without inventing a local 02:30', () => {
    expect(dateTime('2026-03-29T00:30:00Z')).toBe('29.03.2026 01:30');
    expect(dateTime('2026-03-29T01:30:00Z')).toBe('29.03.2026 03:30');
  });
  it('preserves both explicit instants in the autumn repeated hour', () => {
    expect(dateTime('2026-10-25T02:30:00+02:00')).toBe('25.10.2026 02:30');
    expect(dateTime('2026-10-25T02:30:00+01:00')).toBe('25.10.2026 02:30');
  });
  it('does not invent a date for missing or invalid input', () => {
    expect(dateTime(null)).toBe('—');
    expect(dateTime('not a timestamp')).toBe('—');
  });
});
