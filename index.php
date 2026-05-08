<?php
// Force redirect with status code 302
header('Location: carwash/', true, 302);
// Force disable caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Redirecting to WashHub...</title>
    <meta http-equiv="refresh" content="0;url=carwash/" />
    <script>
        // JavaScript redirect as fallback
        window.location.replace('carwash/');
    </script>
</head>
<body>
    <p>Redirecting to <a href="carwash/">WashHub</a>...</p>
</body>
</html>
