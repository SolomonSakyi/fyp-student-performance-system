/* ==========================================================================
   EduTrack — Floating Notifications Bell
   --------------------------------------------------------------------------
   Include on any tenant page:
       <script src="/assets/js/notifications-bell.js" defer></script>

   Behaviour:
     - Injects a floating bell (bottom-right) with an unread badge.
     - Polls notifications-ajax.php?action=unread_count every 60 s.
     - Click → fetch recent, render dropdown.
     - Click item → mark read, follow link.
     - "Mark all as read" button inside the dropdown.
     - "View all" → /platform/tenant/notifications.php

   Session S17g decisions:
     NS8A  shared floating bell JS
     NS9A  standalone notifications-ajax.php
     NS11A soft-delete your own

   Session S20 decisions:
     SH2A  per-session CSRF token; state-changing AJAX calls send X-CSRF-Token
           (read from <meta name="csrf-token"> on the host page)
   ========================================================================== */
(function () {
    'use strict';

    if (window.__edutrackBellLoaded) return;
    window.__edutrackBellLoaded = true;

    var AJAX  = '/platform/tenant/notifications-ajax.php';
    var PAGE  = '/platform/tenant/notifications.php';
    var POLL  = 60000; // 60 s

    // ----------------------------------------------------------------------
    // CSRF token (S20 SH2A)
    // Read from <meta name="csrf-token" content="...">  that each page emits.
    // Fallback: empty string, which will cause the server to reject
    // state-changing calls with a 403 — same result as a missing session,
    // which is the correct conservative behaviour.
    // ----------------------------------------------------------------------
    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? (meta.getAttribute('content') || '') : '';
    }

    // ----------------------------------------------------------------------
    // Styles (self-injected; no external CSS file needed)
    // ----------------------------------------------------------------------
    var style = document.createElement('style');
    style.textContent = [
        '#et-bell-wrap{position:fixed;right:22px;bottom:22px;z-index:10000;font-family:"Inter",sans-serif}',
        '#et-bell-btn{position:relative;width:52px;height:52px;border-radius:50%;border:none;',
        '  background:linear-gradient(135deg,#4facfe 0%,#00f2fe 100%);color:#fff;font-size:20px;',
        '  cursor:pointer;box-shadow:0 8px 24px rgba(79,172,254,0.45);transition:transform .2s}',
        '#et-bell-btn:hover{transform:translateY(-2px)}',
        '#et-bell-btn .et-badge{position:absolute;top:-4px;right:-4px;min-width:20px;height:20px;',
        '  padding:0 6px;border-radius:10px;background:#dc3545;color:#fff;font-size:11px;font-weight:700;',
        '  display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 3px #fff}',
        '#et-bell-panel{position:absolute;right:0;bottom:66px;width:380px;max-width:calc(100vw - 44px);',
        '  background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,0.18);',
        '  overflow:hidden;display:none}',
        '#et-bell-panel.open{display:block}',
        '#et-bell-head{padding:14px 18px;border-bottom:1px solid #f0f2f5;display:flex;',
        '  justify-content:space-between;align-items:center;background:linear-gradient(135deg,#f8fafc,#eef6ff)}',
        '#et-bell-head strong{font-size:14px;color:#1a1a2e}',
        '#et-bell-head a{font-size:12px;color:#4facfe;text-decoration:none}',
        '#et-bell-head a:hover{text-decoration:underline}',
        '#et-bell-list{max-height:420px;overflow-y:auto}',
        '.et-bell-item{display:flex;gap:12px;padding:12px 16px;border-bottom:1px solid #f6f2f5;',
        '  cursor:pointer;text-decoration:none;color:inherit}',
        '.et-bell-item:hover{background:#f8fbff}',
        '.et-bell-item.unread{background:#f0f7ff;border-left:3px solid #4facfe}',
        '.et-bell-item .et-ic{width:36px;height:36px;border-radius:9px;display:flex;',
        '  align-items:center;justify-content:center;font-size:15px;color:#fff;flex-shrink:0;background:#4facfe}',
        '.et-bell-item .et-ic.approved{background:#28a745}',
        '.et-bell-item .et-ic.rejected{background:#dc3545}',
        '.et-bell-item .et-ic.urgent{background:#dc3545}',
        '.et-bell-item .et-ic.info{background:#4facfe}',
        '.et-bell-item .et-body{flex:1;min-width:0}',
        '.et-bell-item .et-title{font-weight:600;font-size:13px;color:#1a1a2e}',
        '.et-bell-item .et-msg{font-size:12px;color:#6c757d;margin-top:2px;',
        '  overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;',
        '  -webkit-box-orient:vertical}',
        '.et-bell-item .et-time{font-size:10px;color:#adb5bd;margin-top:4px}',
        '#et-bell-foot{padding:10px 16px;border-top:1px solid #f0f2f5;text-align:center}',
        '#et-bell-foot a{font-size:12px;color:#4facfe;text-decoration:none}',
        '#et-bell-foot a:hover{text-decoration:underline}',
        '.et-bell-empty{padding:32px 16px;text-align:center;color:#6c757d;font-size:13px}',
        '.et-bell-empty i{font-size:36px;opacity:.3;display:block;margin-bottom:8px}'
    ].join('');
    document.head.appendChild(style);

    // ----------------------------------------------------------------------
    // DOM
    // ----------------------------------------------------------------------
    var wrap  = document.createElement('div');
    wrap.id   = 'et-bell-wrap';
    wrap.innerHTML =
        '<div id="et-bell-panel">' +
          '<div id="et-bell-head">' +
            '<strong>Notifications</strong>' +
            '<a href="#" id="et-bell-markall">Mark all read</a>' +
          '</div>' +
          '<div id="et-bell-list"><div class="et-bell-empty">Loading…</div></div>' +
          '<div id="et-bell-foot"><a href="' + PAGE + '">View all notifications</a></div>' +
        '</div>' +
        '<button id="et-bell-btn" type="button" aria-label="Notifications">' +
          '<i class="fas fa-bell"></i>' +
        '</button>';
    document.body.appendChild(wrap);

    var btn       = document.getElementById('et-bell-btn');
    var panel     = document.getElementById('et-bell-panel');
    var list      = document.getElementById('et-bell-list');
    var markAll   = document.getElementById('et-bell-markall');
    var badge     = null;

    // ----------------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------------
    function escapeHtml(s) {
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function timeAgo(iso) {
        if (!iso) return '';
        var t = new Date(iso.replace(' ', 'T')).getTime();
        if (isNaN(t)) return iso;
        var diff = Math.floor((Date.now() - t) / 1000);
        if (diff < 60)    return 'just now';
        if (diff < 3600)  return Math.floor(diff / 60) + ' min ago';
        if (diff < 86400) return Math.floor(diff / 3600) + ' h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + ' d ago';
        return new Date(t).toLocaleDateString();
    }

    function iconFor(type, priority) {
        if (priority === 'urgent') return 'fa-exclamation-triangle';
        if (type === 'approved')   return 'fa-check-circle';
        if (type === 'rejected')   return 'fa-times-circle';
        if (type === 'discipline') return 'fa-gavel';
        if (type === 'behaviour')  return 'fa-star-half-alt';
        if (type === 'finance')    return 'fa-money-bill-wave';
        if (type === 'result')     return 'fa-file-alt';
        if (type === 'promotion')  return 'fa-level-up-alt';
        if (type === 'attendance') return 'fa-calendar-check';
        if (type === 'reminder')   return 'fa-clock';
        if (type === 'biometric')  return 'fa-fingerprint';
        if (type === 'registration') return 'fa-user-plus';
        if (type === 'credentials')  return 'fa-key';
        if (type === 'escalation')   return 'fa-exclamation-circle';
        return 'fa-info-circle';
    }

    function updateBadge(count) {
        if (badge) {
            badge.parentNode.removeChild(badge);
            badge = null;
        }
        if (count > 0) {
            badge = document.createElement('span');
            badge.className = 'et-badge';
            badge.textContent = count > 99 ? '99+' : String(count);
            btn.appendChild(badge);
        }
    }

    // ----------------------------------------------------------------------
    // Data fetching
    // ----------------------------------------------------------------------
    function fetchCount() {
        fetch(AJAX + '?action=unread_count', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok) updateBadge(d.count || 0);
            })
            .catch(function () { /* silent */ });
    }

    function fetchRecent() {
        list.innerHTML = '<div class="et-bell-empty">Loading…</div>';
        fetch(AJAX + '?action=recent&limit=10', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) {
                    list.innerHTML = '<div class="et-bell-empty"><i class="fas fa-exclamation-circle"></i>Could not load</div>';
                    return;
                }
                if (!d.items || !d.items.length) {
                    list.innerHTML = '<div class="et-bell-empty"><i class="fas fa-bell-slash"></i>No notifications</div>';
                    return;
                }
                var html = '';
                d.items.forEach(function (n) {
                    var cls   = n.is_read ? '' : 'unread';
                    var ico   = iconFor(n.type, n.priority || 'normal');
                    var extra = (n.priority === 'urgent') ? ' urgent' : '';
                    html +=
                        '<a class="et-bell-item ' + cls + '" href="#" ' +
                           'data-id="' + n.id + '" ' +
                           'data-link="' + escapeHtml(n.link || '') + '">' +
                          '<div class="et-ic ' + escapeHtml(n.type) + extra + '"><i class="fas ' + ico + '"></i></div>' +
                          '<div class="et-body">' +
                            '<div class="et-title">' + escapeHtml(n.title) + '</div>' +
                            '<div class="et-msg">'   + escapeHtml(n.message) + '</div>' +
                            '<div class="et-time">'  + escapeHtml(timeAgo(n.created_at)) + '</div>' +
                          '</div>' +
                        '</a>';
                });
                list.innerHTML = html;
                list.querySelectorAll('.et-bell-item').forEach(function (el) {
                    el.addEventListener('click', function (e) {
                        e.preventDefault();
                        var id   = el.getAttribute('data-id');
                        var link = el.getAttribute('data-link') || '';
                        if (!id) return;
                        // S20 SH2A — state-changing call, send CSRF token
                        fetch(AJAX + '?action=mark_read&id=' + encodeURIComponent(id), {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'X-CSRF-Token': csrfToken() }
                        })
                            .finally(function () {
                                if (link) window.location.href = link;
                                else fetchCount();
                            });
                    });
                });
            })
            .catch(function () {
                list.innerHTML = '<div class="et-bell-empty"><i class="fas fa-exclamation-circle"></i>Could not load</div>';
            });
    }

    // ----------------------------------------------------------------------
    // Events
    // ----------------------------------------------------------------------
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (panel.classList.contains('open')) {
            panel.classList.remove('open');
            return;
        }
        panel.classList.add('open');
        fetchRecent();
    });

    document.addEventListener('click', function (e) {
        if (!wrap.contains(e.target)) panel.classList.remove('open');
    });

    markAll.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        // S20 SH2A — state-changing call, send CSRF token
        fetch(AJAX + '?action=mark_all_read', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrfToken() }
        })
            .then(function () {
                fetchCount();
                fetchRecent();
            })
            .catch(function () { /* silent */ });
    });

    // ----------------------------------------------------------------------
    // Boot
    // ----------------------------------------------------------------------
    fetchCount();
    setInterval(fetchCount, POLL);
})();