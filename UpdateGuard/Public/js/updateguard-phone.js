/*
 * UpdateGuard on phones with the Refresh module: entry in Refresh's "Profile" panel (its burger menu), because
 * Refresh's bottom tab bar has a fixed set of tabs. Settings: <meta name="updateguard"> written by the provider
 * (only when Refresh is active and the user is an administrator). Does nothing otherwise.
 */
(function () {
    'use strict';
    var meta = document.querySelector('meta[name="updateguard"]');
    if (!meta) {
        return;
    }
    var cfg;
    try {
        cfg = JSON.parse(meta.getAttribute('content'));
    } catch (e) {
        return;
    }

    var tries = 0;
    var add = function () {
        // Refresh builds its phone shell when the page is ready: wait for the "Profile" panel
        var panel = document.querySelector('.rf-m-acct');
        if (!panel) {
            if (++tries < 40) {
                setTimeout(add, 150);
            }
            return;
        }
        if (panel.querySelector('.ug-m-entry')) {
            return;
        }
        var list = panel.querySelector('.rf-m-acct-list');
        if (!list) {
            return;
        }
        // the same link copied from the "Manage" menu by Refresh (Administration section): replaced by this entry
        Array.prototype.forEach.call(panel.querySelectorAll('.rf-m-acct-row'), function (row) {
            if (row.textContent.trim() === cfg.label) {
                row.parentNode.removeChild(row);
            }
        });

        var row = document.createElement('a');
        row.className = 'rf-m-acct-row ug-m-entry' + (cfg.active ? ' active' : '');
        row.href = cfg.url;
        var icon = document.createElement('img');
        icon.src = cfg.icon;
        icon.alt = '';
        icon.className = 'ug-m-icon';
        // this script runs on every page: styles inline (the module's CSS is loaded on its own pages only)
        icon.style.cssText = 'width:20px;height:20px;flex:none;';
        row.style.textDecoration = 'none';
        row.style.color = 'inherit';
        var label = document.createElement('span');
        label.textContent = cfg.label;
        // same arrow as Refresh's own rows when there is one
        var model = list.querySelector('.rf-m-acct-chev');
        var chev = model ? model.cloneNode(true) : document.createElement('span');
        if (!model) {
            chev.className = 'rf-m-acct-chev';
            chev.textContent = '›';
        }
        row.appendChild(icon);
        row.appendChild(label);
        row.appendChild(chev);
        // first entries of the panel: profile, notifications… then UpdateGuard
        list.appendChild(row);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(add, 0);
        });
    } else {
        setTimeout(add, 0);
    }
})();
