import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';
@Component({ imports: [RouterLink], template: `<div class="reference-error-screen"><div class="reference-error-page"><p class="error-code" aria-hidden="true">403</p><div><h1>Access denied</h1><p>Your account does not have permission to open this page.</p><a class="btn btn-primary" routerLink="/">Return to overview</a></div></div></div>`, styleUrl: './reference-error.scss' })
export class Forbidden {}
