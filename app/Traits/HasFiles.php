<?php

namespace App\Models\Traits;

use App\Models\File;

trait HasFiles
{
    /**
     * Relacionamento de arquivos baseado em entity_name/entity_id
     */
    public function files()
    {
        return $this->hasMany(File::class, 'entity_id')
            ->where('entity_name', strtolower(class_basename($this)));
    }

    /**
     * Busca o primeiro arquivo de um tipo (logo, background, avatar...)
     */
    public function getFile(string $type)
    {
        return $this->files()->where('type', $type)->first();
    }

    /**
     * Retorna todos os arquivos no formato public_url
     */
    public function getFilesUrls()
    {
        return $this->files->pluck('public_url')->filter()->values();
    }
}
