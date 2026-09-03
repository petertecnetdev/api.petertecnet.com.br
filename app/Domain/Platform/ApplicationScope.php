<?php

namespace App\Domain\Platform;

use App\Models\Application;
use App\Services\ApplicationContextService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ApplicationScope
{
    public function __construct(private ApplicationContextService $context) {}

    public function current(?Request $request = null, mixed $resource = null, array $content = []): Application
    {
        $request ??= request();
        $application = $this->context->resolve($request, $resource, $content);

        if (! $application) {
            $boundSlug = trim((string) $request->attributes->get('peter.application_slug', ''));
            if ($boundSlug !== '') {
                $application = Application::query()
                    ->whereRaw('LOWER(slug) = ?', [mb_strtolower($boundSlug)])
                    ->where('is_active', true)
                    ->first();
            }
        }

        if (! $application) {
            throw new HttpException(422, 'Não foi possível determinar o contexto da aplicação para esta operação.');
        }

        if (! $application->is_active) {
            throw new HttpException(503, 'A aplicação informada não está disponível.');
        }

        return $application;
    }

    public function id(?Request $request = null, mixed $resource = null, array $content = []): int
    {
        return (int) $this->current($request, $resource, $content)->id;
    }

    public function slug(?Request $request = null, mixed $resource = null, array $content = []): string
    {
        return (string) $this->current($request, $resource, $content)->slug;
    }

    public function assertBelongsToCurrentApplication(mixed $resource, ?Request $request = null): void
    {
        $application = $this->current($request, $resource);
        $resourceAppId = data_get($resource, 'app_id') ?? data_get($resource, 'application_id');

        if (! is_numeric($resourceAppId) || (int) $resourceAppId !== (int) $application->id) {
            throw new HttpException(404, 'Recurso não encontrado neste contexto de aplicação.');
        }
    }
}
