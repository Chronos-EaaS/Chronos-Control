// Keep the original integer token while editing JSON, and render exact decimal
// text in views. Ordinary JSON.parse otherwise rounds before the UI sees it.
export function parseExactJson(input: string, mode: 'display' | 'edit' = 'display'): unknown {
  return JSON.parse(input, (_key: string, value: unknown, context?: { source?: string }) => {
    if (typeof value !== 'number' || !Number.isInteger(value) || Number.isSafeInteger(value)) return value;
    if (!context?.source) throw new Error('This browser cannot read large JSON numbers exactly.');
    if (!/^-?\d+$/.test(context.source)) return value;
    if (mode === 'display') return context.source;
    const rawJson = (JSON as JSON & { rawJSON?: (token: string) => unknown }).rawJSON;
    if (!rawJson) throw new Error('This browser cannot submit large JSON integers exactly.');
    return rawJson(context.source);
  });
}
