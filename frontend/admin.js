document.addEventListener('DOMContentLoaded', () => {
    if (!checkAuth(['admin', 'superadmin'])) return; // Halt if not authenticated
    const workerSelect = document.getElementById('worker');
    const logWashForm = document.getElementById('log-wash-form');
    const formMessage = document.getElementById('form-message');

    const authToken = getToken();

    // Fetch workers and populate dropdown
    async function fetchWorkers() {
        try {
            const res = await fetch('http://localhost:5000/api/users/washers', {
                headers: {
                    'x-auth-token': authToken
                }
            });

            if (!res.ok) {
                throw new Error('Failed to fetch workers');
            }

            const workers = await res.json();
            workerSelect.innerHTML = '<option value="" disabled selected>Select Worker</option>'; // Reset
            workers.forEach(worker => {
                const option = document.createElement('option');
                option.value = worker.full_name;
                option.textContent = worker.full_name;
                workerSelect.appendChild(option);
            });

        } catch (err) {
            workerSelect.innerHTML = '<option value="" disabled>Error loading workers</option>';
            console.error(err);
        }
    }

    // Handle form submission
    logWashForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        formMessage.textContent = '';

        const formData = {
            service_type: logWashForm.service_type.value,
            car_size: logWashForm.car_size.value,
            number_plate: logWashForm.number_plate.value,
            worker: logWashForm.worker.value,
        };

        try {
            const res = await fetch('http://localhost:5000/api/washes', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'x-auth-token': authToken
                },
                body: JSON.stringify(formData)
            });

            if (!res.ok) {
                const errorData = await res.json();
                throw new Error(errorData.msg || 'Failed to log wash');
            }

            const newWash = await res.json();
            formMessage.textContent = `Successfully logged wash for ${newWash.number_plate}! Amount: $${newWash.amount}`;
            formMessage.style.color = 'var(--success)';
            logWashForm.reset();

        } catch (err) {
            formMessage.textContent = `Error: ${err.message}`;
            formMessage.style.color = 'var(--error)';
        }
    });

    fetchWorkers();
});
