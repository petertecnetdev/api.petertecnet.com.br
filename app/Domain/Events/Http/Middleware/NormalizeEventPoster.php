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
    private const RATIO_TOLERANCE = 0.005;

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

        $source = Image::make($path)->orientate();
        $width = (int) $source->width();
        $height = (int) $source->height();
        $expectedRatio = self::WIDTH / self::HEIGHT;
        $actualRatio = $height > 0 ? $width / $height : 0;

        if ($width < 1 || $height < 1 || abs($actualRatio - $expectedRatio) > self::RATIO_TOLERANCE) {
            throw ValidationException::withMessages([
                'image' => ['A imagem principal do evento deve estar no formato vertical 2:3. Use 1024 × 1536 px.'],
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
