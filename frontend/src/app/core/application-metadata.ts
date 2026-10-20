import { DestroyRef, Injectable, computed, inject, signal } from '@angular/core';
import { Metadata } from './models';
import { LiveSubscription, Realtime } from './realtime';
import { Session } from './session';

/** Public application metadata shares one pushed stream across the application. */
@Injectable({ providedIn: 'root' })
export class ApplicationMetadata {
  private readonly realtime = inject(Realtime);
  private readonly session = inject(Session);
  private readonly value = signal<Metadata | null>(null);
  private subscription?: LiveSubscription<Metadata>;
  private destroyed = false;
  readonly data = this.value.asReadonly();
  readonly version = computed(() => this.value()?.applicationVersion || null);

  constructor() {
    inject(DestroyRef).onDestroy(() => { this.destroyed = true; this.subscription?.close(); });
    void this.start();
  }

  private async start(): Promise<void> {
    // The route guard shares this initial request. Bind the known identity before
    // subscribing, avoiding an anonymous socket immediately replaced after lookup.
    try { await this.session.load(); } catch { /* Public metadata remains available if identity lookup fails. */ }
    if (this.destroyed) return;
    this.subscription = this.realtime.watch<Metadata>('/meta', {}, data => this.value.set(data));
  }
}
