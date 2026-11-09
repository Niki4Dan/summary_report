<?php
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

// Подключаем utils.php для callToBitrix()
// Путь подправьте под реальное расположение файла относительно этого скрипта
require_once __DIR__ . '/utils.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    CMain::FinalActions();
    exit;
}

$currentUserId = (int)$GLOBALS['USER']->GetID();
if (!$currentUserId) out(['success' => false, 'error' => 'Не авторизован']);

if (!Loader::includeModule('crm')) out(['success' => false, 'error' => 'crm не подключён']);

// Список ID из запроса
$idsParam = (string)($_GET['ids'] ?? '');
$ids = array_values(array_filter(array_map('intval', explode(',', $idsParam)), function ($v) { return $v > 0; }));
if (!$ids) out(['success' => true, 'users' => []]);

// Redis — узнаём, какой лид у каждого пользователя в работе
$leadByUser = [];
try {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379, 1.0);
    foreach ($ids as $uid) {
        $raw = $redis->get('user:' . $uid);
        if (!$raw) continue;
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && !empty($decoded['leadId'])) {
            $leadByUser[$uid] = (int)$decoded['leadId'];
        }
    }
    $redis->close();
} catch (\Throwable $e) {
    // не критично — просто не будет leadId
}

// Пользователи через Bitrix (callToBitrix)
$users = [];

$resp = callToBitrix('user.get', [
    'filter' => ['@ID' => $ids],
    'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'PERSONAL_PHOTO'],
]);



if (!empty($resp['error'])) {
    out([
        'success' => false,
        'error'   => 'user.get: ' . ($resp['error_description'] ?? 'unknown'),
    ]);
}

foreach (($resp['result'] ?? []) as $u) {
    $uid = (int)$u['ID'];
    $name = trim(($u['LAST_NAME'] ?? '') . ' ' . ($u['NAME'] ?? '') . ' ' . ($u['SECOND_NAME'] ?? ''));
    if ($name === '') $name = 'Пользователь #' . $uid;

    $avatar = $u['PERSONAL_PHOTO'];


    $users[$uid] = [
        'id'        => $uid,
        'name'      => $name,
        'avatar'    => $avatar,
        'leadId'    => $leadByUser[$uid] ?? null,
        'leadTitle' => '',
    ];
}

// Названия лидов — одним запросом через callToBitrix
$leadIds = array_values(array_filter(array_map(function ($u) { return $u['leadId']; }, $users)));
if ($leadIds) {
    $leadsResp = callToBitrix('crm.lead.list', [
        'filter' => ['@ID' => $leadIds],
        'select' => ['ID', 'TITLE'],
    ]);

    if (empty($leadsResp['error'])) {
        $titleById = [];
        foreach (($leadsResp['result'] ?? []) as $l) {
            $titleById[(int)$l['ID']] = $l['TITLE'] ?? '';
        }
        foreach ($users as $uid => &$u) {
            if ($u['leadId'] && isset($titleById[$u['leadId']])) {
                $u['leadTitle'] = $titleById[$u['leadId']];
            }
        }
        unset($u);
    }
}

out(['success' => true, 'users' => array_values($users)]);