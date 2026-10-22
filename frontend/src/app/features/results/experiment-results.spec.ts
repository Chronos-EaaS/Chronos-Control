import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { ActivatedRoute, convertToParamMap } from '@angular/router';
import { BehaviorSubject } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { ResultConfigurationDocument } from '../../core/results-models';
import { ExperimentResults } from './experiment-results';
import { ResultConfigurationEditor } from './result-configuration-editor';

const configurations = (): ResultConfigurationDocument => ({
  version: '1.0',
  elements: {
    'system-1': { name: 'System defaults', all: [], job: [] },
    'experiment-8-alpha': { name: 'Alpha', all: [], job: [] },
    'experiment-8-beta': { name: 'Beta', all: [], job: [] },
  },
});

const ready = async (fixture: ComponentFixture<ExperimentResults>) => {
  fixture.detectChanges();
  await fixture.whenStable();
  fixture.detectChanges();
};

function button(fixture: ComponentFixture<ExperimentResults>, name: string): HTMLButtonElement {
  const control = [...fixture.nativeElement.querySelectorAll('button')].find(
    (element: any) => element.textContent?.trim() === name,
  );
  expect(control, name).toBeTruthy();
  return control;
}

function editor(fixture: ComponentFixture<ExperimentResults>): ResultConfigurationEditor {
  return fixture.debugElement.query(By.directive(ResultConfigurationEditor)).componentInstance;
}

function details(fixture: ComponentFixture<ExperimentResults>): HTMLDetailsElement {
  return fixture.nativeElement.querySelector('details.configuration-editor');
}

async function collapse(fixture: ComponentFixture<ExperimentResults>): Promise<void> {
  details(fixture).open = false;
  details(fixture).dispatchEvent(new Event('toggle'));
  await ready(fixture);
  expect(details(fixture).open).toBe(false);
  expect(fixture.componentInstance.editorOpen()).toBe(false);
}

describe('experiment result editor disclosure and navigation state', () => {
  let query: BehaviorSubject<ReturnType<typeof convertToParamMap>>;
  let api: { get: ReturnType<typeof vi.fn>; patch: ReturnType<typeof vi.fn> };

  beforeEach(() => {
    query = new BehaviorSubject(convertToParamMap({}));
    api = {
      get: vi.fn(async (path: string) =>
        path.endsWith('/result-configurations') ? configurations() : { aliases: { plots: {} } },
      ),
      patch: vi.fn(async () => ({})),
    };
    TestBed.configureTestingModule({
      imports: [ExperimentResults],
      providers: [
        { provide: Api, useValue: api },
        { provide: ActivatedRoute, useValue: { queryParamMap: query.asObservable() } },
      ],
    });
  });

  async function setup() {
    const fixture = TestBed.createComponent(ExperimentResults);
    fixture.componentRef.setInput('experimentId', 8);
    fixture.componentRef.setInput('systemId', 1);
    fixture.componentRef.setInput('resultId', 'experiment-8-alpha');
    await ready(fixture);
    await vi.waitFor(() => {
      fixture.detectChanges();
      expect(fixture.componentInstance.loaded(), fixture.componentInstance.error()).toBe(true);
    });
    return fixture;
  }

  it('uses labelled icon actions with configuration-specific tooltips and keeps the selected state', async () => {
    const fixture = await setup();
    const rows = [
      ...fixture.nativeElement.querySelectorAll('.configuration-table tbody tr'),
    ] as HTMLTableRowElement[];
    for (const row of rows) {
      for (const control of row.querySelectorAll<HTMLButtonElement>(
        '.configuration-actions button',
      )) {
        expect(control.classList.contains('btn-icon')).toBe(true);
        expect(control.querySelector('app-icon svg')).not.toBeNull();
        expect(control.querySelector('.visually-hidden')?.textContent?.trim()).toBeTruthy();
        expect(
          [...control.childNodes].some(
            (node) => node.nodeType === Node.TEXT_NODE && !!node.textContent?.trim(),
          ),
        ).toBe(false);
      }
    }
    const selected = rows[1].querySelector<HTMLButtonElement>('[aria-pressed]')!;
    expect(selected.disabled).toBe(true);
    expect(selected.getAttribute('aria-pressed')).toBe('true');
    expect(selected.classList.contains('btn-success')).toBe(true);
    expect(selected.title).toBe('Selected configuration: Alpha');
    const select = rows[2].querySelector<HTMLButtonElement>('[aria-pressed]')!;
    expect(select.disabled).toBe(false);
    expect(select.getAttribute('aria-pressed')).toBe('false');
    expect(select.title).toBe('Select configuration: Beta');
    expect(select.textContent?.trim()).toBe('Select configuration');
    expect(
      rows[0].querySelectorAll<HTMLButtonElement>('.configuration-actions button')[1].title,
    ).toBe('Copy to this experiment: System defaults');
    expect(
      rows[2].querySelectorAll<HTMLButtonElement>('.configuration-actions button')[1].title,
    ).toBe('Copy configuration: Beta');

    const changed: string[] = [];
    fixture.componentInstance.resultIdChange.subscribe((value) => changed.push(value));
    select.click();
    await ready(fixture);
    expect(api.patch).toHaveBeenCalledExactlyOnceWith('/experiments/8', {
      resultId: 'experiment-8-beta',
    });
    expect(changed).toEqual(['experiment-8-beta']);
    fixture.componentRef.setInput('resultId', changed[0]);
    await ready(fixture);
    expect(select.disabled).toBe(true);
    expect(select.getAttribute('aria-pressed')).toBe('true');
    expect(selected.disabled).toBe(false);
    expect(selected.getAttribute('aria-pressed')).toBe('false');
  });

  for (const action of ['create', 'edit', 'copy'] as const) {
    it(`reopens a manually collapsed dirty editor for ${action} without discarding its draft`, async () => {
      const fixture = await setup();
      button(fixture, 'Copy to this experiment').click();
      await ready(fixture);
      const draftId = editor(fixture).selectedId();
      editor(fixture).rename('Unsaved Zürich draft');
      await ready(fixture);
      expect(fixture.componentInstance.dirty()).toBe(true);
      await collapse(fixture);

      const before = Object.keys(fixture.componentInstance.editable().elements).length;
      if (action === 'create') button(fixture, 'Create new configuration').click();
      else if (action === 'copy') button(fixture, 'Copy to this experiment').click();
      else {
        const row = [...fixture.nativeElement.querySelectorAll('tbody tr')].find(
          (element: any) => element.querySelector('th')?.textContent?.trim() === 'Beta',
        ) as HTMLTableRowElement;
        [...row.querySelectorAll('button')]
          .find((element) => element.textContent?.trim() === 'Edit job charts')!
          .click();
      }
      await ready(fixture);

      expect(details(fixture).open).toBe(true);
      expect(fixture.componentInstance.editorOpen()).toBe(true);
      expect(fixture.componentInstance.editable().elements[draftId].name).toBe(
        'Unsaved Zürich draft',
      );
      expect(fixture.componentInstance.dirty()).toBe(true);
      expect(Object.keys(fixture.componentInstance.editable().elements)).toHaveLength(
        before + (action === 'edit' ? 0 : 1),
      );
      if (action === 'edit') {
        expect(editor(fixture).selectedId()).toBe('experiment-8-beta');
        expect(editor(fixture).scopes()[0].key).toBe('job');
      }
      expect(
        api.get.mock.calls.filter(([path]) => String(path).endsWith('/result-configurations')),
      ).toHaveLength(1);
    });
  }

  it('applies back/forward query configuration and scope after a row edit while preserving unsaved values', async () => {
    query.next(convertToParamMap({ configuration: 'experiment-8-alpha', scope: 'all' }));
    const fixture = await setup();
    fixture.componentInstance.editConfiguration('experiment-8-beta', 'job');
    await ready(fixture);
    editor(fixture).rename('Unsaved Beta');
    await ready(fixture);
    await collapse(fixture);

    query.next(convertToParamMap({ configuration: 'experiment-8-alpha', scope: 'job' }));
    await ready(fixture);
    expect(details(fixture).open).toBe(true);
    expect(editor(fixture).selectedId()).toBe('experiment-8-alpha');
    expect(editor(fixture).scopes()[0].key).toBe('job');
    expect(fixture.componentInstance.editable().elements['experiment-8-beta'].name).toBe(
      'Unsaved Beta',
    );
    expect(fixture.componentInstance.dirty()).toBe(true);

    query.next(convertToParamMap({ configuration: 'experiment-8-beta', scope: 'all' }));
    await ready(fixture);
    expect(editor(fixture).selectedId()).toBe('experiment-8-beta');
    expect(editor(fixture).scopes()[0].key).toBe('all');
    expect(editor(fixture).selected().name).toBe('Unsaved Beta');
    expect(
      api.get.mock.calls.filter(([path]) => String(path).endsWith('/result-configurations')),
    ).toHaveLength(1);
  });

  it('honors new explicit input selections after local edits and permits a deep-linked editor to stay collapsed', async () => {
    const fixture = await setup();
    fixture.componentInstance.editConfiguration('experiment-8-beta', 'job');
    await ready(fixture);
    fixture.componentRef.setInput('configurationId', 'experiment-8-alpha');
    fixture.componentRef.setInput('initialScope', 'all');
    await ready(fixture);
    expect(editor(fixture).selectedId()).toBe('experiment-8-alpha');
    expect(editor(fixture).scopes()[0].key).toBe('all');
    expect(details(fixture).open).toBe(true);
    await collapse(fixture);
    fixture.detectChanges();
    expect(details(fixture).open).toBe(false);
  });
});
