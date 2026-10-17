import { Component, HostListener, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { VersionNotice } from './shared/version-notice';
import { UnsavedChanges } from './core/unsaved';

@Component({
  imports: [RouterOutlet, VersionNotice],
  selector: 'app-root',
  template: '<router-outlet /><app-version-notice />',
})
export class App {
  private readonly changes = inject(UnsavedChanges);
  @HostListener('window:beforeunload', ['$event']) beforeUnload(event: BeforeUnloadEvent): void {
    if (this.changes.dirty()) { event.preventDefault(); event.returnValue = ''; }
  }
}
