/**
 * Idle-timeout client.
 *
 * Tracks REAL user input (keyboard, mouse, touch, scroll). If the user
 * has been idle for `idle_timeout_ms - idle_warning_ms`, shows a
 * countdown modal asking them to stay signed in. If they don't respond
 * within `idle_warning_ms`, the page replaces itself with a "session
 * timed out" notice.
 *
 * The server enforces the same threshold independently — if the client
 * clock is wrong or someone disables JS, the server will still refuse
 * stale requests with a 401 + code:IDLE_TIMEOUT, which our existing
 * fetch interceptor already handles.
 *
 * Usage:
 *   <script src="/idle-tracker.js"></script>
 *   <script>HubIdle.start({ loginUrl: '/admin' });</script>
 *
 * Public API (window.HubIdle):
 *   start({ loginUrl })  — boot the tracker. loginUrl is where the
 *                          re-login button points after timeout.
 *   showTimeoutPage()    — manually trigger the timed-out screen
 *                          (used by the fetch interceptor when the
 *                          server returns code:IDLE_TIMEOUT).
 */
(function(){
    if (window.HubIdle) return; // idempotent

    let lastActivity = Date.now();
    let idleTimeoutMs = 5 * 60 * 1000;     // overwritten from /config
    let idleWarningMs = 30 * 1000;
    let started = false;
    let warnTimer = null;
    let logoutTimer = null;
    let warningModal = null;
    let countdownInterval = null;
    let loginUrl = '/';
    let timedOut = false;

    const ACTIVITY_EVENTS = ['mousedown','keydown','touchstart','scroll','wheel','click'];

    function noteActivity() {
        lastActivity = Date.now();
        // If the warning modal is already showing, the user just came back —
        // dismiss it and reset the timers.
        if (warningModal && warningModal.parentNode) {
            dismissWarning();
        }
        scheduleTimers();
    }

    function scheduleTimers() {
        if (timedOut) return;
        clearTimeout(warnTimer);
        clearTimeout(logoutTimer);
        const warnIn = Math.max(1000, idleTimeoutMs - idleWarningMs);
        warnTimer = setTimeout(showWarning, warnIn);
        logoutTimer = setTimeout(forceLogout, idleTimeoutMs);
    }

    function dismissWarning() {
        if (warningModal && warningModal.parentNode) warningModal.parentNode.removeChild(warningModal);
        warningModal = null;
        if (countdownInterval) { clearInterval(countdownInterval); countdownInterval = null; }
    }

    function buildWarningModal(secsLeft) {
        const wrap = document.createElement('div');
        wrap.id = 'hub-idle-warn';
        wrap.innerHTML = '\
<style>\
#hub-idle-warn{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,7,32,0.62);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}\
#hub-idle-warn .iw-card{width:100%;max-width:420px;background:#160B2D;color:#fff;border-radius:18px;box-shadow:0 24px 60px rgba(0,0,0,0.5);border:1px solid rgba(255,255,255,0.08);overflow:hidden;animation:iwIn 0.28s cubic-bezier(.2,.9,.2,1);}\
@keyframes iwIn{from{opacity:0;transform:translateY(12px) scale(0.98);}to{opacity:1;transform:translateY(0) scale(1);}}\
#hub-idle-warn .iw-top{background:linear-gradient(135deg,var(--pt-primary,#7C3AED),var(--pt-mid,#8B5CF6));height:3px;}\
#hub-idle-warn .iw-head{display:flex;gap:14px;align-items:center;padding:18px 20px 6px;}\
#hub-idle-warn .iw-icon{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,var(--pt-primary,#7C3AED),var(--pt-mid,#8B5CF6));display:flex;align-items:center;justify-content:center;flex-shrink:0;}\
#hub-idle-warn .iw-icon svg{width:24px;height:24px;color:#fff;}\
#hub-idle-warn .iw-title{font-size:1.05rem;font-weight:700;margin:0;}\
#hub-idle-warn .iw-sub{font-size:0.78rem;color:#cbd5e1;margin-top:2px;}\
#hub-idle-warn .iw-body{padding:8px 20px 4px;font-size:0.92rem;color:#e2e8f0;line-height:1.5;}\
#hub-idle-warn .iw-counter{font-size:2.4rem;font-weight:800;text-align:center;color:#fff;padding:16px 20px 4px;font-variant-numeric:tabular-nums;}\
#hub-idle-warn .iw-counter small{font-size:0.85rem;color:#cbd5e1;font-weight:600;display:block;margin-top:2px;}\
#hub-idle-warn .iw-actions{display:flex;gap:10px;padding:16px 20px 20px;}\
#hub-idle-warn .iw-btn{flex:1;padding:12px 16px;border-radius:12px;border:0;font-weight:700;font-size:0.92rem;cursor:pointer;transition:all 0.2s;}\
#hub-idle-warn .iw-btn.secondary{background:rgba(255,255,255,0.05);color:#cbd5e1;border:1px solid rgba(255,255,255,0.08);}\
#hub-idle-warn .iw-btn.secondary:hover{background:rgba(255,255,255,0.1);}\
#hub-idle-warn .iw-btn.primary{background:linear-gradient(135deg,var(--pt-primary,#7C3AED),var(--pt-mid,#8B5CF6));color:#fff;}\
#hub-idle-warn .iw-btn.primary:hover{box-shadow:0 8px 20px rgba(124,58,237,0.4);transform:translateY(-1px);}\
</style>\
<div class="iw-card">\
  <div class="iw-top"></div>\
  <div class="iw-head">\
    <div class="iw-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></div>\
    <div><div class="iw-title">Are you still there?</div><div class="iw-sub">Inactivity timeout protection</div></div>\
  </div>\
  <div class="iw-body">For your security, we will sign you out shortly because no activity has been detected.</div>\
  <div class="iw-counter"><span id="iw-secs">'+secsLeft+'</span><small>seconds</small></div>\
  <div class="iw-actions">\
    <button class="iw-btn secondary" id="iw-logout">Log out now</button>\
    <button class="iw-btn primary" id="iw-stay">I am here &rarr;</button>\
  </div>\
</div>';
        return wrap;
    }

    function showWarning() {
        if (timedOut) return;
        if (warningModal) return;
        const secsLeft = Math.ceil(idleWarningMs / 1000);
        warningModal = buildWarningModal(secsLeft);
        document.body.appendChild(warningModal);

        const secsEl = warningModal.querySelector('#iw-secs');
        const stayBtn = warningModal.querySelector('#iw-stay');
        const outBtn  = warningModal.querySelector('#iw-logout');

        // Stay-signed-in: send heartbeat, dismiss, reset timers.
        stayBtn.onclick = async () => {
            try { await fetch('/hub/api/auth/heartbeat', { method: 'POST', cache: 'no-store' }); } catch (_) {}
            lastActivity = Date.now();
            dismissWarning();
            scheduleTimers();
        };
        outBtn.onclick = async () => {
            try { await fetch('/hub/api/auth/logout', { cache: 'no-store' }); } catch (_) {}
            window.location.href = loginUrl;
        };

        // Local countdown display. The actual logout is driven by the
        // already-scheduled `logoutTimer`; this just keeps the number ticking.
        let remaining = secsLeft;
        countdownInterval = setInterval(() => {
            remaining -= 1;
            if (remaining < 0) remaining = 0;
            if (secsEl) secsEl.textContent = String(remaining);
        }, 1000);
    }

    function forceLogout() {
        if (timedOut) return;
        timedOut = true;
        clearTimeout(warnTimer); clearTimeout(logoutTimer);
        dismissWarning();
        // Best-effort logout call so the server tears down the session cookie.
        try { fetch('/hub/api/auth/logout', { cache: 'no-store' }); } catch (_) {}
        showTimeoutPage();
    }

    function showTimeoutPage() {
        timedOut = true;
        document.body.innerHTML = '\
<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;text-align:center;padding:14% 20px;color:#fff;max-width:480px;margin:0 auto;">\
  <div style="font-size:48px;margin-bottom:12px;">&#9203;</div>\
  <h2 style="color:#a78bfa;margin:0 0 8px;">Session timed out</h2>\
  <p style="color:#cbd5e1;margin:0 0 16px;line-height:1.5;">You were inactive for too long. Please log in again to continue.</p>\
  <a href="' + loginUrl + '" style="display:inline-block;background:linear-gradient(135deg,#7C3AED,#8B5CF6);color:#fff;font-weight:700;padding:12px 22px;border-radius:12px;text-decoration:none;">Log in again &rarr;</a>\
</div>';
    }

    // Heartbeat loop. We send a heartbeat every 60 seconds, but ONLY if the
    // user has been active in the last 60 seconds. Background polling alone
    // (e.g. our /me poll) does NOT keep the session alive — that defeats
    // the whole point of an idle timeout.
    function startHeartbeat() {
        setInterval(async () => {
            if (timedOut) return;
            const idleFor = Date.now() - lastActivity;
            if (idleFor < 60 * 1000) {
                try { await fetch('/hub/api/auth/heartbeat', { method: 'POST', cache: 'no-store' }); } catch (_) {}
            }
        }, 60 * 1000);
    }

    async function start(opts) {
        if (started) return;
        started = true;
        loginUrl = (opts && opts.loginUrl) || '/';

        // Pull the configured timeouts so client + server stay in lockstep.
        try {
            const r = await fetch('/hub/api/auth/config', { cache: 'no-store' });
            const j = await r.json();
            if (j.idle_timeout_ms) idleTimeoutMs = j.idle_timeout_ms;
            if (j.idle_warning_ms) idleWarningMs = j.idle_warning_ms;
        } catch (_) {}

        // Hook real user-input events. {passive:true} so we don't slow scrolling.
        for (const ev of ACTIVITY_EVENTS) {
            document.addEventListener(ev, noteActivity, { passive: true, capture: true });
        }
        // Also reset when the tab regains focus.
        window.addEventListener('focus', noteActivity);
        window.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') noteActivity();
        });

        scheduleTimers();
        startHeartbeat();
    }

    window.HubIdle = { start, showTimeoutPage };
})();
