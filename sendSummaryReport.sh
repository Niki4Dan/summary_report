#!/bin/bash

# Устанавливаем переменные окружения для cron
export PATH=/usr/bin/npm:$PATH
export NODE_PATH=/home/bitrix/www/local/summary_report_dev/node_modules:/home/bitrix/www/node_modules:/home/bitrix/node_modules
export HOME=/home/bitrix
export USER=bitrix

# Переходим в директорию скрипта
cd /var/www/html/local/summary_report/

# Логируем окружение
echo "=== CRON JOB STARTED $(date) ==="
echo "PATH: $PATH"
echo "NODE_PATH: $NODE_PATH"
echo "PWD: $(pwd)"

# Проверяем наличие node
which node
node --version

# Проверяем наличие npm
which npm
npm --version

# Проверяем наличие puppeteer
npm list puppeteer --depth=0

# Запускаем PHP скрипт
/usr/bin/php /var/www/html/local/summary_report/sendSummaryReport.php

echo "=== CRON JOB FINISHED $(date) ==="