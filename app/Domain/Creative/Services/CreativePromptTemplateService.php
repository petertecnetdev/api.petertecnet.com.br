<?php

namespace App\Domain\Creative\Services;

use App\Domain\Creative\Models\CreativePromptTemplate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class CreativePromptTemplateService
{
    public const EVENT_FLYER_BACKGROUND = 'event_flyer_background';

    private const LABELS = [
        self::EVENT_FLYER_BACKGROUND => 'Imagem de fundo de evento',
    ];

    private const EVENT_VARIABLES = [
        'subject' => 'Nome/título do evento',
        'description' => 'Descrição do evento sem prefixo',
        'category' => 'Categoria do evento',
        'production_name' => 'Nome da produção/estabelecimento',
        'venue' => 'Local do evento',
        'city' => 'Cidade',
        'uf' => 'UF',
        'style' => 'Direção visual já expandida pela plataforma',
        'format' => 'Composição/formato já expandido pela plataforma',
        'description_clause' => 'Frase pronta com categoria e conceito do evento',
        'context_clause' => 'Frase pronta com produção, local, cidade e UF',
    ];

    public function supports(string $key): bool
    {
        return array_key_exists($key, self::LABELS);
    }

    public function definition(string $key): array
    {
        $this->assertSupported($key);
        $record = $this->customRecord($key);
        $default = $this->defaultTemplate($key);

        return [
            'key' => $key,
            'label' => self::LABELS[$key],
            'template' => $record?->template ?: $default,
            'default_template' => $default,
            'is_custom' => (bool) $record,
            'version' => $record?->version ?? 0,
            'updated_at' => $record?->updated_at?->toIso8601String(),
            'updated_by' => $record?->updated_by,
            'variables' => $this->variables($key),
        ];
    }

    public function save(string $key, string $template, int $actorId): CreativePromptTemplate
    {
        $this->assertSupported($key);
        $this->assertStorageAvailable();

        $template = $this->normalizeTemplate($template);
        $this->validateTemplate($key, $template);

        $record = CreativePromptTemplate::query()->firstOrNew(['template_key' => $key]);
        $record->label = self::LABELS[$key];
        $record->template = $template;
        $record->version = $record->exists ? ((int) $record->version + 1) : 1;
        $record->is_active = true;
        $record->updated_by = $actorId;
        $record->save();

        return $record->fresh();
    }

    public function reset(string $key): ?CreativePromptTemplate
    {
        $this->assertSupported($key);
        if (! Schema::hasTable('creative_prompt_templates')) {
            return null;
        }

        $record = CreativePromptTemplate::query()->where('template_key', $key)->first();
        if ($record) {
            $record->delete();
        }

        return $record;
    }

    public function renderEventFlyer(array $data): string
    {
        $definition = $this->definition(self::EVENT_FLYER_BACKGROUND);

        $style = match ($data['style'] ?? 'neon') {
            'premium' => 'luxury nightlife, refined cinematic lighting, elegant dark atmosphere, premium gold highlights',
            'sunset' => 'energetic nightlife, warm magenta and orange lights, vibrant festival atmosphere, high energy',
            'clean' => 'modern minimal event branding, sophisticated dark blue atmosphere, clean geometric lighting, editorial look',
            default => 'futuristic nightlife, electric neon magenta and cyan lighting, immersive club atmosphere, cinematic depth',
        };

        $format = match ($data['format'] ?? 'cover') {
            'story' => 'vertical composition with strong depth and clean negative space in the center and lower third',
            'post', 'portrait' => 'portrait social media composition with strong focal depth and generous clean space for typography',
            'square' => 'square social media composition with a strong central focal point and safe typography areas',
            default => 'wide cinematic composition with generous clean negative space for typography',
        };

        $descriptionParts = array_filter([
            ! empty($data['category']) ? 'Event category: '.trim((string) $data['category']) : null,
            ! empty($data['description']) ? 'Event concept: '.trim((string) $data['description']) : null,
        ]);

        $context = array_filter([
            $data['production_name'] ?? null,
            $data['venue'] ?? null,
            trim(($data['city'] ?? '').' '.($data['uf'] ?? '')) ?: null,
        ]);

        $variables = [
            'subject' => trim((string) ($data['subject'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'category' => trim((string) ($data['category'] ?? '')),
            'production_name' => trim((string) ($data['production_name'] ?? '')),
            'venue' => trim((string) ($data['venue'] ?? '')),
            'city' => trim((string) ($data['city'] ?? '')),
            'uf' => strtoupper(trim((string) ($data['uf'] ?? ''))),
            'style' => $style,
            'format' => $format,
            'description_clause' => implode('. ', $descriptionParts),
            'context_clause' => $context ? 'Venue/producer context: '.implode(', ', $context) : '',
        ];

        $replacements = [];
        foreach ($variables as $name => $value) {
            $replacements['{{'.$name.'}}'] = $value;
        }

        $rendered = strtr((string) $definition['template'], $replacements);
        $rendered = preg_replace('/\s+/u', ' ', $rendered) ?: $rendered;

        return trim($rendered);
    }

    public function variables(string $key): array
    {
        $this->assertSupported($key);

        return collect(self::EVENT_VARIABLES)
            ->map(fn (string $description, string $name): array => [
                'key' => $name,
                'token' => '{{'.$name.'}}',
                'description' => $description,
            ])
            ->values()
            ->all();
    }

    private function defaultTemplate(string $key): string
    {
        $this->assertSupported($key);

        return <<<'PROMPT'
Create a professional promotional background artwork for a real event called "{{subject}}".
{{description_clause}}
{{context_clause}}
Visual direction: {{style}}.
Composition: {{format}}.
High-end commercial event advertising aesthetic, realistic lighting, visually striking, polished, no borders.
IMPORTANT: background artwork only. Do not render any words, letters, dates, prices, logos, watermarks, signs or readable text. Leave safe areas for the application to add exact event information afterward.
PROMPT;
    }

    private function customRecord(string $key): ?CreativePromptTemplate
    {
        if (! Schema::hasTable('creative_prompt_templates')) {
            return null;
        }

        return CreativePromptTemplate::query()
            ->where('template_key', $key)
            ->where('is_active', true)
            ->first();
    }

    private function normalizeTemplate(string $template): string
    {
        $template = trim(str_replace(["\r\n", "\r"], "\n", $template));

        return preg_replace_callback(
            '/{{\s*([a-zA-Z0-9_]+)\s*}}/',
            static fn (array $matches): string => '{{'.$matches[1].'}}',
            $template
        ) ?: $template;
    }

    private function validateTemplate(string $key, string $template): void
    {
        if (mb_strlen($template) < 120) {
            throw ValidationException::withMessages([
                'template' => ['O prompt precisa ter pelo menos 120 caracteres para manter contexto e qualidade visual.'],
            ]);
        }

        if (mb_strlen($template) > 1600) {
            throw ValidationException::withMessages([
                'template' => ['O prompt pode ter no máximo 1600 caracteres para preservar espaço para os dados dinâmicos do evento.'],
            ]);
        }

        preg_match_all('/{{([a-zA-Z0-9_]+)}}/', $template, $matches);
        $allowed = array_keys(self::EVENT_VARIABLES);
        $unknown = array_values(array_diff(array_unique($matches[1] ?? []), $allowed));

        if ($unknown) {
            throw ValidationException::withMessages([
                'template' => ['Variáveis não reconhecidas: '.implode(', ', $unknown).'.'],
            ]);
        }

        if (! str_contains($template, '{{subject}}')) {
            throw ValidationException::withMessages([
                'template' => ['O prompt precisa manter a variável {{subject}} para identificar o evento.'],
            ]);
        }
    }

    private function assertSupported(string $key): void
    {
        if (! $this->supports($key)) {
            throw new RuntimeException('Template de prompt não suportado.');
        }
    }

    private function assertStorageAvailable(): void
    {
        if (! Schema::hasTable('creative_prompt_templates')) {
            throw new RuntimeException('A estrutura de prompts ainda não foi migrada no banco de dados.');
        }
    }
}
