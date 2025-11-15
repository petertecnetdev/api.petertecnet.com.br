<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;
use Intervention\Image\Facades\Image;

trait HandlesImages
{
    /**
     * Remove imagem antiga.
     */
    public function deleteImage($relativePath)
    {
        if (!$relativePath) return;

        $fullPath = public_path($relativePath);

        if (file_exists($fullPath)) {
            unlink($fullPath);

            Log::info("🗑️ [IMAGE] Imagem removida", [
                'path' => $relativePath
            ]);
        }
    }

    /**
     * Upload + resize genérico.
     *
     * $prefix = item_, employer_, establishment_
     * $size = 250 (item), 150 (logo), 1920x600 (background) -> mas pode ser alterado
     */
    public function uploadImage($file, $prefix = 'item_', $size = 250, $height = null)
    {
        Log::info("📥 [IMAGE] Iniciando upload da imagem...");

        $folder = public_path('images');

        // cria pasta se não existir
        if (!is_dir($folder)) {
            mkdir($folder, 0775, true);
        }

        // nome da imagem
        $imageName = uniqid($prefix) . '.' . $file->getClientOriginalExtension();
        $fullPath = $folder . '/' . $imageName;

        // move arquivo
        $file->move($folder, $imageName);

        // resize
        if ($height) {
            // exemplo: background 1920x600
            Image::make($fullPath)->fit($size, $height)->save();
        } else {
            // resize padrão quadrado: 250x250 ou outro passado
            Image::make($fullPath)->fit($size, $size)->save();
        }

        Log::info("✅ [IMAGE] Upload concluído", [
            'file' => "images/" . $imageName
        ]);

        return "images/" . $imageName;
    }

    /**
     * Remove imagem se `remove_image = 1`.
     */
    public function handleRemoveImage($request)
    {
        if ($request->remove_image == 1 && $this->image) {

            $this->deleteImage($this->image);

            $this->image = null;
        }
    }

    /**
     * Faz upload de uma nova imagem, removendo a antiga.
     */
    public function handleUploadNewImage($request, $prefix = 'item_', $size = 250, $height = null)
    {
        if ($request->hasFile('image')) {

            // remove anterior
            if ($this->image) {
                $this->deleteImage($this->image);
            }

            // faz upload
            $this->image = $this->uploadImage(
                $request->file('image'),
                $prefix,
                $size,
                $height
            );
        }
    }
}
