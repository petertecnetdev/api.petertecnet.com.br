<?php

namespace App\Domain\Creative\Services;

use App\Domain\Creative\Models\CreativePromptTemplate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class CreativePromptTemplateService
{
    public const EVENT_FLYER_BACKGROUND = 'event_flyer_background';
    public const EVENT_DESCRIPTION = 'event_description';

    private const LABELS = [
        self::EVENT_FLYER_BACKGROUND => 'Imagem de fundo de evento',
        self::EVENT_DESCRIPTION => 'Descrição de evento',
    ];

    private const EVENT_FLYER_VARIABLES = [
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

    private const EVENT_DESCRIPTION_VARIABLES = [
        'subject' => 'Nome/título do evento',
        'current_description' => 'Descrição atual, quando existir',
        'category' => 'Categoria do evento',
        'production_name' => 'Nome da produção/estabelecimento',
        'venue' => 'Local do evento',
        'city' => 'Cidade',
        'uf' => 'UF',
        'start_date' => 'Data e horário de início informados no formulário',
        'end_date' => 'Data e horário de término informados no formulário',
        'audience' => 'Público informado pelo produtor',
        'tone' => 'Tom editorial já expandido pela plataforma',
        'context_clause' => 'Bloco com fatos disponíveis do evento',
        'current_description_clause' => 'Orientação para melhorar a descrição atual, quando existir',
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

        return $this->render($definition['template'], [
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
        ]);
    }

    public function renderEventDescription(array $data): string
    {
        $definition = $this->definition(self::EVENT_DESCRIPTION);
        $tone = match ($data['tone'] ?? 'engaging') {
            'premium' => 'sofisticado, elegante e convidativo, sem exageros publicitários',
            'casual' => 'leve, próximo e natural, com linguagem simples',
            'family' => 'acolhedor, claro e apropriado para público amplo',
            'corporate' => 'profissional, objetivo e confiável',
            default => 'envolvente, claro e persuasivo, sem promessas que não estejam nos dados',
        };

        $facts = array_filter([
            ! empty($data['category']) ? 'Categoria: '.trim((string) $data['category']) : null,
            ! empty($data['production_name']) ? 'Produção: '.trim((string) $data['production_name']) : null,
            ! empty($data['venue']) ? 'Local: '.trim((string) $data['venue']) : null,
            ! empty($data['city']) ? 'Cidade/UF: '.trim((string) $data['city']).(! empty($data['uf']) ? '/'.strtoupper(trim((string) $data['uf'])) : '') : null,
            ! empty($data['start_date']) ? 'Início: '.trim((string) $data['start_date']) : null,
            ! empty($data['end_date']) ? 'Término: '.trim((string) $data['end_date']) : null,
            ! empty($data['audience']) ? 'Público/contexto: '.trim((string) $data['audience']) : null,
        ]);

        $currentDescription = trim((string) ($data['current_description'] ?? ''));

        return $this->render($definition['template'], [
            'subject' => trim((string) ($data['subject'] ?? '')),
            'current_description' => $currentDescription,
            'category' => trim((string) ($data['category'] ?? '')),
            'production_name' => trim((string) ($data['production_name'] ?? '')),
            'venue' => trim((string) ($data['venue'] ?? '')),
            'city' => trim((string) ($data['city'] ?? '')),
            'uf' => strtoupper(trim((string) ($data['uf'] ?? ''))),
            'start_date' => trim((string) ($data['start_date'] ?? '')),
            'end_date' => trim((string) ($data['end_date'] ?? '')),
            'audience' => trim((string) ($data['audience'] ?? '')),
            'tone' => $tone,
            'context_clause' => $facts ? "Fatos disponíveis:\n- ".implode("\n- ", $facts) : 'Não há outros fatos confirmados além do nome do evento.',
            'current_description_clause' => $currentDescription !== ''
                ? "Descrição atual fornecida pelo produtor:\n\"{$currentDescription}\"\nUse-a como referência e melhore clareza, apelo e organização, sem inventar fatos."
                : 'Não existe descrição anterior. Crie o texto somente a partir dos fatos confirmados acima.',
        ], false);
    }

    public function variables(string $key): array
    {
        $this->assertSupported($key);

        return collect($this->variableDefinitions($key))
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

        if ($key === self::EVENT_DESCRIPTION) {
            return <<<'PROMPT'
Você é o redator de eventos da Cutinapp. Escreva SOMENTE a descrição final do evento em português do Brasil, pronta para publicação.
Evento: "{{subject}}".
{{context_clause}}
{{current_description_clause}}
Tom: {{tone}}.
Regras obrigatórias: use apenas informações fornecidas; não invente artistas, atrações, horários, preços, benefícios, patrocinadores, endereço, regras, disponibilidade ou qualquer detalhe ausente. Não diga que algo é "imperdível", "o maior" ou similar sem evidência. Se os dados forem poucos, escreva uma descrição mais curta em vez de completar lacunas.
Formato: 2 a 4 parágrafos curtos, leitura fácil no celular, aproximadamente 450 a 900 caracteres quando houver contexto suficiente. Destaque a proposta do evento e os fatos úteis conhecidos. Não use Markdown, títulos, listas, hashtags, emojis nem aspas envolvendo a resposta.
PROMPT;
        }

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
                'template' => ['O prompt precisa ter pelo menos 120 caracteres para manter contexto e qualidade.'],
            ]);
        }

        if (mb_strlen($template) > 1600) {
            throw ValidationException::withMessages([
                'template' => ['O prompt pode ter no máximo 1600 caracteres.'],
            ]);
        }

        preg_match_all('/{{([a-zA-Z0-9_]+)}}/', $template, $matches);
        $allowed = array_keys($this->variableDefinitions($key));
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

    private function variableDefinitions(string $key): array
    {
        return $key === self::EVENT_DESCRIPTION
            ? self::EVENT_DESCRIPTION_VARIABLES
            : self::EVENT_FLYER_VARIABLES;
    }

    private function render(string $template, array $variables, bool $collapseWhitespace = true): string
    {
        $replacements = [];
        foreach ($variables as $name => $value) {
            $replacements['{{'.$name.'}}'] = (string) $value;
        }

        $rendered = strtr($template, $replacements);
        if ($collapseWhitespace) {
            $rendered = preg_replace('/\s+/u', ' ', $rendered) ?: $rendered;
        } else {
            $rendered = preg_replace("/\n{3,}/u", "\n\n", $rendered) ?: $rendered;
        }

        return trim($rendered);
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
