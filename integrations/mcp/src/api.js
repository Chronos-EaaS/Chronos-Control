import { setTimeout as delay } from 'node:timers/promises';

const jsonLimit = 2 * 1024 ** 2;
const transientStatuses = new Set([408, 502, 503, 504]);

export class ChronosError extends Error {
  constructor(code, message, details = {}) {
    super(message);
    this.result = { code, message, ...details };
  }
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

async function readJson(response, maxBytes) {
  if (!response.body || !/^application\/json(?:\s*;|$)/i.test(response.headers.get('content-type') ?? '')) {
    await response.body?.cancel();
    throw new ChronosError('invalid_response', 'Chronos did not return JSON.');
  }
  let total = 0;
  const chunks = [];
  // Exiting this loop cancels the stream, including for chunked responses.
  for await (const chunk of response.body) {
    total += chunk.length;
    if (total > maxBytes) {
      throw new ChronosError('response_too_large', 'The Chronos JSON response exceeded the size limit. Try a smaller page or log chunk.');
    }
    chunks.push(chunk);
  }
  try {
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
  } catch {
    throw new ChronosError('invalid_response', 'Chronos returned malformed JSON.');
  }
}

async function envelope(response) {
  const payload = await readJson(response, jsonLimit);
  if (!isObject(payload?.data)) {
    throw new ChronosError('invalid_response', 'Chronos returned an invalid data envelope.');
  }
  return payload.data;
}

async function httpError(response, signal) {
  const status = response.status;
  let remote;
  if (status >= 400 && status < 500) {
    try {
      remote = (await readJson(response, 16 * 1024))?.error;
    } catch {
      signal.throwIfAborted();
    }
  } else {
    await response.body?.cancel();
  }
  const code = typeof remote?.code === 'string' && /^[a-z][a-z0-9_]{0,79}$/.test(remote.code)
    ? remote.code : 'http_error';
  const fields = isObject(remote?.fields) ? remote.fields : undefined;
  const message = typeof remote?.message === 'string'
    ? remote.message : `Chronos returned HTTP ${status}.`;
  return new ChronosError(code, message, { status, ...(fields && { fields }) });
}

function loginState(response, data, cookieName) {
  let cookie;
  // PHP can emit more than one Set-Cookie for its session during rotation.
  for (const header of response.headers.getSetCookie()) {
    const first = header.split(';', 1)[0];
    if (first.startsWith(`${cookieName}=`)) cookie = first;
  }
  if (!cookie || !/^[A-Za-z0-9,-]{1,256}$/.test(cookie.slice(cookieName.length + 1))
      || typeof data.csrfToken !== 'string' || !/^[a-f0-9]{64}$/.test(data.csrfToken)) {
    throw new ChronosError('invalid_session', 'Chronos did not return the configured session cookie and CSRF token.');
  }
  return { cookie, csrf: data.csrfToken };
}

function uncertainWrite(cause) {
  return new ChronosError(
    'write_outcome_unknown',
    'Chronos may have applied this write. Check the relevant Chronos records before sending it again.',
    { ...(cause.result.status && { status: cause.result.status }), cause: cause.result.code },
  );
}

export class ChronosApi {
  #config;
  #session;
  #login;

  constructor(config) {
    this.#config = config;
  }

  async #request(path, { signal, session, body, consume }) {
    const timeout = AbortSignal.timeout(this.#config.timeoutMs);
    const attemptSignal = AbortSignal.any([signal, timeout]);
    const headers = { Accept: 'application/json' };
    if (session) headers.Cookie = session.cookie;
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      headers.Origin = this.#config.origin;
      headers['X-CSRF-Token'] = session.csrf;
    }

    try {
      const response = await fetch(`${this.#config.origin}/api/ui${path}`, {
        method: body === undefined ? 'GET' : 'POST',
        headers, body, signal: attemptSignal, redirect: 'manual',
      });
      if (!response.ok) throw await httpError(response, attemptSignal);
      return await consume(response, attemptSignal);
    } catch (error) {
      signal.throwIfAborted();
      if (timeout.aborted) throw new ChronosError('request_timeout', 'The Chronos HTTP request timed out.');
      if (error instanceof ChronosError) throw error;
      if (error instanceof TypeError) {
        throw new ChronosError('transport_error', 'The Chronos HTTP connection failed.');
      }
      throw error;
    }
  }

  async #read(path, request, retry = true) {
    try {
      return await this.#request(path, request);
    } catch (error) {
      const detail = error instanceof ChronosError ? error.result : {};
      if (!retry || (detail.code !== 'transport_error' && detail.code !== 'request_timeout'
          && !transientStatuses.has(detail.status))) throw error;
      await delay(200, undefined, { signal: request.signal });
      return this.#request(path, request);
    }
  }

  async #authenticate(signal) {
    const cookieName = this.#config.cookieName;
    const bootstrap = await this.#read('/session', {
      signal,
      consume: async response => loginState(response, await envelope(response), cookieName),
    });
    const body = JSON.stringify({ username: this.#config.username, password: this.#config.password, remember: false });
    return this.#request('/session', {
      signal, session: bootstrap, body,
      consume: async response => {
        const data = await envelope(response);
        if (!isObject(data.user) || data.impersonation?.active) {
          throw new ChronosError('invalid_session', 'Chronos did not establish a regular authenticated session.');
        }
        return loginState(response, data, cookieName);
      },
    });
  }

  async #sessionFor(signal) {
    signal.throwIfAborted();
    if (this.#session) return this.#session;
    if (!this.#login) {
      const login = { controller: new AbortController(), waiters: 0 };
      login.pending = this.#authenticate(login.controller.signal).then(session => {
        // A cancelled login may have been replaced by a later caller.
        if (this.#login === login) this.#session = session;
        return session;
      }).finally(() => {
        if (this.#login === login) this.#login = undefined;
      });
      this.#login = login;
    }

    const login = this.#login;
    login.waiters++;
    let onAbort;
    try {
      return await Promise.race([login.pending, new Promise((_, reject) => {
        onAbort = () => reject(signal.reason);
        signal.addEventListener('abort', onAbort, { once: true });
      })]);
    } finally {
      signal.removeEventListener('abort', onAbort);
      if (--login.waiters === 0 && this.#login === login) {
        this.#login = undefined;
        login.controller.abort();
      }
    }
  }

  #invalidate(session) {
    if (this.#session === session) this.#session = undefined;
  }

  async get(path, query, signal, consume = envelope) {
    const params = new URLSearchParams();
    for (const [name, value] of Object.entries(query)) {
      if (value !== undefined) params.set(name, String(value));
    }
    const url = params.size ? `${path}?${params}` : path;
    // Streaming downloads have their own cleanup and are never replayed after transfer begins.
    const read = session => this.#read(url, { session, signal, consume }, consume === envelope);
    const session = await this.#sessionFor(signal);
    try {
      return await read(session);
    } catch (error) {
      if (error.result?.status !== 401) throw error;
      this.#invalidate(session);
    }
    const renewed = await this.#sessionFor(signal);
    try {
      return await read(renewed);
    } catch (error) {
      if (error.result?.status === 401) this.#invalidate(renewed);
      throw error;
    }
  }

  async post(path, payload, signal) {
    const body = JSON.stringify(payload);
    if (Buffer.byteLength(body) > 1_048_576) {
      throw new ChronosError('request_too_large', 'The request exceeds the Chronos 1 MiB JSON body limit.');
    }
    const session = await this.#sessionFor(signal);
    signal.throwIfAborted();
    try {
      return await this.#request(path, { session, body, signal, consume: envelope });
    } catch (error) {
      if (!(error instanceof ChronosError)) throw error;
      const { status, code } = error.result;
      if (status === 401 || code === 'csrf_invalid') this.#invalidate(session);
      if (!status || status === 408 || status < 400 || status >= 500) throw uncertainWrite(error);
      throw error;
    }
  }
}
