<?php
$source = 'frontend/logo.png';
$destination = 'frontend/logo.png'; // Overwrite

if (!file_exists($source)) {
    die("File not found.");
}

$img = imagecreatefromstring(file_get_contents($source));
$width = imagesx($img);
$height = imagesy($img);

$newImg = imagecreatetruecolor($width, $height);
imagealphablending($newImg, false);
imagesavealpha($newImg, true);

$transparent = imagecolorallocatealpha($newImg, 255, 255, 255, 127);
imagefilledrectangle($newImg, 0, 0, $width, $height, $transparent);

for ($x = 0; $x < $width; $x++) {
    for ($y = 0; $y < $height; $y++) {
        $rgb = imagecolorat($img, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        
        // Remove white and near-white backgrounds gracefully
        if ($r > 240 && $g > 240 && $b > 240) {
            imagesetpixel($newImg, $x, $y, $transparent);
        } else {
            $color = imagecolorallocatealpha($newImg, $r, $g, $b, 0);
            imagesetpixel($newImg, $x, $y, $color);
        }
    }
}
imagepng($newImg, $destination);
echo "Background removed successfully!";
?>
