<?php

namespace App\Models\Traits;

use App\Models\File;

trait HasFiles
{
    /**
     * Relacionamento: este modelo possui vários arquivos.
     * 
     * Tabela: files
     * Campos: model_type, model_id
     */
    public function files()
    {
        return $this->morphMany(File::class, 'model');
    }

    /**
     * Retorna o primeiro arquivo de um dado tipo.
     * Ex: $establishment->getFile('logo')
     */
    public function getFile(string $type)
    {
        return $this->files()->where('type', $type)->first();
    }

    /**
     * Retorna todas URLs dos arquivos associados a este modelo.
     */
    public function getFilesUrls()
    {
        return $this->files->map(fn($file) => $file->url)->toArray();
    }

    /**
     * Salvar ou atualizar um arquivo morfado.
     * Exemplo:
     *   $model->storeFile($request->file('logo'), 'logo');
     */
    public function storeFile($uploadedFile, string $type)
    {
        if (!$uploadedFile) {
            return null;
        }

        // gerar nome único
        $filename = uniqid() . '_' . time() . '.' . $uploadedFile->getClientOriginalExtension();

        // salvar fisicamente
        $path = $uploadedFile->storeAs('uploads', $filename, 'public');

        // remover anterior
        $this->files()->where('type', $type)->delete();

        // criar registro
        return $this->files()->create([
            'type' => $type,
            'path' => $path,
        ]);
    }

    /**
     * Apagar um arquivo do tipo informado.
     */
    public function deleteFile(string $type)
    {
        return $this->files()->where('type', $type)->delete();
    }
}
