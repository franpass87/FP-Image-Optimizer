/**
 * FP Image Optimizer — Admin JS
 */
(function () {
    'use strict';

    if (typeof fpImgOptConfig === 'undefined') {
        return;
    }

    const { ajaxUrl, nonce, i18n } = fpImgOptConfig;

    document.addEventListener('DOMContentLoaded', function () {
        const bulkBtn = document.getElementById('fpimgopt-bulk-start');
        const bulkStatus = document.getElementById('fpimgopt-bulk-status');
        if (!bulkBtn || !bulkStatus) {
            return;
        }

        bulkBtn.addEventListener('click', function () {
            if (bulkBtn.disabled) {
                return;
            }

            let offset = 0;
            const limit = 20;
            let totalProcessed = 0;
            let totalConverted = 0;
            let totalFailed = 0;

            bulkBtn.disabled = true;
            bulkStatus.textContent = i18n.bulkRunning || 'Ottimizzazione bulk in corso...';

            const step = function () {
                const body = new URLSearchParams();
                body.set('action', 'fp_imgopt_bulk_convert');
                body.set('nonce', nonce);
                body.set('offset', String(offset));
                body.set('limit', String(limit));

                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: body.toString()
                })
                    .then(function (response) {
                        return response.json();
                    })
                    .then(function (payload) {
                        if (!payload || !payload.success || !payload.data) {
                            throw new Error((payload && payload.data && payload.data.message) ? payload.data.message : (i18n.error || 'Errore'));
                        }

                        const data = payload.data;
                        totalProcessed += Number(data.processed || 0);
                        totalConverted += Number(data.converted || 0);
                        totalFailed += Number(data.failed || 0);
                        offset = Number(data.next || (offset + limit));

                        if (totalProcessed === 0 && !data.has_more) {
                            bulkStatus.textContent = i18n.bulkNone || 'Nessuna immagine da ottimizzare.';
                            bulkBtn.disabled = false;
                            return;
                        }

                        bulkStatus.textContent = (i18n.bulkRunning || 'Ottimizzazione bulk in corso...') +
                            ' ' + totalProcessed +
                            ' | Convertite: ' + totalConverted +
                            ' | Errori: ' + totalFailed;

                        if (data.has_more) {
                            window.setTimeout(step, 150);
                            return;
                        }

                        bulkStatus.textContent = (i18n.bulkDone || 'Ottimizzazione bulk completata.') +
                            ' Totale: ' + totalProcessed +
                            ' | Convertite: ' + totalConverted +
                            ' | Errori: ' + totalFailed;
                        bulkBtn.disabled = false;
                    })
                    .catch(function (err) {
                        bulkStatus.textContent = (i18n.error || 'Errore') + ': ' + (err && err.message ? err.message : 'unknown');
                        bulkBtn.disabled = false;
                    });
            };

            step();
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        const refreshBtn = document.getElementById('fpimgopt-stats-refresh');
        if (!refreshBtn) {
            return;
        }

        refreshBtn.addEventListener('click', function () {
            if (refreshBtn.disabled) {
                return;
            }
            const cfg = typeof fpImgOptConfig !== 'undefined' ? fpImgOptConfig : {};
            const ajaxUrl = cfg.ajaxUrl || '';
            const nonce = cfg.nonce || '';
            const i18n = cfg.i18n || {};

            refreshBtn.disabled = true;
            refreshBtn.querySelector('.dashicons')?.classList.add('is-loading');
            const origText = refreshBtn.innerHTML;
            refreshBtn.innerHTML = '<span class="dashicons dashicons-update is-loading"></span> ' + (i18n.statsLoading || 'Calcolo in corso...');

            const body = new URLSearchParams();
            body.set('action', 'fp_imgopt_stats');
            body.set('nonce', nonce);

            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            })
                .then(function (r) { return r.json(); })
                .then(function (payload) {
                    if (payload && payload.success && payload.data) {
                        const d = payload.data;
                        const el = function (id) { return document.getElementById(id); };
                        if (el('fpimgopt-stat-total')) el('fpimgopt-stat-total').textContent = String(d.total_images || 0);
                        if (el('fpimgopt-stat-converted')) el('fpimgopt-stat-converted').textContent = String(d.with_variants || 0);
                        if (el('fpimgopt-stat-webp')) el('fpimgopt-stat-webp').textContent = String(d.webp_count || 0);
                        if (el('fpimgopt-stat-avif')) el('fpimgopt-stat-avif').textContent = String(d.avif_count || 0);
                        if (el('fpimgopt-stat-saved')) el('fpimgopt-stat-saved').textContent = (d.saved_mb ?? 0) + ' MB';
                    }
                })
                .finally(function () {
                    refreshBtn.disabled = false;
                    refreshBtn.innerHTML = '<span class="dashicons dashicons-update"></span> ' + (i18n.statsRefresh || 'Aggiorna statistiche');
                });
        });
    });
})();
