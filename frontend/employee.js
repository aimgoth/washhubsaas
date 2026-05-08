document.addEventListener('DOMContentLoaded', () => {
    if (!checkAuth(['washer', 'cashier'])) return; // Halt if not authenticated
    const washHistoryTableBody = document.querySelector('#wash-history-table tbody');
    const totalWashesSpan = document.getElementById('total-washes');
    const totalEarnedSpan = document.getElementById('total-earned');

    const authToken = getToken();

    async function fetchWashHistory() {
        try {
            const res = await fetch('http://localhost:5000/api/washes', {
                headers: {
                    'x-auth-token': authToken
                }
            });

            if (!res.ok) {
                throw new Error('Failed to fetch wash history');
            }

            const washes = await res.json();
            displayWashes(washes);

        } catch (err) {
            washHistoryTableBody.innerHTML = `<tr><td colspan="5" style="color: var(--error);">${err.message}</td></tr>`;
            console.error(err);
        }
    }

    function displayWashes(washes) {
        washHistoryTableBody.innerHTML = ''; // Clear existing rows
        let totalEarned = 0;

        if (washes.length === 0) {
            washHistoryTableBody.innerHTML = '<tr><td colspan="5">No washes found.</td></tr>';
            return;
        }

        washes.forEach(wash => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${new Date(wash.timestamp).toLocaleDateString()}</td>
                <td>${wash.service_type}</td>
                <td>${wash.car_size}</td>
                <td>${wash.number_plate}</td>
                <td>$${wash.amount.toFixed(2)}</td>
            `;
            washHistoryTableBody.appendChild(row);
            totalEarned += wash.amount;
        });

        totalWashesSpan.textContent = washes.length;
        totalEarnedSpan.textContent = `$${totalEarned.toFixed(2)}`;
    }

    fetchWashHistory();
});
