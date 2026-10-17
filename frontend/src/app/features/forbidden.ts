import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';
@Component({ imports: [RouterLink], template: `<div class="empty-state"><p class="eyebrow">403</p><h1>Access denied</h1><p>Your account does not have permission to open this page.</p><a class="btn btn-primary" routerLink="/">Return to overview</a></div>` })
export class Forbidden {}
