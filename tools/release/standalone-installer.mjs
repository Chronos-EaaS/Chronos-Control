const deploymentNamespace = 'Chronos\\Infrastructure\\Deployment';
const requires = [
  "require_once __DIR__ . '/app/Infrastructure/Deployment/ReleaseManager.php';",
  "require_once __DIR__ . '/app/Infrastructure/Deployment/Installer.php';",
];

function classBlock(source, name) {
  const preamble = /^<\?php\s*declare\(strict_types=1\);\s*namespace\s+([^;]+);\s*/;
  const match = source.match(preamble);
  if (!match || match[1] !== deploymentNamespace || !source.includes(`final class ${name}\n`)) {
    throw new Error(`The standalone installer requires the namespaced ${name} implementation.`);
  }
  return `namespace ${deploymentNamespace} {\n${source.slice(match[0].length).trimEnd()}\n}\n`;
}

/** Bundle the two bootstrap-free implementations without changing their namespaces. */
export function standaloneInstaller({ entry, release, installer }) {
  const preamble = /^<\?php\s*declare\(strict_types=1\);\s*/;
  if (!preamble.test(entry) || !entry.trimEnd().endsWith('</html>')) {
    throw new Error('The installer entry must declare strict types and end in its HTML form.');
  }
  let body = entry.replace(preamble, '');
  for (const statement of requires) {
    if (body.split(statement).length !== 2) throw new Error('The installer implementation include changed.');
    body = body.replace(statement, '');
  }
  return '<?php\ndeclare(strict_types=1);\n\n'
    + classBlock(release, 'ReleaseManager') + '\n'
    + classBlock(installer, 'Installer')
    + '\nnamespace {\n' + body.trimEnd() + '\n<?php\n}\n';
}
