<?php

namespace App\Models\Traits;

use App\Support\ApplicationContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Opt-in application isolation for models that own an app_id column.
 *
 * Legacy requests are unchanged because ApplicationContext is empty there.
 * /api/v1 requests resolve the context before controllers run, so every query
 * on an opted-in model is automatically constrained to the current app.
 */
trait BelongsToApplicationContext
{
    public static function bootBelongsToApplicationContext(): void
    {
        static::addGlobalScope('application_context', function (Builder $builder): void {
            $container = app();
            if (! $container->bound(ApplicationContext::class)) {
                return;
            }

            $context = $container->make(ApplicationContext::class);
            if (! $context->has()) {
                return;
            }

            $builder->where($builder->qualifyColumn('app_id'), $context->id());
        });

        static::creating(function ($model): void {
            $context = app(ApplicationContext::class);
            if ($context->has() && empty($model->app_id)) {
                $model->app_id = $context->id();
            }
        });
    }
}
