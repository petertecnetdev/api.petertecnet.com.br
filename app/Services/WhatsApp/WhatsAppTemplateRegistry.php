<?php

namespace App\Services\WhatsApp;

use InvalidArgumentException;

class WhatsAppTemplateRegistry
{
    public function resolve(string $type, ?string $locale = null): array
    {
        $key = strtoupper(trim($type));
        $templates = (array) config('services.whatsapp.templates', []);
        $name = trim((string) ($templates[$key] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException("WhatsApp template is not configured for {$key}.");
        }

        $requestedLocale = trim((string) ($locale ?: config('services.whatsapp.language', 'pt_BR')));
        $locales = (array) config('services.whatsapp.locales', []);
        $language = $locales[$requestedLocale] ?? $requestedLocale;

        return ['type' => $key, 'name' => $name, 'language' => $language];
    }
}
