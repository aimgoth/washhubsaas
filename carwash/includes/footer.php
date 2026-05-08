    </main>
<footer style="background-color: var(--secondary-color); color: white; padding: 20px 0; margin-top: 40px;">
    <div class="container" style="text-align: center;">
        <p style="margin: 0; font-size: 0.9rem;">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(getenv('APP_NAME') ?: 'WashHub'); ?>. All rights reserved. Powered by <strong>GothTech Consult</strong></p>
    </div>
</footer>

<script>
    // Auto-hide alerts after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.transition = 'opacity 0.3s ease';
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.style.display = 'none';
                }, 300);
            }, 5000);
        });
        
        // Add smooth scrolling to all links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });
    });
    
    // Function to show a toast notification
    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.classList.add('show');
            
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 300);
            }, 3000);
        }, 100);
    }

    // Add form validation
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.style.borderColor = 'var(--accent-color)';
                    isValid = false;
                } else {
                    field.style.borderColor = '';
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                showToast('Please fill in all required fields', 'error');
            }
        });
    });
</script>

<style>
    /* Floating Quick Add Button */
    .fab-add {
        position: fixed;
        right: 20px;
        bottom: 20px;
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: var(--primary-color);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 32px;
        text-decoration: none;
        box-shadow: 0 6px 16px rgba(0,0,0,0.2);
        z-index: 1001;
        transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.2s ease;
    }
    .fab-add:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.25); }
    .fab-add:active { transform: translateY(0); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
    @media (max-width: 768px) {
        .fab-add { right: 16px; bottom: 16px; width: 64px; height: 64px; font-size: 36px; }
    }

    /* Toast Notifications */
    .toast {
        position: fixed;
        bottom: 20px;
        right: 20px;
        background-color: #333;
        color: white;
        padding: 12px 24px;
        border-radius: 4px;
        opacity: 0;
        transform: translateY(20px);
        transition: all 0.3s ease;
        z-index: 1000;
        max-width: 300px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }
    
    .toast.show {
        opacity: 1;
        transform: translateY(0);
    }
    
    .toast.success {
        background-color: var(--primary-color);
    }
    
    .toast.error {
        background-color: var(--accent-color);
    }
    
    /* Form validation */
    input:invalid, select:invalid, textarea:invalid {
        border-color: #F8D7DA;
    }
    
    input:focus:invalid, select:focus:invalid, textarea:focus:invalid {
        box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.25);
    }
    
    /* Print styles */
    @media print {
        header, footer, .no-print {
            display: none !important;
        }
        
        body {
            padding: 20px;
            font-size: 12px;
        }
        
        .card {
            box-shadow: none;
            border: 1px solid #ddd;
        }
    }
</style>
</body>
</html>
