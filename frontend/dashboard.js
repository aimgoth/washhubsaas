document.addEventListener('DOMContentLoaded', () => {
    if (!checkAuth(['superadmin'])) return; // Halt if not authenticated
    const authToken = getToken();
    const revenueAmount = document.querySelector('.revenue-card .amount');
    const performanceList = document.querySelector('.performance-card ul');
    const servicePopularityCanvas = document.querySelector('.services-card .chart-placeholder');
    const recentActivityTableBody = document.querySelector('.recent-activity-card tbody');

    async function fetchDashboardData() {
        try {
            const res = await fetch('http://localhost:5000/api/reports/summary', {
                headers: { 'x-auth-token': authToken }
            });

            if (!res.ok) {
                throw new Error('Failed to fetch dashboard data');
            }

            const data = await res.json();
            updateDashboard(data);

        } catch (err) {
            console.error(err);
            // Display error on the dashboard
            document.querySelector('.main-content').innerHTML = `<p style="color: var(--error);">${err.message}</p>`;
        }
    }

    function updateDashboard(data) {
        // Update Total Revenue
        revenueAmount.textContent = `$${data.totalRevenue.toFixed(2)}`;

        // Update Top Employee Performance
        performanceList.innerHTML = '';
        data.employeePerformance.forEach(emp => {
            const li = document.createElement('li');
            li.innerHTML = `<span>${emp._id}</span><span class="metric">${emp.totalWashes} Washes</span>`;
            performanceList.appendChild(li);
        });

        // Create Service Popularity Chart
        servicePopularityCanvas.innerHTML = '<canvas id="serviceChart"></canvas>';
        const serviceChartCtx = document.getElementById('serviceChart').getContext('2d');
        new Chart(serviceChartCtx, {
            type: 'doughnut',
            data: {
                labels: data.servicePopularity.map(s => s._id),
                datasets: [{
                    label: 'Service Popularity',
                    data: data.servicePopularity.map(s => s.count),
                    backgroundColor: ['#1ABC9C', '#3498DB', '#9B59B6', '#E74C3C'],
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                }
            }
        });
    }

    fetchDashboardData();
});
