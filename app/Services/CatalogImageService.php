<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogImageService
{
    public function store(UploadedFile $file): string
    {
        $path = $file->getPathname();
        $bytes = file_get_contents($path);
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);
        if (! $image) {
            throw ValidationException::withMessages(['image' => 'Cette photo est illisible. Utilisez une image JPG, PNG ou WebP.']);
        }
        try {
            if ($file->getMimeType() === 'image/jpeg' && function_exists('exif_read_data')) {
                $exif = @exif_read_data($path);
                $orientation = $exif === false ? 1 : ($exif['Orientation'] ?? 1);
                if (in_array($orientation, [2, 4, 5, 7], true)) {
                    imageflip($image, in_array($orientation, [2, 5, 7], true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
                }
                $angle = match ($orientation) {
                    3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0
                };
                if ($angle) {
                    $rotated = imagerotate($image, $angle, 0);
                    if ($rotated !== false) {
                        imagedestroy($image);
                        $image = $rotated;
                    }
                }
            }
            $ratio = min(1, 1600 / max(imagesx($image), imagesy($image)));
            $output = imagecreatetruecolor(max(1, (int) round(imagesx($image) * $ratio)), max(1, (int) round(imagesy($image) * $ratio)));
            imagealphablending($output, false);
            imagesavealpha($output, true);
            imagecopyresampled($output, $image, 0, 0, 0, 0, imagesx($output), imagesy($output), imagesx($image), imagesy($image));
            ob_start();
            imagewebp($output, null, 82);
            $bytes = ob_get_clean();
            imagedestroy($output);
            $destination = 'catalog/'.Str::uuid().'.webp';
            if (! Storage::disk('public')->put($destination, $bytes)) {
                throw ValidationException::withMessages(['image' => 'Impossible d’enregistrer cette photo. Réessayez.']);
            }

            return $destination;
        } finally {
            imagedestroy($image);
        }
    }
}
