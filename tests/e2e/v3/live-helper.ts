import type { Page } from '@playwright/test';

export interface LiveData<T = any> {
  path: string; id: string; query: Record<string, string>; data: T; revision: number;
}
interface Subscription { id: string; path: string; query: Record<string, string>; active: boolean; }

/** Observe real browser/server traffic without replacing the application transport. */
export function observeLive(page: Page) {
  const data: LiveData[] = [];
  const subscriptions: Subscription[] = [];
  const waiting = new Set<() => void>();
  page.on('websocket', socket => {
    if (new URL(socket.url()).pathname !== '/api/ui/live') return;
    const current = new Map<string, Subscription>();
    socket.on('framesent', frame => {
      let value: any; try { value = JSON.parse(frame.payload.toString()); } catch { return; }
      if (value.type === 'subscribe') {
        const subscription = { id: value.id, path: value.path, query: value.query ?? {}, active: true };
        current.set(value.id, subscription); subscriptions.push(subscription);
      } else if (value.type === 'unsubscribe') {
        const subscription = current.get(value.id); if (subscription) subscription.active = false;
      }
      for (const check of waiting) check();
    });
    socket.on('framereceived', frame => {
      let value: any; try { value = JSON.parse(frame.payload.toString()); } catch { return; }
      const subscription = current.get(value.id);
      if (value.type === 'data' && subscription) data.push({ path: subscription.path, id: value.id, query: subscription.query, data: value.data, revision: value.revision });
      for (const check of waiting) check();
    });
    socket.on('close', () => { for (const subscription of current.values()) subscription.active = false; });
  });
  return {
    data, subscriptions,
    mark: () => data.length,
    active: (path: string) => subscriptions.filter(subscription => subscription.active && subscription.path === path),
    next<T = any>(path: string, predicate: (value: LiveData<T>) => boolean = () => true, after = 0): Promise<LiveData<T>> {
      return new Promise((resolve, reject) => {
        const timer = setTimeout(() => { waiting.delete(check); reject(new Error(`No pushed snapshot for ${path} after index ${after}.`)); }, 10_000);
        const check = () => {
          const value = data.slice(after).find(value => value.path === path && predicate(value));
          if (value) { clearTimeout(timer); waiting.delete(check); resolve(value); }
        };
        waiting.add(check); check();
      });
    },
  };
}
