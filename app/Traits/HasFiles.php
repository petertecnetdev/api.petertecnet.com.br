<?php

namespace App\Traits;

use App\Models\File;

trait HasFiles
{
    /**
     * Relacionamento padrão:
     * Toda entidade pode ter vários arquivos associados.
     * Usa entity_name e entity_id (padrão Rasoio).
     */
    public function files()
    {
        return $this->hasMany(File::class, 'entity_id')
            ->where('entity_name', $this->getEntityName());
    }

    /**
     * Retorna o nome da entidade baseado no Model.
     */
    protected function getEntityName()
    {
        return strtolower(class_basename($this)); 
        // establishment, employer, item, user, etc.
    }

    /**
     * Pega o primeiro arquivo de um tipo.
     * Ex: logo, background, avatar, gallery, etc.
     */
    public function file(string $type)
    {
        return $this->files()->where('type', $type)->first();
    }

    /**
     * Retorna a URL pública de um tipo de arquivo.
     */
    public function fileUrl(string $type)
    {
        return $this->file($type)?->public_url;
    }

    /**
     * Deleta arquivos do tipo especificado.
     */
    public function deleteFilesOfType(string $type)
    {
        return $this->files()->where('type', $type)->delete();
    }

    /**
     * Cria um arquivo para esta entidade (já processado pela FileController).
     */
    public function attachFile(array $data)
    {
        $data['entity_id'] = $this->id;
        $data['entity_name'] = $this->getEntityName();
        return File::create($data);
    }

    /**
     * Retorna a lista de imagens para galeria.
     */
    public function gallery()
    {
        return $this->files()->where('type', 'gallery')->orderBy('sort_order')->get();
    }
}
