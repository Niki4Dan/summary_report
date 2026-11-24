#!/usr/bin/env php
<?php
/**
 * Быстрый скрипт для сохранения страницы как PDF и отправки по email
 * Запуск: php test.php
 *
 * Настройки:
 * - debug = true - включить логирование в файл
 * - получатели и настройки SMTP в секции конфигурации ниже
 */

// ==================== КОНФИГУРАЦИЯ ====================

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

setEnvData();

define('USERNAME_SMTP', $_ENV['USERNAME_SMTP']);
define('PASSWORD_SMTP', $_ENV['PASSWORD_SMTP']);
define('USERNAME_B24',  $_ENV['USERNAME_B24'] ?? '');
define('PASSWORD_B24',  $_ENV['PASSWORD_B24'] ?? '');

putenv('PATH=/usr/local/bin:/usr/bin:/bin:/home/bitrix/.nvm/versions/node/v18.19.0/bin:' . getenv('PATH'));

$config = [
    'debug' => true,  // true - логировать в файл, false - только вывод в консоль

    // SMTP настройки
    'smtp' => [
        'host' => 'smtp.yandex.ru',
        'port' => 465,
        'secure' => 'ssl', // tls, ssl, или false
        'auth' => true,
        'username' => USERNAME_SMTP,
        'password' => PASSWORD_SMTP,
        'from' => USERNAME_SMTP,
        'from_name' => 'IT-отдел'
    ],

    // Список получателей
    'recipients' => [
        'nikiforov_d@flotenk.ru',
        'ydaniil256@yandex.ru',
        /*         'borovikov@flotenk.ru',
        'yefimova@flotenk.ru' */
    ],

    // Настройки отчета
    'report' => [
        'subject' => 'Еженедельный отчет',
        'body' => "Здравствуйте!\n\nВо вложении находится PDF-отчет.\n\nС уважением,\nIT-отдел АО \"Флотенк\"",
        'filename' => 'report_' . date('Y-m-d') . '.pdf'
    ]
];

// ==================== НАЧАЛО СКРИПТА ====================

$url = 'http://b24.flotenk.ru/local/summary_report/createPdf.php?pdf=1&script=1';
$scriptDir = __DIR__;
$outputFile = $scriptDir . '/' . $config['report']['filename'];
$logFile = $scriptDir . '/report_generator.log';

function logMessage($message, $level = 'INFO')
{
    global $config, $logFile;

    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[$timestamp] [$level] $message\n";

    // Всегда выводим в консоль
    echo $logLine;

    // Если debug включен - пишем в файл
    if ($config['debug']) {
        file_put_contents($logFile, $logLine, FILE_APPEND);
    }
}

logMessage("🚀 Запуск генерации PDF", "START");
logMessage("📡 URL: $url");

// Проверяем наличие puppeteer
exec('npm list puppeteer --depth=0 2>&1', $npmOutput, $npmExitCode);
if ($npmExitCode !== 0) {
    logMessage("❌ Puppeteer не найден. Установка...", "ERROR");
    logMessage("Выполните: npm install puppeteer", "ERROR");
    exit(1);
}
logMessage("✅ Puppeteer найден");

// Проверяем наличие nodemailer
exec('npm list nodemailer --depth=0 2>&1', $npmOutput2, $npmExitCode2);
$hasNodemailer = ($npmExitCode2 === 0);
if (!$hasNodemailer) {
    logMessage("⚠️ Nodemailer не найден. Установка...", "WARNING");
    logMessage("Выполните: npm install nodemailer", "WARNING");
}

// Создаем PHP-скрипт для формирования JS
$jsScript = <<<'JS'
const puppeteer = require('puppeteer');
const fs = require('fs');

const url        = process.argv[2];
const outputFile = process.argv[3];
const login      = process.env.USERNAME_B24 || '';
const password   = process.env.PASSWORD_B24 || '';
const baseUrl    = new URL(url).origin;

console.log('🔄 Запуск браузера...');

(async () => {
    let browser;
    try {
        browser = await puppeteer.launch({
            headless: 'new',
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu'
            ]
        });

        const page = await browser.newPage();
        await page.setViewport({ width: 1200, height: 800, deviceScaleFactor: 1 });

        // ==================== АВТОРИЗАЦИЯ ====================
        if (login && password) {
            console.log('🔐 Авторизация в Bitrix...');

            await page.goto(baseUrl + '/auth/?login=yes', {
                waitUntil: 'networkidle2',
                timeout: 30000
            });

            await page.waitForSelector('input[name="USER_LOGIN"]', { timeout: 10000 });
            await page.type('input[name="USER_LOGIN"]', login, { delay: 30 });
            await page.type('input[name="USER_PASSWORD"]', password, { delay: 30 });

            const rememberBox = await page.$('input[name="USER_REMEMBER"]');
            if (rememberBox) {
                await rememberBox.click();
            }

            // Универсальный селектор: input/button, а также по name="Login"
            const submitSelector = 'input[type="submit"], button[type="submit"], input[name="Login"], button[name="Login"]';

            let submitted = false;
            try {
                await page.waitForSelector(submitSelector, { timeout: 5000 });
                await Promise.all([
                    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }),
                    page.click(submitSelector)
                ]);
                submitted = true;
            } catch (e) {
                console.log('⚠️ Кнопка submit не найдена, пробуем form.submit()');
            }

            if (!submitted) {
                await Promise.all([
                    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }),
                    page.evaluate(() => {
                        const form = document.querySelector('form[name="system_auth_form"]')
                                  || document.querySelector('form[action*="auth"]')
                                  || document.querySelector('form');
                        if (!form) {
                            throw new Error('Форма авторизации не найдена на странице');
                        }
                        form.submit();
                    })
                ]);
            }

            // Проверка, что мы не остались на форме логина
            const stillLogin = await page.evaluate(() =>
                !!document.querySelector('input[name="USER_LOGIN"]')
            );
            if (stillLogin) {
                const errText = await page.evaluate(() => {
                    const el = document.querySelector('.errortext, .bx-auth-error, [class*="error"]');
                    return el ? el.innerText.trim() : '';
                });
                throw new Error('Авторизация не удалась' + (errText ? ': ' + errText : ''));
            }
            console.log('✅ Авторизация успешна');
        } else {
            console.log('⚠️ Креды B24 не заданы (USERNAME_B24 / PASSWORD_B24), идём анонимно');
        }

        // ==================== ЦЕЛЕВАЯ СТРАНИЦА ====================
        console.log('📄 Загрузка страницы...');
        await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
        console.log('✅ Страница загружена');
        console.log('⏳ Ожидание графиков...');

        await new Promise(resolve => setTimeout(resolve, 5000));

        const chartsInfo = await page.evaluate(() => {
            const canvases = document.querySelectorAll('canvas');
            let ready = 0;
            const total = canvases.length;
            canvases.forEach(canvas => {
                try {
                    const dataUrl = canvas.toDataURL();
                    if (dataUrl !== 'data:,' && dataUrl.length > 1000) {
                        ready++;
                    }
                } catch (e) {}
            });
            return { total, ready, chartsReady: window.readyChartsCount || 0 };
        });

        console.log(`📊 Графики: ${chartsInfo.ready}/${chartsInfo.total} готово, readyChartsCount=${chartsInfo.chartsReady}`);

        if (chartsInfo.ready < chartsInfo.total) {
            console.log('⏳ Дополнительное ожидание...');
            await new Promise(resolve => setTimeout(resolve, 3000));
        }

        console.log('📄 Генерация PDF...');
        await page.pdf({
            path: outputFile,
            format: 'A4',
            printBackground: true,
            margin: {
                top: '10mm',
                bottom: '10mm',
                left: '10mm',
                right: '10mm'
            },
            scale: 0.8
        });

        const stats = fs.statSync(outputFile);
        console.log(`✅ PDF сохранен: ${outputFile} (${Math.round(stats.size/1024)}KB)`);

        await browser.close();
        console.log('✅ Готово!');
        process.exit(0);

    } catch (error) {
        console.error(`❌ Ошибка: ${error.message}`);
        if (browser) {
            await browser.close();
        }
        process.exit(1);
    }
})();
JS;

// Сохраняем скрипт
$tempScript = $scriptDir . '/temp_puppeteer.js';
file_put_contents($tempScript, $jsScript);

logMessage("🔄 Запуск puppeteer...");

// Запускаем (креды B24 — через env, чтобы не светить пароль в ps aux)
$command = "USERNAME_B24=" . escapeshellarg(USERNAME_B24)
    . " PASSWORD_B24=" . escapeshellarg(PASSWORD_B24)
    . " node " . escapeshellarg($tempScript)
    . " " . escapeshellarg($url)
    . " " . escapeshellarg($outputFile);

exec($command . " 2>&1", $output, $exitCode);

// Выводим результат
foreach ($output as $line) {
    echo $line . "\n";
    if ($config['debug']) {
        file_put_contents($logFile, $line . "\n", FILE_APPEND);
    }
}

// Удаляем временные файлы
unlink($tempScript);

if ($exitCode === 0 && file_exists($outputFile) && filesize($outputFile) > 0) {
    $size = filesize($outputFile);
    logMessage("✅ PDF файл создан: $outputFile", "SUCCESS");
    logMessage("📄 Размер: $size байт (" . round($size / 1024, 2) . "KB)", "SUCCESS");

    // ==================== ОТПРАВКА ПО EMAIL ====================
    if ($hasNodemailer) {
        logMessage("📧 Отправка отчета по email...");

        // Экранируем данные для JSON
        $smtpConfig = [
            'host' => $config['smtp']['host'],
            'port' => $config['smtp']['port'],
            'secure' => $config['smtp']['secure'] === 'tls' || $config['smtp']['secure'] === 'ssl',
            'username' => $config['smtp']['username'],
            'password' => $config['smtp']['password']
        ];

        $recipientsJson = json_encode($config['recipients']);
        $smtpJson = json_encode($smtpConfig);
        $subjectJson = json_encode($config['report']['subject']);
        $bodyJson = json_encode($config['report']['body']);
        $filenameJson = json_encode($config['report']['filename']);
        $fromJson = json_encode($config['smtp']['from']);
        $fromNameJson = json_encode($config['smtp']['from_name']);
        $filePathJson = json_encode($outputFile);

        // Создаем email скрипт
        $emailScript = <<<JS
const nodemailer = require('nodemailer');
const fs = require('fs');

const config = {
    smtp: {$smtpJson},
    from: {$fromJson},
    fromName: {$fromNameJson},
    recipients: {$recipientsJson},
    subject: {$subjectJson},
    body: {$bodyJson},
    filePath: {$filePathJson},
    filename: {$filenameJson}
};

async function sendEmail() {
    console.log('📧 Настройка SMTP...');

    try {
        const transporter = nodemailer.createTransport({
            host: config.smtp.host,
            port: config.smtp.port,
            secure: config.smtp.secure,
            auth: {
                user: config.smtp.username,
                pass: config.smtp.password
            },
            tls: {
                rejectUnauthorized: false
            }
        });

        console.log('✅ SMTP настроен');

        await transporter.verify();
        console.log('✅ SMTP подключение успешно');

        const pdfBuffer = fs.readFileSync(config.filePath);
        console.log(`📄 Файл прочитан: \${pdfBuffer.length} байт`);

        const mailOptions = {
            from: `"\${config.fromName}" <\${config.from}>`,
            to: config.recipients.join(', '),
            subject: config.subject,
            text: config.body,
            attachments: [
                {
                    filename: config.filename,
                    content: pdfBuffer,
                    contentType: 'application/pdf'
                }
            ]
        };

        console.log(`📨 Отправка письма получателям: \${config.recipients.join(', ')}`);

        const info = await transporter.sendMail(mailOptions);

        console.log(`✅ Письмо отправлено!`);
        console.log(`📨 ID: \${info.messageId}`);
        console.log(`✅ Готово!`);

        process.exit(0);

    } catch (error) {
        console.error(`❌ Ошибка отправки: \${error.message}`);
        if (error.stack) {
            console.error(error.stack);
        }
        process.exit(1);
    }
}

sendEmail();
JS;

        // Сохраняем email скрипт
        $tempEmailScript = $scriptDir . '/temp_email.js';
        file_put_contents($tempEmailScript, $emailScript);

        logMessage("🔄 Запуск отправки email...");

        // Запускаем отправку
        $emailCommand = "node " . escapeshellarg($tempEmailScript) . " 2>&1";
        exec($emailCommand, $emailOutput, $emailExitCode);

        // Выводим результат отправки
        foreach ($emailOutput as $line) {
            echo $line . "\n";
            if ($config['debug']) {
                file_put_contents($logFile, $line . "\n", FILE_APPEND);
            }
        }

        // Удаляем временный скрипт
        if (file_exists($tempEmailScript)) {
            unlink($tempEmailScript);
        }

        if ($emailExitCode === 0) {
            logMessage("✅ Отчет успешно отправлен по email", "SUCCESS");
        } else {
            logMessage("❌ Ошибка при отправке email", "ERROR");
        }
    } else {
        logMessage("⚠️ Nodemailer не установлен. Пропускаем отправку email.", "WARNING");
        logMessage("Установите: npm install nodemailer", "WARNING");
    }

    logMessage("✅ Скрипт завершен успешно", "SUCCESS");
    exit(0);
} else {
    logMessage("❌ Не удалось создать PDF", "ERROR");
    logMessage("Проверьте доступность URL: $url", "ERROR");
    exit(1);
}
?>