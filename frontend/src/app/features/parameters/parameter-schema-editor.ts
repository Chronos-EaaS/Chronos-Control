import { NgTemplateOutlet } from '@angular/common';
import {
  afterNextRender,
  Component,
  computed,
  effect,
  ElementRef,
  inject,
  Injector,
  input,
  model,
  output,
  signal,
  ViewChild,
} from '@angular/core';
import {
  PARAMETER_TYPES,
  ParameterGroup,
  ParameterIssue,
  ParameterValidation,
  ParameterTypeAliases,
} from '../../core/parameter-models';
import { Icon } from '../../shared/icon';
import {
  domNamespace,
  knownParameterType,
  parameterRenderType,
  parameterRenderGroups,
  newParameterId,
  validateParameterSchema,
  valueText,
} from './parameter-state';
import { elementSettings } from './parameter-schema-fields';
import { ParameterSchemaPreview } from './parameter-schema-preview';
import { ParameterSchemaOutline } from './parameter-schema-outline';
export { elementSettings } from './parameter-schema-fields';

interface Selection {
  group: number;
  element: number | null;
}

@Component({
  selector: 'app-parameter-schema-editor',
  imports: [NgTemplateOutlet, Icon, ParameterSchemaPreview, ParameterSchemaOutline],
  templateUrl: './parameter-schema-editor.html',
  styleUrl: './parameter-schema-editor.scss',
})
export class ParameterSchemaEditor {
  @ViewChild('previewDialog')
  private previewDialog?: ElementRef<HTMLDialogElement>;
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly injector = inject(Injector);
  readonly groups = model<ParameterGroup[]>([]);
  readonly aliases = input<ParameterTypeAliases>({});
  readonly disabled = input(false);
  readonly validation = output<ParameterValidation>();
  readonly validity = output<boolean>();
  readonly previous = signal<ParameterGroup[] | null>(null);
  readonly selection = signal<Selection>({ group: 0, element: 0 });
  readonly search = signal('');
  readonly preview = signal(false);
  readonly newType = signal('string');
  readonly namespace = domNamespace();
  readonly kinds = computed(() => [
    ...PARAMETER_TYPES,
    ...Object.entries(this.aliases())
      .filter(([type, base]) => !knownParameterType(type) && knownParameterType(base))
      .map(([type, base]) => ({
        type,
        label: `${type} (${PARAMETER_TYPES.find((item) => item.type === base)!.label})`,
      })),
  ]);
  readonly text = valueText;
  readonly validationState = computed(() =>
    validateParameterSchema(parameterRenderGroups(this.groups(), this.aliases())),
  );
  readonly parameterNames = computed(() =>
    this.groups()
      .flatMap((group) =>
        group.elements.flatMap((element) =>
          element.parameter.split(',').map((name) => name.trim()),
        ),
      )
      .filter(Boolean),
  );
  readonly count = computed(() =>
    this.groups().reduce((total, group) => total + group.elements.length, 0),
  );
  readonly active = computed(() => {
    const groups = this.groups(),
      requested = this.selection();
    const groupIndex = groups[requested.group] ? requested.group : 0,
      group = groups[groupIndex];
    if (!group) return null;
    const elementIndex =
      requested.element !== null && group.elements[requested.element] ? requested.element : null;
    return {
      group,
      groupIndex,
      elementIndex,
      element: elementIndex === null ? null : group.elements[elementIndex],
    };
  });
  readonly outline = computed(() => {
    const query = this.search().trim().toLocaleLowerCase();
    return this.groups()
      .map((group, index) => {
        const all = !query || `${group.title} ${group.id}`.toLocaleLowerCase().includes(query);
        const elements = group.elements
          .map((element, elementIndex) => ({ element, index: elementIndex }))
          .filter(
            ({ element }) =>
              all ||
              `${element.name} ${element.parameter} ${element.type} ${this.typeLabel(element.type)}`
                .toLocaleLowerCase()
                .includes(query),
          );
        return { group, index, elements, visible: all || elements.length > 0 };
      })
      .filter((group) => group.visible);
  });
  private lastEdited: ParameterGroup[] | null = null;
  private previousSelection: Selection = { group: 0, element: 0 };

  constructor() {
    effect(() => {
      this.validation.emit(this.validationState());
      this.validity.emit(this.validationState().valid);
    });
    effect(() => {
      if (this.groups() !== this.lastEdited) {
        this.previous.set(null);
        this.selection.set({ group: 0, element: 0 });
        this.search.set('');
        this.closePreview();
        this.newType.set('string');
      }
    });
  }
  openPreview(trigger: HTMLButtonElement): void {
    const dialog = this.previewDialog?.nativeElement;
    if (!dialog || dialog.open) return;
    trigger.focus({ preventScroll: true });
    this.preview.set(true);
    dialog.showModal();
  }
  closePreview(): void {
    this.preview.set(false);
    const dialog = this.previewDialog?.nativeElement;
    if (dialog?.open) dialog.close();
  }
  previewClosed(): void {
    if (!this.previewDialog?.nativeElement.open) this.preview.set(false);
  }
  cancelPreview(event: Event): void {
    event.preventDefault();
    this.closePreview();
  }
  dismissPreview(event: MouseEvent): void {
    const dialog = this.previewDialog?.nativeElement;
    if (!dialog || event.target !== dialog) return;
    const bounds = dialog.getBoundingClientRect();
    if (
      event.clientX < bounds.left ||
      event.clientX > bounds.right ||
      event.clientY < bounds.top ||
      event.clientY > bounds.bottom
    )
      this.closePreview();
  }
  renderType(type: string): string {
    return parameterRenderType(type, this.aliases());
  }
  supported(type: string): boolean {
    return knownParameterType(this.renderType(type));
  }
  settings(type: string) {
    return elementSettings(this.renderType(type));
  }
  typeLabel(type: string): string {
    return this.kinds().find((kind) => kind.type === type)?.label ?? type;
  }
  hint(type: string): string {
    switch (this.renderType(type)) {
      case 'boolean':
        return 'A single true or false choice.';
      case 'dropdown':
        return 'Choose one value. Display names and values use the same comma-separated order; spaces in values are preserved.';
      case 'checkbox-selection':
        return 'Select multiple values to compare. Comma-separated values and their spaces are preserved.';
      case 'integer':
        return 'A whole number, including zero.';
      case 'interval':
        return 'Compare whole numbers from the minimum to the maximum, increasing by the step.';
      case 'percentage':
      case 'percentage-dep':
        return 'Calculate a value from another parameter. Choose integer or decimal calculation and its starting percentage.';
      case 'percentage-interval':
        return 'Compare percentages of another parameter, from start to end in the chosen steps.';
      case 'ratio':
        return 'Two complementary parameters. Each start/end ratio totals 100, for example 0:100 to 100:0.';
      case 'software-versions-list':
        return 'Compare branches, tags or revisions from this Git repository.';
      case 'string-list':
        return 'Compare comma-separated text values. Spaces in each value are preserved.';
      case 'string':
        return 'A single text value. Spaces and Unicode characters are preserved.';
      default:
        return 'This extension needs a registered editor and renderer before it can be saved.';
    }
  }
  id(group: number, element: number, field: string): string {
    return `${this.namespace}-${group}-${element}-${field}`;
  }
  activeId(field: string): string {
    const active = this.active();
    return this.id(active?.groupIndex ?? 0, active?.elementIndex ?? -1, field);
  }
  issues(group: number, element: number | null = null): ParameterIssue[] {
    const g = this.groups()[group];
    return g
      ? this.validationState().issues.filter(
          (issue) =>
            issue.groupId === g.id &&
            (element === null || issue.elementId === g.elements[element]?.id),
        )
      : [];
  }
  fieldIssues(field: string): ParameterIssue[] {
    const active = this.active();
    return active
      ? this.issues(active.groupIndex, active.elementIndex).filter(
          (issue) =>
            issue.field === field &&
            (active.element ? issue.elementId === active.element.id : !issue.elementId),
        )
      : [];
  }
  describe(field: string): string | null {
    return this.fieldIssues(field).length ? this.activeId(field + '-errors') : null;
  }
  selectGroup(group: number): void {
    this.selection.set({ group, element: null });
    this.focus('title');
  }
  selectParameter(group: number, element: number): void {
    this.selection.set({ group, element });
    this.focus('name');
  }
  issueContext(issue: ParameterIssue): string {
    const group = this.groups().find((group) => group.id === issue.groupId);
    const element = group?.elements.find((element) => element.id === issue.elementId);
    return [group?.title, element?.name].filter(Boolean).join(' / ');
  }
  reveal(issue: ParameterIssue): void {
    const group = this.groups().findIndex((item) => item.id === issue.groupId);
    if (group < 0) return;
    const index = issue.elementId
      ? this.groups()[group].elements.findIndex((item) => item.id === issue.elementId)
      : -1;
    this.search.set('');
    this.selection.set({ group, element: index < 0 ? null : index });
    this.focus(issue.field ?? (index < 0 ? 'title' : 'name'));
  }
  private focus(field: string): void {
    const expected = this.groups(),
      target = this.activeId(field);
    afterNextRender(
      () => {
        if (this.groups() !== expected || this.activeId(field) !== target) return;
        const node = this.host.nativeElement.ownerDocument.getElementById(target);
        if (!node || !this.host.nativeElement.contains(node)) return;
        for (
          let parent = node.parentElement;
          parent && parent !== this.host.nativeElement;
          parent = parent.parentElement
        )
          if (parent instanceof HTMLDetailsElement) parent.open = true;
        node.focus();
        node.scrollIntoView?.({ block: 'nearest', behavior: 'instant' });
      },
      { injector: this.injector },
    );
  }
  private currentSelection(): Selection {
    const active = this.active();
    return { group: active?.groupIndex ?? 0, element: active?.elementIndex ?? null };
  }
  private edit(
    change: (groups: ParameterGroup[]) => void,
    selection = this.currentSelection(),
  ): void {
    if (this.disabled()) return;
    const before = this.groups(),
      next = before.map((group) => ({
        ...group,
        elements: group.elements.map((element) => ({ ...element })),
      }));
    change(next);
    this.previous.set(before);
    this.previousSelection = this.currentSelection();
    this.lastEdited = next;
    this.groups.set(next);
    this.selection.set(selection);
  }
  addGroup(): void {
    if (this.disabled()) return;
    this.edit(
      (groups) =>
        groups.push({
          id: newParameterId('group'),
          title: 'New group',
          depends: '',
          dependsValue: '',
          elements: [],
        }),
      { group: this.groups().length, element: null },
    );
    this.search.set('');
    this.focus('title');
  }
  removeGroup(index: number): void {
    if (
      this.disabled() ||
      !this.groups()[index] ||
      !window.confirm(
        `Delete group “${this.groups()[index].title}” and all its parameters from the draft?`,
      )
    )
      return;
    const selected = this.currentSelection();
    this.edit(
      (groups) => {
        groups.splice(index, 1);
      },
      {
        group:
          selected.group > index
            ? selected.group - 1
            : selected.group === index
              ? Math.max(0, Math.min(index, this.groups().length - 2))
              : selected.group,
        element: selected.group === index ? null : selected.element,
      },
    );
  }
  updateGroup(index: number, key: 'title' | 'depends' | 'dependsValue', value: string): void {
    if (this.groups()[index] && this.groups()[index][key] !== value)
      this.edit((groups) => {
        groups[index] = { ...groups[index], [key]: value };
      });
  }
  addElement(group: number): void {
    if (this.disabled() || !this.groups()[group]) return;
    const type = this.kinds().some((kind) => kind.type === this.newType())
      ? this.newType()
      : 'string';
    this.edit(
      (groups) =>
        groups[group].elements.push({
          id: newParameterId('element'),
          type,
          name: 'New parameter',
          parameter: '',
          default: '',
        }),
      { group, element: this.groups()[group].elements.length },
    );
    this.search.set('');
    this.focus('name');
  }
  removeElement(group: number, index: number): void {
    if (
      this.disabled() ||
      !this.groups()[group]?.elements[index] ||
      !window.confirm(
        `Delete parameter “${this.groups()[group].elements[index].name}” from the draft?`,
      )
    )
      return;
    const selected = this.currentSelection(),
      remaining = this.groups()[group].elements.length - 1;
    if (selected.group === group && selected.element !== null)
      selected.element =
        selected.element === index
          ? remaining
            ? Math.min(index, remaining - 1)
            : null
          : selected.element > index
            ? selected.element - 1
            : selected.element;
    this.edit((groups) => {
      groups[group].elements.splice(index, 1);
    }, selected);
  }
  updateElement(group: number, index: number, key: string, value: string): void {
    if (
      this.groups()[group]?.elements[index] &&
      this.groups()[group].elements[index][key] !== value
    )
      this.edit((groups) => {
        groups[group].elements[index] = { ...groups[group].elements[index], [key]: value };
      });
  }
  moveGroup(index: number, direction: number): void {
    const to = index + direction;
    if (!this.groups()[index] || !this.groups()[to]) return;
    const selected = this.currentSelection();
    if (selected.group === index) selected.group = to;
    else if (selected.group === to) selected.group = index;
    this.edit((groups) => {
      [groups[index], groups[to]] = [groups[to], groups[index]];
    }, selected);
  }
  moveElement(group: number, index: number, direction: number): void {
    const to = index + direction,
      elements = this.groups()[group]?.elements;
    if (!elements?.[index] || !elements[to]) return;
    const selected = this.currentSelection();
    if (selected.group === group) {
      if (selected.element === index) selected.element = to;
      else if (selected.element === to) selected.element = index;
    }
    this.edit((groups) => {
      const e = groups[group].elements;
      [e[index], e[to]] = [e[to], e[index]];
    }, selected);
  }
  moveToGroup(group: number, index: number, destination: string): void {
    const target = Number(destination);
    if (
      this.disabled() ||
      destination === '' ||
      !Number.isInteger(target) ||
      target === group ||
      !this.groups()[target] ||
      !this.groups()[group]?.elements[index]
    )
      return;
    const selected = this.currentSelection();
    if (selected.group === group && selected.element === index) {
      selected.group = target;
      selected.element = this.groups()[target].elements.length;
    } else if (selected.group === group && selected.element !== null && selected.element > index)
      selected.element--;
    this.edit((groups) => {
      groups[target].elements.push(...groups[group].elements.splice(index, 1));
    }, selected);
    this.search.set('');
  }
  undo(): void {
    if (this.disabled() || this.previous() === null) return;
    const current = this.groups(),
      selection = this.currentSelection();
    this.lastEdited = this.previous()!;
    this.groups.set(this.lastEdited);
    this.previous.set(current);
    this.selection.set(this.previousSelection);
    this.previousSelection = selection;
  }
}
