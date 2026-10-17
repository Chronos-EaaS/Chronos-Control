export const PARAMETER_TYPES = [
  { type: 'boolean', label: 'Boolean' },
  { type: 'checkbox-selection', label: 'Checkbox selection' },
  { type: 'dropdown', label: 'Dropdown' },
  { type: 'integer', label: 'Integer' },
  { type: 'interval', label: 'Integer interval' },
  { type: 'percentage', label: 'Percentage' },
  { type: 'percentage-interval', label: 'Percentage interval' },
  { type: 'percentage-dep', label: 'Depending percentage' },
  { type: 'ratio', label: 'Ratio' },
  { type: 'software-versions-list', label: 'Software versions' },
  { type: 'string-list', label: 'String list' },
  { type: 'string', label: 'String' },
] as const;

export type BuiltinParameterType = typeof PARAMETER_TYPES[number]['type'];

/** Legacy JSON property names are intentional; unknown extension metadata survives edits. */
export interface ParameterElement {
  id: string;
  type: string;
  name: string;
  parameter: string;
  default?: unknown;
  options?: string | string[];
  names?: string | string[];
  dependency?: string;
  repository?: string;
  'parameter-type'?: string;
  'default-min'?: unknown;
  'default-max'?: unknown;
  'default-start'?: unknown;
  'default-end'?: unknown;
  'default-step'?: unknown;
  [key: string]: unknown;
}

export interface ParameterGroup {
  id: string;
  title: string;
  depends: string;
  dependsValue: string | number | boolean | null;
  elements: ParameterElement[];
  [key: string]: unknown;
}

export type ParameterValues = Record<string, unknown>;
export interface ParameterIssue {
  code: string;
  message: string;
  severity: 'error' | 'warning';
  groupId?: string;
  elementId?: string;
  field?: string;
}
export interface ParameterValidation { valid: boolean; issues: ParameterIssue[]; }
export interface ParameterChoice { value: string; label: string; }

/** Ready server registry aliases only; raw schema types remain unchanged in saved JSON. */
export type ParameterTypeAliases = Readonly<Record<string, string>>;
