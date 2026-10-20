import { test, expect, login } from './fixture';

test('LIVE-04: socket projections enforce ACLs, accept view replacement bursts and reject ticket replay', async ({ page }) => {
  await login(page);
  const ticket = (await (await page.request.get('/api/ui/session')).json()).data.live.ticket as string;
  const { frames, burstOpen } = await page.evaluate(async ticket => {
    const socket = new WebSocket(`${location.protocol === 'https:' ? 'wss:' : 'ws:'}//${location.host}/api/ui/live`, ['chronos-ui-v1', `chronos-ticket.${ticket}`]);
    return new Promise<{ frames: any[]; burstOpen: boolean }>((resolve, reject) => {
      const result: any[] = [];
      let burstStarted = false;
      const timer = setTimeout(() => { socket.close(); reject(new Error('Socket authorization or replacement snapshots timed out.')); }, 10_000);
      socket.onerror = () => { clearTimeout(timer); reject(new Error('The authenticated test socket failed.')); };
      socket.onclose = event => { clearTimeout(timer); reject(new Error(`The authenticated test socket closed (${event.code}: ${event.reason}).`)); };
      socket.onopen = () => {
        for (const [id, path] of [['private', '/jobs/14'], ['admin', '/cem/nodes'], ['download', '/jobs/1/download'], ['allowed', '/jobs/1']]) {
          socket.send(JSON.stringify({ type: 'subscribe', id, path, query: {} }));
        }
      };
      socket.onmessage = event => {
        const frame = JSON.parse(event.data);
        if (!['data', 'error'].includes(frame.type)) return;
        if (!burstStarted) {
          result.push(frame);
          if (result.length === 4) {
            burstStarted = true;
            // Fast sort/filter changes replace one authorized view. Exercise the
            // real transport with 40 valid frames while keeping four views open.
            let previous = 'allowed';
            for (let index = 1; index <= 20; index++) {
              const id = `burst-${index}`;
              socket.send(JSON.stringify({ type: 'unsubscribe', id: previous }));
              socket.send(JSON.stringify({ type: 'subscribe', id, path: '/jobs/1', query: {} }));
              previous = id;
            }
          }
        } else if (frame.id === 'burst-20') {
          result.push(frame); clearTimeout(timer);
          resolve({ frames: result, burstOpen: socket.readyState === WebSocket.OPEN });
          socket.close();
        }
      };
    });
  }, ticket);
  expect(frames.find(value => value.id === 'private')).toMatchObject({ type: 'error', status: 403 });
  expect(frames.find(value => value.id === 'admin')).toMatchObject({ type: 'error', status: 403 });
  expect(frames.find(value => value.id === 'download')).toMatchObject({ type: 'error', status: 404 });
  expect(frames.find(value => value.id === 'allowed')).toMatchObject({ type: 'data', data: { id: 1 } });
  expect(frames.find(value => value.id === 'burst-20')).toMatchObject({ type: 'data', data: { id: 1 } });
  expect(burstOpen, 'Rapid authorized view replacements must preserve the open connection.').toBe(true);
  expect(JSON.stringify(frames)).not.toContain('Private Evaluation');
  const replayed = await page.evaluate(ticket => new Promise<boolean>(resolve => {
    const socket = new WebSocket(`${location.protocol === 'https:' ? 'wss:' : 'ws:'}//${location.host}/api/ui/live`, ['chronos-ui-v1', `chronos-ticket.${ticket}`]);
    const timer = setTimeout(() => { socket.close(); resolve(false); }, 8_000);
    socket.onopen = () => { clearTimeout(timer); socket.close(); resolve(true); };
    socket.onerror = () => { clearTimeout(timer); resolve(false); };
  }), ticket);
  expect(replayed, 'An opaque WebSocket ticket may be consumed only once.').toBe(false);
});
