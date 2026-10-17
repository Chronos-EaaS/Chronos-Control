import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';

@Component({ imports: [RouterLink], template: `<main class="empty-state"><p class="eyebrow">404</p><h1>Page not found</h1><p>The requested page could not be found.</p><a class="btn btn-primary" routerLink="/">Return to overview</a></main>` })
export class NotFound {}
