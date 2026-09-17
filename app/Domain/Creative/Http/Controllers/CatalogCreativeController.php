<?php

namespace App\Domain\Creative\Http\Controllers;

use App\Domain\Creative\Services\CloudflareImageGenerator;
use App\Domain\Creative\Services\CloudflareTextGenerator;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

final class CatalogCreativeController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CloudflareImageGenerator $images,
        private readonly CloudflareTextGenerator $texts,
    ) {}

    public function description(Request $request)
    {
        $data = $request->validate([
            'subject' => 'required|string|min:2|max:255',
            'current_description' => 'nullable|string|max:5000',
            'item_type' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:255',
            'subcategory' => 'nullable|string|max:255',
            'brand' => 'nullable|string|max:255',
            'catalog_name' => 'nullable|string|max:255',
            'tone' => ['nullable', Rule::in(['concise', 'commercial', 'premium', 'casual'])],
        ]);

        $current = trim((string) ($data['current_description'] ?? ''));
        $mode = $current !== '' ? 'improve' : 'create';
        $tone = (string) ($data['tone'] ?? 'commercial');

        $systemPrompt = implode(' ', [
            'Você é um redator de catálogo digital brasileiro.',
            'Escreva em português do Brasil, de forma natural, clara e curta.',
            'Nunca invente ingredientes, composição, tamanho, quantidade, benefícios, certificações, marca, disponibilidade ou qualquer fato que não tenha sido informado.',
            'Se houver descrição original, preserve todos os fatos e apenas melhore clareza, fluidez e apelo comercial.',
            'Retorne somente a descrição final, sem título, aspas, markdown, listas ou explicações.',
            'Use no máximo 3 frases curtas.',
        ]);

        $userPrompt = implode("\n", array_filter([
            'Tarefa: '.($mode === 'improve' ? 'melhorar a descrição existente sem alterar os fatos' : 'criar uma descrição curta usando somente os dados informados'),
            'Nome do item: '.$data['subject'],
            ! empty($data['item_type']) ? 'Tipo: '.$data['item_type'] : null,
            ! empty($data['category']) ? 'Categoria: '.$data['category'] : null,
            ! empty($data['subcategory']) ? 'Subcategoria: '.$data['subcategory'] : null,
            ! empty($data['brand']) ? 'Marca: '.$data['brand'] : null,
            ! empty($data['catalog_name']) ? 'Catálogo/estabelecimento: '.$data['catalog_name'] : null,
            'Tom desejado: '.$tone,
            $current !== '' ? 'Descrição original: '.$current : null,
        ]));

        try {
            $result = $this->texts->generate(
                $systemPrompt,
                $userPrompt,
                (int) $request->user()->id,
                $this->context->id(),
                ['max_tokens' => 220, 'temperature' => 0.3],
            );
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => $exception->getMessage(),
                'fallback_available' => true,
            ], 503);
        }

        return response()->json([
            'description' => $this->cleanDescription($result['text']),
            'mode' => $mode,
            'usage' => [
                'provider' => $result['provider'],
                'model' => $result['model'],
                'purpose' => 'catalog_item_description',
            ],
        ]);
    }

    public function image(Request $request)
    {
        $data = $request->validate([
            'subject' => 'required|string|min:2|max:255',
            'description' => 'nullable|string|max:3000',
            'item_type' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:255',
            'subcategory' => 'nullable|string|max:255',
            'brand' => 'nullable|string|max:255',
            'catalog_name' => 'nullable|string|max:255',
        ]);

        $prompt = implode('. ', array_filter([
            'Create a premium square catalog image for a real commercial item',
            'Subject: '.$data['subject'],
            ! empty($data['item_type']) ? 'Item type: '.$data['item_type'] : null,
            ! empty($data['description']) ? 'Known description, use only these factual details: '.trim((string) $data['description']) : null,
            ! empty($data['category']) ? 'Category: '.$data['category'] : null,
            ! empty($data['subcategory']) ? 'Subcategory: '.$data['subcategory'] : null,
            ! empty($data['brand']) ? 'Brand context: '.$data['brand'] : null,
            ! empty($data['catalog_name']) ? 'Merchant/catalog context: '.$data['catalog_name'] : null,
            'Single clear focal subject, polished commercial photography, realistic materials and lighting, appetizing when the subject is food, clean contemporary background, natural depth, believable proportions',
            'Do not invent specific ingredients, accessories, packaging claims or branded elements that were not supplied',
            'No people unless essential to visually communicate a service, and never make a person the dominant subject',
            'IMPORTANT: image only. Do not render words, letters, prices, labels, logos, watermarks, UI, borders or readable text',
        ]));

        try {
            $result = $this->images->generate(
                mb_substr($prompt, 0, 2048),
                (int) $request->user()->id,
                $this->context->id(),
                [
                    'model' => config('creative.cloudflare.catalog_image_model'),
                    'steps' => config('creative.cloudflare.catalog_image_steps', 4),
                    'width' => 1024,
                    'height' => 1024,
                ],
            );
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => $exception->getMessage(),
                'fallback_available' => true,
            ], 503);
        }

        return response()->json([
            'image' => [
                'data_uri' => 'data:'.$result['mime_type'].';base64,'.$result['image'],
                'mime_type' => $result['mime_type'],
                'provider' => $result['provider'],
                'model' => $result['model'],
            ],
            'usage' => [
                'purpose' => 'catalog_item_image',
                'plan' => 'guarded',
            ],
        ]);
    }

    private function cleanDescription(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:text|markdown)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B\"'");

        return mb_substr(trim($text), 0, 1200);
    }
}
