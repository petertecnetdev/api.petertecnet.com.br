<?php

namespace App\Traits;

use Intervention\Image\Facades\Image;

trait HandlesImages
{
    protected $basePath = "/home/petert03/api.petertecnet.com.br/public/";

    public function deleteImage($relativePath)
    {
        if (!$relativePath) return;

        $full = $this->basePath . $relativePath;

        if (file_exists($full)) {
            unlink($full);
        }
    }

    public function uploadImage($file)
    {
        $destination = $this->basePath . "images/";
        $imageName = uniqid("item_") . "." . $file->getClientOriginalExtension();

        $file->move($destination, $imageName);

        $path = $destination . $imageName;

        $img = Image::make($path)->fit(250, 250);
        $img->save($path);

        return "images/" . $imageName;
    }
}
