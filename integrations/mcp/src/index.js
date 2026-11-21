import { serveStdio } from '@modelcontextprotocol/server/stdio';
import { configuration } from './config.js';
import { createServer } from './server.js';

let config;
try {
  config = configuration(process.env);
} catch (error) {
  console.error(`Chronos MCP: ${error.message}`);
  process.exit(1);
}

serveStdio(() => createServer(config));
