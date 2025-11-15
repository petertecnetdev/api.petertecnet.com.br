<?php

namespace App\Traits;

use App\Models\File;

trait HasFiles
{
    public function files()
    {
        return $this->morphMany(
            File::class,
            'entity',
            'entity_name',
            'entity_id'
        )
        ->orderBy('is_primary', 'desc')
        ->orderBy('sort_order')
        ->orderBy('position')
        ->orderBy('id');
    }

    public function primaryImage()
    {
        return $this->files()->where('is_primary', true)->first();
    }

    public function logoImage()
    {
        return $this->files()->where('type', 'logo')->first();
    }

    public function backgroundImage()
    {
        return $this->files()->where('type', 'background')->first();
    }

    public function galleryImages()
    {
        return $this->files()->where('type', 'gallery')->get();
    }

    public function avatarImage()
    {
        return $this->files()->where('type', 'avatar')->first();
    }
}
