<?php
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

header('Content-Type: application/json; charset=utf-8');

function respond($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    CMain::FinalActions();
    exit;
}

function followLog($message)
{
    $logFile  = __DIR__ . '/summary_report.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[{$timestamp}] follow: {$message}\n", FILE_APPEND);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['status' => 'error', 'message' => 'Method not allowed'], 405);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        respond(['status' => 'error', 'message' => 'Некорректный JSON'], 400);
    }

    $userId = (int)$GLOBALS['USER']->GetID();
    if (!$userId) {
        respond(['status' => 'error', 'message' => 'Не авторизован'], 401);
    }

    $leadId = (int)($data['leadId'] ?? 0);
    $action = (string)($data['action'] ?? '');
    $ts     = (int)($data['ts'] ?? 0);

    if ($leadId <= 0) respond(['status' => 'error', 'message' => 'Не указан ID лида'], 400);
    if (!in_array($action, ['open', 'close'], true)) {
        respond(['status' => 'error', 'message' => 'Неверный action'], 400);
    }
    if ($ts <= 0) $ts = (int)(microtime(true) * 1000);

    if (!Loader::includeModule('crm')) {
        respond(['status' => 'error', 'message' => 'Модуль crm не подключён'], 500);
    }

    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379, 1.0);
    } catch (\Throwable $e) {
        followLog("redis connect error user={$userId}: " . $e->getMessage());
        respond(['status' => 'error', 'message' => 'Redis: ' . $e->getMessage()], 500);
    }

    $ttl    = 300;
    $key    = 'user:' . $userId;
    $setKey = 'follow:active_users';

    $result = 'error';

    if ($action === 'open') {
        // Читаем текущее состояние (для отсечения stale)
        $curRaw = $redis->get($key);
        $cur    = $curRaw ? json_decode($curRaw, true) : null;

        if ($cur && isset($cur['ts']) && (int)$cur['ts'] > $ts) {
            // Пришло устаревшее событие — игнорируем
            $result = 'stale';
        } else {
            $wasOpen = $cur !== null;

            // Записываем новое состояние с TTL
            $redis->setex($key, $ttl, json_encode([
                'leadId' => (string)$leadId,
                'ts'     => $ts,
            ]));
            $redis->sadd($setKey, (string)$userId);

            // Публикуем только при реальном переходе closed -> open
            // (или смене лида, если wasOpen, но leadId другой — тоже считаем открытием)
            $isNewOpen = !$wasOpen || (string)($cur['leadId'] ?? '') !== (string)$leadId;

            if ($isNewOpen) {
                $redis->publish('follow:events', json_encode([
                    'command' => 'open_lead',
                    'userId'  => $userId,
                    'leadId'  => $leadId,
                    'ts'      => $ts,
                ], JSON_UNESCAPED_UNICODE));
                $result = 'opened';
            } else {
                $result = 'same';
            }
        }
    } else { // close
        $curRaw = $redis->get($key);
        $cur    = $curRaw ? json_decode($curRaw, true) : null;

        if ($cur && isset($cur['ts']) && (int)$cur['ts'] > $ts) {
            $result = 'stale';
        } elseif (!$cur) {
            $result = 'not-open';
        } elseif ((string)($cur['leadId'] ?? '') !== (string)$leadId) {
            $result = 'different-lead';
        } else {
            $redis->del($key);
            $redis->srem($setKey, (string)$userId);
            $redis->publish('follow:events', json_encode([
                'command' => 'close_lead',
                'userId'  => $userId,
                'leadId'  => $leadId,
                'ts'      => $ts,
            ], JSON_UNESCAPED_UNICODE));
            $result = 'closed';
        }
    }

    followLog("user={$userId} lead={$leadId} action={$action} ts={$ts} result={$result}");

    $redis->close();

    respond(['success' => true, 'result' => $result]);
} catch (\Throwable $e) {
    followLog('exception: ' . $e->getMessage());
    respond(['status' => 'error', 'message' => 'Исключение: ' . $e->getMessage()], 500);
}