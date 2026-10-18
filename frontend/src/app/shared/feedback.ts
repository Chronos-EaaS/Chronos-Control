import { Component, input } from '@angular/core';

@Component({
  selector: 'app-feedback',
  template: `@if (error()) { <div class="alert alert-danger" role="alert">{{ error() }}</div> }
    @if (success()) { <div class="alert alert-success" role="status">{{ success() }}</div> }
    @if (busy() !== undefined) { <div class="loading-state" [style.visibility]="busy() ? 'visible' : 'hidden'" [attr.role]="busy() ? 'status' : null" [attr.aria-hidden]="busy() ? null : 'true'"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> {{ label() }}</div> }`,
  styles: `.loading-state { min-height: 1.5rem; margin-block: .25rem; padding: 0; }`,
})
export class Feedback {
  readonly error = input(''); readonly success = input(''); readonly busy = input<boolean>(); readonly label = input('Loading…');
}
