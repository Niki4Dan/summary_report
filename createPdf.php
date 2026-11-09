<?
/* error_reporting(E_ALL);
ini_set('display_errors', 1); */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('NO_LANG_FILES', true);

$GLOBALS['RAW_POST_BODY'] = file_get_contents('php://input');
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');




$userIdsJson = null;

if (isset($_GET['pdf'])) {
    if ($_GET['pdf'] == 1) {
        $isPdf = true;
    } else {
        $isPdf = false;
    }
} else {
    $isPdf = false;
}


$sapi = php_sapi_name();


if ($sapi == 'cli') {
    $isCLI = true;
    $isWeb = false;
} else if ($sapi == 'apache2handler') {
    $isCLI = false;
    $isWeb = true;
} else {
    $isCLI = false;
    $isCLI = false;
}

require_once __DIR__ . '/utils.php';
// Проверяем, была ли отправлена форма


setEnvData();
define('DB_SERVER', $_ENV['DB_SERVER']);
define('DB_NAME', $_ENV['DB_NAME']);
define('DB_USER', $_ENV['DB_USER']);
define('DB_PASSWORD', $_ENV['DB_PASSWORD']);

$data = [];

$connection = mysqli_connect(DB_SERVER, DB_USER, DB_PASSWORD, DB_NAME);

if (!$connection) {
    die("Ошибка подключения: " . mysqli_connect_error());
}

/*   echo "Успешное подключение к базе данных!"; */
mysqli_set_charset($connection, "utf8mb4");

$refreshState      = 'none';      // none | started | already-running | debounced | unavailable
$refreshStartedAt  = 0;

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && !$isPdf
    && !isset($_GET['script'])       // PDF-режим не трогаем
    && isset($_GET['date_start'])
) {
    // Начало текущей недели (Sat..Fri от «сегодня»)
    $_now          = new DateTime();
    $_dow          = (int)$_now->format('N');          // 1..7
    $_daysSinceSat = ($_dow + 1) % 7;                  // 0 для Sat, 1 для Sun, ...
    $_curStart     = clone $_now;
    if ($_daysSinceSat > 0) {
        $_curStart->modify("-{$_daysSinceSat} days");
    }
    $_curStart->setTime(0, 0, 0);
    $_curStartStr  = $_curStart->format('Y-m-d H:i:s');

    // Триггерим только когда открыта именно текущая неделя
    if ($_GET['date_start'] === $_curStartStr) {
        $runningFlag  = __DIR__ . '/current_week_refresh.running';
        $debounceLock = __DIR__ . '/current_week_refresh.lock';
        $getDataPath  = __DIR__ . '/get_data.php';
        $debounceSec  = 60;

        // Чистим залежавшиеся флаги
        if (file_exists($runningFlag) && (time() - filemtime($runningFlag)) > 300) {
            @unlink($runningFlag);
        }
        $staleLock = __DIR__ . '/summary_report.lock';
        if (file_exists($staleLock) && (time() - filemtime($staleLock)) > 300) {
            @unlink($staleLock);
        }

        if (file_exists($runningFlag)) {
            // Уже идёт (наш предыдущий запрос или cron) — просто поллим
            $refreshState     = 'already-running';
            $refreshStartedAt = (int)filemtime($runningFlag);
        } elseif (file_exists($debounceLock) && (time() - filemtime($debounceLock)) < $debounceSec) {
            // Недавно обновляли — данные достаточно свежие
            $refreshState = 'debounced';
        } else {
            $execDisabled = in_array(
                'exec',
                array_map('trim', explode(',', (string)ini_get('disable_functions')))
            );

            if ($execDisabled || !file_exists($getDataPath)) {
                $refreshState = 'unavailable';
            } else {
                @touch($debounceLock);
                @touch($runningFlag);

                // Путь к PHP CLI
                $phpBinary = PHP_BINARY;
                if (empty($phpBinary) || !is_executable($phpBinary)) {
                    $phpBinary = PHP_BINDIR . '/php';
                }
                if (!is_executable($phpBinary)) {
                    $phpBinary = 'php';
                }

                // $inner: запуск с timeout + гарантированная очистка флага после выхода
                $inner = 'timeout 120 '
                    . escapeshellarg($phpBinary) . ' '
                    . escapeshellarg($getDataPath)
                    . ' > /dev/null 2>&1; rm -f ' . escapeshellarg($runningFlag);

                // nohup + </dev/null: отвязываем от SIGHUP/STDIN Apache-воркера
                $cmd = 'nohup sh -c ' . escapeshellarg($inner)
                    . ' < /dev/null > /dev/null 2>&1 &';

                @exec($cmd);

                $refreshState     = 'started';
                $refreshStartedAt = time();
            }
        }






        $userIds = [];
        try {
            $redis = new Redis();
            $redis->connect('127.0.0.1', 6379, 1.0);
            $userIds = $redis->smembers('follow:active_users') ?: [];
            $userIds = array_values(array_map('intval', $userIds));
            $redis->close();
        } catch (\Throwable $e) { /* отдадим пустой список, дальше refresh синхронизирует */
        }

        $userIdsJson = json_encode($userIds, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}



$weeks = 4;

$dataTmp = [];
$dataForHChart = [];

$baseStart = isset($_GET['date_start'])
    ? DateTime::createFromFormat('Y-m-d H:i:s', $_GET['date_start'])
    : new DateTime();

$baseEnd = isset($_GET['date_end'])
    ? DateTime::createFromFormat('Y-m-d H:i:s', $_GET['date_end'])
    : new DateTime();

if (isset($_GET['date_start']) && isset($_GET['date_end'])) {
    for ($i = 0; $i < $weeks; $i++) {
        $start = clone $baseStart;
        $end = clone $baseEnd;

        if ($i == 0) {
            // Для первой недели используем переданные даты
            $startStr = $start->format('Y-m-d 00:00:00');
            $endStr = $end->format('Y-m-d 23:59:59');


            $sqlGetSources = "SELECT * FROM summary_report_dev WHERE date_start = '$startStr' AND date_end = '$endStr';";
            $resultGetSources = mysqli_query($connection, $sqlGetSources);

            $rowSources = $resultGetSources ? mysqli_fetch_assoc($resultGetSources) : null;

            $sources = null;
            if ($rowSources && !empty($rowSources['leads_sources'])) {
                $sources = json_decode($rowSources['leads_sources'], true);
            }
            if ($sources === null) {
                $sources = ['leadsWithSources' => [], 'sourceStatistics' => ['TOTAL_COUNT' => 0]];
            }
        } else {
            // Для остальных недель отнимаем недели
            $start->modify("-{$i} weeks");
            $end->modify("-{$i} weeks");
            $startStr = $start->format('Y-m-d 00:00:00');
            $endStr = $end->format('Y-m-d 23:59:59');
        }



        $selectGetData = "SELECT * FROM summary_report_dev WHERE date_start = '$startStr' AND date_end = '$endStr';";
        $result = mysqli_query($connection, $selectGetData);

        $row = mysqli_fetch_assoc($result);



        // ИСПРАВЛЕНИЕ: Проверяем, найдена ли запись
        if ($row === null) {
            // Если запись не найдена, создаем пустую структуру
            $dataTmp[$i] = [
                'date_start' => $startStr,
                'date_end' => $endStr,
                'TOTAL_LEADS' => 0,
                'by_managers' => '{"MANAGERS":{}}',
                'MANAGERS' => []
            ];
        } else {
            $dataTmp[$i] = $row;
        }

        // ИСПРАВЛЕНИЕ: Проверяем, что by_managers существует и не null
        if (isset($dataTmp[$i]['by_managers']) && !empty($dataTmp[$i]['by_managers'])) {
            $decodedManagers = json_decode($dataTmp[$i]['by_managers'], true);
            if ($decodedManagers !== null) {
                $dataTmp[$i] = $dataTmp[$i] + $decodedManagers;

                // ИСПРАВЛЕНИЕ: Проверяем, что MANAGERS существует и является массивом
                if (isset($dataTmp[$i]['MANAGERS']) && is_array($dataTmp[$i]['MANAGERS'])) {
                    foreach ($dataTmp[$i]['MANAGERS'] as $id => $managerData) {
                        $dataForHChart[$i][$id]['COUNT_LEADS'] = $managerData['COUNT_LEADS'] ?? 0;
                    }
                }
            }
        } else {
            // Если by_managers нет, создаем пустую структуру
            $dataTmp[$i]['MANAGERS'] = [];
        }

        // Безопасное удаление ключей
        if (isset($dataTmp[$i]['by_managers'])) {
            unset($dataTmp[$i]['by_managers']);
        }
        if (isset($dataTmp[$i]['adv_time_work'])) {
            unset($dataTmp[$i]['adv_time_work']);
        }
    }


    $hasData = true;
    if ($dataTmp[1]["TOTAL_LEADS"] == 0) {
        $hasData = false;
    }
} else {
    for ($i = 1; $i <= $weeks; $i++) {
        $start = clone $baseStart;
        $start->modify("-{$i} weeks");
        $start->modify('previous saturday');
        $startStr = $start->format('Y-m-d 00:00:00'); // начало дня

        $end = clone $baseEnd;
        $end->modify("-" . ($i - 1) . " weeks");
        $end->modify('previous friday');
        $endStr = $end->format('Y-m-d 23:59:59'); // конец дня

        if ($i == 1) {
            $sqlGetSources = "SELECT * FROM summary_report_dev WHERE date_start = '$startStr' AND date_end = '$endStr';";
            $resultGetSources = mysqli_query($connection, $sqlGetSources);

            $rowSources = $resultGetSources ? mysqli_fetch_assoc($resultGetSources) : null;

            $sources = null;
            if ($rowSources && !empty($rowSources['leads_sources'])) {
                $sources = json_decode($rowSources['leads_sources'], true);
            }
            if ($sources === null) {
                $sources = ['leadsWithSources' => [], 'sourceStatistics' => ['TOTAL_COUNT' => 0]];
            }
        }


        $selectGetData = "SELECT * FROM summary_report_dev WHERE date_start = '$startStr' AND date_end = '$endStr';";
        $result = mysqli_query($connection, $selectGetData);

        $row = mysqli_fetch_assoc($result);

        // ИСПРАВЛЕНИЕ: Проверяем, найдена ли запись
        if ($row === null) {
            // Если запись не найдена, создаем пустую структуру
            $dataTmp[$i] = [
                'date_start' => $startStr,
                'date_end' => $endStr,
                'TOTAL_LEADS' => 0,
                'by_managers' => '{"MANAGERS":{}}',
                'MANAGERS' => []
            ];
        } else {
            $dataTmp[$i] = $row;
        }

        // ИСПРАВЛЕНИЕ: Проверяем, что by_managers существует и не null
        if (isset($dataTmp[$i]['by_managers']) && !empty($dataTmp[$i]['by_managers'])) {
            $decodedManagers = json_decode($dataTmp[$i]['by_managers'], true);
            if ($decodedManagers !== null) {
                $dataTmp[$i] = $dataTmp[$i] + $decodedManagers;

                // ИСПРАВЛЕНИЕ: Проверяем, что MANAGERS существует и является массивом
                if (isset($dataTmp[$i]['MANAGERS']) && is_array($dataTmp[$i]['MANAGERS'])) {
                    foreach ($dataTmp[$i]['MANAGERS'] as $id => $managerData) {
                        $dataForHChart[$i][$id]['COUNT_LEADS'] = $managerData['COUNT_LEADS'] ?? 0;
                    }
                }
            }
        } else {
            // Если by_managers нет, создаем пустую структуру
            $dataTmp[$i]['MANAGERS'] = [];
        }

        // Безопасное удаление ключей
        if (isset($dataTmp[$i]['by_managers'])) {
            unset($dataTmp[$i]['by_managers']);
        }
        if (isset($dataTmp[$i]['adv_time_work'])) {
            unset($dataTmp[$i]['adv_time_work']);
        }
    }

    $hasData = true;
    if ($dataTmp[1]["TOTAL_LEADS"] == 0) {
        $hasData = false;
    }
}


// Обогащаем каждую неделю значением «конвертировано в контакт/компанию».
// Считается один раз на страницу — затем используется и в таблице, и в PDF.
foreach ($dataTmp as $idx => &$row) {
    if (!empty($row['date_start']) && !empty($row['date_end'])) {
        $row['CONVERTED_LEADS'] = getConvertedLeadsCountForPeriod(
            $connection,
            $row['date_start'],
            $row['date_end']
        );
    } else {
        $row['CONVERTED_LEADS'] = 0;
    }
}
unset($row);




?>




<?




if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    try {
        $raw = $GLOBALS['RAW_POST_BODY'] ?? file_get_contents('php://input');
        $input = json_decode($raw, true);



        if (isset($input['charts'])) {

            saveChartsToPDF($input['charts'], $dataTmp, $sources);
        } elseif (isset($_POST['chart_image'])) {
            saveChartToPDF($_POST['chart_image'], $dataTmp, $sources);
        } else {
            throw new Exception('Нет данных для обработки');
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Ошибка: ' . $e->getMessage()
        ]);
    }
    exit;
}



// Если это GET запрос - выводим HTML
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

?>
    <!DOCTYPE html>
    <html>

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Сводный отчет</title>
        <script src="chart.umd.min.js"></script>
        <style>
            /* @media print {
                body {
                    width: 100%;
                    margin: 0;
                    padding: 0;
                    font-size: 10pt !important;
                }

                .container {
                    max-width: 100% !important;
                    padding: 0 !important;
                }

                .chart-wrapper {
                    max-width: 100% !important;
                    page-break-inside: avoid !important;
                }

                .no-parted {
                    page-break-inside: avoid !important;
                    page-break-after: avoid !important;
                }

                table {
                    page-break-inside: auto !important;
                }

                tr {
                    page-break-inside: avoid !important;
                    page-break-after: auto !important;
                }

                td,
                th {
                    font-size: 8pt !important;
                    padding: 3px 3px !important;
                }

                #LineChartSumLeads {
                    width: 650px !important;
                    height: 250px !important;
                }
            } */

            body {
                font-family: Arial, sans-serif;
                padding: 20px;
            }

            .container {
                max-width: 800px;
                margin: 0 auto;
            }

            .chart-wrapper {

                padding: 20px;
                border-radius: 8px;

                margin-bottom: 20px;
                max-width: 800px;
            }


            #LineChartSumLeads {
                width: 100% !important;
                height: 400px !important;
            }

            #HorizontalGisto {
                width: 100% !important;
                height: 900px !important;
            }

            #DepartmentLineChart {
                width: 100% !important;
                height: 400px !important;
            }

            .button_wrapper {
                display: flex;
                justify-content: center;
                align-items: center;
                flex-direction: column;
            }

            .btn-pdf {
                background: #4CAF50;
                color: white;
                border: none;
                padding: 12px 30px;
                font-size: 16px;
                border-radius: 5px;
                margin: 10px auto;
                cursor: pointer;
                transition: background 0.3s;
            }

            .btn-pdf:hover {
                background: #45a049;
            }

            .btn-pdf:disabled {
                background: #cccccc;
                cursor: not-allowed;
            }

            .message {
                padding: 10px;
                margin: 10px 0;
                border-radius: 5px;
                display: none;
            }

            .message.success {
                display: block;
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }

            .message.error {
                display: block;
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }

            #loading {
                display: none;
                margin: 10px 0;
                color: #666;
            }

            .dashboard {
                display: flex;
            }

            /* ===== САЙДБАР ===== */
            .sitebar-menu {
                position: fixed;
                display: block;
                border-right: 1px solid #009355;
                max-height: 100vh;
                overflow-y: auto;
                width: 200px;
                background: white;
                z-index: 1000;
                top: 0;
                left: 0;
                padding: 10px;
                transform: translateX(-110%);
                transition: transform 0.3s ease;
                box-shadow: 4px 0 20px rgba(0, 0, 0, 0.15);
            }

            .sitebar-menu.open {
                transform: translateX(0);
            }

            .date-menu {
                list-style: none;
                padding: 0;
                margin: 0;
            }

            .date-menu-item {
                padding: 5px 10px;
                border-bottom: 1px solid #009355;
                background-color: #82ffcb;
                cursor: pointer;
            }

            .active {
                background-color: #009355;
                color: white;
            }

            /* Стилизация скроллбара - тонкая линия */
            .sitebar-menu::-webkit-scrollbar {
                width: 3px;
            }

            .sitebar-menu::-webkit-scrollbar-track {
                background: transparent;
            }

            .sitebar-menu::-webkit-scrollbar-thumb {
                background: #009355;
                border-radius: 10px;
            }

            .sitebar-menu::-webkit-scrollbar-thumb:hover {
                background: #007a45;
            }

            .sitebar-menu {
                scrollbar-width: thin;
                scrollbar-color: #009355 transparent;
            }

            .no-parted {
                page-break-inside: avoid;
            }

            /* ===== БУРГЕР-МЕНЮ ===== */
            .burger-btn {
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                width: 30px;
                height: 22px;
                background: none;
                border: none;
                cursor: pointer;
                padding: 0;
                margin-bottom: 15px;
                z-index: 1001;
                position: fixed;
                left: 20px;
            }

            .burger-btn.active {
                transform: translateX(225px);
                transition: 0.5s all ease;
            }

            /* ===== АНИМАЦИЯ ПУЛЬСАЦИИ ДЛЯ БУРГЕР-КНОПКИ ===== */
            @keyframes pulse-attention {
                0% {
                    box-shadow: 0 0 0 0 rgba(0, 147, 85, 0.7);
                    /* transform: scale(1); */
                }

                50% {
                    box-shadow: 0 0 0 15px rgba(0, 147, 85, 0);
                    /* transform: scale(1.05); */
                }

                100% {
                    box-shadow: 0 0 0 0 rgba(0, 147, 85, 0);
                    /* transform: scale(1); */
                }
            }

            .burger-btn.pulse {
                animation: pulse-attention 0.8s ease-out;
                border-color: #009355;
            }

            .burger-btn span {
                display: block;
                width: 100%;
                height: 3px;
                background: #009355;
                border-radius: 3px;
                transition: 0.3s ease;
            }

            .burger-btn.active span:nth-child(1) {
                transform: translateY(9px) rotate(45deg);
                background: #00c875;
            }

            .burger-btn.active span:nth-child(2) {
                opacity: 0;
                background: #00c875;
            }

            .burger-btn.active span:nth-child(3) {
                transform: translateY(-9px) rotate(-45deg);
                background: #00c875;
            }

            .menu-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 999;
            }

            .menu-overlay.active {
                display: block;
            }

            .mobile-header {
                display: flex;
                /* align-items: center; */
                gap: 12px;
                margin-bottom: 20px;
                padding: 10px 0;
            }

            .mobile-header h3 {
                margin: 0;
                font-size: 18px;
                color: #009355;
            }

            /* ===== АДАПТИВ ===== */
            @media (max-width: 768px) {
                .dashboard {
                    flex-direction: column;
                }
            }


            /* Добавляем разрыв перед элементом */
            .pb-before {
                page-break-before: always;
            }

            /* Добавляем разрыв после элемента */
            .pb-after {
                page-break-after: always;
            }

            /* Предотвращаем разрыв внутри элемента */
            .pb-inside {
                page-break-inside: avoid;
            }
        </style>

    </head>

    <body>

        <?php
        $managersHtml = getManagersHTML($dataTmp, $connection);

        $selectedDate = isset($_GET['date_start']) ? $dataTmp[0] : $dataTmp[1];


        $dateStartKP = date('Y-m-d\TH:i:s', strtotime($selectedDate["date_start"]));
        $dateEndKP = date('Y-m-d\TH:i:s', strtotime($selectedDate["date_end"]));
        $readyData = getDataKP($dateStartKP, $dateEndKP);


        // Подготавливаем данные для вывода
        $departmentsTmp = getDepartments();
        $managers = getManagers();

        // Группируем данные по менеджерам
        $managersData = [];
        foreach ($readyData as $key => $value) {
            if ($key === 'TOTAL_OPPORTUNITY' || $key === 'TOTAL_KP') {
                continue;
            }

            // $key - это ID отдела
            foreach ($value as $managerId => $managerInfo) {
                $managersData[$managerId] = $managerInfo;
            }
        }

        // Вызываем функцию
        $htmlTableKP = getKPManagersHTML($managersData, $departmentsTmp, $managers);

        $htmlReport = getHtml(
            $managersHtml,
            $dataTmp,
            $htmlTableKP,
            $sources,
            [],
            $isPdf,
            $connection,
            $userIdsJson
        );







        ?>
        <div class="dashboard">
            <?php if (!$isPdf) {
                $sqlGetDates = "SELECT DISTINCT date_start, date_end FROM summary_report_dev ORDER BY date_start DESC;";
                $resultDates = mysqli_query($connection, $sqlGetDates);

                $dateArray = array();

                while ($row = mysqli_fetch_assoc($resultDates)) {
                    $dateArray[] = $row;
                }


                mysqli_close($connection);
            ?>


                <!-- Бургер-кнопка всегда видна -->
                <div class="mobile-header">
                    <button class="burger-btn" id="burgerToggle" aria-label="Открыть меню">
                        <h3>📅</h3>
                    </button>

                </div>

                <!-- Оверлей для затемнения -->
                <div class="menu-overlay" id="menuOverlay"></div>

                <div class="sitebar-menu" id="dateSidebar">
                    <div class="selectButtons">
                        <div class="selectDays active">По неделям</div>
                        <div class="selectMounths">По месяцам</div>
                    </div>
                    <ul class="date-menu">
                        <?php
                        foreach ($dateArray as $date) {

                            $date_start = DateTime::createFromFormat('Y-m-d H:i:s', $date['date_start']);
                            $date_end = DateTime::createFromFormat('Y-m-d H:i:s', $date['date_end']);

                            $displayStart = clone $date_start;
                            //$displayStart->modify('+2 days');
                            $dateText = $displayStart->format('d.m');


                            $startStr = $date_start->format('Y-m-d 00:00:00');
                            $endStr = $date_end->format('Y-m-d 23:59:59');
                        ?>
                            <li class="date-menu-item <?php
                                                        $isActive = false;
                                                        if (isset($_GET['date_start'])) {
                                                            // Декодируем URL-параметр
                                                            $getDate = urldecode($_GET['date_start']);
                                                            $getDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $getDate);
                                                            if ($getDateTime && $getDateTime->format('Y-m-d') == $date_start->format('Y-m-d')) {
                                                                $isActive = true;
                                                            }
                                                        }
                                                        echo $isActive ? 'active' : '';
                                                        ?>" onclick="window.location.href='?date_start=<?php echo urlencode($startStr); ?>&date_end=<?php echo urlencode($endStr); ?>'">
                                <?= $dateText . ' - ' . $date_end->format('d.m.Y') ?>
                            </li>

                        <? }

                        ?>
                    </ul>
                </div>
            <?php } ?>

            <?
            if ($hasData) {
                if (!isset($_GET['script'])) {


            ?>
                    <div class="container">
                        <?php if (!$isPdf) { ?>
                            <div class="button_wrapper">
                                <button class="btn-pdf" onclick="save()">
                                    📄 Сохранить в PDF
                                </button>

                                <div id="loading">⏳ Генерация PDF...</div>
                                <div id="message" class="message"></div>
                            </div>
                        <?php }





                        ?>

                        <?= $htmlReport  ?>
                        <? if ($isWeb) { ?>
                            <div style="margin-top:20pt;">
                                <?= getSourcesTableHTML($sources, false) ?>
                            </div>
                        <? } ?>
                        <div class="no-parted pb-before pb-inside">
                            <h3 style="text-align: center;">Динамика количества лидов</h3>
                            <div class="chart-wrapper" style="padding-top:0;">
                                <canvas id="LineChartSumLeads"></canvas>
                            </div>
                        </div>
                        <div class="no-parted">
                            <h3 style="text-align: center;">Количество лидов по отделам за отчетную неделю</h3>
                            <div class="chart-wrapper">
                                <canvas id="DoughnutChart" style="width: 100% !important; height: 400px !important;"></canvas>
                            </div>
                        </div>
                        <div class="no-parted">
                            <h3 style="text-align: center;">Динамика количества лидов по менеджерам (за&nbsp;последние&nbsp;4&nbsp;отчетные&nbsp;недели)</h3>
                            <div class="chart-wrapper">
                                <canvas id="HorizontalGisto"></canvas>
                            </div>
                        </div>
                        <div class="no-parted">
                            <h3 style="text-align: center;">Динамика распределения лидов по отделам, шт</h3>
                            <div class="chart-wrapper">
                                <div style="position: relative; width: 100%; height: 400px;">
                                    <canvas id="DepartmentAbsoluteChart" width="1200" height="600"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="no-parted">
                            <h3 style="text-align: center;">Динамика распределения лидов по отделам, %</h3>
                            <div class="chart-wrapper">

                                <div style="position: relative; width: 100%; height: 400px;">
                                    <canvas id="DepartmentLineChart" width="1200" height="600"></canvas>
                                </div>
                            </div>
                        </div>



                        <div style="margin-top:20pt; margin-bottom:30pt;">
                            <div class="table-wrapper">
                                <h3 style="text-align:center; margin-bottom:8pt;">Статистика по выставленным КП*</h3>
                                <p style="font-style:italic; text-align:center; font-size:8pt; margin:0; margin-bottom:10px;">* Данные неточные, необходимо скорректировать механизм обработки КП. Сейчас отображаются данные сделок, попавших на стадию ТКП, а также те, которые эту стадию пропустили (за исключением забракованных сделок) за прошлую отчетную неделю.</p>
                                <?= $htmlTableKP ?>


                            </div>
                        </div>
                    </div>




                <?php
                } else {
                ?>

                    <style>
                        @page {
                            margin: 1cm 1cm 1cm 1cm;
                        }

                        @media print {
                            body {
                                width: 100%;
                                margin: 0;
                                padding: 0;

                            }


                            .container {
                                max-width: 100%;
                                margin: 0 auto;
                            }

                            .chart-wrapper {
                                max-width: 100% !important;
                                page-break-inside: avoid !important;
                            }

                            .chart-wrapper canvas {
                                margin: 0 auto;
                            }

                            .no-parted {
                                page-break-inside: avoid !important;
                                page-break-after: avoid !important;
                            }

                            /*  table,
                            table * {
                                box-sizing: border-box !important;
                            }

                            table td,
                            table th {
                                padding: 5px 8px !important;
                                border: 1px solid #8f8f8f !important;
                            }

                            .table-wrapper table td,
                            .table-wrapper table th {
                                padding: 5px 8px !important;
                                font-size: 10pt !important;
                            }

                            /* Специфичные стили для ваших классов */
                            /*                             .small {
                                padding: 5px 8px !important;
                                font-size: 10pt !important;
                            }

                            .left {
                                padding: 5px 8px !important;
                                text-align: left !important;
                                font-size: 10pt !important;
                            }
 */
                            /* Для таблицы с источниками */
                            /*                             .iksweb td,
                            .iksweb th {
                                padding: 5px 8px !important;
                                font-size: 10pt !important;
                            }  */

                            #LineChartSumLeads {
                                width: 100% !important;
                                /* height: 275px !important; */
                            }

                            #HorizontalGisto {
                                width: 717.8px !important;
                                height: 850px !important;
                            }

                            #DepartmentLineChart {
                                width: 100% !important;
                                height: 400px !important;
                            }

                            #DoughnutChart {
                                margin-top: 10px;
                                width: 80% !important;
                                /* height: 281.3px !important; */
                            }

                            #DepartmentAbsoluteChart,
                            #DepartmentLineChart {
                                width: 1100px !important;
                                height: 550px !important;
                            }

                            .g1,
                            .g2,
                            .g3,
                            .g4,
                            table td {
                                font-size: 10pt !important;
                                padding: 5px !important;
                            }

                            table th {
                                font-size: 12pt !important;
                                padding: 5px !important;
                            }




                        }

                        /* Добавляем разрыв перед элементом */
                        .pb-before {
                            page-break-before: always;
                        }

                        /* Добавляем разрыв после элемента */
                        .pb-after {
                            page-break-after: always;
                        }

                        /* Предотвращаем разрыв внутри элемента */
                        .pb-inside {
                            page-break-inside: avoid;
                        }
                    </style>
                    <div class="container">


                        <?= $htmlReport  ?>
                        <div style="margin-top:20pt;">
                            <?= getSourcesTableHTML($sources, false) ?>
                        </div>
                        <table style="padding: 0; border:none; ">
                            <tr style="padding: 0; border:none;">
                                <td style="padding: 0; width:65%; border:none; vertical-align:top;">
                                    <h3 style="text-align: center;">Динамика количества лидов</h3>
                                    <div class="chart-wrapper" style="padding-top:0;">
                                        <canvas id="LineChartSumLeads"></canvas>
                                    </div>
                                </td>
                                <td style="padding: 0; width:35%; border:none; vertical-align:top;">
                                    <h3 style="text-align: center;">Количество лидов по отделам за&nbsp;отчетную&nbsp;неделю</h3>
                                    <div class="chart-wrapper">
                                        <canvas id="DoughnutChart" style="width: 100% !important; height: 400px !important;"></canvas>
                                    </div>
                                </td>
                            </tr>

                        </table>
                        <div class="no-parted">
                            <h3 style="text-align: center;">Динамика количества лидов по менеджерам (за&nbsp;последние&nbsp;4&nbsp;отчетные&nbsp;недели)</h3>
                            <div class="chart-wrapper">
                                <canvas id="HorizontalGisto"></canvas>
                            </div>
                        </div>
                        <div class="pb-after pb-inside">
                            <h3 style="text-align: center;">Динамика распределения лидов по отделам, шт</h3>
                            <div class="chart-wrapper">
                                <div style="position: relative;">
                                    <canvas id="DepartmentAbsoluteChart" width="900" height="450"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="no-parted">
                            <h3 style="text-align: center;">Динамика распределения лидов по отделам, %</h3>
                            <div class="chart-wrapper">

                                <div style="position: relative;">
                                    <canvas id="DepartmentLineChart" width="1200" height="600"></canvas>
                                </div>
                            </div>
                        </div>


                        <div style="margin-top:20pt; margin-bottom:30pt;">
                            <div class="table-wrapper">
                                <h3 style="text-align:center; margin-bottom:8pt;">Статистика по выставленным КП*</h3>
                                <p style="font-style:italic; text-align:center; font-size:8pt; margin:0; margin-bottom:10px;">* Данные неточные, необходимо скорректировать механизм обработки КП. Сейчас отображаются данные сделок, попавших на стадию ТКП, а также те, которые эту стадию пропустили (за исключением забракованных сделок) за прошлую отчетную неделю.</p>
                                <?= $htmlTableKP ?>


                            </div>
                        </div>
                    </div>
                <?

                }

                ?>
        </div><?
            } else {

                ?>
        <div class="container">
            <p>Данные за прошлую отчетную неделю ещё не собраны. Выберите другую отчетную неделю из списка слева</p>
        </div>


    <?
            }

    ?>

    <?php
    function getUserPropertiesById($users, $id, $dep)
    {

        foreach ($users as $user) {
            if ((string)$user['ID'] === (string)$id) {
                $fullName = $user['LAST_NAME'] . ' ' . $user['NAME'] . ' ' . $user['SECOND_NAME'];
                $isLeader = getIsLeader($dep, $user);
                return ['FULLNAME' => $fullName, 'IS_LEADER' => $isLeader];
            }
        }
        return null; // или 'Пользователь не найден'
    }

    $managers = getManagers();
    $dep = getDepartments();

    $tmpManagersHC = [];

    foreach ($managers as &$manager) {


        $manager['IS_LEADER'] = getIsLeader($dep, $manager);

        $deptId = $manager['UF_DEPARTMENT'][0];
        if (!isset($tmpManagersHC[$deptId])) {
            $tmpManagersHC[$deptId] = [];
        }
        $tmpManagersHC[$deptId][] = $manager;
    }



    unset($manager);


    $depTMP = sortDepartmentsAndUsers($tmpManagersHC);

    /*         echo "<pre>";
        var_dump($managers, true);
        echo "</pre>"; */
    foreach ($dataForHChart as &$week) {
        $newWeek = [];
        foreach ($week as $managerID => $value) {
            $props = getUserPropertiesById($managers, $managerID, $dep);

            if ($props) {
                $value['COUNT_LEADS'] = $value['COUNT_LEADS'] ?? 0;
                $value['IS_LEADER'] = $props['IS_LEADER'];
                $value['ID'] = $managerID;
                $newWeek[$props['FULLNAME']] = $value;
            }
        }
        $week = $newWeek; // Заменяем исходный массив новым
    }
    unset($week);

    ?>
    <script>
        // Глобальный счетчик готовых графиков
        let readyChartsCount = 0;
        const totalCharts = 5;

        // Функция для разблокировки кнопки
        function enableButton() {
            const btn = document.querySelector('.btn-pdf');
            if (btn) {
                btn.disabled = false;
                console.log('✅ Кнопка разблокирована!');
            }
        }

        // Функция проверки готовности всех графиков
        function checkAllChartsReady() {
            if (readyChartsCount >= totalCharts) {
                enableButton();
                return true;
            }

            // Дополнительная проверка: все ли canvas отрисованы
            const canvasElements = document.querySelectorAll('canvas');
            let allDrawn = true;
            canvasElements.forEach(canvas => {
                // Проверяем, что canvas имеет содержимое
                const dataUrl = canvas.toDataURL();
                if (dataUrl === 'data:,' || dataUrl.length < 100) {
                    allDrawn = false;
                }
            });

            if (allDrawn && canvasElements.length >= totalCharts) {
                // Если все canvas отрисованы, но счетчик не обновился - форсируем
                if (readyChartsCount < totalCharts) {
                    readyChartsCount = totalCharts;
                    console.log('✅ Графики принудительно отмечены как готовые');
                    enableButton();
                    return true;
                }
            }

            return false;
        }

        function createChart(canvasId, config = {}) {
            const ctx = document.getElementById(canvasId);

            if (!ctx) {
                console.error(`Canvas с ID "${canvasId}" не найден`);
                return null;
            }

            // Добавляем плагин для отслеживания готовности
            if (!config.plugins) {
                config.plugins = [];
            }

            config.plugins.push({
                id: 'chartReady_' + canvasId,
                afterDraw: function(chart) {
                    if (!chart._isReady) {
                        chart._isReady = true;
                        chart.isReady = true;

                        if (!window.charts) {
                            window.charts = {};
                        }
                        window.charts[canvasId] = chart;

                        readyChartsCount++;
                        console.log(`✅ График "${canvasId}" готов (${readyChartsCount}/${totalCharts})`);

                        // Проверяем все графики
                        checkAllChartsReady();
                    }
                }
            });

            // Создаем график
            const chart = new Chart(ctx, config);
            chart.isReady = false;
            chart._isReady = false;

            if (!window.charts) {
                window.charts = {};
            }
            window.charts[canvasId] = chart;

            return chart;
        }

        const labels = [];
        const labelsForHC = [];
        const data = [];
        const datasets = [];
        <?php
        $iStart = isset($_GET['date_start']) ? 3 : 4;
        $iEnd = isset($_GET['date_start']) ? 0 : 1;

        for ($i = $iStart; $i >= $iEnd; $i--): ?>
            var label = getDayFromDate("<?= $dataTmp[$i]["date_start"] ?>") +
                ' - ' +
                getDayMonthFormatted("<?= $dataTmp[$i]["date_end"] ?>");
            labels.push(
                label
            );
            data.push(<?= $dataTmp[$i]["TOTAL_LEADS"] ?>);


        <?php endfor; ?>


        const managerIds = [];
        const dataForHC = new Map();

        <?php for ($i = $iStart; $i >= $iEnd; $i--): ?>
            dataForHC.set(<?= $i; ?>, new Map());
        <?php endfor; ?>

        <?php
        foreach ($depTMP as $dep) {
            foreach ($dep as $manager) {
                // Добавляем ID менеджера в массив
                echo "managerIds.push({$manager['ID']});\n";
                // Добавляем имя менеджера в labelsForHC
        ?>
                labelsForHC.push("<?php echo $manager["LAST_NAME"] . ' ' . $manager["NAME"]; ?>");

                <?php for ($i = $iStart; $i >= $iEnd; $i--): ?>
                    <?php if (isset($dataTmp[$i]['MANAGERS'][$manager['ID']]['COUNT_LEADS'])): ?>
                        dataForHC.get(<?= $i; ?>).set(<?= $manager['ID'] ?>, <?= $dataTmp[$i]['MANAGERS'][$manager['ID']]['COUNT_LEADS'] ?>);
                    <?php else: ?>
                        dataForHC.get(<?= $i; ?>).set(<?= $manager['ID'] ?>, 0);
                    <?php endif; ?>
                <?php endfor; ?>
        <?php
            }
        }
        ?>


        const periods = [
            <?php for ($i = $iStart; $i >= $iEnd; $i--): ?> {
                    index: <?= $i; ?>,
                    label: getDayFromDate("<?= $dataTmp[$i]["date_start"] ?>") + ' - ' + getDayMonthFormatted("<?= $dataTmp[$i]["date_end"] ?>")
                },
            <?php endfor; ?>
        ];

        const colors = [
            'rgba(255, 99, 132, 0.7)', // розовый
            'rgba(75, 192, 192, 0.7)', // бирюзовый
            'rgba(153, 102, 255, 0.7)', // фиолетовый
            'rgba(255, 159, 64, 0.7)' // оранжевый
        ];

        const borderColors = [
            'rgb(255, 99, 132)',
            'rgb(75, 192, 192)',
            'rgb(153, 102, 255)',
            'rgb(255, 159, 64)'
        ];



        // Формируем datasets динамически
        const dynamicDatasets = periods.map((period, idx) => {
            const periodData = [];
            const periodMap = dataForHC.get(period.index);

            // Для каждого менеджера получаем данные
            managerIds.forEach(managerId => {
                const value = periodMap.get(managerId) || 0;
                periodData.push(value);
            });

            return {
                axis: 'y',
                label: period.label,
                data: periodData,
                backgroundColor: colors[idx % colors.length],
                borderColor: borderColors[idx % borderColors.length],
                borderWidth: 2,
                borderRadius: 4,
                barPercentage: 0.7,
                categoryPercentage: 0.6
            };
        });






        // Конфигурация первого графика
        const configHorizontalGisto = {
            type: 'bar',
            data: {
                labels: labelsForHC,
                /* менеджеры */
                datasets: dynamicDatasets
            },
            options: {
                indexAxis: 'y', // Горизонтальная ориентация
                responsive: true,
                maintainAspectRatio: false,
                offset: true,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            font: {
                                size: 12,
                                weight: 'bold'
                            },
                            padding: 20,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.parsed.x + ' лидов';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: false,
                        beginAtZero: true,
                        grid: {
                            display: true,
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        title: {
                            display: true,
                            text: 'Количество лидов',
                            font: {
                                size: 14,
                                weight: 'bold'
                            }
                        }
                    },
                    y: {
                        grid: {
                            display: false
                        },
                        title: {
                            display: true,
                            text: 'Менеджеры',
                            font: {
                                size: 14,
                                weight: 'bold'
                            }
                        },
                        ticks: {
                            font: {
                                size: 12
                            },
                            padding: 8
                        }
                    }
                },
                animation: {
                    duration: 1000,
                    easing: 'easeOutQuart',
                    onComplete: function() {
                        // Рисуем значения после анимации для ВСЕХ датасетов
                        const chartInstance = this;
                        const ctx = chartInstance.ctx;

                        ctx.save();

                        // Проходим по ВСЕМ датасетам
                        chartInstance.data.datasets.forEach((dataset, datasetIndex) => {
                            const meta = chartInstance.getDatasetMeta(datasetIndex);

                            meta.data.forEach((element, index) => {
                                const value = dataset.data[index];
                                if (value === 0) return; // пропускаем нулевые значения

                                // Координаты для горизонтального бара
                                const x = element.x + 6; // справа от столбца
                                const y = element.y;

                                // Настройка текста
                                ctx.font = 'bold 9px Arial';
                                ctx.textAlign = 'left';
                                ctx.textBaseline = 'middle';
                                ctx.fillStyle = '#2c3e50';

                                // Рисуем белый фон для читаемости (опционально)
                                const text = String(value);
                                const metrics = ctx.measureText(text);
                                const padding = 4;
                                const width = metrics.width + padding * 2;
                                const height = 20;

                                ctx.fillStyle = 'transparent';
                                ctx.fillRect(x - 2, y - height / 2, width, height);

                                // Рисуем текст
                                ctx.fillStyle = '#2c3e50';
                                ctx.fillText(text, x + 2, y);
                            });
                        });

                        ctx.restore();

                        setTimeout(() => {
                            const canvas = document.getElementById('HorizontalGisto');
                            if (canvas) {
                                const dataUrl = canvas.toDataURL();
                                if (dataUrl !== 'data:,' && dataUrl.length > 100) {
                                    console.log('🔄 Дополнительная проверка: HorizontalGisto готов');
                                    checkAllChartsReady();
                                }
                            }
                        }, 100);
                    }
                },
                layout: {
                    padding: {
                        top: 0,
                        bottom: 20,
                        left: 10,
                        right: 40
                    }
                }
            }
        };

        function getDayFromDate(dateString) {
            const date = new Date(dateString);
            const day = date.getDate();
            return String(day).padStart(2, '0');
        }

        // Получить день и месяц
        function getDayMonthFormatted(dateString) {
            const date = new Date(dateString);
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            return `${day}.${month}`;
        }







        // Конфигурация второго графика
        const LineChartSumLeadsConfig = {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: '',
                    data: data,
                    fill: false,
                    borderColor: 'rgb(75, 192, 192)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                // 🔥 ДОБАВЛЯЕМ ОТСТУПЫ СВЕРХУ И СНИЗУ
                layout: {
                    padding: {
                        top: 30, // отступ сверху для значений
                        bottom: 10,
                        left: 10,
                        right: 10
                    }
                },
                // 🔥 НАСТРАИВАЕМ МАСШТАБ, ЧТОБЫ БЫЛ ЗАПАС
                scales: {
                    y: {
                        beginAtZero: false,
                        // Добавляем запас сверху в 15% от максимума
                        suggestedMax: function(context) {
                            const max = Math.max(...context.chart.data.datasets[0].data);
                            return max * 1.02; // 15% запаса сверху
                        }
                    }
                },
                animation: {
                    onComplete: function() {
                        setTimeout(() => {
                            const chartInstance = this;
                            const ctx = chartInstance.ctx;
                            const meta = chartInstance.getDatasetMeta(0);

                            ctx.save();
                            ctx.font = 'bold 12px Arial';
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'bottom';

                            meta.data.forEach((element, index) => {
                                const value = chartInstance.data.datasets[0].data[index];
                                const x = element.x;
                                const y = element.y - 10;

                                const text = String(value);
                                const metrics = ctx.measureText(text);
                                const padding = 6;
                                const width = metrics.width + padding * 2;
                                const height = 24;

                                ctx.fillStyle = 'transparent';
                                ctx.fillRect(x - width / 2, y - height + 4, width, height);

                                ctx.fillStyle = '#333';
                                ctx.fillText(text, x, y + 4);
                            });

                            ctx.restore();
                            const canvas = document.getElementById('LineChartSumLeads');
                            if (canvas) {
                                const dataUrl = canvas.toDataURL();
                                if (dataUrl !== 'data:,' && dataUrl.length > 100) {
                                    console.log('🔄 Дополнительная проверка: LineChartSumLeads готов');
                                    checkAllChartsReady();
                                }
                            }
                        }, 100);
                    }
                },
                plugins: {
                    legend: {
                        display: false,
                        position: 'top'
                    }
                }
            }
        };


        const doughnutData = {
            labels: [],
            datasets: [{
                label: 'Лиды по отделам',
                data: [],
                backgroundColor: [
                    'rgba(173, 255, 47, 0.8)', // g1 - Емкостное оборуд.
                    // g2 - КХБ
                    // g3 - Пултрузия
                    'rgba(223, 255, 41, 0.8)', // g4 - Спец.ёмкости
                    'rgba(215, 167, 255, 0.8)',
                    'rgba(255, 187, 29, 0.8)'
                ],
                borderColor: [
                    'rgb(173, 255, 47)',

                    'rgb(238, 255, 152)',
                    'rgb(215, 167, 255)',
                    'rgb(255, 187, 29)'
                ],
                borderWidth: 2,
                hoverOffset: 6
            }]
        };

        <?php
        // Собираем данные по отделам
        $departmentLeads = [];
        $managers = getManagers();
        $departmentsTmp = getDepartments();

        foreach ($managers as $manager) {
            $deptId = $manager['UF_DEPARTMENT'][0];
            $deptName = $departmentsTmp[$deptId]['NAME'] ?? 'Неизвестный отдел';

            if (!isset($departmentLeads[$deptId])) {
                $departmentLeads[$deptId] = [
                    'name' => $deptName,
                    'count' => 0
                ];
            }

            if (isset($dataTmp[1]['MANAGERS'][$manager['ID']])) {
                $departmentLeads[$deptId]['count'] += $selectedDate['MANAGERS'][$manager['ID']]['COUNT_LEADS'];
            }
        }

        // Сортируем по убыванию
        uasort($departmentLeads, function ($a, $b) {
            return $b['count'] - $a['count'];
        });

        foreach ($departmentLeads as $deptId => $deptData) {
            echo "doughnutData.labels.push('" . addslashes($deptData['name']) . "');\n";
            echo "doughnutData.datasets[0].data.push(" . $deptData['count'] . ");\n";
        }
        ?>


        // Конфигурация doughnut диаграммы
        const configDoughnut = {
            type: 'doughnut',
            data: doughnutData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '50%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                size: 11,
                                weight: 'bold'
                            },
                            padding: 15,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            generateLabels: function(chart) {
                                const data = chart.data;
                                return data.labels.map((label, i) => {
                                    const value = data.datasets[0].data[i];
                                    // 🔥 Ограничиваем длину текста в легенде
                                    let displayText = label + ' (' + value + ' лид.)';
                                    // Если текст слишком длинный - обрезаем с многоточием
                                    if (displayText.length > 30) {
                                        displayText = displayText.substring(0, 27) + '...';
                                    }
                                    return {
                                        text: displayText,
                                        fillStyle: data.datasets[0].backgroundColor[i],
                                        strokeStyle: data.datasets[0].borderColor[i],
                                        lineWidth: 2,
                                        hidden: false,
                                        index: i,
                                        // 🔥 Добавляем полный текст для подсказки
                                        fullText: label + ' (' + value + ' лид.)'
                                    };
                                });
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.parsed / total) * 100).toFixed(1);
                                return context.label + ': ' + context.parsed + ' лидов (' + percentage + '%)';
                            }
                        }
                    }
                },
                animation: {
                    onComplete: function() {
                        setTimeout(() => {
                            // Рисуем проценты на дугах и общее количество в центре
                            const chartInstance = this;
                            const ctx = chartInstance.ctx;
                            const {
                                data,
                                chartArea: {
                                    top,
                                    bottom,
                                    left,
                                    right
                                }
                            } = chartInstance;

                            const centerX = (left + right) / 2;
                            const centerY = (top + bottom) / 2;

                            // Получаем внутренний и внешний радиусы
                            const meta = chartInstance.getDatasetMeta(0);
                            if (!meta.data || meta.data.length === 0) return;

                            // Используем первый элемент для определения радиусов
                            const firstArc = meta.data[0];
                            const outerRadius = firstArc.outerRadius || Math.min(right - left, bottom - top) / 2 * 0.9;
                            const innerRadius = firstArc.innerRadius || outerRadius * 0.5;

                            // Радиус для текста - посередине между внутренним и внешним радиусом
                            const textRadius = (innerRadius + outerRadius) / 2;

                            const total = data.datasets[0].data.reduce((a, b) => a + b, 0);

                            ctx.save();

                            // Рисуем проценты на дугах
                            meta.data.forEach((arc, index) => {
                                const value = data.datasets[0].data[index];
                                if (value === 0) return;

                                const percentage = ((value / total) * 100).toFixed(1);

                                const startAngle = arc.startAngle;
                                const endAngle = arc.endAngle;
                                const midAngle = (startAngle + endAngle) / 2;

                                // Позиция текста на середине дуги
                                const x = centerX + Math.cos(midAngle) * textRadius;
                                const y = centerY + Math.sin(midAngle) * textRadius;

                                const text = percentage + '%';
                                ctx.font = 'bold 13px Arial';
                                ctx.textAlign = 'center';
                                ctx.textBaseline = 'middle';

                                // Проверяем, что текст не выходит за границы
                                const metrics = ctx.measureText(text);
                                const padding = 6;
                                const textWidth = metrics.width + padding * 2;
                                const textHeight = 26;

                                // Проверяем границы холста
                                const canvasWidth = ctx.canvas.width;
                                const canvasHeight = ctx.canvas.height;
                                let finalX = x;
                                let finalY = y;

                                // Корректируем позицию, если текст выходит за границы
                                if (finalX - textWidth / 2 < 0) finalX = textWidth / 2 + 5;
                                if (finalX + textWidth / 2 > canvasWidth) finalX = canvasWidth - textWidth / 2 - 5;
                                if (finalY - textHeight / 2 < 0) finalY = textHeight / 2 + 5;
                                if (finalY + textHeight / 2 > canvasHeight) finalY = canvasHeight - textHeight / 2 - 5;

                                // Рисуем полупрозрачный фон
                                ctx.fillStyle = 'rgba(255, 255, 255, 0.85)';
                                ctx.shadowColor = 'rgba(0, 0, 0, 0.1)';
                                ctx.shadowBlur = 6;
                                ctx.shadowOffsetX = 1;
                                ctx.shadowOffsetY = 1;
                                ctx.beginPath();
                                const rx = finalX - textWidth / 2;
                                const ry = finalY - textHeight / 2;
                                const r = 4;
                                ctx.moveTo(rx + r, ry);
                                ctx.lineTo(rx + textWidth - r, ry);
                                ctx.quadraticCurveTo(rx + textWidth, ry, rx + textWidth, ry + r);
                                ctx.lineTo(rx + textWidth, ry + textHeight - r);
                                ctx.quadraticCurveTo(rx + textWidth, ry + textHeight, rx + textWidth - r, ry + textHeight);
                                ctx.lineTo(rx + r, ry + textHeight);
                                ctx.quadraticCurveTo(rx, ry + textHeight, rx, ry + textHeight - r);
                                ctx.lineTo(rx, ry + r);
                                ctx.quadraticCurveTo(rx, ry, rx + r, ry);
                                ctx.closePath();
                                ctx.fill();

                                // Рисуем текст
                                ctx.shadowColor = 'transparent';
                                ctx.fillStyle = '#2c3e50';
                                ctx.font = 'bold 13px Arial';
                                ctx.fillText(text, finalX, finalY);
                            });

                            // Рисуем общее количество в центре
                            ctx.shadowColor = 'rgba(0, 0, 0, 0.1)';
                            ctx.shadowBlur = 10;
                            ctx.fillStyle = 'rgba(255, 255, 255, 0.95)';
                            ctx.beginPath();
                            ctx.arc(centerX, centerY, 36, 0, 2 * Math.PI);
                            ctx.fill();

                            ctx.shadowColor = 'transparent';
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'middle';

                            ctx.fillStyle = '#2c3e50';
                            ctx.font = 'bold 18px Arial';
                            ctx.fillText(total, centerX, centerY - 5);

                            ctx.fillStyle = '#7f8c8d';
                            ctx.font = '9px Arial';
                            ctx.fillText('всего лидов', centerX, centerY + 18);

                            ctx.restore();

                            const canvas = document.getElementById('DoughnutChart');
                            if (canvas) {
                                const dataUrl = canvas.toDataURL();
                                if (dataUrl !== 'data:,' && dataUrl.length > 100) {
                                    console.log('🔄 Дополнительная проверка: DoughnutChart готов');
                                    checkAllChartsReady();
                                }
                            }
                        }, 100);
                    }
                }
            }
        };



        <?php
        // Собираем данные по отделам за 4 недели
        $departmentWeeklyData = [];
        $departmentsTmp = getDepartments();

        // Получаем всех менеджеров
        $allManagers = getManagers();

        // Инициализируем структуру для хранения данных по отделам за каждую неделю
        foreach ($departmentsTmp as $deptId => $deptData) {
            $deptName = $deptData['NAME'] ?? 'Отдел ' . $deptId;
            $departmentWeeklyData[$deptId] = [
                'name' => $deptName,
                'weeks' => []
            ];
            for ($i = $iEnd; $i <= $iStart; $i++) {
                $departmentWeeklyData[$deptId]['weeks'][$i] = 0;
            }
        }

        // Собираем данные по неделям
        foreach ($allManagers as $manager) {
            $deptId = $manager['UF_DEPARTMENT'][0];

            for ($i = $iEnd; $i <= $iStart; $i++) {
                if (isset($dataTmp[$i]['MANAGERS'][$manager['ID']])) {
                    $departmentWeeklyData[$deptId]['weeks'][$i] += $dataTmp[$i]['MANAGERS'][$manager['ID']]['COUNT_LEADS'];
                }
            }
        }

        // Вычисляем проценты для каждой недели
        $weekTotals = [];
        for ($i = $iEnd; $i <= $iStart; $i++) {
            $weekTotals[$i] = 0;
            foreach ($departmentWeeklyData as $deptId => $deptData) {
                $weekTotals[$i] += $deptData['weeks'][$i];
            }
        }

        // Преобразуем данные в проценты
        $departmentPercentData = [];
        foreach ($departmentWeeklyData as $deptId => $deptData) {
            $departmentPercentData[$deptId] = [
                'name' => $deptData['name'],
                'percentages' => []
            ];
            for ($i = $iEnd; $i <= $iStart; $i++) {
                if ($weekTotals[$i] > 0) {
                    $percentage = ($deptData['weeks'][$i] / $weekTotals[$i]) * 100;
                } else {
                    $percentage = 0;
                }
                $departmentPercentData[$deptId]['percentages'][$i] = round($percentage, 1);
            }
        }

        // Сортируем отделы по общему количеству лидов (для красивого отображения)
        $deptOrder = [];
        foreach ($departmentPercentData as $deptId => $deptData) {
            $total = array_sum($deptData['percentages']);
            $deptOrder[$deptId] = $total;
        }
        arsort($deptOrder);
        ?>

        <?
        $weekLabels = [];
        for ($i = $iStart; $i >= $iEnd; $i--) {
            $startDate = new DateTime($dataTmp[$i]["date_start"]);
            $endDate = new DateTime($dataTmp[$i]["date_end"]);
            $weekLabels[] = $startDate->format('d') .
                '.' . $startDate->format('m') .
                '-' . $endDate->format('d') .
                '.' . $endDate->format('m');
        } ?>

        // Данные для графика в процентах
        const departmentPercentData = {
            labels: <?= json_encode($weekLabels) ?>,
            datasets: [
                <?php
                $colors = [
                    'rgba(173, 255, 47, 0.8)', // g1 - Емкостное оборуд.
                    // g2 - КХБ
                    // g3 - Пултрузия
                    'rgba(223, 255, 41, 0.8)', // g4 - Спец.ёмкости
                    'rgba(215, 167, 255, 0.8)',
                    'rgba(255, 187, 29, 0.8)'
                ];

                $borderColors = [
                    'rgb(88, 183, 33)',
                    'rgb(205, 220, 57)',

                    'rgb(156, 39, 176)',
                    'rgb(255, 193, 7)'
                ];

                $pointBackgroundColors = [
                    'rgba(173, 255, 47, 0.8)', // g1 - Емкостное оборуд.
                    // g2 - КХБ
                    // g3 - Пултрузия
                    'rgba(223, 255, 41, 0.8)', // g4 - Спец.ёмкости
                    'rgba(215, 167, 255, 0.8)',
                    'rgba(255, 187, 29, 0.8)'
                ];

                $index = 0;
                foreach ($deptOrder as $deptId => $total):
                    $deptData = $departmentPercentData[$deptId];
                    $colorIndex = $index % count($colors);
                ?> {
                        label: '<?= addslashes($deptData['name']) ?>',
                        data: [
                            <?php for ($i = $iStart; $i >= $iEnd; $i--): ?>
                                <?= $deptData['percentages'][$i] ?? 0 ?>,
                            <?php endfor; ?>
                        ],
                        borderColor: '<?= $colors[$colorIndex] ?>',
                        backgroundColor: '<?= $colors[$colorIndex] ?>',
                        pointBackgroundColor: '<?= $colors[$colorIndex] ?>',
                        pointBorderColor: '<?= $borderColors[$colorIndex] ?>',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        tension: 0.3,
                        fill: false,
                        borderWidth: 3
                    },
                <?php
                    $index++;
                endforeach;
                ?>
            ]
        };

        // Конфигурация line графика с процентами
        const configDepartmentLine = {
            type: 'line',
            data: departmentPercentData,
            options: {
                devicePixelRatio: 1,
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                size: 11,
                                weight: 'bold'
                            },
                            padding: 15,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.parsed.y + '%';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            },
                            font: {
                                size: 11
                            },
                            stepSize: 10
                        },
                        title: {
                            display: true,
                            text: 'Процент лидов',
                            font: {
                                size: 12,
                                weight: 'bold'
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        title: {
                            display: true,
                            text: 'Недели',
                            font: {
                                size: 12,
                                weight: 'bold'
                            }
                        }
                    }
                },
                animation: {
                    duration: 1000,
                    easing: 'easeOutQuart',
                    onComplete: function() {
                        setTimeout(() => {
                            const chartInstance = this;
                            const ctx = chartInstance.ctx;

                            ctx.save();

                            // Собираем все данные по позициям X
                            const xPositions = {};

                            chartInstance.data.datasets.forEach((dataset, datasetIndex) => {
                                const meta = chartInstance.getDatasetMeta(datasetIndex);
                                meta.data.forEach((element, index) => {
                                    const x = Math.round(element.x);
                                    const y = element.y;
                                    const value = dataset.data[index];

                                    if (value === 0 || value === null) return;

                                    if (!xPositions[x]) {
                                        xPositions[x] = [];
                                    }
                                    xPositions[x].push({
                                        datasetIndex,
                                        index,
                                        y,
                                        value,
                                        element
                                    });
                                });
                            });

                            // Распределяем метки
                            Object.keys(xPositions).forEach(xKey => {
                                const items = xPositions[xKey];
                                if (items.length <= 1) {
                                    const item = items[0];
                                    drawLabel(ctx, item.element.x, item.element.y, item.value + '%', item.datasetIndex, chartInstance);
                                    return;
                                }

                                items.sort((a, b) => a.y - b.y);
                                const totalHeight = 60;
                                const step = totalHeight / (items.length - 1);

                                items.forEach((item, idx) => {
                                    const offsetY = idx * step - totalHeight / 2;
                                    const newY = item.y + offsetY;
                                    drawLabel(ctx, item.element.x, newY, item.value + '%', item.datasetIndex, chartInstance);
                                });
                            });

                            ctx.restore();

                            const canvas = document.getElementById('DepartmentLineChart');
                            if (canvas) {
                                const dataUrl = canvas.toDataURL();
                                if (dataUrl !== 'data:,' && dataUrl.length > 100) {
                                    console.log('🔄 Дополнительная проверка: DepartmentLineChart готов');
                                    checkAllChartsReady();
                                }
                            }
                        }, 100);
                    }
                }
            }
        };




        <?
        // Собираем данные по отделам за 4 недели (абсолютные значения)
        $departmentAbsoluteData = [];
        $departmentsTmp = getDepartments();

        // Получаем всех менеджеров
        $allManagers = getManagers();

        // Инициализируем структуру для хранения данных по отделам за каждую неделю
        foreach ($departmentsTmp as $deptId => $deptData) {
            $deptName = $deptData['NAME'] ?? 'Отдел ' . $deptId;
            $departmentAbsoluteData[$deptId] = [
                'name' => $deptName,
                'weeks' => []
            ];
            for ($i = $iEnd; $i <= $iStart; $i++) {
                $departmentAbsoluteData[$deptId]['weeks'][$i] = 0;
            }
        }

        // Собираем данные по неделям (абсолютные значения)
        foreach ($allManagers as $manager) {
            $deptId = $manager['UF_DEPARTMENT'][0];
            if (!isset($departmentAbsoluteData[$deptId])) continue;

            for ($i = $iEnd; $i <= $iStart; $i++) {
                if (isset($dataTmp[$i]['MANAGERS'][$manager['ID']])) {
                    $departmentAbsoluteData[$deptId]['weeks'][$i] += $dataTmp[$i]['MANAGERS'][$manager['ID']]['COUNT_LEADS'];
                }
            }
        }

        // Сортируем отделы по общему количеству лидов (для красивого отображения)
        $deptOrderAbs = [];
        foreach ($departmentAbsoluteData as $deptId => $deptData) {
            $total = array_sum($deptData['weeks']);
            $deptOrderAbs[$deptId] = $total;
        }
        arsort($deptOrderAbs);

        $weekLabels = [];
        for ($i = $iStart; $i >= $iEnd; $i--) {
            $startDate = new DateTime($dataTmp[$i]["date_start"]);
            $endDate = new DateTime($dataTmp[$i]["date_end"]);
            $weekLabels[] = $startDate->format('d') .
                '.' . $startDate->format('m') .
                '-' . $endDate->format('d') .
                '.' . $endDate->format('m');
        } ?>

        // Данные для графика с абсолютными значениями
        const departmentAbsoluteData = {
            labels: <?= json_encode($weekLabels) ?>,
            datasets: [
                <?php
                $colors = [
                    'rgba(173, 255, 47, 0.8)', // g1 - Емкостное оборуд.
                    // g2 - КХБ
                    // g3 - Пултрузия
                    'rgba(223, 255, 41, 0.8)', // g4 - Спец.ёмкости
                    'rgba(215, 167, 255, 0.8)',
                    'rgba(255, 187, 29, 0.8)'
                ];

                $borderColors = [
                    'rgb(88, 183, 33)',
                    'rgb(205, 220, 57)',

                    'rgb(156, 39, 176)',
                    'rgb(255, 193, 7)'
                ];



                $index = 0;
                foreach ($deptOrderAbs as $deptId => $total):
                    $deptData = $departmentAbsoluteData[$deptId];
                    $colorIndex = $index % count($colors);
                ?> {
                        label: '<?= addslashes($deptData['name']) ?>',
                        data: [
                            <?php for ($i = $iStart; $i >= $iEnd; $i--): ?>
                                <?= $deptData['weeks'][$i] ?? 0 ?>,
                            <?php endfor; ?>
                        ],
                        borderColor: '<?= $colors[$colorIndex] ?>',
                        backgroundColor: '<?= $colors[$colorIndex] ?>',
                        pointBackgroundColor: '<?= $colors[$colorIndex] ?>',
                        pointBorderColor: '<?= $borderColors[$colorIndex] ?>',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        tension: 0.3,
                        fill: false,
                        borderWidth: 3
                    },
                <?php
                    $index++;
                endforeach;
                ?>
            ]
        };

        function drawLabel(ctx, x, y, value, datasetIndex, chartInstance) {
            if (value === 0 || value === null) return;

            const text = String(value);
            const dataset = chartInstance.data.datasets[datasetIndex];

            // Размеры прямоугольника
            const rectWidth = 36;
            const rectHeight = 24;
            const rectX = x - rectWidth / 2;
            const rectY = y - rectHeight / 2;

            // Рисуем прямоугольник с белым фоном
            ctx.shadowColor = 'rgba(0, 0, 0, 0.1)';
            ctx.shadowBlur = 4;
            ctx.shadowOffsetX = 1;
            ctx.shadowOffsetY = 1;

            ctx.fillStyle = '#ffffff';
            ctx.beginPath();
            const radius = 4;
            ctx.moveTo(rectX + radius, rectY);
            ctx.lineTo(rectX + rectWidth - radius, rectY);
            ctx.quadraticCurveTo(rectX + rectWidth, rectY, rectX + rectWidth, rectY + radius);
            ctx.lineTo(rectX + rectWidth, rectY + rectHeight - radius);
            ctx.quadraticCurveTo(rectX + rectWidth, rectY + rectHeight, rectX + rectWidth - radius, rectY + rectHeight);
            ctx.lineTo(rectX + radius, rectY + rectHeight);
            ctx.quadraticCurveTo(rectX, rectY + rectHeight, rectX, rectY + rectHeight - radius);
            ctx.lineTo(rectX, rectY + radius);
            ctx.quadraticCurveTo(rectX, rectY, rectX + radius, rectY);
            ctx.closePath();
            ctx.fill();

            // Рисуем цветную рамку
            ctx.shadowColor = 'transparent';
            ctx.strokeStyle = dataset.borderColor || '#333';
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.moveTo(rectX + radius, rectY);
            ctx.lineTo(rectX + rectWidth - radius, rectY);
            ctx.quadraticCurveTo(rectX + rectWidth, rectY, rectX + rectWidth, rectY + radius);
            ctx.lineTo(rectX + rectWidth, rectY + rectHeight - radius);
            ctx.quadraticCurveTo(rectX + rectWidth, rectY + rectHeight, rectX + rectWidth - radius, rectY + rectHeight);
            ctx.lineTo(rectX + radius, rectY + rectHeight);
            ctx.quadraticCurveTo(rectX, rectY + rectHeight, rectX, rectY + rectHeight - radius);
            ctx.lineTo(rectX, rectY + radius);
            ctx.quadraticCurveTo(rectX, rectY, rectX + radius, rectY);
            ctx.closePath();
            ctx.stroke();

            // Рисуем текст
            ctx.shadowColor = 'transparent';
            ctx.fillStyle = '#1a1a1a';
            ctx.font = 'bold 10px Arial';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(text, x, y + 1);
        }

        // Конфигурация line графика с абсолютными значениями
        const configDepartmentAbsolute = {
            type: 'line',
            data: departmentAbsoluteData,
            options: {
                devicePixelRatio: 1,
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                size: 11,
                                weight: 'bold'
                            },
                            padding: 15,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.parsed.y + ' лидов';
                            }
                        }
                    }
                },
                /*                 scales: {
                                    y: {
                                        beginAtZero: true,
                                        // Добавляем запас сверху в 15% от максимума
                                        suggestedMax: function(context) {
                                            const max = Math.max(...context.chart.data.datasets[0].data);
                                            return max * 1.15; // 15% запаса сверху
                                        }
                                    }
                                }, */
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font: {
                                size: 11
                            },
                            stepSize: 1
                        },
                        title: {
                            display: true,
                            text: 'Количество лидов',
                            font: {
                                size: 12,
                                weight: 'bold'
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        suggestedMax: function(context) {
                            const max = Math.max(...context.chart.data.datasets[0].data);
                            return max * 1.08; // 15% запаса сверху
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        title: {
                            display: true,
                            text: 'Недели',
                            font: {
                                size: 12,
                                weight: 'bold'
                            }
                        }
                    }
                },
                animation: {
                    duration: 1000,
                    easing: 'easeOutQuart',
                    onComplete: function() {
                        setTimeout(() => {
                            const chartInstance = this;
                            const ctx = chartInstance.ctx;

                            ctx.save();

                            // Для каждой точки на оси X собираем все метки
                            const xPositions = {};

                            // Собираем все данные по позициям X
                            chartInstance.data.datasets.forEach((dataset, datasetIndex) => {
                                const meta = chartInstance.getDatasetMeta(datasetIndex);
                                meta.data.forEach((element, index) => {
                                    const x = Math.round(element.x);
                                    const y = element.y;
                                    const value = dataset.data[index];

                                    if (value === 0 || value === null) return;

                                    if (!xPositions[x]) {
                                        xPositions[x] = [];
                                    }
                                    xPositions[x].push({
                                        datasetIndex,
                                        index,
                                        y,
                                        value,
                                        element
                                    });
                                });
                            });

                            // Для каждой позиции X распределяем метки по вертикали
                            Object.keys(xPositions).forEach(xKey => {
                                const items = xPositions[xKey];
                                if (items.length <= 1) {
                                    // Если только одна метка - рисуем ее без смещения
                                    const item = items[0];
                                    drawLabel(ctx, item.element.x, item.element.y, item.value, item.datasetIndex, chartInstance);
                                    return;
                                }

                                // Сортируем по значению Y (сверху вниз)
                                items.sort((a, b) => a.y - b.y);

                                // Определяем вертикальный шаг
                                const totalHeight = 50; // общая высота для распределения
                                const step = totalHeight / (items.length - 1);
                                const baseY = items[0].y - totalHeight / 2;

                                // Распределяем метки
                                items.forEach((item, idx) => {
                                    const offsetY = idx * step - totalHeight / 2;
                                    const newY = item.y + offsetY;
                                    drawLabel(ctx, item.element.x, newY, item.value, item.datasetIndex, chartInstance);
                                });
                            });

                            ctx.restore();

                            const canvas = document.getElementById('DepartmentAbsoluteChart');
                            if (canvas) {
                                const dataUrl = canvas.toDataURL();
                                if (dataUrl !== 'data:,' && dataUrl.length > 100) {
                                    console.log('🔄 Дополнительная проверка: DepartmentAbsoluteChart готов');
                                    checkAllChartsReady();
                                }
                            }
                        }, 100);
                    }
                }
            }
        };







        // СОЗДАЕМ ГРАФИКИ
        console.log('🔄 Создание графиков...');
        const chartHorizontalGisto = createChart('HorizontalGisto', configHorizontalGisto);
        const lineChartSumLeads = createChart('LineChartSumLeads', LineChartSumLeadsConfig);
        const chartDoughnut = createChart('DoughnutChart', configDoughnut);
        const chartDepartmentLine = createChart('DepartmentLineChart', configDepartmentLine);
        const chartDepartmentAbsolute = createChart('DepartmentAbsoluteChart', configDepartmentAbsolute);


        function parseSearch() {
            const search = location.search.slice(1);
            if (!search) return {};
            return search.split('&').reduce((params, pair) => {
                const [key, value] = pair.split('=');
                params[decodeURIComponent(key)] = decodeURIComponent(value);
                return params;
            }, {});
        }
        // ФУНКЦИЯ СОХРАНЕНИЯ
        function saveToPDF() {
            const btn = document.querySelector('.btn-pdf');
            const loading = document.getElementById('loading');
            const message = document.getElementById('message');

            // Проверяем готовность
            if (readyChartsCount < totalCharts) {
                // Пытаемся принудительно проверить еще раз
                checkAllChartsReady();

                if (readyChartsCount < totalCharts) {
                    showMessage(`Графики еще загружаются (${readyChartsCount}/${totalCharts}). Подождите...`, 'error');
                    return;
                }
            }

            btn.disabled = true;
            loading.style.display = 'block';
            message.style.display = 'none';

            const chartImages = [];
            const canvasElements = document.querySelectorAll('canvas');

            canvasElements.forEach((canvas, index) => {

                const originalWidth = canvas.style.width;
                const originalHeight = canvas.style.height;

                canvas.style.width = '600px !important';
                canvas.style.height = '300px !important';

                chartImages.push({
                    id: canvas.id || 'chart_' + index,
                    data: canvas.toDataURL('image/png', 1)
                });

                canvas.style.width = originalWidth;
                canvas.style.height = originalHeight;
            });

            $path = window.location.href;
            const pageParams = parseSearch();
            const countParams = Object.keys(pageParams).length;

            if (countParams > 0) {
                $path = $path + '&pdf=1'
            } else {
                $path = $path + '?pdf=1'
            }

            fetch($path, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        charts: chartImages
                    })
                })
                .then(response => response.json())
                .then(data => {
                    loading.style.display = 'none';
                    btn.disabled = false;

                    if (data.success) {
                        showMessage('✅ PDF успешно создан!', 'success');
                        window.open(data.url, '_blank');
                        /* fetch(data.url)
                            .then(response => response.blob())
                            .then(blob => {
                                const url = window.URL.createObjectURL(blob);
                                const link = document.createElement('a');
                                link.href = url;
                                link.download = 'report.pdf'; // имя файла
                                document.body.appendChild(link);
                                link.click();
                                document.body.removeChild(link);
                                window.URL.revokeObjectURL(url);
                            })
                            .catch(error => {
                                console.error('Ошибка скачивания:', error);
                                // fallback: открыть в новой вкладке
                                window.open(data.url, '_blank');
                            }); */
                    } else {
                        showMessage('❌ ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    loading.style.display = 'none';
                    btn.disabled = false;
                    showMessage('❌ Ошибка: ' + error.message, 'error');
                    console.error('Error:', error);
                });
        }

        function showMessage(text, type) {
            const message = document.getElementById('message');
            message.textContent = text;
            message.className = 'message ' + type;
            message.style.display = 'block';

            setTimeout(() => {
                message.style.display = 'none';
            }, 5000);
        }

        function save() {
            saveToPDF();
        }

        // ===== БУРГЕР-МЕНЮ (JavaScript) =====
        document.addEventListener('DOMContentLoaded', function() {
            const burgerBtn = document.getElementById('burgerToggle');
            const sidebar = document.getElementById('dateSidebar');
            const overlay = document.getElementById('menuOverlay');

            if (burgerBtn && sidebar && overlay) {



                function pulsateButton() {
                    if (burgerBtn && !burgerBtn.classList.contains('open')) {
                        // Убираем предыдущую анимацию
                        burgerBtn.classList.remove('pulse');
                        // Форсируем перерисовку
                        void burgerBtn.offsetWidth;
                        // Добавляем анимацию
                        burgerBtn.classList.add('pulse');

                        // Убираем класс после завершения анимации
                        setTimeout(() => {
                            burgerBtn.classList.remove('pulse');
                        }, 800);
                    }
                }

                function toggleMenu() {
                    burgerBtn.classList.toggle('active');

                    sidebar.classList.toggle('open');
                    overlay.classList.toggle('active');
                    document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
                }


                setTimeout(() => {
                    pulsateButton();
                }, 3000);

                setInterval(pulsateButton, 5000);


                burgerBtn.addEventListener('click', toggleMenu);
                overlay.addEventListener('click', toggleMenu);

                // Закрываем меню при клике на пункт
                sidebar.querySelectorAll('.date-menu-item').forEach(item => {
                    item.addEventListener('click', function() {
                        if (sidebar.classList.contains('open')) {
                            // Даем время на переход по ссылке, потом закрываем
                            setTimeout(() => {
                                if (sidebar.classList.contains('open')) {
                                    toggleMenu();
                                }
                            }, 200);
                        }
                    });
                });
            }

            // Инициализация кнопки PDF
            document.querySelector('.btn-pdf').disabled = true;

            // Периодическая проверка готовности
            let checkInterval = setInterval(() => {
                if (readyChartsCount >= totalCharts) {
                    clearInterval(checkInterval);
                    enableButton();
                    console.log('✅ Кнопка разблокирована через интервал');
                } else {
                    // Проверяем состояние canvas
                    const canvasElements = document.querySelectorAll('canvas');
                    let allHaveContent = true;
                    canvasElements.forEach(canvas => {
                        const dataUrl = canvas.toDataURL();
                        if (dataUrl === 'data:,' || dataUrl.length < 100) {
                            allHaveContent = false;
                        }
                    });

                    if (allHaveContent && canvasElements.length >= totalCharts) {
                        readyChartsCount = totalCharts;
                        clearInterval(checkInterval);
                        enableButton();
                        console.log('✅ Кнопка разблокирована (проверка содержимого canvas)');
                    }
                }
            }, 500);

            // Финальная проверка через 5 секунд
            setTimeout(() => {
                if (readyChartsCount < totalCharts) {
                    console.log('⚠️ Принудительная разблокировка через 5 секунд');
                    readyChartsCount = totalCharts;
                    enableButton();
                }
            }, 5000);
        });

        // Альтернативный способ - следим за изменениями в DOM
        if (window.MutationObserver) {
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'childList' || mutation.type === 'attributes') {
                        checkAllChartsReady();
                    }
                });
            });

            // Начинаем наблюдение после загрузки
            document.addEventListener('DOMContentLoaded', function() {
                const canvasElements = document.querySelectorAll('canvas');
                canvasElements.forEach(canvas => {
                    observer.observe(canvas, {
                        attributes: true,
                        attributeFilter: ['width', 'height']
                    });
                });
            });
        }

        console.log('🔄 Скрипт загружен, ожидаем готовность графиков...');


        <? if ($isCLI): ?>
            setTimeout(() => {
                document.querySelectorAll('canvas').forEach(canvas => {
                    // Создаем img
                    const img = document.createElement('img');
                    img.src = canvas.toDataURL('image/png');
                    img.style.width = canvas.style.width || '100%';
                    img.style.height = canvas.style.height || '400px';
                    img.className = 'chart-image';

                    // Заменяем canvas на img
                    canvas.parentNode.replaceChild(img, canvas);
                });

                // Скрываем кнопку сохранения если она не нужна

                document.querySelector('.btn-pdf')?.remove();

            }, 2000);
        <? endif; ?>
    </script>


    <?php if ($refreshState === 'started' || $refreshState === 'already-running'): ?>
        <script>
            (function() {
                const startAt = Date.now();
                const maxDuration = 180000; // 3 минуты
                const pollEvery = 2000; // 2 секунды

                function showBanner(text, autoHideMs) {
                    let el = document.getElementById('refresh-banner');
                    if (!el) {
                        el = document.createElement('div');
                        el.id = 'refresh-banner';
                        el.style.cssText = 'position:fixed;top:16px;right:16px;' +
                            'background:#009355;color:#fff;padding:10px 18px;' +
                            'border-radius:6px;z-index:99999;' +
                            'font:14px Arial,sans-serif;' +
                            'box-shadow:0 4px 12px rgba(0,0,0,0.2);' +
                            'transition:opacity 0.3s;';
                        document.body.appendChild(el);
                    }
                    el.textContent = text;
                    el.style.opacity = '1';

                    if (autoHideMs) {
                        setTimeout(function() {
                            el.style.opacity = '0';
                            setTimeout(function() {
                                el.remove();
                            }, 400);
                        }, autoHideMs);
                    }
                }

                function poll() {
                    if (Date.now() - startAt > maxDuration) {
                        showBanner('⚠️ Обновление занимает дольше обычного. Попробуйте обновить страницу вручную.', 6000);
                        return;
                    }

                    fetch('refresh_status.php?t=' + Date.now(), {
                            cache: 'no-store'
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(data) {
                            if (!data.running) {
                                showBanner('✅ Данные обновлены, перезагрузка…');
                                setTimeout(function() {
                                    location.reload();
                                }, 400);
                            } else {
                                setTimeout(poll, pollEvery);
                            }
                        })
                        .catch(function() {
                            setTimeout(poll, pollEvery);
                        });
                }

                document.addEventListener('DOMContentLoaded', function() {
                    showBanner('⏳ Обновляем данные текущей недели…');
                    setTimeout(poll, 800); // даём браузеру отрисовать страницу
                });
            })();
        </script>
    <?php endif; ?>
    </body>

    </html>
<?php
}
?>