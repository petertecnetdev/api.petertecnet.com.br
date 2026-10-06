<?php

namespace App\Services\WhatsApp;

use InvalidArgumentException;

class WhatsAppTemplateRegistry
{
    public function resolve(string $key, ?string $locale = null): array
    {
        $key = strtoupper(trim($key));
        $entry = (array) config('whatsapp.templates.'.$key, []);

        if (empty($entry['name'])) {
            throw new InvalidArgumentException("Template lógico de WhatsApp não configurado: {$key}");
        }

        return [
            'key' => $key,
            'name' => (string) $entry['name'],
            'locale' => $locale ?: (string) ($entry['locale'] ?? config('whatsapp.language', 'pt_BR')),
        ];
    }
}
