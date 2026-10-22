import { Component, input, model, output } from '@angular/core';
import { ParameterElement, ParameterGroup, ParameterIssue } from '../../core/parameter-models';
import { Icon } from '../../shared/icon';
import { domNamespace } from './parameter-state';

export interface ParameterOutlineEntry {
  group: ParameterGroup;
  index: number;
  elements: readonly { element: ParameterElement; index: number }[];
}

@Component({
  selector: 'app-parameter-schema-outline',
  imports: [Icon],
  templateUrl: './parameter-schema-outline.html',
  styleUrl: './parameter-schema-outline.scss',
})
export class ParameterSchemaOutline {
  readonly entries = input<readonly ParameterOutlineEntry[]>([]);
  readonly selectedGroup = input(-1);
  readonly selectedElement = input<number | null>(null);
  readonly kinds = input<readonly { type: string; label: string }[]>([]);
  readonly issues = input<readonly ParameterIssue[]>([]);
  readonly query = model('');
  readonly groupSelect = output<number>();
  readonly parameterSelect = output<{ group: number; element: number }>();
  readonly namespace = domNamespace();

  typeLabel(type: string): string {
    return this.kinds().find(kind => kind.type === type)?.label ?? type;
  }

  issueCount(groupIndex: number, elementIndex?: number): number {
    const group = this.entries().find(entry => entry.index === groupIndex)?.group;
    if (!group || (elementIndex !== undefined && !group.elements[elementIndex])) return 0;
    return this.issues().filter(issue => issue.groupId === group.id &&
      (elementIndex === undefined || issue.elementId === group.elements[elementIndex].id)).length;
  }
}
