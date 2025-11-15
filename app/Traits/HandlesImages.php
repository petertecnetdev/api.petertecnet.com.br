<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

trait HandlesImages
{
    /**
     * Remove uma imagem do disco public/
     * Ex: storage/items/abc.png → public/items/abc.png
     */
    public function deleteImage($relativePath)
    {
        if (!$relativePath) {
            return;
        }

        $cleanPath = str_replace('storage/', '', $relativePath);

        if (Storage::disk('public')->exists($cleanPath)) {
            Storage::disk('public')->delete($cleanPath);

            Log::info("🗑️ [IMAGE] Removida com sucesso", [
                'path' => $relativePath
            ]);
        }
    }

    /**
     * Upload + resize igual ao Establishment
     * Salva em: storage/app/public/{folder}/arquivo.png
     * Retorna:  storage/{folder}/arquivo.png
     */
    public function uploadImage($file, $folder = 'items', $size = 250)
    {
        Log::info("📤 [IMAGE] Iniciando upload...", [
            'folder' => $folder,
            'size'   => $size
        ]);

        $extension = $file->getClientOriginalExtension();
        $fileName  = uniqid($folder . '_') . '.' . $extension;
        $path      = $folder . '/' . $fileName;

        // Salva o arquivo bruto
        Storage::disk('public')->put($path, file_get_contents($file));

        // Redimensiona
        $absolute = Storage::disk('public')->path($path);

        Image::make($absolute)
            ->fit($size, $size)
            ->save();

        Log::info("✅ [IMAGE] Upload finalizado", [
            'saved_as' => "storage/" . $path
        ]);

        return "storage/" . $path;
    }
}
