<?php

namespace App\Domain\Creative\Services;

final class CatalogImageService
{
    public function __construct(
        private readonly CloudflareImageGenerator $generator,
    ) {}

    public function generate(array $data, int $userId, int $applicationId): array
    {
        return $this->generator->generate(
            $this->buildPrompt($data),
            $userId,
            $applicationId,
            [
                'model' => config('creative.cloudflare.catalog_image_model'),
                'steps' => config('creative.cloudflare.catalog_image_steps', 4),
                'width' => 1024,
                'height' => 1024,
            ],
        );
    }

    public function buildPrompt(array $data): string
    {
        return mb_substr(implode('. ', array_filter([
            'Create a premium square catalog image for a real commercial item or service',
            'Subject: '.$data['subject'],
            ! empty($data['item_type']) ? 'Item type: '.$data['item_type'] : null,
            ! empty($data['description']) ? 'Known description, use only these factual details: '.trim((string) $data['description']) : null,
            ! empty($data['category']) ? 'Category: '.$data['category'] : null,
            ! empty($data['subcategory']) ? 'Subcategory: '.$data['subcategory'] : null,
            ! empty($data['brand']) ? 'Brand context: '.$data['brand'] : null,
            ! empty($data['catalog_name']) ? 'Merchant or catalog context: '.$data['catalog_name'] : null,
            'Use a single clear focal subject, polished commercial photography, realistic materials and lighting, clean contemporary background, natural depth and believable proportions',
            'When the subject is food or drink, make it appetizing and realistic without inventing ingredients that were not supplied',
            'When the subject is a service, communicate the service visually without making a person the dominant subject unless essential',
            'Do not invent specific ingredients, accessories, packaging claims, certifications, promotions or branded elements that were not supplied',
            'IMPORTANT: image only. Do not render words, letters, prices, labels, logos, watermarks, UI, borders or readable text',
        ])), 0, 2048);
    }
}
