import { createHash } from 'node:crypto';

const sourceNamespace = 'Chronos\\Infrastructure\\Persistence\\Migrations';
export const migrationSources = ['Migration', 'MigrationException', 'UserEnabledMigration', 'LiveUpdatesMigration', 'EventTimelineIndexMigration', 'ScopedEventTimelineIndexesMigration', 'ResourceEventTimelineIndexMigration', 'MigrationCatalog', 'MigrationRunner'];

/** Isolate verified target code from classes already loaded by the previous release. */
export function migrationRuntime(sources) {
  if (Object.keys(sources).sort().join() !== [...migrationSources].sort().join()) throw new Error('The complete migration runtime is required.');
  const hash = createHash('sha256').update('chronos-database-runtime-v1\n');
  for (const name of migrationSources) hash.update(name + '\0' + sources[name] + '\0');
  const namespace = 'Chronos\\DatabaseRuntime\\H' + hash.digest('hex');
  const checksums = {};
  const blocks = migrationSources.map(name => {
    const source = sources[name];
    const preamble = /^<\?php\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*namespace\s+([^;]+);\s*/;
    const match = source.match(preamble);
    if (!match || match[1] !== sourceNamespace) throw new Error(`The migration ${name} has an unsupported namespace.`);
    let body = source.slice(match[0].length).trimEnd();
    if (body.includes(sourceNamespace) || /<\?php|\?>/.test(body)) throw new Error('The migration runtime must use local names and one PHP code boundary.');
    const declaration = body.match(/^(?:final |abstract )?(class|interface|trait)\s+(\w+)/m);
    if (!declaration || declaration[2] !== name) throw new Error(`The migration ${name} declaration changed.`);
    if (new RegExp(`class ${name} implements Migration\\b`).test(body)) checksums[name] = createHash('sha256').update(source).digest('hex');
    const imports = [...body.matchAll(/^use [^;]+;\s*/gm)].map(item => item[0]);
    body = body.replace(/^use [^;]+;\s*/gm, '');
    const exists = declaration[1] === 'interface' ? 'interface_exists' : declaration[1] === 'trait' ? 'trait_exists' : 'class_exists';
    return `namespace ${namespace} {\n${imports.join('')}if (!${exists}(__NAMESPACE__ . '\\\\${name}', false)) {\n${body}\n}\n}\n`;
  });
  if (!Object.keys(checksums).length) throw new Error('No immutable migrations were found.');
  const checksumLiteral = '[' + Object.entries(checksums).map(([name, value]) => `'${name}' => '${value}'`).join(', ') + ']';
  return '<?php\ndeclare(strict_types=1);\n\n' + blocks.join('\n')
    + `\nnamespace {\nreturn ['format' => 1, 'version' => \\${namespace}\\MigrationCatalog::latestVersion(),\n`
    + `'run' => static function (\\PDO $database, string $action, ?string $target = null): array {\n`
    + `    $runner = new \\${namespace}\\MigrationRunner($database, null, 10, ${checksumLiteral});\n`
    + `    if ($action === 'status') return $runner->status();\n`
    + `    if ($action === 'migrate') return $runner->migrateTo($target);\n`
    + `    if ($action === 'assert') { $runner->assertCurrent(); return $runner->status(); }\n`
    + `    throw new \\InvalidArgumentException('Unsupported database migration action.');\n`
    + `}, 'lock' => static function (\\PDO $database): \\Closure {\n`
    + `    return (new \\${namespace}\\MigrationRunner($database, null, 10, ${checksumLiteral}))->lease();\n`
    + `}];\n}\n`;
}
