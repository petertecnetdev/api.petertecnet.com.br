<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Platform\Services\ApplicationAdminService;
use App\Models\Application;
use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Explicit, one-at-a-time classification for old institutional records.
 * Never infer curatorial status from a NULL marker, the institution's e-mail
 * string, or the record's presence in an import.
 */
final class ClassifyCuratedListing extends Command
{
    protected $signature = 'independence:classify-curated-listing
        {application : Application slug}
        {type : production or event}
        {id : Existing entity ID}
        {--reviewer= : Verified application moderator user ID}
        {--source-url= : Public HTTPS source confirming the identity of this listing}
        {--reason= : Human classification justification}
        {--apply : Perform the reviewed change; otherwise print a dry run}';

    protected $description = 'Classifica uma ficha curatorial após conferência humana, sem assumir titularidade do organizador.';

    public function handle(ApplicationAdminService $administrators): int
    {
        $type = (string) $this->argument('type');
        $id = (int) $this->argument('id');
        $reviewerId = (int) $this->option('reviewer');
        $sourceUrl = trim((string) $this->option('source-url'));
        $reason = trim((string) $this->option('reason'));

        $host = parse_url($sourceUrl, PHP_URL_HOST);
        $host = is_string($host) ? strtolower(rtrim($host, '.')) : '';
        if (! in_array($type, ['event', 'production'], true) || $id < 1 || $reviewerId < 1
            || strlen($reason) < 20 || strlen($reason) > 2000 || strlen($sourceUrl) > 2048
            || ! str_starts_with($sourceUrl, 'https://') || ! filter_var($sourceUrl, FILTER_VALIDATE_URL)
            || $host === '' || $host === 'localhost' || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal') || filter_var($host, FILTER_VALIDATE_IP)) {
            $this->error('Requer tipo, IDs, motivo detalhado e fonte HTTPS pública válida.');
            return self::FAILURE;
        }

        $app = Application::query()->where('slug', $this->argument('application'))->first();
        $reviewer = User::query()->find($reviewerId);
        if (! $app || ! $reviewer || ! $administrators->hasPermission($reviewer, (int) $app->id, 'moderation.manage')) {
            $this->error('Aplicação ou revisor não autorizado.');
            return self::FAILURE;
        }

        try {
            $inspect = function (bool $forUpdate = false) use ($administrators, $app, $id, $type): array {
                $query = ($type === 'event' ? Event::query() : Production::query())
                    ->where('app_id', $app->id);
                // O preflight não bloqueia; a mutação trava a mesma linha até
                // persistir classificação e auditoria, evitando revisão dupla.
                if ($forUpdate) {
                    $query->lockForUpdate();
                }
                $entity = $query->findOrFail($id);

                $institutionalAuthorId = $type === 'event'
                    ? (int) $entity->created_by_user_id : (int) $entity->user_id;
                $author = User::query()->find($institutionalAuthorId);
                abort_unless($author
                    && $administrators->hasPermission($author, (int) $app->id, 'moderation.manage'),
                    409, 'A identidade de autoria não tem perfil institucional de curadoria.');

                abort_unless($entity->ownership_status === null, 409,
                    'Não altere fichas já classificadas ou verificadas.');

                if ($type === 'event') {
                    abort_unless($entity->production_id && Production::query()
                        ->where('app_id', $app->id)
                        ->whereKey($entity->production_id)
                        ->where('user_id', $institutionalAuthorId)->exists(),
                        409, 'Evento sem produção institucional vinculada nesta aplicação.');
                    abort_unless(! $entity->tickets()->whereHas('passes')->exists(),
                        409, 'Evento já tem ingressos emitidos; classifique após análise comercial específica.');
                } else {
                    abort_unless(! $entity->events()->whereHas('tickets.passes')->exists(),
                        409, 'A produção já possui eventos com ingressos emitidos; revisão comercial obrigatória.');
                }

                return [$entity, $institutionalAuthorId];
            };

            [$entity, $authorId] = $inspect();
            if (! $this->option('apply')) {
                $this->info("DRY-RUN: {$type} #{$id}, app={$app->slug}, autor={$authorId}; nenhuma alteração aplicada.");
                return self::SUCCESS;
            }

            DB::transaction(function () use ($app, $type, $id, $reviewerId, $sourceUrl, $reason, $inspect): void {
                [$entity, $authorId] = $inspect(true);
                $entity->forceFill([
                    'ownership_status' => 'unclaimed',
                    'listing_source' => 'curated_public',
                    'source_url' => $sourceUrl,
                    'last_verified_at' => now(),
                    'published_by_user_id' => $authorId,
                    'commerce_authorized_at' => null,
                ])->saveOrFail();

                DB::table('independence_listing_classification_audits')->insert([
                    'app_id' => $app->id,
                    'entity_type' => $type,
                    'entity_id' => $id,
                    'actor_user_id' => $reviewerId,
                    'institutional_author_id' => $authorId,
                    'source_url' => $sourceUrl,
                    'review_note' => $reason,
                    'created_at' => now(),
                ]);
            }, 3);

            $this->info("Ficha {$type} #{$id} classificada como não reivindicada, sem autorização comercial.");
            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('Classificação bloqueada: '.$error->getMessage());
            return self::FAILURE;
        }
    }
}
