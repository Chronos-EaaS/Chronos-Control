import { describe, expect, it } from 'vitest';
import { ansiSpans } from './ansi';
describe('safe terminal log formatting', () => {
  it('preserves text and normalizes line endings', () => { expect(ansiSpans('first\r\nsecond\rlast\n')[0].text).toBe('first\nsecond\nlast\n'); });
  it('applies colors and resets without interpreting HTML', () => { const spans = ansiSpans('\x1b[31;1m<img src=x onerror=alert(1)>\x1b[0m normal'); expect(spans[0]).toMatchObject({ text: '<img src=x onerror=alert(1)>', bold: true, color: '#ef7474' }); expect(spans[1]).toEqual({ text: ' normal' }); });
  it('supports indexed/RGB colors and drops terminal navigation and links', () => { const spans = ansiSpans('\x1b]8;;https://external.test\x07link\x1b]8;;\x07\x1b[2J\x1b[38;2;1;2;3mRGB\x1b[48;5;196mBG'); expect(spans.map(span => span.text).join('')).toBe('linkRGBBG'); expect(spans[1].color).toBe('rgb(1, 2, 3)'); expect(spans[2].background).toBe('rgb(255, 0, 0)'); });
});
