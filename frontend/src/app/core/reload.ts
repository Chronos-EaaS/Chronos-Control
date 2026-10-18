import { Injectable } from '@angular/core';

@Injectable({ providedIn: 'root' })
export class ReloadPage {
  latest(buildId: string | null): void {
    const url = new URL(window.location.href);
    if (buildId) url.searchParams.set('_ui', buildId);
    window.location.replace(url.href);
  }
}
