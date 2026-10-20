// Unit-test transport adapter. Runtime code must never import this file.
import { Provider } from '@angular/core';
import { Api, ApiError } from './api';
import { LiveSubscription, Realtime } from './realtime';

type Query = Record<string, string | number | boolean>;
interface Listener { path: string; query: Query; apply: (data: unknown) => void; active: boolean; }

export class RealtimeMock {
  readonly listeners = new Set<Listener>();
  constructor(private readonly api: { get: unknown }) {}
  read<T>(path: string, query: Query = {}): Promise<T> { return (this.api.get as Api['get'])<T>(path, query); }
  watch<T>(path: string, query: Query, apply: (data: T) => void, failed?: (error: ApiError) => void): LiveSubscription<T> {
    const listener: Listener = { path, query: { ...query }, apply: value => apply(value as T), active: true };
    this.listeners.add(listener);
    let sequence = 0;
    const load = async (): Promise<T> => {
      const current = ++sequence;
      try {
        const value = await this.read<T>(path, listener.query);
        if (listener.active && current === sequence) apply(value);
        return value;
      } catch (error) {
        if (listener.active && current === sequence) failed?.(error as ApiError);
        throw error;
      }
    };
    return {
      ready: load(), refresh: load,
      update: next => { listener.query = { ...next }; return load(); },
      checkpoint: next => { listener.query = { ...next }; },
      close: () => { listener.active = false; ++sequence; this.listeners.delete(listener); },
    };
  }
  push(path: string, data: unknown): void {
    for (const listener of this.listeners) if (listener.active && listener.path === path) listener.apply(data);
  }
}

export function provideRealtimeMock(api: { get: unknown }): Provider {
  return { provide: Realtime, useFactory: () => new RealtimeMock(api) };
}
