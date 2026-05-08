<?php
// Create assets directory if it doesn't exist
if (!file_exists('assets')) {
    mkdir('assets', 0755, true);
}

// Sample icon generation (replace with your actual logo)
$sizes = [192, 512];
$logoPath = '../frontend/assets/image1.jpg'; // Your logo path

foreach ($sizes as $size) {
    $image = imagecreatetruecolor($size, $size);
    $bgColor = imagecolorallocate($image, 78, 115, 223); // Primary color from your theme
    imagefill($image, 0, 0, $bgColor);
    
    // Add text to the icon
    $textColor = imagecolorallocate($image, 255, 255, 255);
    $text = "EW";
    $font = 5; // Default font
    $textWidth = imagefontwidth($font) * strlen($text);
    $textHeight = imagefontheight($font);
    $x = ($size - $textWidth) / 2;
    $y = ($size - $textHeight) / 2;
    imagestring($image, $font, $x, $y, $text, $textColor);
    
    // Save the image
    $outputFile = "assets/icon-{$size}x{$size}.png";
    imagepng($image, $outputFile);
    imagedestroy($image);
    
    echo "Created: $outputFile\n";
}

echo "Icons generated successfully!\n";
?>

<!-- Add this to your admin_header.php -->
<!-- 
<link rel="apple-touch-icon" href="assets/icon-192x192.png">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icon-192x192.png">
<link rel="icon" type="image/png" sizes="512x512" href="assets/icon-512x512.png">
-->
