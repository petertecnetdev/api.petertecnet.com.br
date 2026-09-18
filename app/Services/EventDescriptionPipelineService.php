<?php

namespace App\Services;

use App\Models\AiContentGeneration;
use App\Models\AiContentState;
use App\Models\User;
use Illuminate\Support\Str;

final class EventDescriptionPipelineService
{
    public const PROMPT_VERSION = 'event-copy-v3.0';

    public function __construct(
        private readonly AiDescriptionService $descriptions,
        private readonly EventDescriptionContextBuilder $contextBuilder,
        private readonly AiDescriptionQualityService $quality,
    ) {}

    public function generate(array $data, User $user): array
    {
        $action = in_array(($data['action'] ?? 'improve'), ['improve', 'rewrite', 'enrich'], true)
            ? (string) $data['action']
            : 'improve';

        $built = $this->contextBuilder->build((array) ($data['context'] ?? []), $user);
        $context = (array) $built['context'];
        $historicalTexts = (array) $built['historical_texts'];
        $entityId = $built['event_id'] ? (int) $built['event_id'] : null;
        $event = $built['event'];

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' && $event) {
            $title = trim((string) $event->title);
        }

        $currentDescription = trim((string) ($data['current_description'] ?? ''));
        if ($currentDescription === '' && $event) {
            $currentDescription = trim((string) $event->description);
        }

        $state = $entityId
            ? AiContentState::query()->firstOrNew([
                'app_id' => (int) ($event?->app_id ?? app(\App\Support\ApplicationContext::class)->id()),
                'entity_type' => 'event',
                'entity_id' => $entityId,
            ])
            : null;

        $userDraft = $this->resolveUserDraft($currentDescription, $state?->user_draft, $state?->latest_ai_text);

        if ($state) {
            $this->observePreviousDecision($state, $currentDescription, $event?->description, $action);

            if ($userDraft !== '') {
                $state->user_draft = $userDraft;
            }
            $state->prompt_version = self::PROMPT_VERSION;
            $state->save();
        }

        $editorialPlan = $this->descriptions->planEventDescription(
            title: $title,
            currentDraft: $userDraft,
            context: $context,
            historicalTexts: $historicalTexts,
        );

        $groupId = (string) Str::uuid();
        $candidatePlans = $this->candidatePlans($action);
        $candidates = [];

        foreach ($candidatePlans as $index => $plan) {
            $candidateContext = $context;
            $candidateContext['generation_action'] = $action;
            $candidateContext['candidate_angle'] = $plan['angle'];
            $candidateContext['editorial_goal'] = $plan['goal'];
            $candidateContext['prompt_version'] = self::PROMPT_VERSION;
            if (is_array($editorialPlan)) {
                $candidateContext['editorial_plan'] = mb_substr(
                    json_encode($editorialPlan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
                    0,
                    1200,
                );
            }

            if ($action === 'rewrite' && $state?->latest_ai_text) {
                $candidateContext['avoid_previous_ai'] = mb_substr((string) $state->latest_ai_text, 0, 500);
            }

            $result = $this->descriptions->generateDescription([
                'entity_type' => 'event',
                'title' => $title,
                'current_description' => $userDraft,
                'context' => $candidateContext,
                'locale' => $data['locale'] ?? 'pt-BR',
                'tone' => $plan['tone'],
            ], $user->getAuthIdentifier());

            $evaluation = $this->quality->evaluate(
                (string) $result['description'],
                $userDraft,
                $context,
                $historicalTexts,
            );

            $generation = $this->recordCandidate(
                groupId: $groupId,
                user: $user,
                entityId: $entityId,
                action: $action,
                candidateIndex: $index + 1,
                draft: $userDraft,
                output: (string) $result['description'],
                model: (string) ($result['model'] ?? ''),
                quality: $evaluation,
                context: $context,
            );

            $candidates[] = [
                'description' => (string) $result['description'],
                'model' => (string) ($result['model'] ?? ''),
                'usage' => (array) ($result['usage'] ?? []),
                'quality' => $evaluation,
                'generation' => $generation,
            ];
        }

        $critic = $this->descriptions->reviewEventCandidates(
            candidates: array_map(fn ($candidate) => $candidate['description'], $candidates),
            currentDraft: $userDraft,
            context: $context,
            historicalTexts: $historicalTexts,
        );

        $selectedIndex = $this->selectCandidateIndex($candidates, $critic);
        $selected = $candidates[$selectedIndex];

        if (! ($selected['quality']['passes'] ?? false)) {
            $retryContext = $context;
            $retryContext['generation_action'] = $action;
            $retryContext['candidate_angle'] = 'revisão final após crítica de qualidade';
            $retryContext['quality_feedback'] = implode('; ', array_slice((array) ($selected['quality']['issues'] ?? []), 0, 6));
            $retryContext['avoid_previous_ai'] = mb_substr((string) $selected['description'], 0, 500);
            $retryContext['prompt_version'] = self::PROMPT_VERSION;
            if (is_array($editorialPlan)) {
                $retryContext['editorial_plan'] = mb_substr(
                    json_encode($editorialPlan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
                    0,
                    1200,
                );
            }

            $retry = $this->descriptions->generateDescription([
                'entity_type' => 'event',
                'title' => $title,
                'current_description' => $userDraft,
                'context' => $retryContext,
                'locale' => $data['locale'] ?? 'pt-BR',
                'tone' => 'editorial, natural, específico, autoral e convincente; corrigir os problemas apontados pela crítica',
            ], $user->getAuthIdentifier());

            $retryQuality = $this->quality->evaluate(
                (string) $retry['description'],
                $userDraft,
                $context,
                $historicalTexts,
            );

            $retryGeneration = $this->recordCandidate(
                groupId: $groupId,
                user: $user,
                entityId: $entityId,
                action: $action,
                candidateIndex: count($candidates) + 1,
                draft: $userDraft,
                output: (string) $retry['description'],
                model: (string) ($retry['model'] ?? ''),
                quality: $retryQuality,
                context: $context,
            );

            if (($retryQuality['score'] ?? 0) > ($selected['quality']['score'] ?? 0)) {
                $selected = [
                    'description' => (string) $retry['description'],
                    'model' => (string) ($retry['model'] ?? ''),
                    'usage' => (array) ($retry['usage'] ?? []),
                    'quality' => $retryQuality,
                    'generation' => $retryGeneration,
                ];
            }
        }

        AiContentGeneration::query()
            ->where('group_id', $groupId)
            ->update(['status' => 'rejected']);

        $selected['generation']->forceFill([
            'status' => 'selected',
            'applied_at' => now(),
            'quality_details' => array_merge(
                (array) $selected['quality'],
                ['critic' => $critic],
            ),
        ])->save();

        if ($state) {
            $state->latest_ai_text = $selected['description'];
            $state->latest_generation_id = $selected['generation']->id;
            $state->prompt_version = self::PROMPT_VERSION;
            $state->metadata = [
                'last_action' => $action,
                'quality_score' => (int) ($selected['quality']['score'] ?? 0),
                'candidate_count' => AiContentGeneration::query()->where('group_id', $groupId)->count(),
                'critic_used' => is_array($critic),
                'planner_used' => is_array($editorialPlan),
            ];
            $state->save();
        }

        return [
            'description' => $selected['description'],
            'mode' => $userDraft !== '' ? 'improve' : 'generate',
            'model' => $selected['model'],
            'usage' => $selected['usage'],
            'generation_id' => $selected['generation']->id,
            'quality' => $selected['quality'],
            'prompt_version' => self::PROMPT_VERSION,
            'candidate_count' => AiContentGeneration::query()->where('group_id', $groupId)->count(),
        ];
    }

    private function observePreviousDecision(
        AiContentState $state,
        string $currentDescription,
        ?string $persistedEventDescription,
        string $newAction,
    ): void {
        $previousId = (int) ($state->latest_generation_id ?? 0);
        if ($previousId <= 0 || trim((string) $state->latest_ai_text) === '') return;

        $previous = AiContentGeneration::query()->find($previousId);
        if (! $previous) return;

        $current = trim($currentDescription);
        $latest = trim((string) $state->latest_ai_text);
        $persisted = trim((string) $persistedEventDescription);

        if ($current !== '' && $this->sameText($current, $latest)) {
            $previous->status = $newAction === 'rewrite' ? 'replaced' : 'accepted';
            if ($persisted !== '' && $this->sameText($persisted, $latest)) {
                $state->accepted_text = $latest;
            }
        } elseif ($current !== '' && ! $this->quality->isLowValue($current)) {
            $previous->status = 'edited';
            $state->accepted_text = $current;
        } else {
            $previous->status = 'discarded';
        }

        $previous->save();
    }

    private function resolveUserDraft(string $current, ?string $storedDraft, ?string $latestAi): string
    {
        $current = trim($current);
        $storedDraft = trim((string) $storedDraft);
        $latestAi = trim((string) $latestAi);

        if ($current !== '' && $latestAi !== '' && $this->sameText($current, $latestAi)) {
            return $storedDraft;
        }

        if ($current === '' || $this->quality->isLowValue($current)) {
            return $storedDraft;
        }

        return $current;
    }

    private function candidatePlans(string $action): array
    {
        $base = match ($action) {
            'rewrite' => 'criar uma versão realmente diferente da anterior, preservando os fatos e a intenção',
            'enrich' => 'aproveitar ao máximo os fatos confirmados do evento sem inventar nada',
            default => 'corrigir o rascunho, preservar sua intenção e desenvolver a ideia com mais qualidade',
        };

        return [
            [
                'angle' => 'narrativa editorial',
                'goal' => $base . '; abertura autoral, ritmo natural e sensação de contexto',
                'tone' => 'editorial, autoral, natural, elegante e convidativo; sem clichês',
            ],
            [
                'angle' => 'comercial natural',
                'goal' => $base . '; valorizar os diferenciais confirmados e facilitar a decisão sem soar como propaganda genérica',
                'tone' => 'comercial sofisticado, claro, natural e convincente; sem exageros',
            ],
            [
                'angle' => 'experiência e contexto',
                'goal' => $base . '; transformar dados objetivos em uma narrativa humana e fácil de ler no celular',
                'tone' => 'envolvente, específico, contemporâneo e fluido; linguagem humana',
            ],
        ];
    }

    private function recordCandidate(
        string $groupId,
        User $user,
        ?int $entityId,
        string $action,
        int $candidateIndex,
        string $draft,
        string $output,
        string $model,
        array $quality,
        array $context,
    ): AiContentGeneration {
        $hashContext = $context;
        ksort($hashContext);

        return AiContentGeneration::query()->create([
            'group_id' => $groupId,
            'app_id' => app(\App\Support\ApplicationContext::class)->id(),
            'user_id' => $user->getAuthIdentifier(),
            'entity_type' => 'event',
            'entity_id' => $entityId,
            'action' => $action,
            'candidate_index' => $candidateIndex,
            'prompt_version' => self::PROMPT_VERSION,
            'model' => $model,
            'draft_before' => $draft !== '' ? $draft : null,
            'output' => $output,
            'quality_score' => (int) ($quality['score'] ?? 0),
            'quality_details' => $quality,
            'status' => 'candidate',
            'context_hash' => hash('sha256', json_encode($hashContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
        ]);
    }

    private function selectCandidateIndex(array $candidates, ?array $critic): int
    {
        $ranked = array_keys($candidates);
        usort($ranked, fn ($a, $b) => ($candidates[$b]['quality']['score'] ?? 0) <=> ($candidates[$a]['quality']['score'] ?? 0));

        if (is_array($critic)) {
            $preferred = (int) ($critic['preferred_index'] ?? -1);
            if (isset($candidates[$preferred])) {
                $quality = $candidates[$preferred]['quality'] ?? [];
                if (($quality['scores']['fidelity'] ?? 0) >= 72 && ($quality['score'] ?? 0) >= 65) {
                    return $preferred;
                }
            }
        }

        return (int) ($ranked[0] ?? 0);
    }

    private function sameText(string $a, string $b): bool
    {
        $normalize = static function (string $value): string {
            $value = mb_strtolower(trim($value));
            return preg_replace('/\s+/u', ' ', $value) ?? $value;
        };

        return $normalize($a) === $normalize($b);
    }
}
