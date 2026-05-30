document.addEventListener('DOMContentLoaded', () => {
    // Silent check for existing session
    fetch('/hub/api/auth/me')
        .then(res => {
            if (res.ok) {
                return res.json().then(u => {
                    window.location.href = u.role === 'admin' ? '/admin' : '/dashboard';
                });
            }
        }).catch(() => { /* Silent on 401/Unauthorized */ });

    // Lazy-load Cloudflare Turnstile script if the server tells us it's enabled.
    fetch('/hub/api/auth/config').then(r => r.json()).then(cfg => {
        if (!cfg.turnstile_enabled || !cfg.turnstile_site_key) return;
        window.__turnstileSiteKey = cfg.turnstile_site_key;
        const s = document.createElement('script');
        s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=__hubTurnstileLoaded';
        s.async = true; s.defer = true;
        document.head.appendChild(s);
    }).catch(()=>{});
});

window.__hubTurnstileLoaded = function() {
    const containers = document.querySelectorAll('[data-hub-turnstile]');
    containers.forEach(c => {
        if (c.dataset.rendered === '1') return;
        c.dataset.rendered = '1';
        try {
            const id = window.turnstile.render(c, {
                sitekey: window.__turnstileSiteKey,
                theme: 'auto',
                callback: (token) => { c.dataset.token = token; }
            });
            c.dataset.widgetId = id;
        } catch (e) { console.warn('turnstile render failed', e); }
    });
};

function showAlert(boxId, type, msg) {
    const el = document.getElementById(boxId);
    if (!el) return;
    el.className = `alert alert-${type}`;
    el.innerText = msg;
    el.style.display = 'block';
    setTimeout(() => el.style.display = 'none', 5000);
}

// --- Single-session takeover modal -----------------------------------------
// Builds the modal lazily the first time we need it. Uses Scholar Genie
// purple tokens already defined in style.css (--pt-primary, --pt-mid, etc).
function ensureTakeoverModal() {
    let el = document.getElementById('hub-takeover-modal');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'hub-takeover-modal';
    el.innerHTML = `
      <style>
        #hub-takeover-modal { position: fixed; inset: 0; z-index: 99999; display: none; align-items: center; justify-content: center; padding: 16px;
            background: rgba(15, 7, 32, 0.62); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        #hub-takeover-modal.open { display: flex; }
        #hub-takeover-modal .tk-card { width: 100%; max-width: 460px; background: #160B2D; color: #fff;
            border-radius: 18px; box-shadow: 0 24px 60px rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.08);
            overflow: hidden; animation: tkIn 0.28s cubic-bezier(.2,.9,.2,1); }
        @keyframes tkIn { from { opacity: 0; transform: translateY(12px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
        #hub-takeover-modal .tk-top { background: linear-gradient(135deg, var(--pt-primary, #7C3AED), var(--pt-mid, #8B5CF6)); height: 3px; }
        #hub-takeover-modal .tk-head { display: flex; gap: 14px; align-items: center; padding: 18px 20px 8px; }
        #hub-takeover-modal .tk-icon { width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, var(--pt-primary, #7C3AED), var(--pt-mid, #8B5CF6));
            display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        #hub-takeover-modal .tk-icon svg { width: 22px; height: 22px; color:#fff; }
        #hub-takeover-modal .tk-title { font-size: 1.05rem; font-weight: 700; margin:0; }
        #hub-takeover-modal .tk-subtitle { font-size: 0.78rem; color: #cbd5e1; margin-top:2px; }
        #hub-takeover-modal .tk-pill { display:inline-block; padding: 2px 10px; border-radius: 999px; font-weight: 600; font-size: 0.74rem;
            background: rgba(124,58,237,0.25); color:#c4b5fd; margin-right: 6px; }
        #hub-takeover-modal .tk-section { margin: 10px 20px; padding: 12px 14px; border-radius: 14px;
            background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.07); }
        #hub-takeover-modal .tk-section.removed { border-color: rgba(220, 38, 38, 0.35); }
        #hub-takeover-modal .tk-section.new { border-color: rgba(124, 58, 237, 0.45); }
        #hub-takeover-modal .tk-section-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        #hub-takeover-modal .tk-section-head .tk-label { display:flex; gap:8px; align-items:center; color:#94a3b8; font-size:0.7rem; letter-spacing:0.06em; font-weight: 700; text-transform: uppercase; }
        #hub-takeover-modal .tk-section-head .tk-tag { font-size: 0.7rem; font-weight: 700; padding: 3px 10px; border-radius: 999px; }
        #hub-takeover-modal .tk-section-head .tk-tag.removed { background: rgba(220,38,38,0.18); color:#fca5a5; }
        #hub-takeover-modal .tk-section-head .tk-tag.this { background: rgba(124,58,237,0.25); color:#c4b5fd; }
        #hub-takeover-modal .tk-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; font-size: 0.83rem; border-top: 1px solid rgba(255,255,255,0.05); }
        #hub-takeover-modal .tk-row:first-of-type { border-top: 0; }
        #hub-takeover-modal .tk-row .tk-k { color:#94a3b8; display:flex; gap:6px; align-items:center; }
        #hub-takeover-modal .tk-row .tk-v { color:#fff; font-weight: 600; text-align: right; }
        #hub-takeover-modal .tk-ip { font-family: ui-monospace, "SF Mono", monospace; background: rgba(255,255,255,0.05); padding: 1px 8px; border-radius: 6px; font-size: 0.78rem; }
        #hub-takeover-modal .tk-arrow { display:flex; justify-content:center; margin: 4px 0; }
        #hub-takeover-modal .tk-arrow div { width: 32px; height: 32px; border-radius: 50%;
            background: linear-gradient(135deg, var(--pt-primary, #7C3AED), var(--pt-mid, #8B5CF6));
            display:flex; align-items:center; justify-content:center; }
        #hub-takeover-modal .tk-notice { margin: 12px 20px 0; padding: 12px 14px; border-radius: 14px;
            background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.3); display: flex; gap: 12px; }
        #hub-takeover-modal .tk-notice svg { flex-shrink: 0; color: #f59e0b; }
        #hub-takeover-modal .tk-notice div { font-size: 0.78rem; color: #fde68a; }
        #hub-takeover-modal .tk-notice div b { color: #f59e0b; display: block; margin-bottom: 2px; font-size: 0.82rem; }
        #hub-takeover-modal .tk-actions { display: flex; gap: 10px; padding: 16px 20px 20px; }
        #hub-takeover-modal .tk-btn { flex: 1; padding: 12px 16px; border-radius: 12px; border: 0;
            font-weight: 700; font-size: 0.92rem; cursor: pointer; transition: all 0.2s; }
        #hub-takeover-modal .tk-btn.cancel { background: rgba(255,255,255,0.05); color: #cbd5e1; border: 1px solid rgba(255,255,255,0.08); }
        #hub-takeover-modal .tk-btn.cancel:hover { background: rgba(255,255,255,0.1); }
        #hub-takeover-modal .tk-btn.continue { background: linear-gradient(135deg, var(--pt-primary, #7C3AED), var(--pt-mid, #8B5CF6)); color:#fff; }
        #hub-takeover-modal .tk-btn.continue:hover { box-shadow: 0 8px 20px rgba(124, 58, 237, 0.4); transform: translateY(-1px); }
        #hub-takeover-modal .tk-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none !important; }
        #hub-takeover-modal .tk-status { display:flex; gap:6px; align-items:center; color:#34d399; font-weight:700; font-size:0.83rem; }
        #hub-takeover-modal .tk-status::before { content:''; width:8px; height:8px; border-radius:50%; background:#10b981; box-shadow:0 0 8px #10b981; }
      </style>
      <div class="tk-card">
        <div class="tk-top"></div>
        <div class="tk-head">
            <div class="tk-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg></div>
            <div>
                <div class="tk-title">Active Session Found</div>
                <div class="tk-subtitle"><span class="tk-pill">1 session</span>will be ended</div>
            </div>
        </div>
        <div class="tk-section removed">
            <div class="tk-section-head">
                <div class="tk-label"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg> EXISTING SESSION</div>
                <div class="tk-tag removed">&#10005; Will be removed</div>
            </div>
            <div class="tk-row"><span class="tk-k">Device</span><span class="tk-v" id="tk-old-device">—</span></div>
            <div class="tk-row"><span class="tk-k">IP Address</span><span class="tk-v"><span class="tk-ip" id="tk-old-ip">—</span></span></div>
            <div class="tk-row"><span class="tk-k">Location</span><span class="tk-v" id="tk-old-loc">—</span></div>
            <div class="tk-row"><span class="tk-k">Last Active</span><span class="tk-v" id="tk-old-time">—</span></div>
        </div>
        <div class="tk-arrow"><div><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg></div></div>
        <div class="tk-section new">
            <div class="tk-section-head">
                <div class="tk-label"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg> NEW SESSION</div>
                <div class="tk-tag this">&#10003; This device</div>
            </div>
            <div class="tk-row"><span class="tk-k">Device</span><span class="tk-v" id="tk-new-device">—</span></div>
            <div class="tk-row"><span class="tk-k">IP Address</span><span class="tk-v"><span class="tk-ip" id="tk-new-ip">—</span></span></div>
            <div class="tk-row"><span class="tk-k">Location</span><span class="tk-v" id="tk-new-loc">—</span></div>
            <div class="tk-row"><span class="tk-k">Status</span><span class="tk-v"><span class="tk-status">Logging in now</span></span></div>
        </div>
        <div class="tk-notice">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <div><b>Security Notice</b>If you don't recognize the existing session, change your password after logging in.</div>
        </div>
        <div class="tk-actions">
            <button class="tk-btn cancel" id="tk-cancel">Cancel</button>
            <button class="tk-btn continue" id="tk-continue">Continue &rarr;</button>
        </div>
      </div>`;
    document.body.appendChild(el);
    return el;
}

function fmtRelativeTime(epochMs) {
    if (!epochMs) return '—';
    const diff = Date.now() - epochMs;
    if (diff < 60_000) return 'Just now';
    if (diff < 3600_000) return Math.floor(diff/60_000) + ' min ago';
    if (diff < 86400_000) return Math.floor(diff/3600_000) + ' h ago';
    return Math.floor(diff/86400_000) + ' d ago';
}

function showTakeoverModal(payload, onConfirm, onCancel) {
    const el = ensureTakeoverModal();
    const ex = payload.existing || {};
    const cur = payload.this_device || {};

    document.getElementById('tk-old-device').textContent = (ex.device || 'Unknown') + (ex.browser ? ' (' + ex.browser + ')' : '');
    document.getElementById('tk-old-ip').textContent = ex.ip || '—';
    document.getElementById('tk-old-loc').textContent = (ex.country_code ? ex.country_code.toUpperCase() + ' ' : '') + (ex.country || 'Unknown Location');
    document.getElementById('tk-old-time').textContent = fmtRelativeTime(ex.last_active);

    document.getElementById('tk-new-device').textContent = (cur.device || 'Unknown') + (cur.browser ? ' (' + cur.browser + ')' : '');
    document.getElementById('tk-new-ip').textContent = cur.ip || '—';
    document.getElementById('tk-new-loc').textContent = (cur.country_code ? cur.country_code.toUpperCase() + ' ' : '') + (cur.country || 'Unknown Location');

    el.classList.add('open');
    const cancelBtn = document.getElementById('tk-cancel');
    const continueBtn = document.getElementById('tk-continue');
    continueBtn.disabled = false;
    continueBtn.innerHTML = 'Continue &rarr;';
    cancelBtn.onclick = () => { el.classList.remove('open'); if (onCancel) onCancel(); };
    continueBtn.onclick = async () => {
        continueBtn.disabled = true;
        continueBtn.innerHTML = 'Continuing…';
        try { await onConfirm(); } finally { el.classList.remove('open'); }
    };
}

async function performLogin(email, password) {
    // Read Turnstile token if a widget rendered one.
    let tsToken = null;
    const tsBox = document.querySelector('[data-hub-turnstile]');
    if (tsBox && tsBox.dataset.token) tsToken = tsBox.dataset.token;

    const doFetch = (extra = {}) => fetch('/hub/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password, turnstile_token: tsToken, ...extra })
    });

    let res = await doFetch();
    if (res.status === 409) {
        const data = await res.json().catch(() => ({}));
        // Show the takeover modal. On confirm, retry with confirm_takeover.
        await new Promise((resolve) => {
            showTakeoverModal(data,
                async () => {
                    res = await doFetch({ confirm_takeover: true });
                    resolve();
                },
                () => { res = null; resolve(); }
            );
        });
        if (!res) return { ok: false, cancelled: true };
    }
    const data = await res.json().catch(() => ({}));
    return { ok: res.ok, data, status: res.status };
}

document.getElementById('login-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = e.target.querySelector('button');
    btn.disabled = true;
    btn.innerText = 'Logging in...';

    const email = document.getElementById('login-email').value;
    const password = document.getElementById('login-password').value;

    try {
        const r = await performLogin(email, password);
        if (r.cancelled) { btn.disabled = false; btn.innerText = 'Login to Hub'; return; }
        if (r.ok) {
            window.location.href = r.data.user.role === 'admin' ? '/admin' : '/dashboard';
        } else {
            showAlert('login-alert', 'error', (r.data && r.data.error) || 'Login failed');
            btn.disabled = false;
            btn.innerText = 'Login to Hub';
        }
    } catch(e) {
        showAlert('login-alert', 'error', 'Network error');
        btn.disabled = false;
        btn.innerText = 'Login to Hub';
    }
});

document.getElementById('register-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = e.target.querySelector('button');
    btn.disabled = true;
    btn.innerText = 'Creating session...';

    const username = document.getElementById('reg-user').value;
    const email = document.getElementById('reg-email').value;
    const password = document.getElementById('reg-password').value;

    try {
        const res = await fetch('/hub/api/auth/register', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ username, email, password })
        });
        const data = await res.json();

        if (res.ok) {
            showAlert('register-alert', 'success', 'Registration successful! Please sign in.');
            setTimeout(() => document.getElementById('show-login').click(), 2000);
        } else {
            showAlert('register-alert', 'error', data.error || 'Registration failed');
            btn.disabled = false;
            btn.innerText = 'Register';
        }
    } catch(e) {
        showAlert('register-alert', 'error', 'Network error');
        btn.disabled = false;
        btn.innerText = 'Register';
    }
});

// Expose for admin-login.html which has its own inline form handler.
window.__hubAuth = { performLogin, showTakeoverModal };
