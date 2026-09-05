<?php

namespace App\Services\Catalog;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class CatalogImageAnalysisService
{
    /**
     * @param  array<int, UploadedFile>  $images
     * @return array{items: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    public function analyze(array $images, string $locale = 'pt-BR', string $currency = 'BRL'): array
    {
        $apiKey = trim((string) config('services.openai.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('A análise inteligente de cardápio ainda não foi configurada no servidor.');
        }

        if ($images === []) {
            throw new RuntimeException('Envie ao menos uma imagem para análise.');
        }

        $content = [[
            'type' => 'input_text',
            'text' => $this->prompt($locale, $currency),
        ]];

        foreach ($images as $image) {
            $mime = $image->getClientMimeType() ?: $image->getMimeType() ?: 'image/jpeg';
            $content[] = [
                'type' => 'input_image',
                'detail' => 'high',
                'image_url' => sprintf('data:%s;base64,%s', $mime, base64_encode((string) $image->get())),
            ];
        }

        $response = $this->client($apiKey)->post('/responses', [
            'model' => (string) config('services.openai.catalog_vision_model', 'gpt-5-mini'),
            'store' => false,
            'input' => [[
                'role' => 'user',
                'content' => $content,
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'catalog_image_items',
                    'strict' => true,
                    'schema' => $this->schema(),
                ],
            ],
            'max_output_tokens' => 10000,
        ]);

        if (! $response->successful()) {
            report(new RuntimeException(sprintf(
                'OpenAI catalog analysis failed with HTTP %d: %s',
                $response->status(),
                Str::limit((string) $response->body(), 1000)
            )));

            throw new RuntimeException('Não foi possível analisar o cardápio agora. Tente novamente em instantes.');
        }

        $payload = $response->json();
        $text = $this->extractOutputText(is_array($payload) ? $payload : []);
        $decoded = json_decode($text, true);

        if (! is_array($decoded) || ! isset($decoded['items']) || ! is_array($decoded['items'])) {
            throw new RuntimeException('A análise do cardápio retornou um formato inválido. Tente novamente.');
        }

        $items = collect($decoded['items'])
            ->filter(fn ($item) => is_array($item) && trim((string) ($item['name'] ?? '')) !== '')
            ->take(200)
            ->map(fn (array $item) => $this->normalizeItem($item))
            ->values()
            ->all();

        return [
            'items' => $items,
            'warnings' => collect($decoded['warnings'] ?? [])
                ->filter(fn ($warning) => is_string($warning) && trim($warning) !== '')
                ->map(fn ($warning) => trim($warning))
                ->take(20)
                ->values()
                ->all(),
        ];
    }

    private function client(string $apiKey): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/'))
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(max(15, (int) config('services.openai.timeout', 90)))
            ->retry(2, 750, throw: false);
    }

    private function prompt(string $locale, string $currency): string
    {
        return <<<PROMPT
Analise as imagens como um importador de catálogo/cardápio. Extraia somente itens realmente oferecidos ao cliente.

Regras obrigatórias:
- Responda exclusivamente no JSON exigido pelo schema.
- Idioma de referência: {$locale}. Moeda de referência: {$currency}.
- Preserve o nome comercial do item, corrigindo apenas erros óbvios de leitura.
- Separe categoria e subcategoria quando o cardápio deixar isso claro.
- Leia preço como número decimal, sem símbolo monetário. Se o preço não estiver legível, use null.
- Se houver tamanhos/variações com preços diferentes e não existir estrutura de variações no schema, gere um item por variação e inclua o tamanho no nome, evitando perder preço.
- Não invente descrição, marca, SKU ou preço. Use null quando não houver evidência.
- type deve ser "product" para comida, bebida, mercadoria ou ingresso físico/digital mostrado como produto; use "service" somente quando a imagem realmente descrever um serviço.
- confidence deve refletir a confiança de leitura entre 0 e 1.
- source_text deve conter um trecho curto do texto visual que sustenta o item quando útil.
- Ignore cabeçalhos, telefones, endereços, formas de pagamento, redes sociais e textos institucionais.
- Não crie duplicatas quando o mesmo item aparecer repetido nas imagens.
- Em warnings, informe problemas relevantes como preço ilegível, imagem cortada ou seção ambígua.
PROMPT;
    }

    private function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['items', 'warnings'],
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'maxItems' => 200,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'name', 'description', 'price', 'category', 'subcategory',
                            'brand', 'sku', 'type', 'confidence', 'source_text',
                        ],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'description' => $nullableString,
                            'price' => ['type' => ['number', 'null']],
                            'category' => $nullableString,
                            'subcategory' => $nullableString,
                            'brand' => $nullableString,
                            'sku' => $nullableString,
                            'type' => ['type' => 'string', 'enum' => ['product', 'service']],
                            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                            'source_text' => $nullableString,
                        ],
                    ],
                ],
                'warnings' => [
                    'type' => 'array',
                    'maxItems' => 20,
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }

    private function extractOutputText(array $payload): string
    {
        if (is_string($payload['output_text'] ?? null) && trim($payload['output_text']) !== '') {
            return $payload['output_text'];
        }

        foreach ($payload['output'] ?? [] as $output) {
            if (! is_array($output)) {
                continue;
            }

            foreach ($output['content'] ?? [] as $content) {
                if (is_array($content) && ($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    return $content['text'];
                }
            }
        }

        throw new RuntimeException('A análise do cardápio não retornou conteúdo utilizável.');
    }

    private function normalizeItem(array $item): array
    {
        $price = $item['price'] ?? null;

        return [
            'name' => Str::limit(trim((string) ($item['name'] ?? '')), 255, ''),
            'description' => $this->nullableText($item['description'] ?? null, 5000),
            'price' => is_numeric($price) ? round(max(0, (float) $price), 2) : null,
            'category' => $this->nullableText($item['category'] ?? null, 120),
            'subcategory' => $this->nullableText($item['subcategory'] ?? null, 120),
            'brand' => $this->nullableText($item['brand'] ?? null, 120),
            'sku' => $this->nullableText($item['sku'] ?? null, 100),
            'type' => in_array(($item['type'] ?? null), ['product', 'service'], true) ? $item['type'] : 'product',
            'confidence' => round(min(1, max(0, (float) ($item['confidence'] ?? 0))), 3),
            'source_text' => $this->nullableText($item['source_text'] ?? null, 500),
        ];
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : Str::limit($value, $max, '');
    }
}
