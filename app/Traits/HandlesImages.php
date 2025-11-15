<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;
use Intervention\Image\Facades\Image;

trait HandlesImages
{
    /**
     * Remove uma imagem existente.
     */
    public function deleteImage($relativePath)
    {
        if (!$relativePath) return;

        $fullPath = public_path($relativePath);

        if (file_exists($fullPath)) {
            unlink($fullPath);
            Log::info("🗑️ [IMAGE] Imagem removida", ['path' => $relativePath]);
        }
    }

    /**
     * Faz upload + resize (250x250)
     */
    public function uploadImage($file, $prefix = 'item_', $size = 250)
    {
        Log::info("📥 [IMAGE] Iniciando upload da imagem...");

        $folder = public_path('images');
        if (!is_dir($folder)) {
            mkdir($folder, 0775, true);
        }

        $imageName = uniqid($prefix) . '.' . $file->getClientOriginalExtension();
        $filePath = $folder . '/' . $imageName;

        $file->move($folder, $imageName);

        // RESIZE
        Image::make($filePath)->fit($size, $size)->save();

        Log::info("✅ [IMAGE] Upload concluído", [
            'file' => "images/" . $imageName
        ]);

        return "images/" . $imageName;
    }
}
