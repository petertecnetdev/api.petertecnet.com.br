<?php

namespace App\Models\Traits;

use App\Models\File;

trait HasFiles
{
    protected function getEntityName()
    {
        return strtolower(class_basename($this)); 
        // "establishment", "item", "employer"
    }

    public function files()
    {
        return $this->hasMany(File::class, 'entity_id')
            ->where('entity_name', $this->getEntityName());
    }

    public function getFile(string $type)
    {
        return $this->files()->where('type', $type)->first();
    }

    public function getFilesUrls()
    {
        return $this->files->map(fn($file) => $file->public_url)->toArray();
    }

    public function storeFile($uploadedFile, string $type)
    {
        if (!$uploadedFile) return null;

        $filename = uniqid() . '_' . time() . '.' . $uploadedFile->getClientOriginalExtension();
        $path = $uploadedFile->storeAs('uploads', $filename, 'public');

        $this->files()->where('type', $type)->delete();

        return $this->files()->create([
            'entity_name' => $this->getEntityName(),
            'type' => $type,
            'path' => $path,
        ]);
    }

    public function deleteFile(string $type)
    {
        return $this->files()->where('type', $type)->delete();
    }
}
