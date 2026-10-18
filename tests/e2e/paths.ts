import path from 'node:path';

// Resolve from this project, independent of the caller's working directory.
export const repositoryRoot = path.resolve(__dirname, '../..');
export const legacyStack = path.join(repositoryRoot, 'tools/test/test-stack');
export const v3Stack = path.join(repositoryRoot, 'tools/test/test-stack-v3');
export const resultsRoot = path.join(repositoryRoot, 'dist/test-results');
export const reportRoot = path.join(repositoryRoot, 'dist/playwright-report');
