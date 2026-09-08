/* =====================================================================
   AppToast - toast notification (Sonner-style)
   Dipakai semua halaman: AppToast.success/error/info/warning(title, desc?)
   ===================================================================== */
(function () {
    'use strict';

    var container = null;
    var ICONS = {
        success: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>',
        error: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
        info: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>',
        warning: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>'
    };
    var DURATION = 4500;

    function ensureContainer() {
        if (container) return container;
        container = document.createElement('div');
        container.className = 'app-toast-container';
        container.setAttribute('aria-live', 'polite');
        document.body.appendChild(container);
        return container;
    }

    function show(type, title, description) {
        var c = ensureContainer();

        // hilangkan toast lama yg sejenis biar tidak numpuk nggak perlu
        var existing = c.querySelectorAll('[data-type="' + type + '"]');
        if (existing.length >= 3) existing[0].remove();

        var el = document.createElement('div');
        el.className = 'app-toast toast-' + type;
        el.setAttribute('data-type', type);
        el.setAttribute('role', 'status');

        var iconHtml = ICONS[type] || ICONS.info;

        el.innerHTML =
            '<div class="toast-icon">' + iconHtml + '</div>' +
            '<div class="toast-content">' +
                '<div class="toast-title"></div>' +
                (description ? '<div class="toast-desc"></div>' : '') +
            '</div>' +
            '<button type="button" class="toast-close" aria-label="Tutup">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>' +
            '</button>';

        el.querySelector('.toast-title').textContent = title;
        if (description) el.querySelector('.toast-desc').textContent = description;

        c.appendChild(el);

        // animasi masuk
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                el.classList.add('in');
            });
        });

        var timer = setTimeout(function () { dismiss(el); }, DURATION);

        el.querySelector('.toast-close').addEventListener('click', function () {
            clearTimeout(timer);
            dismiss(el);
        });

        el.addEventListener('mouseenter', function () { clearTimeout(timer); });
        el.addEventListener('mouseleave', function () {
            timer = setTimeout(function () { dismiss(el); }, DURATION / 2);
        });
    }

    function dismiss(el) {
        if (!el || el.classList.contains('out')) return;
        el.classList.remove('in');
        el.classList.add('out');
        el.addEventListener('transitionend', function () {
            if (el.parentNode) el.parentNode.removeChild(el);
        });
        // fallback kalau transitionend tidak terpicu
        setTimeout(function () {
            if (el.parentNode) el.parentNode.removeChild(el);
        }, 500);
    }

    window.AppToast = {
        success: function (title, desc) { show('success', title || 'Berhasil', desc); },
        error: function (title, desc) { show('error', title || 'Gagal', desc); },
        info: function (title, desc) { show('info', title || 'Info', desc); },
        warning: function (title, desc) { show('warning', title || 'Peringatan', desc); }
    };
})();