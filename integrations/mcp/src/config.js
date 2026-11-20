import { resolve } from 'node:path';

function integer(env, name, fallback, max) {
  const raw = env[name];
  if (raw === undefined) return fallback;
  const value = Number(raw);
  if (!/^[0-9]+$/.test(raw) || !Number.isSafeInteger(value) || value < 1 || value > max) {
    throw new Error(`${name} must be an integer between 1 and ${max}.`);
  }
  return value;
}

export function configuration(env) {
  let url;
  try {
    url = new URL(env.CHRONOS_URL);
  } catch {
    throw new Error('Set CHRONOS_URL to the Chronos instance origin.');
  }
  const loopback = ['127.0.0.1', 'localhost', '[::1]'].includes(url.hostname);
  if ((url.protocol !== 'https:' && !(url.protocol === 'http:' && loopback))
      || url.username || url.password || url.pathname !== '/' || url.search || url.hash) {
    throw new Error('CHRONOS_URL must be an HTTPS origin or a loopback HTTP origin, without credentials or a path.');
  }
  const username = env.CHRONOS_USERNAME?.trim();
  const password = env.CHRONOS_PASSWORD;
  if (!username || [...username].length > 50 || !password || Buffer.byteLength(password) > 1024) {
    throw new Error('Set CHRONOS_USERNAME and CHRONOS_PASSWORD to a Chronos account.');
  }

  const cookieName = env.CHRONOS_SESSION_COOKIE ?? 'PHPSESSID';
  if (!/^[a-zA-Z0-9_-]+$/.test(cookieName)) {
    throw new Error('CHRONOS_SESSION_COOKIE must be the configured PHP session cookie name.');
  }

  return {
    origin: url.origin,
    username,
    password,
    cookieName,
    timeoutMs: integer(env, 'CHRONOS_REQUEST_TIMEOUT_MS', 15_000, 300_000),
    exportDir: env.CHRONOS_EXPORT_DIR ? resolve(env.CHRONOS_EXPORT_DIR) : undefined,
    exportLimit: integer(env, 'CHRONOS_MAX_EXPORT_BYTES', 32 * 1024 ** 2, 1024 ** 3),
  };
}
