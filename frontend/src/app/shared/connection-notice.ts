import { Component, DestroyRef, ElementRef, afterRenderEffect, inject, viewChild } from '@angular/core';
import { Realtime } from '../core/realtime';

@Component({
  selector: 'app-connection-notice',
  template: `<aside #notice popover="manual" class="connection-notice shadow-lg" role="status" aria-live="polite">
    <span class="connection-notice-indicator" aria-hidden="true"></span>
    Chronos cannot be reached. Reconnecting automatically…
  </aside>`,
  styles: `
    .connection-notice { inset: auto; position: fixed; bottom: max(1rem, env(safe-area-inset-bottom)); left: 50%; transform: translateX(-50%);
      width: max-content; max-width: calc(100vw - 2rem); margin: 0; padding: .85rem 1.1rem; border: 1px solid #d9b66f;
      border-radius: .5rem; background: #fff7e7; color: #5b431b; font-size: .875rem; line-height: 1.5; }
    .connection-notice:popover-open { display: flex; align-items: center; gap: .65rem; }
    .connection-notice-indicator { flex: 0 0 .5rem; width: .5rem; height: .5rem; border-radius: 50%; background: #ad7520; }
  `,
})
export class ConnectionNotice {
  private readonly realtime = inject(Realtime);
  private readonly notice = viewChild<ElementRef<HTMLElement>>('notice');
  constructor() {
    afterRenderEffect(() => {
      const element = this.notice()?.nativeElement;
      if (!element) return;
      if (this.realtime.disconnected()) { if (!element.matches(':popover-open')) element.showPopover(); }
      else if (element.matches(':popover-open')) element.hidePopover();
    });
    // Keep the connection status above subsequently opened native result dialogs.
    const raisedDialog = (event: Event) => {
      if (event.target instanceof HTMLDialogElement && event.target.open && this.realtime.disconnected()) {
        const element = this.notice()?.nativeElement;
        element?.hidePopover(); element?.showPopover();
      }
    };
    document.addEventListener('toggle', raisedDialog, true);
    inject(DestroyRef).onDestroy(() => document.removeEventListener('toggle', raisedDialog, true));
  }
}
