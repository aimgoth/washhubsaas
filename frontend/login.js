document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('login-form');
    const loginMessage = document.getElementById('login-message');

    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        loginMessage.textContent = '';

        const username = loginForm.username.value;
        const password = loginForm.password.value;

        try {
            const res = await fetch('http://localhost:5000/api/users/login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password })
            });

            const data = await res.json();

            if (!res.ok) {
                throw new Error(data.msg || 'Login failed');
            }

            localStorage.setItem('token', data.token);

            // Decode token to get user role
            const payload = JSON.parse(atob(data.token.split('.')[1]));
            const role = payload.user.role;

            // Redirect based on role
            if (role === 'superadmin') {
                window.location.href = 'index.html';
            } else if (role === 'admin') {
                window.location.href = 'admin.html';
            } else if (role === 'washer' || role === 'cashier') {
                window.location.href = 'employee.html';
            } else {
                throw new Error('Unknown user role');
            }

        } catch (err) {
            loginMessage.textContent = `Error: ${err.message}`;
            loginMessage.style.color = 'var(--error)';
        }
    });
});
