// npm i ws ioredis
const https = require('https');
const { WebSocketServer } = require('ws');
const Redis = require('ioredis');

const WS_PORT   = 8080;
const REDIS_URL = 'redis://127.0.0.1:6379';
const USERS_KEY = 'follow:active_users';
const CHANNEL   = 'follow:events';

const redis    = new Redis(REDIS_URL);
const redisSub = new Redis(REDIS_URL);

const server = https.createServer((req, res) => {
    if (req.url === '/health') { res.writeHead(200); res.end('ok'); return; }
    res.writeHead(404); res.end();
});
const wss = new WebSocketServer({ server, path: '/ws/follow' });

async function getUsers() {
    const ids = await redis.smembers(USERS_KEY);
    return ids.map(String);
}

function broadcast(obj) {
    const payload = JSON.stringify(obj);
    for (const c of wss.clients) {
        if (c.readyState === 1 /* OPEN */) c.send(payload);
    }
}

wss.on('connection', async (ws, req) => {
    // Здесь можно проверить авторизацию (см. раздел 6)
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
    broadcast(data); // {command:"open_lead"|"close_lead", userId:123}
});

server.listen(WS_PORT, () => console.log('[follow-ws] listening on', WS_PORT));