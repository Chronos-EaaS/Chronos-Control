import { Component, input } from '@angular/core';

@Component({
  selector: 'app-feedback',
  template: `@if (error()) { <div class="alert alert-danger" role="alert">{{ error() }}</div> }
    @if (success()) { <div class="alert alert-success" role="status">{{ success() }}</div> }
    @if (busy()) { <div class="loading-state" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> {{ label() }}</div> }`,
})
export class Feedback {
  readonly error = input(''); readonly success = input(''); readonly busy = input(false); readonly label = input('Loading…');
}
