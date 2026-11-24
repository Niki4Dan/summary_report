<?php

// Проверяем существование файла
$autoloadPath = __DIR__ . '/dompdf/autoload.inc.php';
if (!file_exists($autoloadPath)) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Dompdf не найден. Установите: composer require dompdf/dompdf'
    ]);
    exit;
}

require_once $autoloadPath;

// ---------------------------------------------------------------------------
// В CLI-режиме разбираем аргументы вида `script=1 date_start=...` в $_GET,
// чтобы запуск `php createPdf.php script=1 date_start=... date_end=...`
// работал так же, как через query string в браузере.
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && !empty($argv) && is_array($argv)) {
    foreach ($argv as $arg) {
        if (is_string($arg) && strpos($arg, '=') !== false) {
            [$k, $v] = explode('=', $arg, 2);
            if ($k !== '' && !isset($_GET[$k])) {
                $_GET[$k] = $v;
            }
        }
    }
}

use Dompdf\Dompdf;
use Dompdf\Options;

function logMessage($message)
{
    $logFile = __DIR__ . '/summary_report.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

$departments = [
    /* Емкостное оборудование */
    "269" => ['class' => 'g1'],
    /* КХБ */
    "270" => ['class' => 'g2'],
    /* ПЛТР */
    "218" => ['class' => 'g3'],
    /* Емкости специального назначения */
    "260" => ['class' => 'g4'],
];

define("DEPARTMENTS", $departments);


function setEnvData(string $filePath = ''): void
{
    if (empty($filePath)) {
        $filePath = __DIR__ . '/.env';
    }
    if (!file_exists($filePath)) {
        throw new RuntimeException('Wrong file path.');
    }
    $file = file_get_contents($filePath);
    $data = array_filter(explode(PHP_EOL, $file));
    if (empty($data)) {
        return;
    }
    foreach ($data as $i => $line) {
        $data[$i] = array_map('trim', explode('=', $line, 2));
        if (isset($data[$i][1])) {
            $data[$i][1] = trim($data[$i][1], '"');
        } else {
            $data[$i][1] = '';
        }
        $_ENV[$data[$i][0]] = $data[$i][1];
    }
}


function getDepartments()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $dataDepartments = [];
    foreach (DEPARTMENTS as $department => $class) {
        $responseDepartment = callToBitrix('department.get', ['ID' => [$department]]);
        $dataDepartments[$department] = $responseDepartment['result'][0] ?? [];
        $dataDepartments[$department]['class'] = $class['class'];
    }
    return $cache = $dataDepartments;
}


function sortDepartmentsAndUsers($tmpUsers)
{
    $departmentOrder = array_keys(DEPARTMENTS);
    $sortedResult = [];

    foreach ($departmentOrder as $deptId) {
        if (!isset($tmpUsers[$deptId])) {
            continue;
        }
        $users = $tmpUsers[$deptId];

        usort($users, function ($a, $b) {
            if ($a['IS_LEADER'] && !$b['IS_LEADER']) return -1;
            if (!$a['IS_LEADER'] && $b['IS_LEADER']) return 1;

            $nameA = $a['LAST_NAME'] . ' ' . $a['NAME'] . ' ' . $a['SECOND_NAME'];
            $nameB = $b['LAST_NAME'] . ' ' . $b['NAME'] . ' ' . $b['SECOND_NAME'];
            return strcasecmp($nameA, $nameB);
        });

        $sortedResult[$deptId] = $users;
    }

    return $sortedResult;
}


function getManagers()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $paramsGetUser = [
        "select" => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'UF_DEPARTMENT'],
        "filter" => [
            "@UF_DEPARTMENT" => array_keys(DEPARTMENTS),
            "!@ID"           => [106, 1450],
            "ACTIVE"         => true,
        ],
    ];
    $responseUser = callToBitrix('user.get', $paramsGetUser);
    return $cache = ($responseUser['result'] ?? []);
}


function getIsLeader($dep, $manager)
{
    $isLeader = false;
    if (isset($dep[$manager['UF_DEPARTMENT'][0]]['UF_HEAD'])) {
        if ($dep[$manager['UF_DEPARTMENT'][0]]['UF_HEAD'] == $manager['ID']) {
            $isLeader = true;
        }
    }
    return $isLeader;
}


function getHtml($managersHtml, $data, $tablekp, $leads_sources, $charts = [], $pdf = false, ?mysqli $connection = null, $userIdsJson = null)
{
    $selectedDate = isset($_GET['date_start']) ? $data[0] : $data[1];

    $decoded = is_string($userIdsJson) ? json_decode($userIdsJson, true) : $userIdsJson;
    $countInwork_dinamic = is_array($decoded) ? count($decoded) : 0;

    // Определяем script-режим (PDF/консоль). После парсинга CLI-аргументов
    // $_GET['script'] доступен и при запуске из консоли.
    $isScript = isset($_GET['script']) && (int)$_GET['script'] === 1;

    $isCompleted = isset($selectedDate['is_completed']) ? (int)$selectedDate['is_completed'] : 1;

    if ($isCompleted) {
        $quoteText = '* Количество лидов, отражённое в колонках статусов, приведено по состоянию на утро понедельника текущей недели';
    } else {
        $quoteText = '* Данные текущей недели ещё не зафиксированы и отражены по состоянию на '
            . date('d.m.Y H:i')
            . '. Итоговые значения будут зафиксированы в ближайший понедельник.';
    }

    $fontsize = $pdf ? 'font-size:12pt;' : '';

    // Переиспользуем соединение, если передано снаружи
    $ownConn = false;
    if ($connection === null) {
        $connection = mysqli_connect(DB_SERVER, DB_USER, DB_PASSWORD, DB_NAME);
        $ownConn = true;
    }

    $stageCounts = getDealsCountByExistingStages($connection, $selectedDate['date_start'], $selectedDate['date_end']);

    // ИСПРАВЛЕНО: скобки вокруг каждого ?? — иначе ?? применяется ко всей сумме
    $row2 = ($stageCounts['by_stage']['C29:NEW']['count']              ?? 0)
        + ($stageCounts['by_stage']['C29:PREPARATION']['count']       ?? 0)
        + ($stageCounts['by_stage']['C29:PREPAYMENT_INVOIC']['count'] ?? 0);

    $row3 = ($stageCounts['by_stage']['C29:UC_UD1NMI']['count']     ?? 0)
        + ($stageCounts['by_stage']['C29:UC_3OMQWF']['count']     ?? 0)
        + ($stageCounts['by_stage']['C29:EXECUTING']['count']     ?? 0)
        + ($stageCounts['by_stage']['C29:FINAL_INVOICE']['count'] ?? 0);

    if ($pdf && !$isScript) {
        $columnTitleVP   = '<td rowspan="6">С<br>т<br>а<br>д<br>и<br>и<br><span style="margin-top:10px;">ВП</span></td>';
        $lineheighttitle = '12pt';
    } else {
        $columnTitleVP   = '<td rowspan="6" style="transform:rotate(-90deg);">Стадии ВП</td>';
        $lineheighttitle = '18pt';
    }

    // ------------------------------------------------------------------
    // Заголовок. В script/PDF-режиме (в т.ч. запуск из консоли) используем
    // обычные пробелы вместо &nbsp; — чтобы избежать нежелательных переносов
    // и проблем с отрисовкой в Dompdf/headless-браузерах.
    // ------------------------------------------------------------------
    $dtStart = new DateTime($selectedDate['date_start']);
    //$dtStart->modify('+2 days');
    $titlePeriodStart = $dtStart->format('d.m.Y');
    $titlePeriodEnd   = (new DateTime($selectedDate['date_end']))->format('d.m.Y');

    if ($isScript) {
        $titleHtml = '<div class="title">Сводный отчет по входящим лидам за период с '
            . $titlePeriodStart . ' (00:00) по ' . $titlePeriodEnd . ' (23:59)</div>';
    } else {
        $titleHtml = '<div class="title">Сводный отчет по входящим лидам за&nbsp;период&nbsp;с&nbsp;'
            . $titlePeriodStart . '&nbsp;(00:00)&nbsp;по&nbsp;' . $titlePeriodEnd . '&nbsp;(23:59)</div>';
    }

    $html = '
        <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
            <style>

            body, table, td, th, .title, .subtitle, .quote {
                font-family: "DejaVu Sans", sans-serif;
            }
            @media print {
            .svod td{
                        font-size: 10pt !important;
                        padding: 8px !important;
                            }
            .svod th {
                font-size: 12pt !important;
                padding: 8px !important;
            }

            .managers_table td {
                font-size: 10pt !important;
                padding: 8px !important;
            }

            .managers_table th {
                font-size: 12pt !important;
                padding: 8px !important;
            }

            .title{
                font-size:24pt !important;
                margin-bottom:10px;
                line-height: 12pt !important;
            }

            .quote{
                font-size:10pt !important;
                margin-bottom:15px  !important;
            }
            }
            @page { margin: 0.5cm 1cm 0.5cm 1cm; }
                body {
                    font-family: "DejaVu Sans", sans-serif;
                    padding: 0pt;
                    margin: 0;
                }
                .title {
                    text-align: center;
                    font-size: 16pt;
                    font-weight: bold;
                    margin: 0 0 12pt 0;
                    line-height:' . $lineheighttitle . ';
                }
                .subtitle {
                    text-align: center;
                    font-size: 10pt;
                    font-style: italic;
                    margin: 0 0 8pt 0;
                    line-height:12pt;
                }
                .chart-container {
                    text-align: center;
                    margin: 15pt auto;
                    page-break-inside: avoid;
                }
                .chart-container img {
                    margin:0 auto;
                }
                .chart-title {
                    font-size: 10pt;
                    font-weight: bold;
                    margin: 5pt 0;
                    color: #333;
                }
                .doughnut-container {
                    text-align: center;
                    margin: 15px auto;
                    page-break-inside: avoid;
                    max-width: 500px;
                }
                .doughnut-container img {
                    max-width: 400px;
                    max-height: 400px;
                }
                .doughnut-title {
                    font-size: 10pt;
                    font-weight: bold;
                    margin: 5pt 0;
                    color: #333;
                    text-align: center;
                }
                table {
                    border-collapse: collapse;
                    margin: 0 auto 12pt auto;
                    font-family: "DejaVu Sans", sans-serif;
                    width: 100%;
                    font-size: 8pt;
                }
                td {
                    border: 1px solid #8f8f8f;
                    padding: 3px 3px;
                    text-align: center;
                    vertical-align: middle;
                }
                .th {
                    font-weight: bold;
                    background-color: #f2f2f2;
                    font-size: 8pt;
                }
                .left { text-align: left; }
                .bold { font-weight: bold; }
                .g1 { background-color: #adff2f; font-size: 7pt;}
                .g2 { background-color: #ffbb1d; font-size: 7pt;}
                .g3 { background-color: rgb(215, 167, 255); font-size: 7pt;}
                .g4 { background-color: #eeff98; font-size: 7pt;}
                .group { font-weight: bold; }
                .small { font-size: 10px; width:fit-content; }

                .table-wrapper {
                    width: 100%;
                    margin-bottom: 10px;
                    page-break-inside: avoid !important;
                }

                #LineChartSumLeads { width:650px; height:250px; }
                #HorizontalGisto   { width: 506.7px ; height: 600px ; }
                #DepartmentLineChart { width: 100% ; height: 400px ; }
                #DoughnutChart { margin-top:10px; width:475px; height:250px; }

                .legend_table {
                    border-collapse: collapse;
                    width: 100%;
                    font-family: "DejaVu Sans", sans-serif;
                    font-size: 10px;
                    border:none;
                    margin-top:20px;
                    margin-bottom:8pt !important;
                }
                .legend_table th,
                .legend_table td {
                    text-align: left;
                    vertical-align: top;
                    border-right: 1px solid #d1d5db;
                }
                .legend_table th:last-child,
                .legend_table td:last-child {
                    border-right: none;
                }
                .legend_table th {
                    font-weight: bold;
                    border-bottom: 1px solid #d1d5db;
                    text-align:center;
                }
                .legend_table td {
                    border:none;
                    border-right: 1px solid #d1d5db;
                }
                .number_pp { text-align:center !important; }


                .legend_table_managers table{
                    font-size:8pt;
                    width:auto;
                    margin-top:10px;
                }

                .legend_table_managers table td{
                    border:none;
                    border-left:1px solid gray;
                    padding-left:10px;
                    padding-right:10px;
                }

                .legend_table_managers table td:first-child{
                    border:none;
                }

                .legend_table_managers table td p{
                                        padding:0 !important;
                                        margin:0;
                   
                }
            </style>
        </head>
        <body>

            ' . $titleHtml . '

            <table class="svod" style="margin-bottom:5pt;">
    <tr>
        <td colspan="2" rowspan="3"></td>
        <td colspan="7" class="th">Лиды</td>
    </tr>
    <tr>
        <td rowspan="2">Общее число</td>
        <td rowspan="2">Качественные</td>
        <td colspan="2">В ожидании</td>
        <td rowspan="2">В работе</td>
        <td rowspan="2">Не обработан</td>
        <td rowspan="2">Нецелевой</td>
    </tr>
    <tr>
        <td>ФЛ</td>
        <td>Клиент</td>
    </tr>
    <tr>
        <td colspan="2" class="bold">Всего за неделю</td>
        <td class="bold">' . $selectedDate["TOTAL_LEADS"] . '</td>
        <td class="bold">' . $selectedDate["COUNT_SUCCESS_LEADS"] . '</td>
        <td class="bold">' . ($selectedDate["AWAIT_MANAGERS"] ?? 0) . '</td>
        <td class="bold">' . ($selectedDate["AWAIT_CLIETNS"] ?? 0) . '</td>
        <td class="bold">
    <a class="dinamic_count_inwork"
       href="/local/summary_report/follow_online.php"
       target="_blank"
       rel="noopener"
       style="color: inherit; text-decoration: underline; cursor: pointer;">' . $countInwork_dinamic . '</a>
</td>
        <td class="bold">' . $selectedDate["COUNT_NEW_LEADS"] . '</td>
        <td class="bold">' . $selectedDate["COUNT_FAILED_LEADS"] . '</td>
    </tr>
    <tr>
        <td colspan="2">Контакт/компания</td>
        <td></td>
        <td>' . (int)($selectedDate['CONVERTED_LEADS'] ?? 0) . '</td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        ' . $columnTitleVP . '
        <td>1</td>
        <td></td>
        <td></td>
        <td>' . ($selectedDate["AWAIT_MANAGERS"] ?? 0) . '</td>
        <td>' . ($selectedDate["AWAIT_CLIETNS"] ?? 0) . '</td>
        <td></td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>2</td>
        <td></td>
        <td>' . $row2 . '</td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>3</td>
        <td></td>
        <td>' . $row3 . '</td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>4</td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>5</td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td>6</td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
    </tr>
</table>
<table class="legend_table">
  <thead>
    <tr>
      <th style="line-height:6pt;">Стадии<br>воронки</th>
      <th>Описание</th>
      <th>Состав</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td class="number_pp">1</td>
      <td>Потенциальные покупатели</td>
      <td>Лиды: Входящий, передан в обработку, в работе (ожидании)</td>
    </tr>
    <tr>
      <td class="number_pp">2</td>
      <td style="width:280px;">Потенциальные покупатели, с которыми был произведён первый контакт.</td>
      <td>Сделки: Новая, Опрос потребностей, Расчет</td>
    </tr>
    <tr>
      <td class="number_pp">3</td>
      <td>Заинтересованные потенциальные покупатели</td>
      <td>Сделки: ТКП, Проектирование, Экспертиза, Рабочая документация</td>
    </tr>
    <tr>
      <td class="number_pp">4</td>
      <td>Покупатели в процессе обсуждения цены</td>
      <td>Сделки: активная фаза</td>
    </tr>
    <tr>
      <td class="number_pp">5</td>
      <td>Покупатели в процессе подписания договора</td>
      <td>Сделки: Договор/счёт</td>
    </tr>
    <tr>
      <td class="number_pp">6</td>
      <td>Покупатели, заплатившие деньги</td>
      <td>Сделки: Производство, Готов к отгрузке, Документы</td>
    </tr>
  </tbody>
</table>



            <div class="subtitle">В том числе по менеджерам*</div>
             <table style="margin-bottom:5px;">
                <tr>
                    <td class="g1">1 - Емкостное оборуд.</td>
                    <td class="g2">2 - КХБ</td>
                    <td class="g3">3 - Пултрузия</td>
                    <td class="g4">4 - Спец.ёмкости</td>
                </tr>
            </table>

            <div class="legend_table_managers">
                            <table>
                                <tr>
                                    <td><p><b>I</b> - Контакт/компания</p></td>
                                    <td><p><b>II</b> - Стадия 2 ВП</p></td>
                                    <td><p><b>III</b> - Стадия 3 ВП</p></td>
                                </tr>
                            </table>
                                                
                        
                        
                        </div>

<table class="managers_table">
    <tr>
        <td class="th" rowspan="2">№</td>
        <td class="th" rowspan="2">Ответственный</td>
        <td class="th" rowspan="2" style="width:50px;">Общее число</td>
        <td class="th" colspan="3">Качеств.</td>
        <td class="th" colspan="2">В&nbsp;ожидании</td>
        <td class="th" rowspan="2">Не&nbsp;обраб.</td>
        <td class="th" rowspan="2">Нецелев.</td>
        <td class="th" colspan="2">Время обработки</td>
    </tr>
    <tr>
        <td class="th">I</td>
        <td class="th">II</td>
        <td class="th">III</td>
        <td class="th">ФЛ</td>
        <td class="th">Клиент</td>
        <td class="th" style="width:80px;">Среднее</td>
        <td class="th" style="width:80px;">Общее</td>
    </tr>
   ' . $managersHtml . '
</table>




                        <p class="quote" style="font-style:italic; text-align:center; font-size:8pt; margin:0;">' . htmlspecialchars($quoteText) . '</p>
                        

            ';

    if ($pdf) {
        $html .= '
            <div style="margin-top:20pt;">
            ' . getSourcesTableHTML($leads_sources, $pdf) . '
            </div>';
    }

    // $charts по умолчанию [] — предупреждений при foreach(null) не будет
    foreach ($charts as $chart) {
        $chartId   = htmlspecialchars($chart['id']);
        $imageData = $chart['data'];

        $imageData   = str_replace('data:image/png;base64,', '', $imageData);
        $imageData   = str_replace(' ', '+', $imageData);
        $imageBinary = base64_decode($imageData);
        $imageBase64 = base64_encode($imageBinary);
        $imageSrc    = 'data:image/png;base64,' . $imageBase64;

        $chartTitle = '';
        if ($chartId == 'DoughnutChart') {
            $chartTitle = '<h3 style="text-align:center;' . $fontsize . '">Количество лидов по разделам за отчетную неделю</h3>';
        } else if ($chartId == 'LineChartSumLeads') {
            $chartTitle = '<h3 style="text-align: center;' . $fontsize . '">Динамика количества лидов</h3>';
        } else if ($chartId == 'HorizontalGisto') {
            $chartTitle = '<h3 style="text-align: center;' . $fontsize . '">Динамика количества лидов по менеджерам (за&nbsp;последние&nbsp;4&nbsp;отчетные&nbsp;недели)</h3>';
        } else if ($chartId == 'DepartmentAbsoluteChart') {
            $chartTitle = '<h3 style="text-align: center;' . $fontsize . '">Динамика распределения лидов по отделам, шт</h3>';
        } else if ($chartId == 'DepartmentLineChart') {
            $chartTitle = '<h3 style="text-align: center;' . $fontsize . '">Динамика распределения лидов по отделам, %</h3>';
        }

        $html .= '
            <div class="chart-container">
            ' . $chartTitle . '
                <img src="' . $imageSrc . '" alt="' . $chartId . '" id="' . $chartId . '">
            </div>
            ';
    }



    if ($pdf) {
        $html .= ' <div style="margin-top:20pt; margin-bottom:30pt;">
                <div class="table-wrapper">
                    <h3 style="text-align:center; margin-bottom:8pt;' . $fontsize . '">Статистика по выставленным КП*</h3>
                    <p style="font-style:italic; text-align:center; font-size:8pt; margin:0; margin-bottom:10px;">* Данные неточные, необходимо скорректировать механизм обработки КП. Сейчас отображаются данные сделок, попавших на стадию ТКП, а также те, которые эту стадию пропустили (за исключением забракованных сделок) за прошлую отчетную неделю.</p>
            ' . $tablekp . '
            </div>
            </div>';
    }


    // --- Настройки WS (правьте под свой сервер) -------------------------
    // Если WS-сервер за nginx reverse-proxy на том же хосте:
    //   wss://portal.example.com/ws/follow   (HTTPS-сайт → обязательно wss)
    //   ws://portal.example.com/ws/follow    (HTTP-сайт)
    $wsProto = 'wss';
    $wsHost  = 'b24.flotenk.ru:8081';
    $wsPath  = '/ws/follow';
    $wsUrl   = $wsProto . '://' . $wsHost . $wsPath;

    $script = '<script>
(function () {
    var WS_URL = ' . json_encode($wsUrl) . ';
    var INITIAL_USERS = ' . ($userIdsJson ?: '[]') . ';

    var openUsers = INITIAL_USERS.map(String);
    var countLeadsSpan = document.querySelector(".dinamic_count_inwork");
    var refreshing = false;

    var ws = null;
    var reconnectTimer = null;
    var reconnectDelay = 1000;
    var MAX_DELAY = 30000;
    var heartbeatTimer = null;

    function render() {
        if (countLeadsSpan) countLeadsSpan.textContent = openUsers.length;
    }

    // Полная сверка с сервером — на случай пропущенных сообщений
    function refresh() {
        if (refreshing) return;
        refreshing = true;
        fetch("/local/summary_report/api/follow_state.php?_=" + Date.now(), {
            credentials: "same-origin",
            cache: "no-store"
        })
        .then(function (r) { return r.ok ? r.text() : ""; })
        .then(function (t) {
            if (!t) return;
            var data;
            try { data = JSON.parse(t); } catch (e) { return; }
            if (!data.success) return;
            openUsers = (data.users || []).map(String);
            render();
        })
        .catch(function (e) { console.error("[follow] refresh:", e); })
        .finally(function () { refreshing = false; });
    }

    function handleMessage(msg) {
        if (!msg || typeof msg !== "object") return;
        var command = msg.command || msg.type;
        if (!command) return;

        if (command === "open_lead") {
            var uid = msg.userId != null ? String(msg.userId) : null;
            if (uid && openUsers.indexOf(uid) === -1) { openUsers.push(uid); render(); }
        } else if (command === "close_lead") {
            var uid2 = msg.userId != null ? String(msg.userId) : null;
            if (uid2) { openUsers = openUsers.filter(function (x) { return x !== uid2; }); render(); }
        } else if (command === "state") {
            // Полный снапшот от сервера: {command:"state", users:[...]}
            openUsers = (msg.users || []).map(String);
            render();
        } else if (command === "pong") {
            // ignore
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
        if (ws && (ws.readyState === WebSocket.CONNECTING || ws.readyState === WebSocket.OPEN)) {
            return;
        }
        try {
            ws = new WebSocket(WS_URL);
        } catch (e) {
            console.error("[follow] WS init error:", e);
            scheduleReconnect();
            return;
        }

        ws.onopen = function () {
            reconnectDelay = 1000;
            refresh(); // сверка с сервером сразу после подключения
            // heartbeat каждые 25 сек, чтобы прокси не рвал idle-соединение
            if (heartbeatTimer) clearInterval(heartbeatTimer);
            heartbeatTimer = setInterval(function () {
                if (ws && ws.readyState === WebSocket.OPEN) {
                    ws.send(JSON.stringify({ command: "ping" }));
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
            scheduleReconnect();
        };

        ws.onerror = function () {
            /* onclose сработает следом */
        };
    }

    // Публичный API — если понадобится отправить что-то с клиента
    window.followWs = {
        get socket() { return ws; },
        isOpen:  function () { return !!(ws && ws.readyState === WebSocket.OPEN); },
        refresh: refresh,
        send:    function (obj) {
            if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(obj));
        }
    };

    render();
    refresh();     // начальная синхронизация
    connect();     // постоянное WS-соединение

    // Страховка: даже если WS пропустил события — раз в 10 с поллим REST
    setInterval(refresh, 10000);

    // При возврате на вкладку — переподключиться и свериться
    document.addEventListener("visibilitychange", function () {
        if (!document.hidden) {
            refresh();
            if (!ws || ws.readyState > 1) connect();
        }
    });
})();
</script>';

    $html .= $script;



    if ($ownConn) {
        mysqli_close($connection);
    }

    return $html;
}


function getManagersHTML($data, ?mysqli $connection = null)
{
    $managers       = getManagers();
    $departmentsTmp = getDepartments();

    $selectedDate = isset($_GET['date_start']) ? $data[0] : $data[1];

    // --- разбивка качественных по стадиям 2 и 3 + контакты/компании ---
    $stageCountsByManager   = ['MANAGERS' => []];
    $contactsCountsByManager = ['MANAGERS' => []];

    if (!empty($selectedDate['date_start']) && !empty($selectedDate['date_end'])) {
        $ownConn = false;
        if ($connection === null) {
            $connection = mysqli_connect(DB_SERVER, DB_USER, DB_PASSWORD, DB_NAME);
            $ownConn    = true;
            if ($connection) {
                mysqli_set_charset($connection, "utf8mb4");
            }
        }
        if ($connection) {
            $stageCountsByManager = getDealsStagesByManagerCounts(
                $connection,
                $selectedDate['date_start'],
                $selectedDate['date_end']
            );
            $contactsCountsByManager = getContactsByManagerCounts(
                $connection,
                $selectedDate['date_start'],
                $selectedDate['date_end']
            );
        }
        if ($ownConn && $connection) {
            mysqli_close($connection);
        }
    }
    // -------------------------------------------------------------------

    $tmpManagers = [];
    foreach ($managers as $manager) {
        $manager['IS_LEADER'] = getIsLeader($departmentsTmp, $manager);
        $deptId = $manager['UF_DEPARTMENT'][0] ?? 0;
        $tmpManagers[$deptId][] = $manager;
    }

    $departments = sortDepartmentsAndUsers($tmpManagers);

    $k = 1;
    $managersHtml = '';
    foreach ($departments as $department) {
        if (empty($department)) continue;

        $rowspan    = count($department);
        $first      = true;
        $deptId     = $department[0]['UF_DEPARTMENT'][0] ?? 0;
        $groupClass = $departmentsTmp[$deptId]['class'] ?? '';

        foreach ($department as $manager) {
            $mData     = $selectedDate['MANAGERS'][$manager['ID']]      ?? null;
            $mStage    = $stageCountsByManager['MANAGERS'][$manager['ID']]     ?? ['STAGE2' => 0, 'STAGE3' => 0];
            $mContacts = $contactsCountsByManager['MANAGERS'][$manager['ID']]  ?? 0;

            // Форматирование ФИО: Фамилия И.О.
            $lastName   = trim($manager['LAST_NAME'] ?? '');
            $firstName  = trim($manager['NAME'] ?? '');
            $secondName = trim($manager['SECOND_NAME'] ?? '');

            $initials = '';
            if ($firstName !== '') {
                $initials .= mb_substr($firstName, 0, 1, 'UTF-8') . '.';
            }
            if ($secondName !== '') {
                $initials .= mb_substr($secondName, 0, 1, 'UTF-8') . '.';
            }
            $shortFio = trim($lastName . ' ' . $initials);

            $managersHtml .= '<tr class="' . $groupClass . '">';

            if ($first) {
                $managersHtml .= '<td rowspan="' . $rowspan . '" class="group">' . $k . '</td>';
                $first = false;
            }

            $managersHtml .= '<td class="left small">' . htmlspecialchars($shortFio) . '</td>
          <td class="small">' . ($mData['COUNT_LEADS']        ?? 0) . '</td>
          <td class="small">' . $mContacts                           . '</td>
          <td class="small">' . ($mStage['STAGE2']             ?? 0) . '</td>
          <td class="small">' . ($mStage['STAGE3']             ?? 0) . '</td>
          <td class="small">' . ($mData['AWAIT_MANAGERS']     ?? 0) . '</td>
          <td class="small">' . ($mData['AWAIT_CLIENTS']      ?? 0) . '</td>
          <td class="small">' . ($mData['COUNT_NEW_LEADS']     ?? 0) . '</td>
          <td class="small">' . ($mData['COUNT_FAILED_LEADS']  ?? 0) . '</td>
          <td class="small" style="font-size:8px;">' . formatTime(parseFormattedTimeToMinutes($mData['AVG_TIME']   ?? '')) . '</td>
          <td class="small" style="font-size:8px;">' . formatTime(parseFormattedTimeToMinutes($mData['TOTAL_TIME'] ?? '')) . '</td>
      </tr>';
        }
        $k++;
    }

    return $managersHtml;
}


function getSources($startStr = null, $endStr = null)
{
    $leadsWithSources = [];
    $sourceStatistics = [];

    $excludedSources = [
        'STORE',
        'UC_WGMCJ7',
        '20|BASECONNECTOR',
        'APP_SKYWEB24_HH_SOURCE',
        'RC_GENERATOR',
        'WEBFORM',
        'CALLBACK',
        'BOOKING',
    ];

    if ($startStr === null) {
        if (isset($_GET['date_start'])) {
            $startSources = DateTime::createFromFormat('Y-m-d H:i:s', $_GET['date_start']);
        } else {
            $startSources = new DateTime();
            $startSources->modify("-1 weeks");
            $startSources->modify('previous saturday');
        }
        $startStr = $startSources->format('Y-m-d 00:00:00');
    }

    if ($endStr === null) {
        if (isset($_GET['date_end'])) {
            $endSources = DateTime::createFromFormat('Y-m-d H:i:s', $_GET['date_end']);
        } else {
            $endSources = new DateTime();
            $endSources->modify('previous friday');
        }
        $endStr = $endSources->format('Y-m-d 23:59:59');
    }

    $paramsGetLeads = [
        'select' => ['ID', 'SOURCE_ID'],
        'filter' => [
            '>=DATE_CREATE'    => $startStr,
            '<=DATE_CREATE'    => $endStr,
            '!@SOURCE_ID'      => $excludedSources,
            '@ASSIGNED_BY_ID'  => getManagersIds(),
        ],
    ];

    $statusMap = getStatuses();

    $sourceStatistics['TOTAL_COUNT'] = 0;
    foreach ($statusMap as $sourceId => $sourceName) {
        if (!in_array($sourceId, $excludedSources)) {
            $sourceStatistics[$sourceName] = 0;
        }
    }

    $start = 0;
    do {
        $paramsGetLeads['start'] = $start;
        $responseGetLeads = callToBitrix('crm.lead.list', $paramsGetLeads);

        if (!empty($responseGetLeads['result'])) {
            foreach ($responseGetLeads['result'] as &$lead) {
                $lead['SOURCE_NAME'] = $statusMap[$lead['SOURCE_ID']] ?? 'Неизвестно';

                if (isset($sourceStatistics[$statusMap[$lead['SOURCE_ID']] ?? ''])) {
                    $sourceStatistics[$statusMap[$lead['SOURCE_ID']] ?? '']++;
                } else {
                    $sourceStatistics[$statusMap[$lead['SOURCE_ID']] ?? 'Неизвестно'] = 1;
                }
                $sourceStatistics['TOTAL_COUNT']++;
            }
            unset($lead);
            $leadsWithSources = array_merge($leadsWithSources, $responseGetLeads['result']);
        }

        $start = $responseGetLeads['next'] ?? 0;
    } while (!empty($responseGetLeads['next']));

    return json_encode([
        'leadsWithSources' => $leadsWithSources,
        'sourceStatistics' => $sourceStatistics,
    ], JSON_UNESCAPED_UNICODE);
}


function getSourcesTableHTML($leads_sources, $pdf = false)
{
    $leadsWithSources = $leads_sources['leadsWithSources'] ?? [];
    $sourceStatistics = $leads_sources['sourceStatistics'] ?? ['TOTAL_COUNT' => 0];

    $fontsize = $pdf ? 'font-size:12pt;' : '';

    $formQuiz = ['Квиз', 'CRM-форма'];
    $email    = ['Email', '@flotenk.ru', 'личн'];
    $phone    = ['+7', 'Входящ'];

    $filterWithValuesPreserveAll = function ($statistics, $patterns) {
        $filtered = [];
        foreach ($statistics as $key => $value) {
            if ($key === 'TOTAL_COUNT') continue;
            foreach ($patterns as $pattern) {
                if (stripos($key, $pattern) !== false) {
                    $filtered[$key] = $value;
                    break;
                }
            }
        }
        return $filtered;
    };

    $formsStatuses = $filterWithValuesPreserveAll($sourceStatistics, $formQuiz);
    $emailStatuses = $filterWithValuesPreserveAll($sourceStatistics, $email);
    $phoneStatuses = $filterWithValuesPreserveAll($sourceStatistics, $phone);

    $getStatusesHtmlWithGroupPercent = function ($statuses, $totalCount, $groupName) {
        $totalGroup   = array_sum($statuses);
        $groupPercent = $totalCount > 0 ? round(($totalGroup / $totalCount) * 100, 2) : 0;
        $rowsCount    = count($statuses);

        $html  = '<tr><td class="table-title" colspan="3">' . htmlspecialchars($groupName) . '</td></tr>';
        $html .= '<tr><td class="table-header">Источник</td><td class="table-header">Кол-во</td><td class="table-header">Итого (%)</td></tr>';

        $rowIndex = 0;
        foreach ($statuses as $name => $count) {
            $html .= '<tr>';
            $html .= '<td style="text-align:center; vertical-align:middle; padding: 3px 5px;">' . htmlspecialchars($name) . '</td>';
            $html .= '<td style="text-align:center; vertical-align:middle; padding: 3px 5px;">' . $count . '</td>';
            if ($rowIndex === 0) {
                $html .= '<td style="text-align:center; vertical-align:middle; padding: 3px 5px; font-weight:bold;" rowspan="' . $rowsCount . '">' . $groupPercent . '%</td>';
            }
            $html .= '</tr>';
            $rowIndex++;
        }
        return $html;
    };

    $emailStatusesHtml = $getStatusesHtmlWithGroupPercent($emailStatuses, $sourceStatistics['TOTAL_COUNT'], 'Электронная почта');
    $phoneStatusesHtml = $getStatusesHtmlWithGroupPercent($phoneStatuses, $sourceStatistics['TOTAL_COUNT'], 'Входящие звонки');
    $formsStatusesHtml = $getStatusesHtmlWithGroupPercent($formsStatuses, $sourceStatistics['TOTAL_COUNT'], 'CRM-формы, квизы и пр.');

    $sourcesHtml = '
    <style>
        table.iksweb { width: 100%; border-collapse: collapse; border-spacing: 0; }
        table.iksweb, table.iksweb td, table.iksweb th { border: 1px solid #595959; }
        table.iksweb td, table.iksweb th { padding: 3px 5px; }
        table.iksweb th { background: #ffffff; color: #000000; font-weight: normal; }
        .table-wrapper { width: 100%; margin-bottom: 10px; page-break-inside: avoid; }
        .table-wrapper td { vertical-align: top; padding: 0 5px; }
        .table-wrapper table { margin: 0; }
        .sumItog table td { padding: 4px 5px; }
        .sumItog table td:first-child { text-align: right; font-weight: bold; }
        .sumItog table td:nth-child(2) { text-align: center; font-weight: bold; }
        .sumItog table td:last-child { text-align: center; font-weight: bold; }
        .left-col { width: 50%; padding-right: 5px; }
        .right-col { width: 50%; padding-left: 5px; }
        .table-title { background-color: #e2e2e2; text-align: center; vertical-align: middle; padding: 4px 5px; font-weight: bold; }
        .table-header { text-align: center; vertical-align: middle; padding: 3px 5px; font-weight:bold; background-color: #ffffff; color: #000000; }
    </style>
    <div class="table-wrapper">
    <h3 style="text-align: center;' . $fontsize . '">Источники лидов</h3>
        <table style="width:100%; border-collapse:collapse; border:none;">
            <tr>
                <td class="left-col" style="border: none;">
                    <table class="iksweb" style="margin-bottom:5px;">' . $emailStatusesHtml . '</table>
                    <table class="iksweb">' . $phoneStatusesHtml . '</table>
                </td>
                <td class="right-col" style="border: none;">
                    <table class="iksweb" style="margin-bottom:5px;">' . $formsStatusesHtml . '</table>
                    <table class="iksweb sumItog">
                        <tr>
                            <td style="text-align: right; padding: 4px 5px; font-weight:bold;">Всего</td>
                            <td style="text-align: center; padding: 4px 5px; font-weight:bold;">' . $sourceStatistics['TOTAL_COUNT'] . '</td>
                            <td style="text-align: center; padding: 4px 5px; font-weight:bold;">100%</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>';

    return $sourcesHtml;
}


function saveChartsToPDF($charts, $data, $leads_sources)
{
    try {
        $managersHtml    = getManagersHTML($data);
        $departmentsTmp  = getDepartments();
        $managers        = getManagers();

        $selectedDate = isset($_GET['date_start']) ? $data[0] : $data[1];

        $dateStartKP = date('Y-m-d\TH:i:s', strtotime($selectedDate["date_start"]));
        $dateEndKP   = date('Y-m-d\TH:i:s', strtotime($selectedDate["date_end"]));
        $readyData   = getDataKP($dateStartKP, $dateEndKP);

        $managersData = [];
        foreach ($readyData as $key => $value) {
            if ($key === 'TOTAL_OPPORTUNITY' || $key === 'TOTAL_KP') continue;
            foreach ($value as $managerId => $managerInfo) {
                $managersData[$managerId] = $managerInfo;
            }
        }
        $htmlTableKP = getKPManagersHTML($managersData, $departmentsTmp, $managers);

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

        $html  = getHtml(
            $managersHtml,
            $data,
            $htmlTableKP,
            $leads_sources,
            $charts,
            true,
            null,
            $userIdsJson
        );

        $html .= '</body></html>';

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);
        $options->set('chroot', __DIR__);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdf      = $dompdf->output();
        $filePath = __DIR__ . '/report.pdf';

        if (file_put_contents($filePath, $pdf)) {
            echo json_encode([
                'success' => true,
                'message' => 'PDF создан!',
                'url'     => 'report.pdf',
            ]);
        } else {
            throw new Exception('Не удалось сохранить PDF');
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Ошибка: ' . $e->getMessage(),
        ]);
    }
}


function saveChartToPDF($imageData, $data, $leads_sources)
{
    $charts = [['id' => 'Chart', 'data' => $imageData]];
    saveChartsToPDF($charts, $data, $leads_sources);
}


setEnvData();
define('WEBHOOK', $_ENV['WEBHOOK']);


function callToBitrix($method, $params = array())
{
    $webhook = WEBHOOK;
    $url     = $webhook . "/" . $method;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_HEADER         => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        return [
            'error'             => true,
            'error_description' => 'cURL Error: ' . $error,
            'http_code'         => $httpCode,
            'url'               => $url,
        ];
    }

    $result = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'error'             => true,
            'error_description' => 'JSON Error: ' . json_last_error_msg(),
            'raw_response'      => $response,
        ];
    }

    if (isset($result['error'])) {
        return [
            'error'             => true,
            'error_code'        => $result['error'],
            'error_description' => $result['error_description'] ?? 'Unknown Bitrix error',
            'result'            => $result['result'] ?? null,
        ];
    }

    return [
        'error'  => false,
        'result' => $result['result'] ?? $result,
        'total'  => $result['total']  ?? null,
        'next'   => $result['next']   ?? null,
    ];
}


/**
 * Параллельные запросы к Bitrix24 через curl_multi.
 * $requests: ['key' => ['method' => '...', 'params' => [...]]]
 * Возвращает ['key' => ['error'=>bool,'result'=>mixed,'next'=>int|null]]
 */
function callToBitrixMulti(array $requests, int $concurrency = 10): array
{
    if (empty($requests)) {
        return [];
    }
    $out = [];
    foreach (array_chunk($requests, $concurrency, true) as $batch) {
        $out += _callToBitrixMultiBatch($batch);
    }
    return $out;
}

function _callToBitrixMultiBatch(array $requests): array
{
    $webhook = WEBHOOK;
    $multi   = curl_multi_init();
    $handles = [];

    foreach ($requests as $key => $req) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $webhook . '/' . $req['method'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($req['params'] ?? []),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$key] = $ch;
    }

    do {
        $status = curl_multi_exec($multi, $active);
        if ($active) {
            curl_multi_select($multi, 0.5);
        }
    } while ($active && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $key => $ch) {
        $raw = curl_multi_getcontent($ch);
        $err = curl_error($ch);
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);

        if ($err) {
            $results[$key] = ['error' => true, 'error_description' => $err, 'result' => null, 'next' => null];
            continue;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $results[$key] = ['error' => true, 'error_description' => 'JSON parse error', 'result' => null, 'next' => null];
            continue;
        }
        $results[$key] = [
            'error'  => isset($data['error']),
            'result' => $data['result'] ?? null,
            'next'   => $data['next']   ?? null,
        ];
    }
    curl_multi_close($multi);

    return $results;
}


function getWeekRange($date)
{
    $start = clone $date;
    $dayOfWeek = (int)$date->format('N');

    if ($dayOfWeek == 6) {
        $start->setTime(0, 0, 0);
    } elseif ($dayOfWeek == 7) {
        $start->modify('-1 day')->setTime(0, 0, 0);
    } else {
        $daysToSubtract = $dayOfWeek + 1;
        $start->modify("-{$daysToSubtract} days")->setTime(0, 0, 0);
    }

    $end = clone $start;
    $end->modify('+6 days')->setTime(23, 59, 59);

    return [
        'start'       => $start,
        'end'         => $end,
        'start_str'   => $start->format('Y-m-d\TH:i:s'),
        'end_str'     => $end->format('Y-m-d\TH:i:s'),
        'start_mysql' => $start->format('Y-m-d H:i:s'),
        'end_mysql'   => $end->format('Y-m-d H:i:s'),
        'week_number' => $start->format('W'),
    ];
}


function getManagersIds()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $resp = callToBitrix('im.department.employees.get', ['ID' => array_keys(DEPARTMENTS)]);
    $blacklist = [106 => true];  // как было в оригинале; 1450 фильтруется в getManagers()
    $ids = [];

    $result = $resp['result'] ?? [];
    if (is_array($result)) {
        foreach ($result as $departmentUserIds) {
            if (!is_array($departmentUserIds)) continue;
            foreach ($departmentUserIds as $uid) {
                if (!isset($blacklist[(int)$uid])) {
                    $ids[] = (int)$uid;
                }
            }
        }
    }

    return $cache = array_values(array_unique($ids));
}


/**
 * Оптимизированная версия: батчинг активностей и stagehistory,
 * отказ от select=>["*"], корректная логика первого входа в IN_PROCESS.
 */
function getLeadsAndDealsByManagers($dateStart, $dateEnd)
{
    $leads = [
        'MANAGERS'            => [],
        'TOTAL_LEADS'         => 0,
        'COUNT_SUCCESS_LEADS' => 0,
        'COUNT_FAILED_LEADS'  => 0,
        'COUNT_IN_WORK_LEADS' => 0,
        'COUNT_NEW_LEADS'     => 0,
        'AWAIT_CLIETNS'       => 0,
        'AWAIT_MANAGERS'      => 0,
    ];
    $historyUtems = [];

    /* ---------- 1. Все лиды за период ---------- */
    $managersIDs = getManagersIds();
    $paramsL = [
        'select' => [
            'ID',
            'ASSIGNED_BY_ID',
            'DATE_CLOSED',
            'MOVED_TIME',
            'DATE_CREATE',
            'STATUS_SEMANTIC_ID',
            'STATUS_ID'
        ],
        'filter' => [
            '>=DATE_CREATE'   => $dateStart,
            '<=DATE_CREATE'   => $dateEnd,
            '@ASSIGNED_BY_ID' => $managersIDs,
        ],
        'order'  => ['DATE_CREATE' => 'DESC'],
    ];

    $allLeads = [];
    $start = 0;
    do {
        $paramsL['start'] = $start;
        $resp = callToBitrix('crm.lead.list', $paramsL);
        if (!empty($resp['result'])) {
            $allLeads = array_merge($allLeads, $resp['result']);
        }
        $start = $resp['next'] ?? 0;
    } while (!empty($resp['next']));

    if (empty($allLeads)) {
        return [$leads, $historyUtems];
    }

    /* ---------- 2. Активности — батчами (было N+1) ---------- */
    $leadIds     = array_column($allLeads, 'ID');
    $hasActivity = [];

    foreach (array_chunk($leadIds, 50) as $chunk) {
        $st = 0;
        do {
            $resp = callToBitrix('crm.activity.list', [
                'filter' => [
                    'OWNER_TYPE_ID' => 1,
                    '@OWNER_ID'     => $chunk,
                    'COMPLETED'     => 'N',
                ],
                'select' => ['ID', 'OWNER_ID'],
                'start'  => $st,
            ]);
            if (!empty($resp['result'])) {
                foreach ($resp['result'] as $a) {
                    $hasActivity[(int)$a['OWNER_ID']] = true;
                }
            }
            $st = $resp['next'] ?? 0;
        } while (!empty($resp['next']));
    }

    foreach ($allLeads as &$lead) {
        $lead['AWAITENER'] = isset($hasActivity[(int)$lead['ID']]) ? 'CLIENT' : 'MANAGER';
    }
    unset($lead);

    /* ---------- 3. Группировка по менеджерам ---------- */
    foreach ($allLeads as $lead) {
        $mid = $lead['ASSIGNED_BY_ID'];
        if (!isset($leads['MANAGERS'][$mid])) {
            $leads['MANAGERS'][$mid] = [
                'LEADS'              => [],
                'TOTAL_TIME_MINUTES' => 0,
                'COUNT_LEADS'        => 0,
            ];
        }
        $leads['MANAGERS'][$mid]['LEADS'][] = $lead;
        $leads['MANAGERS'][$mid]['COUNT_LEADS']++;
        $leads['TOTAL_LEADS']++;
    }

    /* ---------- 4. Stage history — батчами (было N+1) ---------- */
    $needHistory = [];
    foreach ($allLeads as $lead) {
        if (!empty($lead['DATE_CLOSED'])) {
            $needHistory[] = (int)$lead['ID'];
        }
    }

    $historyByLead = [];
    if (!empty($needHistory)) {
        // Запросы формируем заранее — используем параллельный запуск
        $requests = [];
        foreach (array_chunk($needHistory, 50) as $i => $chunk) {
            $requests[$i] = [
                'method' => 'crm.stagehistory.list',
                'params' => ['entityTypeId' => 1, 'filter' => ['@OWNER_ID' => $chunk]],
            ];
        }

        $responses = callToBitrixMulti($requests);
        $pending   = [];

        foreach ($responses as $i => $r) {
            $items = $r['result']['items'] ?? [];
            foreach ($items as $item) {
                $historyByLead[(int)$item['OWNER_ID']][] = $item;
            }
            if (!empty($r['next'])) {
                $pending[$i] = (int)$r['next'];
            }
        }

        // Пагинация
        while (!empty($pending)) {
            $requests2 = [];
            foreach ($pending as $i => $st) {
                $requests2[$i] = $requests[$i];
                $requests2[$i]['params']['start'] = $st;
            }
            $responses2 = callToBitrixMulti($requests2);
            $pending    = [];

            foreach ($responses2 as $i => $r) {
                $items = $r['result']['items'] ?? [];
                foreach ($items as $item) {
                    $historyByLead[(int)$item['OWNER_ID']][] = $item;
                }
                if (!empty($r['next'])) {
                    $pending[$i] = (int)$r['next'];
                }
            }
        }
    }
    $historyUtems = $historyByLead;

    /* ---------- 5. Подсчёты ---------- */
    foreach ($leads['MANAGERS'] as &$manager) {
        $countSuccess = 0;
        $countFailed  = 0;
        $countInWork  = 0;
        $countNew     = 0;
        $countManagers = 0;
        $countClients = 0;

        foreach ($manager['LEADS'] as &$lead) {
            $leadId = (int)$lead['ID'];

            if ($lead['AWAITENER'] === 'MANAGER' && $lead['STATUS_SEMANTIC_ID'] === 'P') {
                $countManagers++;
            } elseif ($lead['AWAITENER'] === 'CLIENT') {
                $countClients++;
            }

            if ($lead['STATUS_ID'] === 'NEW' || $lead['STATUS_ID'] === '1') {
                $countNew++;
            }
            if ($lead['STATUS_ID'] === 'IN_PROCESS') {
                $countInWork++;
            }
            if ($lead['STATUS_SEMANTIC_ID'] === 'F') {
                $countFailed++;
            }
            if ($lead['STATUS_SEMANTIC_ID'] === 'S') {
                $countSuccess++;
            }

            if ($lead['DATE_CLOSED'] !== '') {
                $closed = !empty($lead['MOVED_TIME']) ? $lead['MOVED_TIME'] : $lead['DATE_CLOSED'];
                unset($lead['MOVED_TIME']);

                // Ищем ПЕРВЫЙ вход в IN_PROCESS/1 (или fallback — DATE_CREATE)
                $items = $historyByLead[$leadId] ?? [];
                if (!empty($items)) {
                    usort($items, function ($a, $b) {
                        return strcmp($a['CREATED_TIME'] ?? '', $b['CREATED_TIME'] ?? '');
                    });
                }
                $startTime = null;
                foreach ($items as $it) {
                    $stId = $it['STATUS_ID'] ?? '';
                    if ($stId === 'IN_PROCESS' || $stId === '1') {
                        $startTime = $it['CREATED_TIME'] ?? null;
                        break;
                    }
                }
                if ($startTime === null) {
                    $startTime = $lead['DATE_CREATE'];
                }

                $wt = getWorkTime($startTime, $closed);
                $lead['IN_PROGRESS_START_TIME'] = $startTime;
                $lead['WORK_TIME']              = $wt['formatted'];
                $manager['TOTAL_TIME_MINUTES'] += $wt['minutes'];
            }
        }
        unset($lead);

        $manager['TOTAL_TIME']          = formatTime($manager['TOTAL_TIME_MINUTES']);
        $manager['AVG_TIME']            = getAvgTime($manager['COUNT_LEADS'], $manager['TOTAL_TIME_MINUTES']);
        $manager['COUNT_SUCCESS_LEADS'] = $countSuccess;
        $manager['COUNT_FAILED_LEADS']  = $countFailed;
        $manager['COUNT_IN_WORK_LEADS'] = $countInWork;
        $manager['COUNT_NEW_LEADS']     = $countNew;
        $manager['AWAIT_MANAGERS']      = $countManagers;
        $manager['AWAIT_CLIENTS']       = $countClients;


        $leads['COUNT_SUCCESS_LEADS'] += $countSuccess;
        $leads['COUNT_FAILED_LEADS']  += $countFailed;
        $leads['COUNT_IN_WORK_LEADS'] += $countInWork;
        $leads['COUNT_NEW_LEADS']     += $countNew;
        $leads['AWAIT_CLIETNS']       += $countClients;
        $leads['AWAIT_MANAGERS']      += $countManagers;

        unset($manager['LEADS'], $manager['TOTAL_TIME_MINUTES']);
    }
    unset($manager);

    return [$leads, $historyUtems];
}


function getWorkTime($dateStart, $dateEnd)
{
    $seconds = strtotime($dateEnd) - strtotime($dateStart);
    $minutes = (int)floor($seconds / 60);
    $hours   = (int)floor($minutes / 60);
    $rem     = $minutes % 60;

    return [
        'formatted' => $hours . ' ч ' . $rem . ' мин',
        'minutes'   => $minutes,
    ];
}

function formatTime($totalMinutes)
{
    $totalMinutes     = (int)round($totalMinutes);
    $WORK_DAY_MINUTES = 8 * 60; // 480 мин = 1 рабочий день

    $days    = intdiv($totalMinutes, $WORK_DAY_MINUTES);
    $rest    = $totalMinutes % $WORK_DAY_MINUTES;
    $hours   = intdiv($rest, 60);
    $minutes = $rest % 60;

    return $days . ' дн. ' . $hours . ' ч. ' . $minutes . ' мин.';
}

function getAvgTime($countLeads, $totalTime)
{
    if ($countLeads === 0) {
        return '0 дн. 0 ч. 0 мин.';
    }
    return formatTime($totalTime / $countLeads);
}


function getStatuses()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $allStatuses = callToBitrix('crm.status.list', [
        'select' => ['STATUS_ID', 'NAME'],
        'filter' => ['ENTITY_ID' => 'SOURCE'],
    ]);
    $statusMap = [];
    foreach (($allStatuses['result'] ?? []) as $status) {
        $statusMap[$status['STATUS_ID']] = $status['NAME'];
    }
    return $cache = $statusMap;
}


function filterWithValues($array, $keywords, $preserveKeys = true, $sortDesc = true)
{
    $result = ['TOTAL' => 0];

    foreach ($array as $key => $value) {
        foreach ($keywords as $keyword) {
            if (stripos($key, $keyword) !== false) {
                if ($preserveKeys) {
                    $result[$key] = $value;
                } else {
                    $result[] = $value;
                }
                $result['TOTAL'] += $value;
                break;
            }
        }
    }

    if ($sortDesc) {
        $total = $result['TOTAL'];
        unset($result['TOTAL']);
        arsort($result);
        $result = ['TOTAL' => $total] + $result;
    }

    return $result;
}

function getStatusesHtml($statuses, $statistics)
{
    $percentage = 0;
    if (($statistics['TOTAL_COUNT'] ?? 0) != 0) {
        $percentage = round(($statuses['TOTAL'] * 100) / $statistics['TOTAL_COUNT'], 2);
    }

    $html = '';
    $k = 1;
    foreach ($statuses as $status => &$count) {
        if ($status == 'TOTAL') continue;

        $percentageHtml = '';
        if ($k == 1) {
            $percentageHtml = '<td rowspan="' . (count($statuses) - 1) . '" style="text-align:center; vertical-align:middle;">' . $percentage . '%</td>';
            $k++;
        }

        $html = $html . '<tr>
                <td>' . $status . '</td>
                <td style="text-align:center; vertical-align:middle;">' . $count . '</td>
                ' . $percentageHtml . '
            </tr>';

        $count = $html;
    }
    unset($count);

    return $html;
}


/**
 * Оптимизированная версия: батчинг сделок, дедупликация по ID,
 * исправлено накопление items при пагинации.
 */
function getDataKP($dataStart, $dataEnd)
{
    /* 1. Stage history — с правильным накоплением items */
    $paramsD2 = [
        'entityTypeId' => 2,
        'filter' => [
            '>=CREATED_TIME' => $dataStart,
            '<=CREATED_TIME' => $dataEnd,
            '@STAGE_ID'      => [
                'C29:EXECUTING',
                'C29:FINAL_INVOICE',
                'C29:UC_3OMQWF',
                'C29:UC_UD1NMI',
                'C29:UC_TGIA8C',
                'C29:UC_I960K3',
                'C29:UC_4X6J4D',
                'C29:UC_0EJ3Z0',
                'C29:UC_HJ33NP',
                'C29:WON',
            ],
        ],
        'select' => ['OWNER_ID', 'CREATED_TIME', 'STAGE_ID'],
    ];

    $items = [];
    $start = 0;
    do {
        $paramsD2['start'] = $start;
        $resp = callToBitrix('crm.stagehistory.list', $paramsD2);
        if (!empty($resp['result']['items'])) {
            $items = array_merge($items, $resp['result']['items']);
        }
        $start = $resp['next'] ?? 0;
    } while (!empty($resp['next']));

    // Дедупликация по ID сделки: последняя стадия побеждает
    $tmpDeals = [];
    foreach ($items as $it) {
        $dealId = (int)($it['OWNER_ID'] ?? 0);
        if ($dealId > 0) {
            $tmpDeals[$dealId] = [
                'STAGE_ID'     => $it['STAGE_ID']    ?? null,
                'CREATED_TIME' => $it['CREATED_TIME'] ?? null,
            ];
        }
    }

    if (empty($tmpDeals)) {
        return ['TOTAL_OPPORTUNITY' => 0, 'TOTAL_KP' => 0];
    }

    /* 2. Сделки — батчами (было N+1) */
    $ownerIds = array_keys($tmpDeals);
    foreach (array_chunk($ownerIds, 50) as $chunk) {
        $resp = callToBitrix('crm.deal.list', [
            'select' => ['ID', 'ASSIGNED_BY_ID', 'OPPORTUNITY'],
            'filter' => ['@ID' => $chunk],
        ]);
        foreach (($resp['result'] ?? []) as $d) {
            $id = (int)$d['ID'];
            if (isset($tmpDeals[$id])) {
                $tmpDeals[$id]['ASSYGNED_BY_ID'] = $d['ASSIGNED_BY_ID'];
                $tmpDeals[$id]['OPPORTUNITY']    = $d['OPPORTUNITY'];
            }
        }
    }

    /* 3. Агрегация */
    $readyData = ['TOTAL_OPPORTUNITY' => 0, 'TOTAL_KP' => 0];

    $userDept = [];
    foreach (getManagers() as $u) {
        $userDept[(int)$u['ID']] = (int)($u['UF_DEPARTMENT'][0] ?? 0);
    }

    foreach ($tmpDeals as $dealId => $deal) {
        if (!isset($deal['ASSYGNED_BY_ID'], $deal['OPPORTUNITY'])) continue;

        $userId = (int)$deal['ASSYGNED_BY_ID'];
        $dept   = $userDept[$userId] ?? 0;
        $opp    = (int)$deal['OPPORTUNITY'];

        if (!isset($readyData[$dept][$userId])) {
            $readyData[$dept][$userId] = ['OPPORTUNITY' => $opp, 'COUNT' => 1];
        } else {
            $readyData[$dept][$userId]['OPPORTUNITY'] += $opp;
            $readyData[$dept][$userId]['COUNT']++;
        }
        $readyData['TOTAL_OPPORTUNITY'] += $opp;
        $readyData['TOTAL_KP']++;
    }

    return $readyData;
}


function getKPManagersHTML($data, $departmentsTmp, $managers)
{
    $tmpManagers = [];
    foreach ($managers as $manager) {
        $manager['IS_LEADER'] = getIsLeader($departmentsTmp, $manager);
        $deptId = $manager['UF_DEPARTMENT'][0] ?? 0;
        $tmpManagers[$deptId][] = $manager;
    }

    $departments = sortDepartmentsAndUsers($tmpManagers);

    $k = 1;
    $managersHtml = '';
    $grandTotalLeads = 0;
    $grandTotalOpportunity = 0;

    foreach ($departments as $department) {
        if (empty($department)) continue;

        $rowspan = count($department);
        $deptId  = $department[0]['UF_DEPARTMENT'][0] ?? 0;
        $groupClass = DEPARTMENTS[$deptId]['class'] ?? '';

        // Суммы по отделу
        $totalLeads = 0;
        $totalOpportunity = 0;
        foreach ($department as $manager) {
            $mData = $data[$manager['ID']] ?? null;
            $totalLeads       += $mData['COUNT']       ?? 0;
            $totalOpportunity += $mData['OPPORTUNITY'] ?? 0;
        }
        $grandTotalLeads       += $totalLeads;
        $grandTotalOpportunity += $totalOpportunity;

        $first = true;
        foreach ($department as $manager) {
            $mData = $data[$manager['ID']] ?? null;
            $countLeads  = $mData['COUNT']       ?? 0;
            $opportunity = $mData['OPPORTUNITY'] ?? 0;

            $managersHtml .= '<tr class="' . $groupClass . '">';

            if ($first) {
                $managersHtml .= '<td rowspan="' . $rowspan . '" class="group" style="padding:3px; font-size:8pt; font-weight:normal; vertical-align:middle;">' . $k . '</td>';
            }

            $fullName = trim($manager['LAST_NAME'] . ' ' . $manager['NAME'] . ' ' . $manager['SECOND_NAME']);
            $managersHtml .= '<td class="left small" style="padding:3px;">' . htmlspecialchars($fullName) . '</td>';
            $managersHtml .= '<td class="small" style="padding:3px;">' . $countLeads . '</td>';
            $managersHtml .= '<td class="small" style="padding:3px;">' . number_format($opportunity, 0, '.', ' ') . '</td>';

            if ($first) {
                $managersHtml .= '<td rowspan="' . $rowspan . '" class="small" style="padding:3px; vertical-align:middle;">' . number_format($totalOpportunity, 0, '.', ' ') . '</td>';
                $first = false;
            }

            $managersHtml .= '</tr>';
        }
        $k++;
    }

    $totalSummary = 'Выставлено ' . $grandTotalLeads . ' коммерческих предложений на '
        . number_format($grandTotalOpportunity, 0, '.', ' ') . ' руб';

    return '
    <p style="font-size:8pt; margin-bottom:10px;">' . $totalSummary . '</p>
    <table border="1" cellpadding="5" cellspacing="0" style="font-size:10pt;">
        <thead>
            <tr>
                <th>№</th>
                <th>ФИО</th>
                <th>Кол-во КП</th>
                <th>Сумма КП</th>
                <th>Сумма КП по отделу</th>
            </tr>
        </thead>
        <tbody>
            ' . $managersHtml . '
        </tbody>
    </table>
    ';
}


/**
 * Количество уникальных лидов, конвертированных в контакт/компанию
 * за период — БЕЗ учёта лидов, по которым создана сделка.
 * Добавлен static-кеш.
 */
function getConvertedLeadsCountForPeriod($connection, $periodStart, $periodEnd)
{
    static $cache = [];
    $ck = $periodStart . '|' . $periodEnd;
    if (array_key_exists($ck, $cache)) {
        return $cache[$ck];
    }

    $ps = mysqli_real_escape_string($connection, $periodStart);
    $pe = mysqli_real_escape_string($connection, $periodEnd);

    $sql = "SELECT snapshot_data
            FROM quality_leads_snapshot_dev
            WHERE period_start = '{$ps}'
              AND period_end   = '{$pe}'
            LIMIT 1";

    $res = mysqli_query($connection, $sql);
    if (!$res) {
        logMessage("❌ getConvertedLeadsCountForPeriod SQL error: " . mysqli_error($connection));
        return $cache[$ck] = 0;
    }
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);

    if (!$row || empty($row['snapshot_data'])) {
        return $cache[$ck] = 0;
    }

    $data = json_decode($row['snapshot_data'], true);
    if (!is_array($data) || empty($data['leads']) || !is_array($data['leads'])) {
        return $cache[$ck] = 0;
    }

    $dealsByLead = $data['dealsByLead'] ?? [];
    $uniqueConverted = [];

    foreach ($data['leads'] as $lead) {
        $leadId = (int)($lead['ID'] ?? 0);
        if ($leadId <= 0) continue;
        if (!empty($dealsByLead[$leadId])) continue;

        $contactId = (int)($lead['CONTACT_ID'] ?? 0);
        $companyId = (int)($lead['COMPANY_ID'] ?? 0);
        if ($contactId > 0 || $companyId > 0) {
            $uniqueConverted[$leadId] = true;
        }
    }

    return $cache[$ck] = count($uniqueConverted);
}


/**
 * Подсчёт сделок по существующим стадиям (из снапшота).
 * Добавлен static-кеш по (periodStart, periodEnd, флаги).
 */
function getDealsCountByExistingStages(
    mysqli $connection,
    string $periodStart,
    string $periodEnd,
    bool $excludeFinalStages = false,
    ?string $categoryFilter = null
): array {
    static $cache = [];
    $ck = $periodStart . '|' . $periodEnd . '|' . (int)$excludeFinalStages . '|' . ($categoryFilter ?? '');
    if (array_key_exists($ck, $cache)) {
        return $cache[$ck];
    }

    $empty = ['total' => 0, 'by_stage' => []];

    $ps = mysqli_real_escape_string($connection, $periodStart);
    $pe = mysqli_real_escape_string($connection, $periodEnd);

    $sql = "SELECT snapshot_data
            FROM quality_leads_snapshot_dev
            WHERE period_start = '{$ps}'
              AND period_end   = '{$pe}'
            LIMIT 1";

    $res = mysqli_query($connection, $sql);
    if (!$res) {
        logMessage("❌ getDealsCountByExistingStages SQL error: " . mysqli_error($connection));
        return $cache[$ck] = $empty;
    }

    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);

    if (!$row || empty($row['snapshot_data'])) {
        return $cache[$ck] = $empty;
    }

    $data = json_decode($row['snapshot_data'], true);
    if (!is_array($data)) {
        return $cache[$ck] = $empty;
    }

    $dealsByLead  = $data['dealsByLead']  ?? [];
    $dealStageMap = $data['dealStageMap'] ?? [];

    if (empty($dealsByLead) || empty($dealStageMap)) {
        return $cache[$ck] = $empty;
    }

    $counts    = [];
    $seenDeals = [];

    foreach ($dealsByLead as $deals) {
        if (!is_array($deals)) continue;
        foreach ($deals as $deal) {
            if (!is_array($deal)) continue;

            $dealId = $deal['ID'] ?? null;
            if (!$dealId || isset($seenDeals[$dealId])) continue;
            $seenDeals[$dealId] = true;

            $stageId = $deal['STAGE_ID'] ?? null;
            if (!$stageId || !isset($dealStageMap[$stageId])) continue;

            if ($excludeFinalStages) {
                if (stripos($stageId, 'WON')  !== false) continue;
                if (stripos($stageId, 'LOSE') !== false) continue;
            }
            if ($categoryFilter !== null && strpos($stageId, $categoryFilter) !== 0) {
                continue;
            }

            $counts[$stageId] = ($counts[$stageId] ?? 0) + 1;
        }
    }

    $total   = array_sum($counts);
    $byStage = [];
    foreach ($counts as $stageId => $cnt) {
        $byStage[$stageId] = [
            'name'  => $dealStageMap[$stageId] ?? $stageId,
            'count' => $cnt,
        ];
    }

    uasort($byStage, function ($a, $b) {
        return $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']);
    });

    return $cache[$ck] = ['total' => $total, 'by_stage' => $byStage];
}


/**
 * Количество качественных лидов (сделок) по стадиям 2 и 3 в разрезе менеджеров.
 * Стадии 2: C29:NEW, C29:PREPARATION, C29:PREPAYMENT_INVOIC
 * Стадии 3: C29:UC_UD1NMI, C29:UC_3OMQWF, C29:EXECUTING, C29:FINAL_INVOICE
 * Атрибуция — по ASSIGNED_BY_ID сделки. Дедупликация по ID сделки.
 */
function getDealsStagesByManagerCounts(mysqli $connection, string $periodStart, string $periodEnd): array
{
    static $cache = [];
    $ck = $periodStart . '|' . $periodEnd;
    if (array_key_exists($ck, $cache)) {
        return $cache[$ck];
    }

    $empty = ['MANAGERS' => []];

    $ps = mysqli_real_escape_string($connection, $periodStart);
    $pe = mysqli_real_escape_string($connection, $periodEnd);

    $sql = "SELECT snapshot_data
            FROM quality_leads_snapshot_dev
            WHERE period_start = '{$ps}'
              AND period_end   = '{$pe}'
            LIMIT 1";

    $res = mysqli_query($connection, $sql);
    if (!$res) {
        logMessage("❌ getDealsStagesByManagerCounts SQL error: " . mysqli_error($connection));
        return $cache[$ck] = $empty;
    }
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);

    if (!$row || empty($row['snapshot_data'])) {
        return $cache[$ck] = $empty;
    }

    $data = json_decode($row['snapshot_data'], true);
    if (!is_array($data)) {
        return $cache[$ck] = $empty;
    }

    $dealsByLead = $data['dealsByLead'] ?? [];
    if (empty($dealsByLead) || !is_array($dealsByLead)) {
        return $cache[$ck] = $empty;
    }

    $stage2Set = array_flip(['C29:NEW', 'C29:PREPARATION', 'C29:PREPAYMENT_INVOIC']);
    $stage3Set = array_flip(['C29:UC_UD1NMI', 'C29:UC_3OMQWF', 'C29:EXECUTING', 'C29:FINAL_INVOICE']);

    $seen   = [];
    $result = [];

    foreach ($dealsByLead as $deals) {
        if (!is_array($deals)) continue;
        foreach ($deals as $deal) {
            if (!is_array($deal)) continue;

            $dealId = (int)($deal['ID'] ?? 0);
            if ($dealId <= 0 || isset($seen[$dealId])) continue;
            $seen[$dealId] = true;

            $stageId = $deal['STAGE_ID'] ?? '';
            if ($stageId === '') continue;

            $mid = (int)($deal['ASSIGNED_BY_ID'] ?? 0);
            if ($mid <= 0) continue;

            $isStage2 = isset($stage2Set[$stageId]);
            $isStage3 = isset($stage3Set[$stageId]);
            if (!$isStage2 && !$isStage3) continue;

            if (!isset($result[$mid])) {
                $result[$mid] = ['STAGE2' => 0, 'STAGE3' => 0];
            }
            if ($isStage2) $result[$mid]['STAGE2']++;
            if ($isStage3) $result[$mid]['STAGE3']++;
        }
    }

    return $cache[$ck] = ['MANAGERS' => $result];
}



/**
 * Количество уникальных лидов, конвертированных в контакт/компанию,
 * в разрезе менеджеров (ASSIGNED_BY_ID лида, без сделок — как в getConvertedLeadsCountForPeriod).
 */
function getContactsByManagerCounts(mysqli $connection, string $periodStart, string $periodEnd): array
{
    static $cache = [];
    $ck = $periodStart . '|' . $periodEnd;
    if (array_key_exists($ck, $cache)) {
        return $cache[$ck];
    }

    $empty = ['MANAGERS' => []];

    $ps = mysqli_real_escape_string($connection, $periodStart);
    $pe = mysqli_real_escape_string($connection, $periodEnd);

    $sql = "SELECT snapshot_data
            FROM quality_leads_snapshot_dev
            WHERE period_start = '{$ps}'
              AND period_end   = '{$pe}'
            LIMIT 1";

    $res = mysqli_query($connection, $sql);
    if (!$res) {
        logMessage("❌ getContactsByManagerCounts SQL error: " . mysqli_error($connection));
        return $cache[$ck] = $empty;
    }
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);

    if (!$row || empty($row['snapshot_data'])) {
        return $cache[$ck] = $empty;
    }

    $data = json_decode($row['snapshot_data'], true);
    if (!is_array($data) || empty($data['leads']) || !is_array($data['leads'])) {
        return $cache[$ck] = $empty;
    }

    $dealsByLead = $data['dealsByLead'] ?? [];
    $result      = [];
    $seen        = []; // дедуп по ID лида

    foreach ($data['leads'] as $lead) {
        $leadId = (int)($lead['ID'] ?? 0);
        if ($leadId <= 0 || isset($seen[$leadId])) continue;
        $seen[$leadId] = true;

        // Как в getConvertedLeadsCountForPeriod — только лиды без сделок
        if (!empty($dealsByLead[$leadId])) continue;

        $contactId = (int)($lead['CONTACT_ID'] ?? 0);
        $companyId = (int)($lead['COMPANY_ID'] ?? 0);
        if ($contactId <= 0 && $companyId <= 0) continue;

        $mid = (int)($lead['ASSIGNED_BY_ID'] ?? 0);
        if ($mid <= 0) continue;

        $result[$mid] = ($result[$mid] ?? 0) + 1;
    }

    return $cache[$ck] = ['MANAGERS' => $result];
}

/**
 * Парсит уже отформатированную строку ("7 ч 30 мин", "25 ч 0 мин",
 * "3 дн. 1 ч. 0 мин.") обратно в минуты. 1 день = 8 ч.
 */
function parseFormattedTimeToMinutes($formatted): int
{
    if (!is_string($formatted) || $formatted === '') {
        return 0;
    }
    $minutes = 0;
    if (preg_match('/(\d+)\s*дн/u',  $formatted, $m)) $minutes += (int)$m[1] * 8 * 60;
    if (preg_match('/(\d+)\s*ч/u',   $formatted, $m)) $minutes += (int)$m[1] * 60;
    if (preg_match('/(\d+)\s*мин/u', $formatted, $m)) $minutes += (int)$m[1];
    return $minutes;
}
