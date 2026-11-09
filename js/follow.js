(function () {
    'use strict';
    if (window.__leadViewInit) return;
    window.__leadViewInit = true;

    var LEAD_ID = '';
    window.LEAD_ID = '';

    var LeadView = (function () {
        if (window.__leadView) return window.__leadView;

        var currentLeadId = null;
        var reportedOpen  = false; // подтверждён ли open на сервере (для решения, отправлять ли close)

        function isVisible() { return !document.hidden; }

        function deliver(payload, useBeacon) {
            if (useBeacon && navigator.sendBeacon) {
                try {
                    var blob = new Blob([payload], { type: 'application/json' });
                    if (navigator.sendBeacon('/local/summary_report/api/follow.php', blob)) return;
                } catch (e) { /* fallback ниже */ }
            }
            fetch('/local/summary_report/api/follow.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: payload,
                keepalive: true,
                credentials: 'same-origin'
            }).catch(function (e) { console.error('[lead-view] deliver:', e); });
        }

        function send(leadId, action) {
            if (!leadId) return;
            var useBeacon = (action === 'close') && !isVisible();
            console.log('[lead-view] →', action, leadId, isVisible() ? '(visible)' : '(hidden)', 'beacon=' + useBeacon);
            deliver(JSON.stringify({ leadId: leadId, action: action, ts: Date.now() }), useBeacon);
        }

        // Приводим серверное состояние к текущему.
        // При visible — всегда open (сервер отсечёт дубли через Lua: 'same').
        // При hidden — close только если open был подтверждён.
        function syncNow() {
            if (!currentLeadId) return;
            if (isVisible()) {
                reportedOpen = true;
                send(currentLeadId, 'open');
            } else {
                if (!reportedOpen) return;
                reportedOpen = false;
                send(currentLeadId, 'close');
            }
        }

        function setOpen(id) {
            if (!id) return;
            id = String(id);
            if (currentLeadId === id) { syncNow(); return; }

            // При смене лида старый закрываем сразу
            if (currentLeadId && reportedOpen) {
                reportedOpen = false;
                send(currentLeadId, 'close');
            }
            currentLeadId = id;
            LEAD_ID = id;
            window.LEAD_ID = id;

            // ВАЖНО: open отправляем безусловно, даже если вкладка скрыта.
            // Сервер (Lua) сам отсечёт дубли ('same') и не опубликует событие,
            // но при первом open после 'not-open'/'different-lead' — опубликует.
            reportedOpen = true;
            send(currentLeadId, 'open');
        }

        function setClosed() {
            if (!currentLeadId) return;
            if (reportedOpen) {
                reportedOpen = false;
                send(currentLeadId, 'close');
            }
            currentLeadId = null;
            LEAD_ID = '';
            window.LEAD_ID = '';
        }

        document.addEventListener('visibilitychange', syncNow);

        // pagehide: финальный close (не сбрасывая currentLeadId,
        // чтобы pageshow из bfcache смог снова открыть)
        window.addEventListener('pagehide', function () {
            if (reportedOpen && currentLeadId) {
                reportedOpen = false;
                send(currentLeadId, 'close');
            }
        });
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) syncNow();
        });

        window.__leadView = { setOpen: setOpen, setClosed: setClosed, syncNow: syncNow };
        return window.__leadView;
    })();

    // 1) Прямой заход на полную карточку лида
    (function () {
        var m = window.location.pathname.match(/^\/crm\/lead\/details\/(\d+)(?:[\/?#]|$)/);
        if (m) LeadView.setOpen(m[1]);
    })();

    // 2) Карточка лида в слайдере
    if (typeof BX !== 'undefined' && BX.SidePanel && BX.SidePanel.Instance) {
        function getLeadIdFromUrl(url) {
            if (!url) return null;
            var match = url.match(/\/crm\/lead\/details\/(\d+)(?:[\/?#]|$)/i);
            return match ? match[1] : null;
        }
        function getSlider(event) {
            if (!event) return null;
            return event.getSlider ? event.getSlider() : event.slider || null;
        }

        BX.addCustomEvent('SidePanel.Slider:onOpen', function (event) {
            try {
                var slider = getSlider(event); if (!slider) return;
                var url = slider.getUrl ? slider.getUrl() : slider.url;
                var leadId = getLeadIdFromUrl(url);
                if (leadId) LeadView.setOpen(leadId);
            } catch (e) { console.error('[lead-view] onOpen:', e); }
        });

        BX.addCustomEvent('SidePanel.Slider:onCloseComplete', function (event) {
            try {
                var slider = getSlider(event); if (!slider) return;
                var url = slider.getUrl ? slider.getUrl() : slider.url;
                if (getLeadIdFromUrl(url)) LeadView.setClosed();
            } catch (e) { console.error('[lead-view] onCloseComplete:', e); }
        });
    } else {
        console.warn('[lead-view] BX.SidePanel недоступен.');
    }
})();