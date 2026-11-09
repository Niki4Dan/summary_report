<?php

/* require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php"); */
error_reporting(1);
ini_set('display_errors', 1);
ini_set('max_execution_time', 3600);
ini_set('memory_limit', '2048M');




require_once __DIR__ . '/utils.php';



setEnvData();
define('DB_SERVER', $_ENV['DB_SERVER']);
define('DB_NAME', $_ENV['DB_NAME']);
define('DB_USER', $_ENV['DB_USER']);
define('DB_PASSWORD', $_ENV['DB_PASSWORD']);


$connection = mysqli_connect(DB_SERVER, DB_USER, DB_PASSWORD, DB_NAME);


if (!$connection) {
    die("Ошибка подключения: " . mysqli_connect_error());
}

mysqli_set_charset($connection, "utf8mb4");

mysqli_query($connection, "CREATE TABLE IF NOT EXISTS quality_leads_snapshot_dev (
    id INT AUTO_INCREMENT PRIMARY KEY,
    period_start DATETIME NOT NULL,
    period_end DATETIME NOT NULL,
    leads_count INT DEFAULT 0,
    snapshot_data LONGTEXT NOT NULL,
    is_final TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_period (period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


mysqli_query($connection, "CREATE TABLE IF NOT EXISTS summary_report_dev (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date_start DATETIME NOT NULL,
    date_end DATETIME NOT NULL,
    by_managers LONGTEXT NOT NULL,
    is_completed TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    leads_sources LONGTEXT NOT NULL,
    UNIQUE KEY unique_period (date_start, date_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");








/* ============================================================
 * Функции снапшота (без изменений)
 * ============================================================ */

function snapshot_getQualityLeads($startStr, $endStr)
{
    $managersIDs = getManagersIds();
    $params = [
        'select' => [
            'ID','TITLE','NAME','LAST_NAME','ASSIGNED_BY_ID','STATUS_ID',
            'STATUS_SEMANTIC_ID','DATE_CREATE','DATE_MODIFY','DATE_CLOSED',
            'SOURCE_ID','COMPANY_ID','CONTACT_ID','OPPORTUNITY','CURRENCY_ID'
        ],
        'filter' => [
            '>=DATE_CREATE' => $startStr,
            '<=DATE_CREATE' => $endStr,
            '@ASSIGNED_BY_ID' => $managersIDs,
            '@STATUS_SEMANTIC_ID' => ['S'],
        ],
        'order' => ['DATE_CREATE' => 'DESC'],
    ];

    $allLeads = [];
    $start = 0;
    do {
        $params['start'] = $start;
        $resp = callToBitrix('crm.lead.list', $params);
        if (!empty($resp['result'])) {
            $allLeads = array_merge($allLeads, $resp['result']);
        }
        $start = $resp['next'] ?? 0;
    } while (!empty($resp['next']));

    return $allLeads;
}

function snapshot_getDealsByLeadIds(array $leadIds)
{
    if (empty($leadIds)) return [];
    $result = [];
    foreach (array_chunk($leadIds, 50) as $chunk) {
        $params = [
            'select' => [
                'ID','TITLE','LEAD_ID','STAGE_ID','CATEGORY_ID','OPPORTUNITY',
                'CURRENCY_ID','ASSIGNED_BY_ID','DATE_CREATE','DATE_MODIFY',
                'CLOSEDATE','COMPANY_ID','CONTACT_ID'
            ],
            'filter' => ['@LEAD_ID' => $chunk],
        ];
        $start = 0;
        do {
            $params['start'] = $start;
            $resp = callToBitrix('crm.deal.list', $params);
            if (!empty($resp['result'])) {
                foreach ($resp['result'] as $deal) {
                    $result[$deal['LEAD_ID']][] = $deal;
                }
            }
            $start = $resp['next'] ?? 0;
        } while (!empty($resp['next']));
    }
    return $result;
}

function snapshot_getContactsByIds(array $ids)
{
    if (empty($ids)) return [];
    $result = [];
    foreach (array_chunk($ids, 50) as $chunk) {
        $resp = callToBitrix('crm.contact.list', [
            'select' => ['ID','NAME','LAST_NAME','SECOND_NAME','COMPANY_ID','ASSIGNED_BY_ID'],
            'filter' => ['@ID' => $chunk],
        ]);
        if (!empty($resp['result'])) {
            foreach ($resp['result'] as $c) $result[$c['ID']] = $c;
        }
    }
    return $result;
}

function snapshot_getCompaniesByIds(array $ids)
{
    if (empty($ids)) return [];
    $result = [];
    foreach (array_chunk($ids, 50) as $chunk) {
        $resp = callToBitrix('crm.company.list', [
            'select' => ['ID','TITLE','ASSIGNED_BY_ID'],
            'filter' => ['@ID' => $chunk],
        ]);
        if (!empty($resp['result'])) {
            foreach ($resp['result'] as $c) $result[$c['ID']] = $c;
        }
    }
    return $result;
}

function snapshot_getDealStageMap()
{
    $map = [];
    $resp = callToBitrix('crm.status.list', [
        'filter' => ['ENTITY_ID' => 'DEAL_STAGE'],
        'select' => ['STATUS_ID','NAME','SEMANTICS'],
    ]);
    if (!empty($resp['result'])) {
        foreach ($resp['result'] as $s) $map[$s['STATUS_ID']] = $s['NAME'];
    }
    $resp29 = callToBitrix('crm.status.list', [
        'filter' => ['ENTITY_ID' => 'DEAL_STAGE_29'],
        'select' => ['STATUS_ID','NAME'],
    ]);
    if (!empty($resp29['result'])) {
        foreach ($resp29['result'] as $s) $map[$s['STATUS_ID']] = $s['NAME'];
    }
    return $map;
}

function snapshot_getLeadStatusMap()
{
    $resp = callToBitrix('crm.status.list', [
        'filter' => ['ENTITY_ID' => 'STATUS'],
        'select' => ['STATUS_ID','NAME'],
    ]);
    $map = [];
    if (!empty($resp['result'])) {
        foreach ($resp['result'] as $s) $map[$s['STATUS_ID']] = $s['NAME'];
    }
    return $map;
}

function saveQualityLeadsSnapshot($connection, $startStr, $endStr, $isCurrent = false)
{
    // 1. Проверяем наличие снапшота
    $checkSql = "SELECT id, is_final FROM quality_leads_snapshot_dev 
                 WHERE period_start = '" . mysqli_real_escape_string($connection, $startStr) . "'
                   AND period_end   = '" . mysqli_real_escape_string($connection, $endStr) . "'
                 LIMIT 1";
    $checkRes = mysqli_query($connection, $checkSql);
    $existingRow = null;
    if ($checkRes && mysqli_num_rows($checkRes) > 0) {
        $existingRow = mysqli_fetch_assoc($checkRes);
    }
    if ($checkRes) mysqli_free_result($checkRes);

    // 2. Определяем действие
    $action = 'insert';
    if ($existingRow !== null) {
        if ((int)$existingRow['is_final'] === 1) {
            logMessage("⏭️ Снапшот quality за {$startStr} - {$endStr} уже зафиксирован. Пропускаем.");
            return;
        }
        if ($isCurrent) {
            $action = 'update';       // текущая неделя — перезаписываем
        } else {
            $action = 'finalize';     // была текущей на прошлом прогоне — фиксируем
        }
    }

    logMessage("📸 Собираем снапшот quality за {$startStr} - {$endStr} [{$action}]");

    // 3. Сбор данных (без изменений)
    $leads       = snapshot_getQualityLeads($startStr, $endStr);
    $leadIds     = array_column($leads, 'ID');
    $dealsByLead = snapshot_getDealsByLeadIds($leadIds);

    $contactIds = [];
    $companyIds = [];
    foreach ($leads as $lead) {
        if (!empty($dealsByLead[$lead['ID']])) continue;
        if (!empty($lead['CONTACT_ID'])) $contactIds[] = $lead['CONTACT_ID'];
        if (!empty($lead['COMPANY_ID'])) $companyIds[] = $lead['COMPANY_ID'];
    }
    $contacts  = snapshot_getContactsByIds(array_unique($contactIds));
    $companies = snapshot_getCompaniesByIds(array_unique($companyIds));

    $dealStageMap  = snapshot_getDealStageMap();
    $leadStatusMap = snapshot_getLeadStatusMap();

    $managersList = getManagers();
    $managersMap  = [];
    foreach ($managersList as $m) {
        $managersMap[$m['ID']] = trim($m['LAST_NAME'] . ' ' . $m['NAME'] . ' ' . $m['SECOND_NAME']);
    }

    $payload = [
        'period'        => ['start' => $startStr, 'end' => $endStr],
        'leads'         => $leads,
        'dealsByLead'   => $dealsByLead,
        'contacts'      => $contacts,
        'companies'     => $companies,
        'dealStageMap'  => $dealStageMap,
        'leadStatusMap' => $leadStatusMap,
        'managersMap'   => $managersMap,
        'is_final'      => $isCurrent ? 0 : 1,
        'captured_at'   => date('Y-m-d H:i:s'),
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        logMessage("❌ Ошибка json_encode снапшота: " . json_last_error_msg());
        return;
    }

    // 4. Запись в БД
    $finalFlag = $isCurrent ? 0 : 1;
    $jsonEsc   = mysqli_real_escape_string($connection, $json);
    $leadsCnt  = (int)count($leads);

    if ($action === 'insert') {
        $sql = "INSERT INTO quality_leads_snapshot_dev 
                (period_start, period_end, leads_count, snapshot_data, is_final, created_at, updated_at)
                VALUES (
                    '" . mysqli_real_escape_string($connection, $startStr) . "',
                    '" . mysqli_real_escape_string($connection, $endStr) . "',
                    {$leadsCnt},
                    '{$jsonEsc}',
                    {$finalFlag},
                    NOW(),
                    NOW()
                )";
    } else {
        $rowId = (int)$existingRow['id'];
        $sql = "UPDATE quality_leads_snapshot_dev SET 
                    leads_count   = {$leadsCnt},
                    snapshot_data = '{$jsonEsc}',
                    is_final      = {$finalFlag},
                    updated_at    = NOW()
                WHERE id = {$rowId}";
    }

    if (!mysqli_query($connection, $sql)) {
        logMessage("❌ Ошибка записи снапшота: " . mysqli_error($connection));
    } else {
        logMessage("✅ Снапшот quality сохранён [{$action}]. Лидов: {$leadsCnt}, is_final={$finalFlag}");
    }
}




logMessage("Запуск cron-обработки сводного отчета");

$lockFile = __DIR__ . '/summary_report.lock';
if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    if (time() - $lockTime < 3600) {
        logMessage("Процесс уже запущен. Пропускаем.");
        mysqli_close($connection);
        exit;
    }
}
file_put_contents($lockFile, time());


try {

    $baseStart = isset($_GET['date_start'])
        ? DateTime::createFromFormat('Y-m-d H:i:s', $_GET['date_start'])
        : new DateTime();

    $baseEnd = isset($_GET['date_end'])
        ? DateTime::createFromFormat('Y-m-d H:i:s', $_GET['date_end'])
        : new DateTime();

    $processedCount = 0;
    $errorCount = 0;
    $skippedCount = 0;

    // 3 предыдущие недели (Sat..Fri), от свежей к старой
    $weeks = [];
    for ($i = 0; $i < 3; $i++) {
        $weekBaseStart = clone $baseStart;
        $weekBaseEnd   = clone $baseEnd;

        if ($i > 0) {
            $weekBaseStart->modify("-{$i} weeks");
            $weekBaseEnd->modify("-{$i} weeks");
        }

        $start = clone $weekBaseStart;
        $start->modify("-1 weeks");
        $start->modify('previous saturday');
        $startStr = $start->format('Y-m-d 00:00:00');

        $end = clone $weekBaseEnd;
        $end->modify('previous friday');
        $endStr = $end->format('Y-m-d 23:59:59');

        $weeks[] = ['start' => $startStr, 'end' => $endStr];
    }

    $weeks = array_reverse($weeks); // $weeks[2] = самая свежая предыдущая

    // ----- Определяем ТЕКУЩУЮ неделю (Sat..upcoming Friday) -----
    $today = clone $baseStart;
    $dow   = (int)$today->format('N'); // 1=Mon..7=Sun
    $daysSinceSaturday = ($dow + 1) % 7; // 0=Sat, 1=Sun, ..., 6=Fri

    $currentStart = clone $today;
    if ($daysSinceSaturday > 0) {
        $currentStart->modify("-{$daysSinceSaturday} days");
    }
    $currentStart->setTime(0, 0, 0);

    $currentEnd = clone $currentStart;
    $currentEnd->modify("+6 days");
    $currentEnd->setTime(23, 59, 59);

    $currentStartStr = $currentStart->format('Y-m-d H:i:s');
    $currentEndStr   = $currentEnd->format('Y-m-d H:i:s');

    // ----- Снапшот самой свежей ПРЕДЫДУЩЕЙ недели (фиксируется) -----
    try {
        saveQualityLeadsSnapshot($connection, $weeks[2]['start'], $weeks[2]['end']);
    } catch (Exception $e) {
        logMessage("❌ Ошибка снапшота за {$weeks[2]['start']} - {$weeks[2]['end']}: " . $e->getMessage());
    }

 // ----- Снапшот ТЕКУЩЕЙ недели (живой, is_final = 0) -----
    try {
        saveQualityLeadsSnapshot($connection, $currentStartStr, $currentEndStr, true);
    } catch (Exception $e) {
        logMessage("❌ Ошибка снапшота текущей недели {$currentStartStr} - {$currentEndStr}: " . $e->getMessage());
    }
    // ----- Текущая неделя добавляется в конец (после $weeks[2]) -----
    $weeks[] = [
        'start'      => $currentStartStr,
        'end'        => $currentEndStr,
        'is_current' => true,
    ];

    foreach ($weeks as $week) {
        $startStr  = $week['start'];
        $endStr    = $week['end'];
        $isCurrent = !empty($week['is_current']);

        logMessage("Проверка недели ({$startStr} - {$endStr})" . ($isCurrent ? " [ТЕКУЩАЯ]" : ""));

        // Ищем существующую строку
        $checkSql = "SELECT id, is_completed FROM summary_report_dev 
                     WHERE date_start = '" . mysqli_real_escape_string($connection, $startStr) . "' 
                       AND date_end   = '" . mysqli_real_escape_string($connection, $endStr) . "' 
                     LIMIT 1";
        $checkResult = mysqli_query($connection, $checkSql);

        if ($checkResult === false) {
            $errorCount++;
            logMessage("❌ Ошибка проверки недели {$startStr} - {$endStr}: " . mysqli_error($connection));
            continue;
        }

        $existingRow = null;
        if (mysqli_num_rows($checkResult) > 0) {
            $existingRow = mysqli_fetch_assoc($checkResult);
        }
        mysqli_free_result($checkResult);

        // Решаем, что делать
        $action = 'insert';
        if ($existingRow !== null) {
            if ($isCurrent) {
                $action = 'update';
            } else {
                if ((int)$existingRow['is_completed'] === 1) {
                    $skippedCount++;
                    logMessage("⏭️ Неделя {$startStr} - {$endStr} уже зафиксирована. Пропускаем.");
                    continue;
                } else {
                    $action = 'finalize';
                }
            }
        }

        try {
            logMessage("Обработка недели ({$startStr} - {$endStr}) [{$action}]");

            $leadsByManagersArr = getLeadsAndDealsByManagers($startStr, $endStr);

            if (empty($leadsByManagersArr)) {
                logMessage("Нет данных для недели {$startStr} - {$endStr}");
            }

            // Передаём диапазон явно, чтобы источники считались именно за эту неделю
            $sourcesJSON = mysqli_real_escape_string($connection, getSources($startStr, $endStr));
            $jsonData    = mysqli_real_escape_string(
                $connection,
                json_encode($leadsByManagersArr[0] ?? [], JSON_UNESCAPED_UNICODE)
            );

            $completedFlag = $isCurrent ? 0 : 1;

            if ($action === 'insert') {
                $sql = "INSERT INTO summary_report_dev 
                        (date_start, date_end, by_managers, is_completed, created_at, updated_at, leads_sources) 
                        VALUES ('{$startStr}', '{$endStr}', '{$jsonData}', {$completedFlag}, NOW(), NOW(), '{$sourcesJSON}')";
            } else {
                $rowId = (int)$existingRow['id'];
                $sql = "UPDATE summary_report_dev SET 
                            by_managers  = '{$jsonData}', 
                            is_completed = {$completedFlag}, 
                            updated_at   = NOW(), 
                            leads_sources = '{$sourcesJSON}' 
                        WHERE id = {$rowId}";
            }

            if (!mysqli_query($connection, $sql)) {
                throw new Exception("Ошибка запроса: " . mysqli_error($connection));
            }
            $processedCount++;
            logMessage("✅ Неделя {$startStr} - {$endStr} обработана [{$action}]");
        } catch (Exception $e) {
            $errorCount++;
            logMessage("❌ Ошибка обработки недели {$startStr} - {$endStr}: " . $e->getMessage());
        }
    }

    logMessage("Обработка завершена. Обработано: {$processedCount}, пропущено: {$skippedCount}, ошибок: {$errorCount}");
} catch (Exception $e) {
    logMessage("Критическая ошибка: " . $e->getMessage());
} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
    if ($connection) {
        mysqli_close($connection);
    }
}
