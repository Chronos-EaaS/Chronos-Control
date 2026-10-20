import { Component, HostListener, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { VersionNotice } from './shared/version-notice';
import { UnsavedChanges } from './core/unsaved';
import { ConnectionNotice } from './shared/connection-notice';

@Component({
  imports: [RouterOutlet, VersionNotice, ConnectionNotice],
  selector: 'app-root',
  template: '<router-outlet /><app-version-notice /><app-connection-notice />',
})
export class App {
  private readonly changes = inject(UnsavedChanges);
  @HostListener('window:beforeunload', ['$event']) beforeUnload(event: BeforeUnloadEvent): void {
    if (this.changes.dirty()) { event.preventDefault(); event.returnValue = ''; }
  }
}
