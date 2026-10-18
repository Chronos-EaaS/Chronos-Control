import { Component, effect, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api';
import { Feedback } from '../../shared/feedback';

export interface ExtensionPageSummary { id: string; title: string; description: string; access: 'owner' | 'members'; status: 'ready' | 'blocked'; message: string | null; }
export interface ExtensionCatalog { pages: ExtensionPageSummary[]; diagnostics: { code: string; kind: string; type?: string; message: string }[]; }

@Component({
  selector: 'app-system-extensions', imports: [RouterLink, Feedback],
  template: `@if (catalog(); as extensions) { @if (extensions.pages.length || extensions.diagnostics.length) {
    <section class="card mb-4"><div class="card-header"><h2 class="card-title">System extensions</h2></div><div class="card-body">
      <ul class="list-group list-group-flush">@for (page of extensions.pages; track page.id) { <li class="list-group-item px-0">@if (page.status === 'ready') { <a [routerLink]="['/extensions/systems', systemId(), 'pages', page.id]">{{ page.title }}</a> } @else { <strong>{{ page.title }}</strong><span class="badge text-bg-warning ms-2">Setup required</span> }<p class="text-secondary small mb-0">{{ page.message || page.description }}</p></li> }</ul>
      @for (diagnostic of extensions.diagnostics; track $index) { <p class="alert alert-warning mb-2">{{ diagnostic.type ? diagnostic.type + ': ' : '' }}{{ diagnostic.message }}</p> }
    </div></section>
  } }<app-feedback [error]="error()" />`,
})
export class SystemExtensions {
  private readonly api = inject(Api);
  readonly systemId = input.required<number>();
  readonly catalog = signal<ExtensionCatalog | null>(null); readonly error = signal('');
  private generation = 0;
  constructor() { effect(() => { void this.load(this.systemId()); }); }
  private async load(id: number): Promise<void> {
    const generation = ++this.generation; this.error.set(''); this.catalog.set(null);
    try { const catalog = await this.api.get<ExtensionCatalog>(`/systems/${id}/extensions`); if (generation === this.generation && Array.isArray(catalog.pages) && Array.isArray(catalog.diagnostics)) this.catalog.set(catalog); }
    catch (error) { if (generation === this.generation) this.error.set(errorMessage(error)); }
  }
}
