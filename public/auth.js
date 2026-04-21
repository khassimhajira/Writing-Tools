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
});

function showAlert(boxId, type, msg) {
    const el = document.getElementById(boxId);
    el.className = `alert alert-${type}`;
    el.innerText = msg;
    el.style.display = 'block';
    setTimeout(() => el.style.display = 'none', 5000);
}

document.getElementById('login-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = e.target.querySelector('button');
    btn.disabled = true;
    btn.innerText = 'Logging in...';

    const email = document.getElementById('login-email').value;
    const password = document.getElementById('login-password').value;

    try {
        const res = await fetch('/hub/api/auth/login', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ email, password })
        });
        const data = await res.json();
        
        if (res.ok) {
            window.location.href = data.user.role === 'admin' ? '/admin' : '/dashboard';
        } else {
            showAlert('login-alert', 'error', data.error || 'Login failed');
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
