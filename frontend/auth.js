function getToken() {
    return localStorage.getItem('token');
}

function checkAuth(allowedRoles = []) {
    if (window.location.pathname.endsWith('login.html')) {
        return true; // Don't run checks on login page
    }

    const token = getToken();
    if (!token) {
        window.location.href = 'login.html';
        return false; // Not authenticated
    }

    try {
        const payload = JSON.parse(atob(token.split('.')[1]));
        const userRole = payload.user.role;

        if (allowedRoles.length > 0 && !allowedRoles.includes(userRole)) {
            alert('Access Denied: You do not have permission to view this page.');
            logout();
            return false; // Not authorized
        }

        const userInfo = document.querySelector('.user-info span');
        if (userInfo) {
            // Use full_name from token for a better welcome message
            userInfo.textContent = `Welcome, ${payload.user.name || userRole}`;
        }
        return true; // Authenticated and authorized
    } catch (e) {
        console.error('Invalid token:', e);
        logout();
        return false; // Error, not authenticated
    }
}

function logout() {
    localStorage.removeItem('token');
    window.location.href = 'login.html';
}

document.addEventListener('DOMContentLoaded', () => {
    const logoutButtons = document.querySelectorAll('.logout-btn');
    logoutButtons.forEach(button => {
        button.addEventListener('click', logout);
    });
});
