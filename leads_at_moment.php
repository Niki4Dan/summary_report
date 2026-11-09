<?php
/**
 * Отчет: на каком этапе сейчас работа с клиентом по качественному лиду.
 *
 * Схема вывода:
 *   LEAD #ID → DEAL #ID (стадия)   — если лид сконвертирован в сделку
 *   LEAD #ID → CONTACT #ID / COMPANY #ID — если сделка не создана
 *   LEAD #ID → (нет связей)        — если лид никуда не конвертирован
 *
 * Запуск: quality_leads_report.php?date_start=YYYY-MM-DD&date_end=YYYY-MM-DD
 *         quality_leads_report.php?...&csv=1 — выгрузка в CSV
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 600);
ini_set('memory_limit', '1024M');

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

/* ============================================================
 * 1. Определяем период отчета
 * ============================================================ */
if (isset($_GET['date_start']) && isset($_GET['date_end'])) {
    $startStr = date('Y-m-d 00:00:00', strtotime($_GET['date_start']));
    $endStr   = date('Y-m-d 23:59:59', strtotime($_GET['date_end']));
} else {
    $base = new DateTime();
    $base->modify('-1 weeks');
    $base->modify('previous saturday');
    $startStr = $base->format('Y-m-d 00:00:00');

    $baseEnd = new DateTime();
    $baseEnd->modify('previous friday');
    $endStr = $baseEnd->format('Y-m-d 23:59:59');
}

$isPdf = isset($_GET['pdf']) && $_GET['pdf'] == 1;
$isCsv = isset($_GET['csv']) && $_GET['csv'] == 1;


/* ============================================================
 * 1.1. Список периодов для меню
 * ============================================================ */
$availablePeriods = [];
$resPeriods = mysqli_query($connection,
    "SELECT period_start, period_end 
     FROM quality_leads_snapshot_dev 
     ORDER BY period_start DESC"
);
if ($resPeriods) {
    while ($r = mysqli_fetch_assoc($resPeriods)) {
        $availablePeriods[] = $r;
    }
    mysqli_free_result($resPeriods);
}


/* ============================================================
 * 2. Читаем снапшот из БД
 * ============================================================ */
$snapshot = null;
$stmt = mysqli_prepare($connection,
    "SELECT snapshot_data, leads_count, created_at 
     FROM quality_leads_snapshot_dev 
     WHERE period_start = ? AND period_end = ? 
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'ss', $startStr, $endStr);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$rowSnap = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

if ($rowSnap) {
    $snapshot = json_decode($rowSnap['snapshot_data'], true);
}

/* ============================================================
 * 3. Разворачиваем данные снапшота
 * ============================================================ */
if ($snapshot) {
    $leads        = $snapshot['leads']         ?? [];
    $dealsByLead  = $snapshot['dealsByLead']   ?? [];
    $contacts     = $snapshot['contacts']      ?? [];
    $companies    = $snapshot['companies']     ?? [];
    $dealStageMap = $snapshot['dealStageMap']  ?? [];
    $leadStatusMap= $snapshot['leadStatusMap'] ?? [];
    $managersMap  = $snapshot['managersMap']   ?? [];
    $capturedAt   = $snapshot['captured_at']   ?? $rowSnap['created_at'];
} else {
    $leads         = [];
    $dealsByLead   = [];
    $contacts      = [];
    $companies     = [];
    $dealStageMap  = [];
    $leadStatusMap = [];
    $managersMap   = [];
    $capturedAt    = null;
}

/* ============================================================
 * 4. Строим плоские строки отчёта (для CSV)
 * ============================================================ */
function buildReportRows($leads, $dealsByLead, $contacts, $companies, $dealStageMap, $leadStatusMap, $managersMap)
{
    $rows = [];
    foreach ($leads as $lead) {
        $leadId = $lead['ID'];
        $mgr = $managersMap[$lead['ASSIGNED_BY_ID']] ?? ('ID ' . $lead['ASSIGNED_BY_ID']);
        $leadStatusName = $leadStatusMap[$lead['STATUS_ID']] ?? $lead['STATUS_ID'];
        $deals = $dealsByLead[$leadId] ?? [];

        if (!empty($deals)) {
            foreach ($deals as $deal) {
                $stage = $dealStageMap[$deal['STAGE_ID']] ?? $deal['STAGE_ID'];
                $sem = 'work';
                if (stripos($deal['STAGE_ID'], 'WON') !== false) $sem = 'won';
                elseif (stripos($deal['STAGE_ID'], 'LOSE') !== false) $sem = 'lose';

                $amount = '';
                if (!empty($deal['OPPORTUNITY']) && $deal['OPPORTUNITY'] > 0) {
                    $amount = number_format((float)$deal['OPPORTUNITY'], 0, '.', ' ') . ' ' . ($deal['CURRENCY_ID'] ?? '');
                }
                $rows[] = [
                    'lead_id'     => $leadId,
                    'manager'     => $mgr,
                    'lead_status' => $leadStatusName,
                    'link_type'   => 'Сделка',
                    'link_id'     => $deal['ID'],
                    'link_name'   => 'Сделка #' . $deal['ID'],
                    'stage'       => $stage,
                    'stage_sem'   => $sem,
                    'amount'      => $amount,
                    'detail'      => 'Сделка #' . $deal['ID'] . ' — ' . $stage . ($amount ? ' — ' . $amount : ''),
                ];
            }
        } else {
            $hasContact = !empty($lead['CONTACT_ID']) && isset($contacts[$lead['CONTACT_ID']]);
            $hasCompany = !empty($lead['COMPANY_ID']) && isset($companies[$lead['COMPANY_ID']]);

            if ($hasContact) {
                $c = $contacts[$lead['CONTACT_ID']];
                $cName = trim(($c['LAST_NAME'] ?? '') . ' ' . ($c['NAME'] ?? '') . ' ' . ($c['SECOND_NAME'] ?? ''));
                $rows[] = [
                    'lead_id'     => $leadId,
                    'manager'     => $mgr,
                    'lead_status' => $leadStatusName,
                    'link_type'   => 'Контакт',
                    'link_id'     => $c['ID'],
                    'link_name'   => 'Контакт #' . $c['ID'] . ' ' . $cName,
                    'stage'       => '',
                    'stage_sem'   => '',
                    'amount'      => '',
                    'detail'      => 'Контакт #' . $c['ID'] . ' ' . $cName,
                ];
            }
            if ($hasCompany) {
                $co = $companies[$lead['COMPANY_ID']];
                $rows[] = [
                    'lead_id'     => $leadId,
                    'manager'     => $mgr,
                    'lead_status' => $leadStatusName,
                    'link_type'   => 'Компания',
                    'link_id'     => $co['ID'],
                    'link_name'   => 'Компания #' . $co['ID'] . ' ' . $co['TITLE'],
                    'stage'       => '',
                    'stage_sem'   => '',
                    'amount'      => '',
                    'detail'      => 'Компания #' . $co['ID'] . ' ' . $co['TITLE'],
                ];
            }
            if (!$hasContact && !$hasCompany) {
                $rows[] = [
                    'lead_id'     => $leadId,
                    'manager'     => $mgr,
                    'lead_status' => $leadStatusName,
                    'link_type'   => 'Нет связей',
                    'link_id'     => '',
                    'link_name'   => '',
                    'stage'       => '',
                    'stage_sem'   => '',
                    'amount'      => '',
                    'detail'      => 'Лид не конвертирован в сделку, контакт/компания отсутствуют',
                ];
            }
        }
    }
    return $rows;
}

$reportRows = buildReportRows(
    $leads, $dealsByLead, $contacts, $companies,
    $dealStageMap, $leadStatusMap, $managersMap
);


/* ============================================================
 * 5. Выгрузка CSV
 * ============================================================ */
if ($isCsv) {
    $filename = 'quality_leads_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');

    // BOM для корректного открытия в Excel
    fwrite($out, "\xEF\xBB\xBF");

    // Разделитель ";" — привычнее для Excel в РФ
    $delimiter = ';';

    fputcsv($out, [
        'Лид',
        'Ответственный',
        'Статус лида',
        'Тип связи',
        'Этап (стадия)',
    ], $delimiter);

    foreach ($reportRows as $r) {
        fputcsv($out, [
            '#' . $r['lead_id'],
            $r['manager'],
            $r['lead_status'],
            $r['link_type'],
            $r['stage'],
        ], $delimiter);
    }

    fclose($out);
    mysqli_close($connection);
    exit;
}

/* ============================================================
 * 7. HTML-вывод
 * ============================================================ */
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Этапы работы с качественными лидами</title>
<style>
    body { font-family: Arial, sans-serif; padding: 20px; background:#fafafa; }
    h1 { font-size: 20px; text-align: center; }
    .subtitle { text-align:center; color:#666; font-size:12px; margin-bottom:20px; }
    .btn-pdf, .btn-csv { display:inline-block; margin:0 5px 20px; padding:10px 24px;
               color:#fff; border:none; border-radius:5px; cursor:pointer; font-size:14px; text-decoration:none; }
    .btn-pdf { background:#4CAF50; }
    .btn-pdf:hover { background:#45a049; }
    .btn-csv { background:#2980b9; }
    .btn-csv:hover { background:#2471a3; }
    .btn-wrap { text-align:center; }

    table.report { width:100%; border-collapse: collapse; background:#fff; font-size: 11px; }
    table.report th, table.report td {
        border: 1px solid #ccc; padding: 6px 8px; vertical-align: top;
    }
    table.report th { background:#f0f0f0; font-weight:bold; text-align:center; }

    .lead-cell    { background:#eaf6ff; }
    .deal-cell    { background:#e8fbe8; }
    .contact-cell { background:#fff7e6; }
    .company-cell { background:#f3e8ff; }

    .arrow { color:#888; font-weight:bold; text-align:center; white-space:nowrap; }

    .stage-badge {
        display:inline-block; padding:2px 8px; border-radius:10px;
        background:#4CAF50; color:#fff; font-size:10px; font-weight:bold;
    }
    .stage-badge.won { background:#1b8a2a; }
    .stage-badge.lose { background:#c0392b; }
    .stage-badge.work { background:#e67e22; }

    .muted { color:#888; font-style:italic; }

    .status-lead { display:inline-block; padding:1px 6px; background:#dfe6e9;
                   border-radius:8px; font-size:10px; color:#2d3436; }

    .no-deal { background:#fff3f3; }


    /* ===== САЙДБАР С ДАТАМИ ===== */
.sitebar-menu {
    position: fixed;
    top: 0;
    left: 0;
    width: 220px;
    max-height: 100vh;
    overflow-y: auto;
    background: #ffffff;
    border-right: 1px solid #009355;
    box-shadow: 4px 0 20px rgba(0, 0, 0, 0.15);
    padding: 15px 10px;
    z-index: 1000;
    transform: translateX(-110%);
    transition: transform 0.3s ease;
}
.sitebar-menu.open { transform: translateX(0); }

.sitebar-menu::-webkit-scrollbar { width: 3px; }
.sitebar-menu::-webkit-scrollbar-track { background: transparent; }
.sitebar-menu::-webkit-scrollbar-thumb { background: #009355; border-radius: 10px; }
.sitebar-menu { scrollbar-width: thin; scrollbar-color: #009355 transparent; }

.date-menu { list-style: none; padding: 0; margin: 0; }
.date-menu-item {
    padding: 8px 10px;
    border-bottom: 1px solid #009355;
    background-color: #82ffcb;
    cursor: pointer;
    font-size: 12px;
    transition: background 0.15s;
}
.date-menu-item:hover { background-color: #6beeb9; }
.date-menu-item.active { background-color: #009355; color: #fff; }

/* ===== БУРГЕР-КНОПКА ===== */
.burger-btn {
    position: fixed;
    left: 20px;
    top: 20px;
    width: 32px;
    height: 24px;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    z-index: 1001;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.5s ease;
}
.burger-btn.active { transform: translateX(230px); }

@keyframes pulse-attention {
    0%   { box-shadow: 0 0 0 0 rgba(0, 147, 85, 0.7); }
    70%  { box-shadow: 0 0 0 15px rgba(0, 147, 85, 0); }
    100% { box-shadow: 0 0 0 0 rgba(0, 147, 85, 0); }
}
.burger-btn.pulse { animation: pulse-attention 0.8s ease-out; border-radius: 50%; }

/* Затемняющий оверлей */
.menu-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 999;
}
.menu-overlay.active { display: block; }

/* Сдвигаем контент, когда меню закрыто — чтобы бургер не наложился на контент */
body { padding-left: 70px; }
@media (max-width: 768px) { body { padding-left: 60px; } }

/* Чтобы кнопки PDF/CSV не перекрывались бургером */
.btn-wrap { margin-top: 10px; }


@media print {
    .sitebar-menu, .burger-btn, .menu-overlay, .btn-wrap { display: none !important; }
    body { padding-left: 0 !important; }
}
</style>
</head>
<body>

<!-- Бургер-кнопка -->
<button class="burger-btn" id="burgerToggle" aria-label="Открыть меню">
    <h3 style="margin:0;color:#009355;">📅</h3>
</button>

<!-- Оверлей -->
<div class="menu-overlay" id="menuOverlay"></div>

<!-- Сайдбар с датами -->
<div class="sitebar-menu" id="dateSidebar">
    <h3 style="margin:0 0 12px; font-size:14px; color:#009355; text-align:center;">
        Отчётные недели
    </h3>
    <ul class="date-menu">
        <?php foreach ($availablePeriods as $p):
            $pStart = $p['period_start'];
            $pEnd   = $p['period_end'];

            // Отображаем так же, как в createPdf.php: +2 дня к началу
            $dispStart = new DateTime($pStart);
            $dispStart->modify('+2 days');

            // Активность
            $isActive = ($pStart === $startStr && $pEnd === $endStr);
        ?>
            <li class="date-menu-item <?= $isActive ? 'active' : '' ?>"
                onclick="window.location.href='?date_start=<?= urlencode($pStart) ?>&date_end=<?= urlencode($pEnd) ?>'">
                <?= $dispStart->format('d.m') ?> — <?= (new DateTime($pEnd))->format('d.m.Y') ?>
            </li>
        <?php endforeach; ?>

        <?php if (empty($availablePeriods)): ?>
            <li class="date-menu-item" style="cursor:default; background:#f5f5f5; color:#888;">
                Нет доступных снапшотов
            </li>
        <?php endif; ?>
    </ul>
</div>

<h1>Этапы работы с качественными лидами</h1>
<div class="subtitle">
    Период: <b><?= htmlspecialchars($startStr) ?></b> — <b><?= htmlspecialchars($endStr) ?></b><br>
    Найдено качественных лидов: <b><?= count($leads) ?></b>
</div>

<?php if (!$isPdf): ?>
    <div class="btn-wrap">
        <button class="btn-pdf" onclick="window.print()">📄 Сохранить в PDF</button>
        <a class="btn-csv"
           href="?<?= http_build_query(array_merge($_GET, ['csv' => 1])) ?>">
            📊 Скачать CSV
        </a>
    </div>
<?php endif; ?>

<table class="report">
    <thead>
        <tr>
            <th style="width:60px;">Лид</th>
            <th style="width:180px;">Ответственный</th>
            <th style="width:120px;">Статус лида</th>
            <th style="width:60px;">→</th>
            <th>Текущий этап (сделка / контакт / компания)</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($leads as $lead):
        $leadId = $lead['ID'];
        $mgr = $managersMap[$lead['ASSIGNED_BY_ID']] ?? ('ID ' . $lead['ASSIGNED_BY_ID']);
        $leadStatusName = $leadStatusMap[$lead['STATUS_ID']] ?? $lead['STATUS_ID'];
        $deals = $dealsByLead[$leadId] ?? [];
    ?>
        <tr>
            <td class="lead-cell">
                <b>#<?= $leadId ?></b><br>
                <a href="https://b24.flotenk.ru/crm/lead/details/<?= $leadId ?>/"
                   target="_blank" style="font-size:10px;color:#555;">открыть</a>
            </td>
            <td><?= htmlspecialchars($mgr) ?></td>
            <td>
                <span class="status-lead"><?= htmlspecialchars($leadStatusName) ?></span>
            </td>

            <?php if (!empty($deals)): ?>
                <td class="arrow">→</td>
                <td class="deal-cell">
                    <?php foreach ($deals as $deal):
                        $stage = $dealStageMap[$deal['STAGE_ID']] ?? $deal['STAGE_ID'];
                        if (stripos($deal['STAGE_ID'], 'WON') !== false) $sem = 'won';
                        elseif (stripos($deal['STAGE_ID'], 'LOSE') !== false) $sem = 'lose';
                        else $sem = 'work';
                    ?>
                        <div style="margin-bottom:4px;">
                            <b>Сделка #<?= $deal['ID'] ?></b>
                            <span class="stage-badge <?= $sem ?>"><?= htmlspecialchars($stage) ?></span>
                            <?php if (!empty($deal['OPPORTUNITY']) && $deal['OPPORTUNITY'] > 0): ?>
                                <span style="color:#555;font-size:10px;">
                                    — <?= number_format((float)$deal['OPPORTUNITY'], 0, '.', ' ') ?>
                                    <?= htmlspecialchars($deal['CURRENCY_ID'] ?? '') ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </td>
            <?php else:
                $hasContact = !empty($lead['CONTACT_ID']) && isset($contacts[$lead['CONTACT_ID']]);
                $hasCompany = !empty($lead['COMPANY_ID']) && isset($companies[$lead['COMPANY_ID']]);
            ?>
                <td class="arrow">→</td>
                <td class="no-deal">
                    <?php if ($hasContact):
                        $c = $contacts[$lead['CONTACT_ID']];
                        $cName = trim(($c['LAST_NAME'] ?? '') . ' ' . ($c['NAME'] ?? '') . ' ' . ($c['SECOND_NAME'] ?? ''));
                    ?>
                        <div class="contact-cell" style="padding:4px 6px;border-radius:4px;margin-bottom:4px;">
                            <b>Контакт #<?= $c['ID'] ?></b>
                            <?= htmlspecialchars($cName) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($hasCompany):
                        $co = $companies[$lead['COMPANY_ID']];
                    ?>
                        <div class="company-cell" style="padding:4px 6px;border-radius:4px;">
                            <b>Компания #<?= $co['ID'] ?></b>
                            <?= htmlspecialchars($co['TITLE']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$hasContact && !$hasCompany): ?>
                        <span class="muted">Лид не конвертирован в сделку, контакт/компания отсутствуют</span>
                    <?php endif; ?>
                </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>

    <?php if (empty($leads)): ?>
        <tr><td colspan="5" style="text-align:center;padding:20px;" class="muted">
            За выбранный период качественных лидов не найдено.
        </td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php if ($isPdf): ?>
<style>
    body { background:#fff; padding:0; }
    .btn-pdf, .btn-csv, .btn-wrap { display:none; }
    table.report { font-size:10px; }
</style>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const burgerBtn = document.getElementById('burgerToggle');
    const sidebar   = document.getElementById('dateSidebar');
    const overlay   = document.getElementById('menuOverlay');

    if (!burgerBtn || !sidebar || !overlay) return;

    function toggleMenu() {
        burgerBtn.classList.toggle('active');
        sidebar.classList.toggle('open');
        overlay.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    }

    burgerBtn.addEventListener('click', toggleMenu);
    overlay.addEventListener('click', toggleMenu);

    // Закрываем меню по клику на элемент списка (после перехода)
    sidebar.querySelectorAll('.date-menu-item').forEach(item => {
        item.addEventListener('click', function () {
            setTimeout(() => {
                if (sidebar.classList.contains('open')) toggleMenu();
            }, 200);
        });
    });

    // Пульсация для привлечения внимания
    function pulsateButton() {
        if (!burgerBtn.classList.contains('open')) {
            burgerBtn.classList.remove('pulse');
            void burgerBtn.offsetWidth; // перезапуск анимации
            burgerBtn.classList.add('pulse');
            setTimeout(() => burgerBtn.classList.remove('pulse'), 800);
        }
    }
    setTimeout(pulsateButton, 3000);
    setInterval(pulsateButton, 7000);

    // Если пользователь открыл страницу не через меню, а по URL без параметров —
    // ничего не подставляем, просто показываем активный период, если он есть.
});
</script>

</body>
</html>

<?php mysqli_close($connection); ?>