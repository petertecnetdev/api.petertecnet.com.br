<?php

namespace App\Models\Traits;

use App\Support\ApplicationContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Opt-in application isolation.
 *
 * Models using `app_id` need no configuration. Platform-native models using
 * `application_id` override applicationContextColumn(). Legacy requests remain
 * unaffected because ApplicationContext is empty outside scoped v1 routes.
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

            $model = $builder->getModel();
            $column = method_exists($model, 'applicationContextColumn')
                ? $model->applicationContextColumn()
                : 'app_id';

            $builder->where($builder->qualifyColumn($column), $context->id());
        });

        static::creating(function ($model): void {
            $context = app(ApplicationContext::class);
            if (! $context->has()) {
                return;
            }

            $column = method_exists($model, 'applicationContextColumn')
                ? $model->applicationContextColumn()
                : 'app_id';

            if (empty($model->{$column})) {
                $model->{$column} = $context->id();
            }
        });
    }

    protected function applicationContextColumn(): string
    {
        return 'app_id';
    }
}
