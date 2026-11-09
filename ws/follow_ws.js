import { createServer } from 'https';
import { readFileSync } from 'fs';
import { WebSocketServer } from 'ws';
import Redis from 'ioredis';
import 'dotenv/config';

const server = createServer({
  cert: readFileSync(process.env.TLS_CERT),
  key: readFileSync(process.env.TLS_KEY)
});

const wss = new WebSocketServer({ server, path: '/ws/follow' });

const REDIS_URL = 'redis://127.0.0.1:6379';
const USERS_KEY = 'follow:active_users';
const CHANNEL   = 'follow:events';

const redis    = new Redis(REDIS_URL);
const redisSub = new Redis(REDIS_URL);

async function getUsers() {
  const ids = await redis.smembers(USERS_KEY);
  return ids.map(String);
}

function broadcast(obj) {
  const payload = JSON.stringify(obj);
  for (const c of wss.clients) {
    if (c.readyState === 1) c.send(payload);
  }
}

wss.on('connection', async (ws) => {
  try {
    const users = await getUsers();
    ws.send(JSON.stringify({ command: 'state', users }));
  } catch (e) {
    console.error('[follow-ws] state send error:', e);
  }

  ws.on('message', (raw) => {
    try {
      const msg = JSON.parse(raw);
      if (msg && msg.command === 'ping') {
        ws.send(JSON.stringify({ command: 'pong' }));
      }
    } catch (_) {}
  });

  ws.on('error', (e) => console.error('[follow-ws] client error:', e.message));
});

redisSub.subscribe(CHANNEL, (err) => {
  if (err) console.error('[follow-ws] subscribe error:', err);
  else     console.log('[follow-ws] subscribed to', CHANNEL);
});

redisSub.on('message', (channel, message) => {
  if (channel !== CHANNEL) return;
  let data;
  try { data = JSON.parse(message); } catch (e) { return; }
  console.log('[follow-ws] event:', data);
  broadcast(data);
});

server.listen(8081, () => console.log('[follow-ws] listening on 8081'));