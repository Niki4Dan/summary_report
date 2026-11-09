<?php
// Мини-эндпоинт для polling статуса фонового обновления текущей недели.

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$runningFlag = __DIR__ . '/current_week_refresh.running';

// Уборка залежавшегося флага (напр. процесс убит по timeout/OOM)
if (file_exists($runningFlag) && (time() - filemtime($runningFlag)) > 300) {
    @unlink($runningFlag);
}

echo json_encode([
    'running' => file_exists($runningFlag),
    'now'     => time(),
]);