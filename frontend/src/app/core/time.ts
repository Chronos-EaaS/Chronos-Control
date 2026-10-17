const date = new Intl.DateTimeFormat('de-DE', { timeZone: 'Europe/Zurich', day: '2-digit', month: '2-digit', year: 'numeric' });
const time = new Intl.DateTimeFormat('de-DE', { timeZone: 'Europe/Zurich', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
const preciseTime = new Intl.DateTimeFormat('de-DE', { timeZone: 'Europe/Zurich', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });

export function dateTime(value: string | null | undefined, seconds = false): string {
  if (!value) return '—';
  const instant = new Date(value);
  if (!Number.isFinite(instant.getTime())) return '—';
  return `${date.format(instant)} ${seconds ? preciseTime.format(instant) : time.format(instant)}`;
}

const relative = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
export function relativeTime(value: string | null | undefined, now = Date.now()): string {
  if (!value) return '—';
  const seconds = (new Date(value).getTime() - now) / 1000;
  if (!Number.isFinite(seconds)) return '—';
  for (const [unit, duration] of [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60], ['second', 1]] as const) {
    if (Math.abs(seconds) >= duration || unit === 'second') return relative.format(Math.round(seconds / duration), unit);
  }
  return '—';
}
