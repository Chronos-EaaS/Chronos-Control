import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';

@Component({
  imports: [RouterLink],
  template: `<main class="reference-error-screen">
    <img class="error-logo" src="/chronos-symbol.svg" alt="Chronos" width="72" height="72" />
    <div class="reference-error-page">
      <p class="error-code" aria-hidden="true">404</p>
      <div>
        <h1>Page not found</h1>
        <p>The requested page could not be found.</p>
        <a class="btn btn-primary" routerLink="/">Return to overview</a>
      </div>
    </div>
  </main>`,
  styleUrl: './reference-error.scss',
})
export class NotFound {}
