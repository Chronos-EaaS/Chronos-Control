export interface LogSpan { text: string; color?: string; background?: string; bold?: boolean; italic?: boolean; underline?: boolean; }
const colors = ['#20262e', '#ef7474', '#71c693', '#e2c772', '#85b4ed', '#cc9ce1', '#7ccdd0', '#e5eaf0', '#7b8794', '#ff9b9b', '#9ae9b8', '#ffe29a', '#aad0ff', '#e3b5f5', '#a5f4f3', '#ffffff'];
function extended(code: number): string | undefined {
  if (!Number.isInteger(code) || code < 0 || code > 255) return undefined;
  if (code < 16) return colors[code];
  if (code >= 232) { const n = 8 + (code - 232) * 10; return `rgb(${n}, ${n}, ${n})`; }
  const n = code - 16; const channel = (value: number) => value === 0 ? 0 : 55 + value * 40;
  return `rgb(${channel(Math.floor(n / 36))}, ${channel(Math.floor(n / 6) % 6)}, ${channel(n % 6)})`;
}

/** SGR styles are data, never HTML. OSC links and other terminal commands are discarded. */
export function ansiSpans(source: string): LogSpan[] {
  const text = source.replace(/\r\n?/g, '\n').replace(/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\)/g, '');
  const result: LogSpan[] = []; let offset = 0; let style: Omit<LogSpan, 'text'> = {};
  const push = (value: string) => { const clean = value.replace(/[\x00-\x08\x0b-\x1f\x7f]/g, ''); if (clean) result.push({ text: clean, ...style }); };
  for (const match of text.matchAll(/\x1b\[([0-9;]*)([A-Za-z])/g)) {
    push(text.slice(offset, match.index)); offset = match.index + match[0].length;
    if (match[2] !== 'm') continue;
    const codes = match[1].split(';').map(value => Number(value || 0));
    for (let i = 0; i < codes.length; i++) {
      const code = codes[i];
      if (code === 0) style = {};
      else if (code === 1) style.bold = true;
      else if (code === 3) style.italic = true;
      else if (code === 4) style.underline = true;
      else if (code === 22) delete style.bold;
      else if (code === 23) delete style.italic;
      else if (code === 24) delete style.underline;
      else if (code === 39) delete style.color;
      else if (code === 49) delete style.background;
      else if (code >= 30 && code <= 37) style.color = colors[code - 30];
      else if (code >= 90 && code <= 97) style.color = colors[code - 90 + 8];
      else if (code >= 40 && code <= 47) style.background = colors[code - 40];
      else if (code >= 100 && code <= 107) style.background = colors[code - 100 + 8];
      else if (code === 38 || code === 48) {
        let value: string | undefined;
        if (codes[i + 1] === 5) { value = extended(codes[i + 2]); i += 2; }
        else if (codes[i + 1] === 2) { const channels = codes.slice(i + 2, i + 5); if (channels.length === 3 && channels.every(n => Number.isInteger(n) && n >= 0 && n <= 255)) value = `rgb(${channels.join(', ')})`; i += 4; }
        if (value) style[code === 38 ? 'color' : 'background'] = value;
      }
    }
  }
  push(text.slice(offset)); return result;
}
