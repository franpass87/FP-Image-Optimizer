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

            const onlyMissing = document.getElementById('fpimgopt-bulk-only-missing');
            const onlyMissingVal = onlyMissing && onlyMissing.checked ? '1' : '';

            const step = function () {
                const body = new URLSearchParams();
                body.set('action', 'fp_imgopt_bulk_convert');
                body.set('nonce', nonce);
                body.set('offset', String(offset));
                body.set('limit', String(limit));
                if (onlyMissingVal) body.set('only_missing', onlyMissingVal);

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
        const cfg = typeof fpImgOptConfig !== 'undefined' ? fpImgOptConfig : {};
        const ajaxUrl = cfg.ajaxUrl || '';
        const nonce = cfg.nonce || '';
        const i18n = cfg.i18n || {};

        const removeBtn = document.getElementById('fpimgopt-remove-variants');
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                if (!confirm(i18n.removeConfirm || 'Eliminare tutte le varianti?')) return;
                removeBtn.disabled = true;
                const body = new URLSearchParams();
                body.set('action', 'fp_imgopt_remove_variants');
                body.set('nonce', nonce);
                fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() })
                    .then(function (r) { return r.json(); })
                    .then(function (p) {
                        if (p && p.success) {
                            alert((i18n.removeSuccess || 'Varianti rimosse.') + ' ' + (p.data && p.data.deleted ? p.data.deleted + ' file eliminati.' : ''));
                            if (typeof location !== 'undefined') location.reload();
                        }
                    })
                    .finally(function () { removeBtn.disabled = false; });
            });
        }

        const clearLogBtn = document.getElementById('fpimgopt-clear-log');
        if (clearLogBtn) {
            clearLogBtn.addEventListener('click', function () {
                clearLogBtn.disabled = true;
                const body = new URLSearchParams();
                body.set('action', 'fp_imgopt_clear_log');
                body.set('nonce', nonce);
                fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() })
                    .then(function (r) { return r.json(); })
                    .then(function () { if (typeof location !== 'undefined') location.reload(); })
                    .finally(function () { clearLogBtn.disabled = false; });
            });
        }

        const bulkBgBtn = document.getElementById('fpimgopt-bulk-background');
        const bulkStatus = document.getElementById('fpimgopt-bulk-status');
        if (bulkBgBtn && bulkStatus) {
            bulkBgBtn.addEventListener('click', function () {
                if (bulkBgBtn.disabled) return;
                bulkBgBtn.disabled = true;
                const onlyMissing = document.getElementById('fpimgopt-bulk-only-missing');
                const body = new URLSearchParams();
                body.set('action', 'fp_imgopt_bulk_start_background');
                body.set('nonce', nonce);
                if (onlyMissing && onlyMissing.checked) body.set('only_missing', '1');
                fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() })
                    .then(function (r) { return r.json(); })
                    .then(function (p) {
                        if (p && p.success) {
                            bulkStatus.textContent = i18n.bulkBackgroundOk || 'Bulk avviato in background.';
                            bulkStatusPolling();
                        } else {
                            bulkStatus.textContent = (i18n.error || 'Errore') + ': ' + (p && p.data && p.data.message ? p.data.message : '');
                        }
                    })
                    .catch(function () { bulkStatus.textContent = (i18n.error || 'Errore') + ' di rete.'; })
                    .finally(function () { bulkBgBtn.disabled = false; });
            });
        }

        var bulkPollingTimer = null;
        function bulkStatusPolling() {
            const body = new URLSearchParams();
            body.set('action', 'fp_imgopt_bulk_state');
            body.set('nonce', nonce);
            fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() })
                .then(function (r) { return r.json(); })
                .then(function (p) {
                    const st = p && p.success ? p.data : null;
                    const el = document.getElementById('fpimgopt-bulk-status');
                    if (!el) return;
                    if (!st || st.offset === undefined) {
                        clearTimeout(bulkPollingTimer);
                        bulkPollingTimer = null;
                        if (!st) el.textContent = (i18n.bulkDone || 'Bulk completato.') + ' Ricarica per aggiornare.';
                        return;
                    }
                    el.textContent = (i18n.bulkRunning || 'Bulk in corso...') + ' Processate: ' + (st.processed || 0) + ' | Convertite: ' + (st.converted || 0) + ' | Errori: ' + (st.failed || 0);
                    bulkPollingTimer = setTimeout(bulkStatusPolling, 4000);
                });
        }

        (function () {
            const el = document.getElementById('fpimgopt-bulk-status');
            if (!el) return;
            const body = new URLSearchParams();
            body.set('action', 'fp_imgopt_bulk_state');
            body.set('nonce', nonce);
            fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() })
                .then(function (r) { return r.json(); })
                .then(function (p) {
                    const st = p && p.success ? p.data : null;
                    if (st && st.offset !== undefined) {
                        el.textContent = (i18n.bulkRunning || 'Bulk in corso...') + ' Processate: ' + (st.processed || 0) + ' | Convertite: ' + (st.converted || 0) + ' | Errori: ' + (st.failed || 0);
                        bulkPollingTimer = setTimeout(bulkStatusPolling, 4000);
                    }
                });
        })();

        const retryBtn = document.getElementById('fpimgopt-retry-failed');
        if (retryBtn) {
            retryBtn.addEventListener('click', function () {
                if (retryBtn.disabled) return;
                retryBtn.disabled = true;
                const body = new URLSearchParams();
                body.set('action', 'fp_imgopt_retry_failed');
                body.set('nonce', nonce);
                fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() })
                    .then(function (r) { return r.json(); })
                    .then(function (p) {
                        if (p && p.success && typeof location !== 'undefined') location.reload();
                    })
                    .finally(function () { retryBtn.disabled = false; });
            });
        }
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
                        if (el('fpimgopt-stat-total')) el('fpimgopt-stat-total').textContent = String(d.total_images || 0) + (d.capped ? ' *' : '');
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

    document.addEventListener('DOMContentLoaded', function () {
        const renameBtns = document.querySelectorAll('.fpimgopt-rename-post-btn');
        renameBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const postId = btn.getAttribute('data-post-id');
                const postTitle = btn.getAttribute('data-post-title') || '';
                if (!postId || !confirm((fpImgOptConfig.i18n && fpImgOptConfig.i18n.renameConfirm) || 'Rinominare le immagini?')) {
                    return;
                }
                btn.disabled = true;
                var origHtml = btn.innerHTML;
                btn.innerHTML = '<span class="dashicons dashicons-update is-loading"></span> ' + (fpImgOptConfig.i18n && fpImgOptConfig.i18n.converting ? fpImgOptConfig.i18n.converting : 'Elaborazione...');
                var body = new URLSearchParams();
                body.set('action', 'fp_imgopt_rename_post_images');
                body.set('nonce', fpImgOptConfig.nonce);
                body.set('post_id', postId);
                fetch(fpImgOptConfig.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString()
                })
                    .then(function (r) { return r.json(); })
                    .then(function (p) {
                        if (p && p.success && p.data) {
                            var msg = (fpImgOptConfig.i18n && fpImgOptConfig.i18n.renameSuccess) || 'Rinominate.';
                            if (p.data.renamed !== undefined) msg += ' ' + p.data.renamed + '/' + (p.data.total || 0);
                            if (p.data.errors && p.data.errors.length) msg += ' (' + p.data.errors.length + ' errori)';
                            alert(msg);
                            if (typeof location !== 'undefined') location.reload();
                        } else {
                            var errMsg = ((fpImgOptConfig.i18n && fpImgOptConfig.i18n.renameError) || 'Errore.') + ' ' + (p && p.data && p.data.message ? p.data.message : '');
                            alert(errMsg);
                            btn.disabled = false;
                            btn.innerHTML = origHtml;
                        }
                    })
                    .catch(function () {
                        alert((fpImgOptConfig.i18n && fpImgOptConfig.i18n.renameError) || 'Errore di rete.');
                        btn.disabled = false;
                        btn.innerHTML = origHtml;
                    });
            });
        });
    });
})();
