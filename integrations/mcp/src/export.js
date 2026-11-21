import { createHash } from 'node:crypto';
import { createWriteStream } from 'node:fs';
import { mkdtemp, rm } from 'node:fs/promises';
import { join } from 'node:path';
import { Transform } from 'node:stream';
import { pipeline } from 'node:stream/promises';
import { ChronosError } from './api.js';

const formats = {
  csv: { endpoint: 'results.csv', extension: 'csv', mediaType: 'text/csv' },
  zip: { endpoint: 'download', extension: 'zip', mediaType: 'application/zip' },
  log: { endpoint: 'log/download', extension: 'log', mediaType: 'text/plain' },
};

export function exportEvidence(api, config, { scope, id, format }, signal) {
  if (format === 'log' && scope !== 'job') {
    throw new ChronosError('invalid_format', 'Log files are available for individual jobs.');
  }
  const media = formats[format];
  return download(api, config, `/${scope}s/${id}/${media.endpoint}`, `${scope}-${id}`, media, { scope, id, format }, signal);
}

export function exportAttemptEvidence(api, config, { jobId, attemptId, format }, signal) {
  const endpoint = format === 'zip' ? 'archive' : 'log';
  return download(api, config, `/jobs/${jobId}/attempts/${attemptId}/${endpoint}`, `attempt-${attemptId}`,
    formats[format], { jobId, attemptId, format }, signal);
}

async function download(api, config, endpoint, basename, { extension, mediaType }, metadata, signal) {
  if (!config.exportDir) {
    throw new ChronosError('export_not_configured', 'Set CHRONOS_EXPORT_DIR to an existing directory on the MCP host.');
  }
  const directory = await mkdtemp(join(config.exportDir, 'chronos-')).catch(() => {
    throw new ChronosError('export_write_failed', 'Could not create a private export directory in CHRONOS_EXPORT_DIR.');
  });
  const file = join(directory, `${basename}.${extension}`);

  try {
    return await api.get(endpoint, {}, signal, async (response, requestSignal) => {
      const contentType = response.headers.get('content-type')?.split(';', 1)[0].trim().toLowerCase();
      const limitError = new ChronosError('export_too_large', 'The evidence exceeds CHRONOS_MAX_EXPORT_BYTES.');
      const length = Number(response.headers.get('content-length'));
      if (!response.body || contentType !== mediaType || response.status !== 200) {
        await response.body?.cancel();
        throw new ChronosError('invalid_download', 'Chronos did not return a complete download in the expected format.');
      }
      if (!response.headers.has('content-encoding') && length > config.exportLimit) {
        await response.body.cancel();
        throw limitError;
      }
      const digest = createHash('sha256');
      let sizeBytes = 0;
      const meter = new Transform({
        transform(chunk, _encoding, callback) {
          sizeBytes += chunk.length;
          if (sizeBytes > config.exportLimit) return callback(limitError);
          digest.update(chunk);
          callback(null, chunk);
        },
      });
      await pipeline(response.body, meter, createWriteStream(file, { flags: 'wx', mode: 0o600 }), {
        signal: requestSignal,
      });
      return { ...metadata, path: file, mediaType, sizeBytes, sha256: digest.digest('hex') };
    });
  } catch (error) {
    await rm(directory, { recursive: true });
    if (error instanceof ChronosError) throw error;
    signal.throwIfAborted();
    throw new ChronosError('export_failed', 'The evidence transfer failed. The partial file was removed.');
  }
}
