/* UpdateGuard — confirmations, POST links of the "⋮" menus, progress of queued operations. No dependency but jQuery (FreeScout). */
(function ($) {
    'use strict';

    // forms and links with data-ug-confirm: native confirmation (works the same on phones)
    $(document).on('submit', 'form[data-ug-confirm]', function (e) {
        if (!window.confirm($(this).attr('data-ug-confirm'))) {
            e.preventDefault();
        }
    });

    // menu entries that must POST (CSRF form of the page)
    $(document).on('click', 'a[data-ug-post]', function (e) {
        e.preventDefault();
        var message = $(this).attr('data-ug-confirm');
        if (message && !window.confirm(message)) {
            return;
        }
        var form = $('#ug-post-form');
        form.attr('action', $(this).attr('data-ug-post'));
        form.trigger('submit');
    });

    /*
     * The page was rendered before the operation ended: versions, states and buttons are those of before. Once the
     * operation is finished the page is reloaded (once per operation) so that it shows the new state without a
     * manual refresh.
     */
    function reloadOnce(step) {
        var key = 'ug-reloaded-' + step;
        try {
            if (window.sessionStorage.getItem(key)) {
                return;
            }
            window.sessionStorage.setItem(key, '1');
        } catch (e) {
            // storage unavailable: a finished operation has no progress box after the reload (no loop); an observed
            // one still has one, so no automatic reload for it
            if (/-observing$/.test(step)) {
                return;
            }
        }
        setTimeout(function () { window.location.reload(); }, 1500);
    }

    // progress: polls the job every 3 s until it is finished, then shows the report link and refreshes the page
    function poll($box) {
        var url = $box.attr('data-ug-job');
        $.getJSON(url).done(function (job) {
            $box.find('.ug-progress-label').text((job.finished ? '' : '● ') + job.label + ' — ' + job.update_id);
            if (job.message) {
                $box.find('.ug-progress-message').text(job.message);
            }
            if (job.decision) {
                // paused by the Security Gate: the administrator decides on the security summary
                var $link = $box.find('.ug-progress-report');
                $link.attr('href', job.decision).text($box.attr('data-ug-decision-label') || job.label).removeClass('hidden').addClass('btn btn-warning btn-sm');
                if ($box.attr('data-ug-follow') === '1') {
                    window.location.href = job.decision;
                }
                return;
            }
            var width = Math.max(2, job.progress) + '%';
            $box.find('.progress-bar').css('width', width).attr('aria-valuenow', job.progress);
            if (job.finished) {
                $box.find('.progress-bar').addClass(job.status === 'successful' ? 'progress-bar-success' : 'progress-bar-danger');
                $box.find('.ug-progress-report').attr('href', job.report).removeClass('hidden');
                if (job.type === 'scan' && $box.attr('data-ug-follow') === '1') {
                    // a scan the administrator just started: open its result
                    window.location.href = job.report;
                    return;
                }
                reloadOnce(job.id + '-' + job.status);
                return;
            }
            if (job.status === 'observing') {
                // files already replaced: show the new version during the observation period
                reloadOnce(job.id + '-observing');
            }
            setTimeout(function () { poll($box); }, 3000);
        }).fail(function () {
            setTimeout(function () { poll($box); }, 10000);
        });
    }

    /*
     * Settings saved automatically on every change ("change" fires when a checkbox / select changes, or when a text
     * or number field is left): no Save button needed. A new VirusTotal API key is tested by the server before it is
     * saved; the result is shown under the field.
     */
    function autosave($form) {
        var $status = $('.ug-autosave-status');
        var $key = $form.find('input[name="vt_api_key"]');
        var $keyStatus = $form.find('.ug-key-status');
        var pending = null;
        $form.find('.ug-save').hide();
        function save() {
            var keyTyped = $.trim($key.val() || '') !== '';
            $status.removeClass('text-danger').text($status.attr('data-saving'));
            if (keyTyped) {
                $keyStatus.attr('class', 'ug-key-status text-help').text($keyStatus.attr('data-testing'));
            }
            $.ajax({
                url: $form.attr('action'),
                method: 'POST',
                data: $form.serialize(),
                dataType: 'json',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            }).done(function (r) {
                $status.text('✓ ' + r.message);
                if (r.key) {
                    $keyStatus.attr('class', 'ug-key-status ' + (r.key.ok ? 'text-success' : (r.key.saved ? 'text-warning' : 'text-danger')))
                        .text((r.key.ok ? '✓ ' : '! ') + r.key.message);
                    if (r.key.saved) {
                        // the key itself is never shown again: only its mask
                        $key.val('').attr('placeholder', r.mask || '');
                        $form.find('input[name="vt_api_key_remove"]').prop('checked', false);
                    }
                }
            }).fail(function () {
                $status.addClass('text-danger').text('× ' + $status.attr('data-error'));
                $form.find('.ug-save').show();
            });
        }
        $form.on('change', 'input, select, textarea', function () {
            clearTimeout(pending);
            pending = setTimeout(save, 300);
        });
        // Enter in a field: save now instead of submitting the page
        $form.on('submit', function (e) {
            if (!e.originalEvent || !e.originalEvent.submitter || !$(e.originalEvent.submitter).attr('formaction')) {
                e.preventDefault();
                clearTimeout(pending);
                save();
            }
        });
    }

    $(function () {
        $('form[data-ug-autosave]').each(function () { autosave($(this)); });
        $('[data-ug-job]').each(function () { poll($(this)); });
    });
})(window.jQuery);
