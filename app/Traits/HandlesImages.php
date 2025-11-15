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
<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

trait HandlesImages
{
    /**
     * Remove uma imagem do disco `public`
     * Espera caminhos como: storage/items/xxx.png
     */
    public function deleteImage($relativePath)
    {
        if (!$relativePath) return;

        // Converte "storage/items/xxx.png" → "public/items/xxx.png"
        $path = str_replace('storage/', '', $relativePath);

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);

            Log::info("🗑️ [IMAGE] Imagem removida", [
                'path' => $relativePath
            ]);
        }
    }

    /**
     * Upload + resize idêntico ao Establishment
     * Salva no disco "public" usando Storage::put
     *
     * @param  UploadedFile $file
     * @param  string $folder ("items", "logos", etc.)
     * @param  int $size
     */
    public function uploadImage($file, $folder = 'items', $size = 250)
    {
        Log::info("📥 [IMAGE] Iniciando upload...", [
            'folder' => $folder,
            'size' => $size,
        ]);

        // Gera nome único
        $name = uniqid($folder . '_') . '.' . $file->getClientOriginalExtension();

        // Caminho completo dentro do disco public
        $path = $folder . '/' . $name;

        // Salva o arquivo original no disco
        Storage::disk('public')->put($path, file_get_contents($file));

        // Agora abre e redimensiona via Intervention
        $fullPath = Storage::disk('public')->path($path);

        Image::make($fullPath)
            ->fit($size, $size)
            ->save();

        Log::info("✅ [IMAGE] Upload concluído", [
            'saved_as' => "storage/" . $path
        ]);

        // Retorna caminho igual Establishment
        return "storage/" . $path;
    }
}
