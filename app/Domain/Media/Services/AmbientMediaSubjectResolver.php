<?php

namespace App\Domain\Media\Services;

use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Database\Eloquent\Model;

final class AmbientMediaSubjectResolver
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function resolve(string $subjectType, int $subjectId): Model
    {
        return match ($subjectType) {
            'organization' => Production::query()
                ->where('app_id', $this->context->id())
                ->findOrFail($subjectId),
            'event' => Event::query()
                ->where('app_id', $this->context->id())
                ->with('production:id,app_id,user_id,is_published,is_cancelled')
                ->findOrFail($subjectId),
            default => abort(404, 'Tipo de conteúdo não suportado.'),
        };
    }

    public function resolvePublic(string $subjectType, int $subjectId): Model
    {
        $subject = $this->resolve($subjectType, $subjectId);

        if ($subject instanceof Production) {
            abort_unless($subject->is_published && ! $subject->is_cancelled, 404, 'Organização não encontrada.');
            return $subject;
        }

        if ($subject instanceof Event) {
            abort_unless(
                $subject->is_published && ! $subject->is_cancelled && ! $subject->is_private
                && $subject->production && $subject->production->is_published && ! $subject->production->is_cancelled,
                404,
                'Evento não encontrado.'
            );
        }

        return $subject;
    }

    public function resolveOwned(string $subjectType, int $subjectId, User $user): Model
    {
        $subject = $this->resolve($subjectType, $subjectId);
        $admin = method_exists($user, 'hasProfile') && $user->hasProfile('Administrador');
        $ownerId = $subject instanceof Production
            ? $subject->user_id
            : ($subject instanceof Event ? $subject->production?->user_id : null);

        abort_unless($admin || (int) $ownerId === (int) $user->id, 403, 'Você não pode gerenciar a trilha deste conteúdo.');

        return $subject;
    }
}
