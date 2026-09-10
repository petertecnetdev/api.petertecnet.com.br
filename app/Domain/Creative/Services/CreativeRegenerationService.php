<?php

namespace App\Domain\Creative\Services;

final class CreativeRegenerationService
{
    private const MODIFIERS = [
        'more_premium' => 'Make the visual more premium, exclusive and editorial, with refined lighting and fewer cheap-looking decorative effects.',
        'more_bold' => 'Increase visual impact, scale, energy and contrast while preserving a premium commercial finish.',
        'more_elegant' => 'Make the composition more elegant, sophisticated and restrained, with stronger visual hierarchy and cleaner negative space.',
        'more_party' => 'Increase nightlife energy, crowd atmosphere, motion, light beams and celebratory depth without becoming visually chaotic.',
        'more_realistic' => 'Push toward believable high-end commercial photography, natural anatomy, realistic skin/materials and physically plausible lighting.',
        'change_subject' => 'Change the hero subject/person/pose while keeping the same event identity, color world and overall art direction.',
        'change_scene' => 'Keep the core campaign identity but redesign the environment, background depth and spatial composition.',
        'keep_subject_change_scene' => 'Preserve the hero subject concept while creating a distinctly new setting, lighting pattern and background composition.',
    ];

    public function keys(): array
    {
        return array_keys(self::MODIFIERS);
    }

    public function apply(string $prompt, ?string $modifier): string
    {
        if (! $modifier || ! isset(self::MODIFIERS[$modifier])) {
            return $prompt;
        }

        return trim($prompt."\nREGENERATION REQUEST: ".self::MODIFIERS[$modifier]);
    }
}
