import { Component, input } from '@angular/core';

@Component({
  selector: 'app-icon',
  template: `<svg
    viewBox="0 0 24 24"
    width="20"
    height="20"
    style="width: var(--app-icon-size, 20px); height: var(--app-icon-size, 20px)"
    fill="none"
    stroke="currentColor"
    stroke-width="1.7"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    @switch (name()) {
      @case ('menu') {
        <path d="M4 6h16M4 12h16M4 18h16" />
      }
      @case ('home') {
        <path d="m3 10 9-7 9 7v10H3Z" />
        <path d="M9 20v-7h6v7" />
      }
      @case ('projects') {
        <path d="M3 7V5h7l2 3h9v12H3Z" />
      }
      @case ('systems') {
        <rect x="3" y="3" width="18" height="7" rx="2" />
        <rect x="3" y="14" width="18" height="7" rx="2" />
        <path d="M7 6.5h.01M7 17.5h.01M12 7h5M12 18h5" />
      }
      @case ('cem') {
        <rect x="8" y="2" width="8" height="6" rx="1" />
        <rect x="2" y="16" width="8" height="6" rx="1" />
        <rect x="14" y="16" width="8" height="6" rx="1" />
        <path d="M12 8v4H6v4M12 12h6v4" />
      }
      @case ('settings') {
        <path
          d="m9 3-.6 2.5-2 .9L4 5.7 2 9.2l1.8 1.8v2L2 14.8 4 18.3l2.4-.7 2 .9L9 21h6l.6-2.5 2-.9 2.4.7 2-3.5-1.8-1.8v-2L22 9.2 20 5.7l-2.4.7-2-.9L15 3Z"
        />
        <circle cx="12" cy="12" r="3" />
      }
      @case ('user') {
        <circle cx="12" cy="8" r="4" />
        <path d="M4 21a8 8 0 0 1 16 0" />
      }
      @case ('enabled') {
        <circle cx="12" cy="12" r="9" />
        <path d="m8 12 3 3 5-6" />
      }
      @case ('disabled') {
        <circle cx="12" cy="12" r="9" />
        <path d="m6 6 12 12" />
      }
      @case ('arrow') {
        <path d="M4 12h15m-5-5 5 5-5 5" />
      }
      @case ('plus') {
        <path d="M12 4v16M4 12h16" />
      }
      @case ('copy') {
        <rect x="8" y="8" width="13" height="13" rx="2" />
        <path d="M16 8V3H3v13h5" />
      }
      @case ('archive') {
        <rect x="3" y="3" width="18" height="4" rx="1" />
        <path d="M5 7v14h14V7M9 11h6" />
      }
      @case ('restore') {
        <path d="M3 10a9 9 0 1 1 1 7M3 4v6h6M12 7v5l3 2" />
      }
      @case ('edit') {
        <path d="m15 4 5 5M3 21l6-1L21 8a2.1 2.1 0 0 0-5-5L4 15Z" />
      }
      @case ('switch-user') {
        <circle cx="10" cy="7" r="3" />
        <path d="M3 19v-2a7 7 0 0 1 11-5M16 14h6m-2-2 2 2-2 2M22 20h-6m2-2-2 2 2 2" />
      }
      @case ('download') {
        <path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5" />
      }
      @case ('upload') {
        <path d="M12 15V3m-5 5 5-5 5 5M4 16v5h16v-5" />
      }
      @case ('delete') {
        <path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7" />
      }
      @case ('search') {
        <circle cx="10.5" cy="10.5" r="6.5" />
        <path d="m16 16 5 5" />
      }
      @case ('filter') {
        <path d="M3 4h18l-7 8v7l-4 2v-9Z" />
      }
      @case ('close') {
        <path d="m6 6 12 12M6 18 18 6" />
      }
      @case ('percent') {
        <circle cx="6" cy="6" r="3" />
        <circle cx="18" cy="18" r="3" />
        <path d="M4 20 20 4" />
      }
      @case ('calendar') {
        <rect x="3" y="5" width="18" height="16" rx="2" />
        <path d="M3 10h18M7 3v4M17 3v4M7 14h2M15 14h2M7 18h2" />
      }
      @case ('email') {
        <rect x="3" y="5" width="18" height="14" rx="2" />
        <path d="m3 6 9 7 9-7" />
      }
      @case ('key') {
        <circle cx="8" cy="8" r="5" />
        <path d="m12 12 9 9m-6-6 3-3m0 6 3-3" />
      }
      @case ('external-link') {
        <path d="M14 3h7v7M21 3l-11 11M10 3H3v18h18v-7" />
      }
      @case ('refresh') {
        <path d="M3 10a9 9 0 0 1 15-5l3 3M21 3v5h-5M21 14a9 9 0 0 1-15 5l-3-3M3 21v-5h5" />
      }
      @case ('save') {
        <path d="M3 3h15l3 3v15H3ZM7 3v6h10V3M7 21v-8h10v8" />
      }
      @case ('experiment') {
        <path d="M9 3h6M10 3v6L4 19a1.3 1.3 0 0 0 1.1 2h13.8a1.3 1.3 0 0 0 1.1-2L14 9V3M7 14h10" />
      }
      @case ('evaluation') {
        <path d="m3 10 18-7-7 18-3-8ZM11 13 21 3" />
      }
      @case ('tasks') {
        <path d="M9 5h12M9 12h12M9 19h12M2 4h3v3H2zM2 11h3v3H2zM2 18h3v3H2z" />
      }
      @default {
        <circle cx="12" cy="12" r="9" />
        <path d="M12 7v5l3 2" />
      }
    }
  </svg>`,
})
export class Icon {
  readonly name = input('clock');
}
