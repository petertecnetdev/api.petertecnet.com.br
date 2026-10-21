<?php

namespace App\Domain\Events\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Facades\Image;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class NormalizeEventPoster
{
    private const WIDTH = 1024;
    private const HEIGHT = 1536;

    public function handle(Request $request, Closure $next): Response
    {
        $file = $request->file('image');

        if (! $file) {
            return $next($request);
        }

        $path = $file->getRealPath();
        $readable = $file->isValid()
            && is_string($path)
            && $path !== ''
            && is_file($path)
            && is_readable($path);

        if (! $readable) {
            $message = in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'A imagem principal do evento excede o limite permitido. Envie um arquivo de até 5 MB.'
                : 'Não foi possível ler a imagem principal do evento. Envie um JPG, PNG ou WebP válido.';

            throw ValidationException::withMessages([
                'image' => [$message],
            ]);
        }

        // A API é a proteção final para clientes antigos: nunca exigir que a
        // pessoa redimensione a própria foto. O editor web permite ajustar
        // manualmente, e outros clientes recebem recorte central automático.
        if ($file->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'image' => ['A imagem principal deve ter no máximo 5 MB.'],
            ]);
        }

        $dimensions = @getimagesize($path);
        $width = (int) ($dimensions[0] ?? 0);
        $height = (int) ($dimensions[1] ?? 0);
        $mime = (string) ($dimensions['mime'] ?? '');
        if ($width < 1 || $height < 1 || $width > 12000 || $height > 12000
            || ($width * $height) > 60000000
            || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages([
                'image' => ['Não foi possível processar a imagem. Envie JPG, PNG ou WebP válido, até 60 megapixels.'],
            ]);
        }

        try {
            $source = Image::make($path)->orientate();
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'image' => ['Não foi possível ler esta imagem. Envie JPG, PNG ou WebP válido.'],
            ]);
        }

        $posterBinary = (string) $source
            ->fit(self::WIDTH, self::HEIGHT)
            ->encode('webp', 88);

        $response = $next($request);

        if ($response->getStatusCode() >= 400 || ! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);
        $path = (string) data_get($payload, 'event.image', '');

        if ($path === '' || ! str_contains($path, '/events/')) {
            return $response;
        }

        if (! Storage::disk('public')->put($path, $posterBinary)) {
            throw new RuntimeException('Não foi possível finalizar a imagem principal do evento.');
        }

        return $response;
    }
}
