<?php
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

$userId = (int)$GLOBALS['USER']->GetID();
if (!$userId) {
    http_response_code(401);
    echo 'Не авторизован';
    exit;
}

// Начальные данные: активные пользователи из Redis
$activeUsers = [];
try {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379, 1.0);
    $activeUsers = $redis->smembers('follow:active_users') ?: [];
    $activeUsers = array_values(array_map('intval', $activeUsers));
    $redis->close();
} catch (\Throwable $e) {}

// Настройки WS — те же, что в отчёте
$wsUrl = 'wss://b24.flotenk.ru:8081/ws/follow';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Менеджеры в работе с лидами — онлайн</title>
<style>
    body { font-family: Arial, sans-serif; background: #f5f7fa; margin: 0; padding: 20px; }
    h1 { font-size: 20px; color: #2c3e50; margin: 0 0 16px; }
    .status { font-size: 13px; color: #7f8c8d; margin-bottom: 16px; }
    .status.online { color: #27ae60; }
    .status.offline { color: #c0392b; }
    .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
    .card {
        background: #fff; border-radius: 10px; padding: 14px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        display: flex; gap: 12px; align-items: center;
        transition: opacity 0.3s, transform 0.3s;
        opacity: 1; transform: scale(1);
    }
    .card.removing { opacity: 0; transform: scale(0.9); }
    .card img {
        width: 56px; height: 56px; border-radius: 50%;
        object-fit: cover; background: #e0e0e0; flex-shrink: 0;
    }
    .card .info { min-width: 0; }
    .card .info .name { font-weight: bold; font-size: 14px; color: #2c3e50; }
    .card .info .lead { font-size: 12px; color: #7f8c8d; margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .card .info .uid { font-size: 11px; color: #bdc3c7; margin-top: 4px; }
    .empty { color: #95a5a6; font-size: 14px; padding: 40px; text-align: center; }
</style>
</head>
<body>
<h1>Менеджеры в работе с лидами — онлайн</h1>
<div class="status offline" id="status">Подключение к каналу...</div>
<div class="cards" id="cards"></div>

<script>
(function () {
    var WS_URL = <?= json_encode($wsUrl) ?>;
    var INITIAL_USERS = <?= json_encode($activeUsers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    var users = {};        // userId -> { name, avatar, leadId, leadTitle }
    var cardsEl = document.getElementById('cards');
    var statusEl = document.getElementById('status');
    var ws = null;
    var reconnectTimer = null;
    var reconnectDelay = 1000;
    var MAX_DELAY = 30000;
    var heartbeatTimer = null;

    function setStatus(text, online) {
        statusEl.textContent = text;
        statusEl.className = 'status ' + (online ? 'online' : 'offline');
    }

    // Загружаем данные о пользователях и их лидах через Bitrix
    function loadUsers(userIds) {
        if (!userIds.length) return Promise.resolve();
        return fetch('/local/summary_report/follow_online_data.php?ids=' + userIds.join(','), {
            credentials: 'same-origin',
            cache: 'no-store'
        })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
            if (!data || !data.success) return;
            (data.users || []).forEach(function (u) {
                users[String(u.id)] = {
                    name: u.name,
                    avatar: u.avatar || '/bitrix/images/1.gif',
                    leadId: u.leadId || null,
                    leadTitle: u.leadTitle || ''
                };
            });
            render();
        })
        .catch(function (e) { console.error('[online] loadUsers:', e); });
    }

    function render() {
        var ids = Object.keys(users);
        cardsEl.innerHTML = '';
        if (!ids.length) {
            cardsEl.innerHTML = '<div class="empty">Нет активных пользователей</div>';
            return;
        }
        ids.forEach(function (id) {
            var u = users[id];
            var card = document.createElement('div');
            card.className = 'card';
            card.dataset.uid = id;
            card.innerHTML =
                '<img src="' + escapeHtml(u.avatar) + '" alt="">' +
                '<div class="info">' +
                    '<div class="name">' + escapeHtml(u.name) + '</div>' +
                    '<div class="lead">' + escapeHtml(u.leadTitle || (u.leadId ? 'Лид #' + u.leadId : '—')) + '</div>' +
                    '<div class="uid">ID: ' + escapeHtml(id) + '</div>' +
                '</div>';
            cardsEl.appendChild(card);
        });
    }

    function addUser(uid, leadId) {
        uid = String(uid);
        if (users[uid]) {
            // Уже есть — обновим leadId, если пришёл
            if (leadId) users[uid].leadId = leadId;
            render();
            return;
        }
        users[uid] = { name: 'Загрузка…', avatar: '', leadId: leadId || null, leadTitle: '' };
        render();
        loadUsers([uid]);
    }

    function removeUser(uid) {
        uid = String(uid);
        if (!users[uid]) return;
        var card = cardsEl.querySelector('[data-uid="' + uid + '"]');
        if (card) {
            card.classList.add('removing');
            setTimeout(function () { delete users[uid]; render(); }, 300);
        } else {
            delete users[uid];
            render();
        }
    }

    function handleMessage(msg) {
        if (!msg || typeof msg !== 'object') return;
        var cmd = msg.command || msg.type;
        if (cmd === 'open_lead') {
            addUser(msg.userId, msg.leadId);
        } else if (cmd === 'close_lead') {
            removeUser(msg.userId);
        } else if (cmd === 'state') {
            // Полный снапшот — перезагружаем всех
            var incoming = (msg.users || []).map(String);
            Object.keys(users).forEach(function (id) {
                if (incoming.indexOf(id) === -1) delete users[id];
            });
            incoming.forEach(function (id) {
                if (!users[id]) users[id] = { name: 'Загрузка…', avatar: '', leadId: null, leadTitle: '' };
            });
            render();
            loadUsers(incoming);
        }
    }

    function scheduleReconnect() {
        if (reconnectTimer) return;
        reconnectTimer = setTimeout(function () {
            reconnectTimer = null;
            connect();
        }, reconnectDelay);
        reconnectDelay = Math.min(Math.round(reconnectDelay * 1.8), MAX_DELAY);
    }

    function connect() {
        if (ws && (ws.readyState === WebSocket.CONNECTING || ws.readyState === WebSocket.OPEN)) return;
        try { ws = new WebSocket(WS_URL); }
        catch (e) { scheduleReconnect(); return; }

        ws.onopen = function () {
            reconnectDelay = 1000;
            setStatus('Подключено. Ожидание событий…', true);
            if (heartbeatTimer) clearInterval(heartbeatTimer);
            heartbeatTimer = setInterval(function () {
                if (ws && ws.readyState === WebSocket.OPEN) {
                    ws.send(JSON.stringify({ command: 'ping' }));
                }
            }, 25000);
        };

        ws.onmessage = function (ev) {
            var payload;
            try { payload = JSON.parse(ev.data); } catch (e) { return; }
            handleMessage(payload);
        };

        ws.onclose = function () {
            ws = null;
            if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
            setStatus('Соединение потеряно. Переподключение…', false);
            scheduleReconnect();
        };

        ws.onerror = function () {};
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Старт
    INITIAL_USERS.forEach(function (id) {
        users[String(id)] = { name: 'Загрузка…', avatar: '', leadId: null, leadTitle: '' };
    });
    render();
    loadUsers(INITIAL_USERS.map(String));
    connect();
})();
</script>
</body>
</html>