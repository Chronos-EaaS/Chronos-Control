import { Component, input } from '@angular/core';

@Component({
  selector: 'app-icon',
  template: `<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch (name()) {
      @case ('menu') { <path d="M4 6h16M4 12h16M4 18h16" /> }
      @case ('home') { <path d="m3 10 9-7 9 7v10H3Z" /><path d="M9 20v-7h6v7" /> }
      @case ('projects') { <path d="M3 7V5h7l2 3h9v12H3Z" /> }
      @case ('systems') { <rect x="3" y="3" width="18" height="7" rx="2" /><rect x="3" y="14" width="18" height="7" rx="2" /><path d="M7 6.5h.01M7 17.5h.01M12 7h5M12 18h5" /> }
      @case ('user') { <circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /> }
      @case ('enabled') { <circle cx="12" cy="12" r="9" /><path d="m8 12 3 3 5-6" /> }
      @case ('disabled') { <circle cx="12" cy="12" r="9" /><path d="m6 6 12 12" /> }
      @case ('arrow') { <path d="M4 12h15m-5-5 5 5-5 5" /> }
      @case ('plus') { <path d="M12 4v16M4 12h16" /> }
      @default { <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /> }
    }
  </svg>`,
})
export class Icon { readonly name = input('clock'); }
