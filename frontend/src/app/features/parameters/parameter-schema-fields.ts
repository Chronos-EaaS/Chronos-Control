export interface SettingField {
  key: string;
  label: string;
  type?: string;
  prefix?: string;
  options?: { value: string; label: string }[];
}
const numericType = {
  key: 'parameter-type',
  label: 'Calculated value type',
  options: [
    { value: 'int', label: 'Integer (round down)' },
    { value: 'float', label: 'Decimal' },
  ],
};
const dependency = { key: 'dependency', label: 'Depending on parameter' };
const defaultField = { key: 'default', label: 'Default value' };

export function elementSettings(type: string): SettingField[] {
  switch (type) {
    case 'boolean':
      return [
        {
          key: 'default',
          label: 'Default value',
          options: [
            { value: 'false', label: 'False' },
            { value: 'true', label: 'True' },
          ],
        },
      ];
    case 'checkbox-selection':
      return [
        { key: 'options', label: 'Available values (comma-separated)' },
        { key: 'default', label: 'Initially selected values (comma-separated)' },
      ];
    case 'dropdown':
      return [
        { key: 'options', label: 'Available values (comma-separated)' },
        { key: 'names', label: 'Display names (comma-separated, same order)' },
        defaultField,
      ];
    case 'integer':
      return [{ ...defaultField, type: 'number' }];
    case 'interval':
      return [
        { key: 'default-min', label: 'Default minimum', type: 'number' },
        { key: 'default-max', label: 'Default maximum', type: 'number' },
        { key: 'default-step', label: 'Default step', type: 'number' },
      ];
    case 'percentage':
    case 'percentage-dep':
      return [
        numericType,
        dependency,
        { key: 'default', label: 'Default percentage', type: 'number', prefix: '%' },
      ];
    case 'percentage-interval':
      return [
        numericType,
        dependency,
        { key: 'default-start', label: 'Default start percentage', type: 'number', prefix: '%' },
        { key: 'default-end', label: 'Default end percentage', type: 'number', prefix: '%' },
        { key: 'default-step', label: 'Default step percentage', type: 'number', prefix: '%' },
      ];
    case 'ratio':
      return [
        { key: 'default-start', label: 'Default start ratio (for example 0:100)' },
        { key: 'default-end', label: 'Default end ratio (for example 100:0)' },
        { key: 'default-step', label: 'Default step (left value increases)' },
      ];
    case 'software-versions-list':
      return [{ key: 'repository', label: 'Git repository', type: 'text' }];
    case 'string-list':
      return [{ key: 'default', label: 'Default values (comma-separated)' }];
    case 'string':
      return [defaultField];
    default:
      return [];
  }
}
