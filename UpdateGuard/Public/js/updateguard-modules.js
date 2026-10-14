/*
 * UpdateGuard on FreeScout's Manage › Modules page: when FreeScout shows an available update for a community module,
 * a note recommends doing it with UpdateGuard (SHA-256, backup, health check, automatic rollback). Never for
 * UpdateGuard itself nor for official modules. FreeScout's page has no hook: its markup is read
 * (resources/views/modules/modules.blade.php, modules/partials/module_card.blade.php) and nothing is changed if it is
 * not found. Settings: <meta name="updateguard-modules"> written by the provider (administrators, setting
 * "modules_page_hint" on). Can be switched off in UpdateGuard › Settings › Notifications.
 */
(function () {
    'use strict';
    var meta = document.querySelector('meta[name="updateguard-modules"]');
    if (!meta) {
        return;
    }
    var cfg;
    try {
        cfg = JSON.parse(meta.getAttribute('content'));
    } catch (e) {
        return;
    }

    function note(text, url, label) {
        var div = document.createElement('div');
        div.className = 'ug-fs-hint';
        // styles inline: UpdateGuard's stylesheet is only loaded on its own pages
        div.style.cssText = 'margin-top:8px;padding:6px 10px;border-left:3px solid #3b7dd8;background:rgba(59,125,216,.08);font-size:13px;';
        div.appendChild(document.createTextNode('ℹ ' + text + ' '));
        var a = document.createElement('a');
        a.href = url;
        a.textContent = label;
        a.style.fontWeight = '600';
        div.appendChild(a);

        return div;
    }

    function run() {
        var found = 0;
        Array.prototype.forEach.call(document.querySelectorAll('.module-card[data-alias]'), function (card) {
            var alias = card.getAttribute('data-alias');
            var module = cfg.modules[alias];
            var alert = card.querySelector('.alert-module-update');
            if (!module || !alert || alert.querySelector('.ug-fs-hint')) {
                return;
            }
            alert.appendChild(note(cfg.text.replace('__MODULE__', module.name), module.url, cfg.link));
            found++;
        });
        // summary at the top ("There are updates available"), when at least one listed module is concerned
        var list = document.getElementById('new_versions_list');
        if (list && !list.parentNode.querySelector('.ug-fs-hint')) {
            var concerned = Array.prototype.some.call(list.querySelectorAll('a[data-module-alias]'), function (a) {
                return !!cfg.modules[a.getAttribute('data-module-alias')];
            });
            if (concerned || found) {
                list.parentNode.appendChild(note(cfg.summary, cfg.dashboard, cfg.dashboardLink));
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
