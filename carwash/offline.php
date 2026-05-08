<?php include 'includes/header.php'; ?>

    <div class="offline-container">
        <div class="offline-icon">
            <i class="fas fa-wifi-slash"></i>
        </div>
        <h2>You're Offline</h2>
        <p class="text-muted">We can't connect to the internet right now. Please check your connection and try again.</p>
        <button class="btn btn-primary mt-3" onclick="window.location.reload()">
            <i class="fas fa-sync-alt me-2"></i> Try Again
        </button>
    </div>
<?php include 'includes/footer.php'; ?>
