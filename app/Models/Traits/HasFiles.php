<?php

namespace App\Models\Traits;

use App\Models\File;

trait HasFiles
{
    /**
     * Relacionamento correto para sua tabela files
     * (entity_name + entity_id)
     */
    public function files()
    {
        return $this->hasMany(File::class, 'entity_id')
            ->where('entity_name', $this->getEntityName());
    }

    /**
     * Descobre o nome da entidade automaticamente
     * (establishment, item, employer, etc)
     */
    public function getEntityName()
    {
        return $this->entity_name ?? strtolower(class_basename($this));
    }

    public function getFile(string $type)
    {
        return $this->files()->where('type', $type)->first();
    }

    public function storeFile($uploadedFile, string $type)
    {
        if (!$uploadedFile) return null;

        $filename = uniqid() . '_' . time() . '.' . $uploadedFile->getClientOriginalExtension();
        $path = $uploadedFile->storeAs('uploads', $filename, 'public');

        // remove arquivo antigo do mesmo tipo
        $this->files()->where('type', $type)->delete();

        return $this->files()->create([
            'entity_name' => $this->getEntityName(),
            'type' => $type,
            'path' => $path,
        ]);
    }
}
