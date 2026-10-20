import { readdir } from 'node:fs/promises';
import path from 'node:path';

/** Upstream source archives can include CI/VCS metadata even with Composer --no-dev. */
export async function packageFiles(directory, prefix, add) {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    // Keep the deployment path boundary strict: these are never runtime inputs.
    if (entry.name.startsWith('.git') || entry.name === '.hg') continue;
    if (entry.isSymbolicLink()) throw new Error('A build contains an unexpected symbolic link.');
    if (entry.isDirectory()) await packageFiles(path.join(directory, entry.name), `${prefix}/${entry.name}`, add);
    else if (entry.isFile() && !entry.name.endsWith('.map')) await add(path.join(directory, entry.name), `${prefix}/${entry.name}`);
  }
}
