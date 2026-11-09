<?php
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function out($data)
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    CMain::FinalActions();
    exit;
}

$userId = (int)$GLOBALS['USER']->GetID();
if (!$userId) {
    out(['success' => false, 'error' => 'Не авторизован']);
}

try {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379, 1.0);
    // $redis->auth('...');
    $users = $redis->smembers('follow:active_users') ?: [];
    $users = array_values(array_map('intval', $users));
    out(['success' => true, 'users' => $users]);
} catch (\Throwable $e) {
    out(['success' => false, 'error' => 'Redis: ' . $e->getMessage()]);
}