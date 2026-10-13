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

    // progress: polls the job every 3 s until it is finished, then shows the report link
    function poll($box) {
        var url = $box.attr('data-ug-job');
        $.getJSON(url).done(function (job) {
            $box.find('.ug-progress-label').text((job.finished ? '' : '● ') + job.label + ' — ' + job.update_id);
            if (job.message) {
                $box.find('.ug-progress-message').text(job.message);
            }
            var width = Math.max(2, job.progress) + '%';
            $box.find('.progress-bar').css('width', width).attr('aria-valuenow', job.progress);
            if (job.finished) {
                $box.find('.progress-bar').addClass(job.status === 'successful' ? 'progress-bar-success' : 'progress-bar-danger');
                $box.find('.ug-progress-report').attr('href', job.report).removeClass('hidden');
                return;
            }
            setTimeout(function () { poll($box); }, 3000);
        }).fail(function () {
            setTimeout(function () { poll($box); }, 10000);
        });
    }

    $(function () {
        $('[data-ug-job]').each(function () { poll($(this)); });
    });
})(window.jQuery);
