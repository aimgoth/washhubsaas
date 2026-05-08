<?php
$source = 'frontend/logo.png';
$destination = 'frontend/logo.png';

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
        $colors = imagecolorsforindex($img, $rgb);
        $r = $colors['red'];
        $g = $colors['green'];
        $b = $colors['blue'];
        
        // Target BOTH the white squares AND the light-grey checkerboard squares!
        // The grey squares are typically RGB(204,204,204) or RGB(229,229,229).
        // The logo colors are Cyan (R=0) and Navy (R=27), so any pixel with Red > 200 is definitely fake background!
        
        $is_grayscale = abs($r - $g) < 15 && abs($g - $b) < 15 && abs($r - $b) < 15;
        
        if ($is_grayscale && $r > 190 && $g > 190 && $b > 190) {
            // It's the fake checkerboard background! Make it transparent.
            imagesetpixel($newImg, $x, $y, $transparent);
        } else {
            // Preserve the actual logo
            $color = imagecolorallocatealpha($newImg, $r, $g, $b, 0);
            imagesetpixel($newImg, $x, $y, $color);
        }
    }
}

imagepng($newImg, $destination);
imagedestroy($img);
imagedestroy($newImg);

echo "Fake checkerboard background completely annihilated!";
?>
