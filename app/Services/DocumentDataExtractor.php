<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class DocumentDataExtractor
{
    public function extract(string $disk, string $path, string $mimeType, string $category): array
    {
        $apiKey = env('OPENAI_API_KEY');
        if (!$apiKey) {
            throw new RuntimeException('Extração automática indisponível: OPENAI_API_KEY não configurada.');
        }

        $bytes = Storage::disk($disk)->get($path);
        $base64 = base64_encode($bytes);
        $instructions = <<<'TXT'
Extraia somente dados explicitamente visíveis no documento. Não invente nem complete lacunas.
Retorne SOMENTE JSON válido, sem markdown, com estas chaves:
full_name, tax_id, birthdate, birthplace, document_type, document_number, document_issuer,
parent_1, parent_2, marital_status, occupation, address, city, state, postal_code,
confidence, warnings.
Use null quando o dado não estiver legível ou não existir. birthdate em YYYY-MM-DD. state com 2 letras.
confidence deve ser número entre 0 e 1. warnings deve ser array de strings.
TXT;

        $content = [['type' => 'input_text', 'text' => $instructions."\nCategoria declarada: {$category}." ]];
        if (str_starts_with($mimeType, 'image/')) {
            $content[] = ['type' => 'input_image', 'image_url' => "data:{$mimeType};base64,{$base64}"];
        } elseif ($mimeType === 'application/pdf') {
            $content[] = [
                'type' => 'input_file',
                'filename' => basename($path),
                'file_data' => "data:application/pdf;base64,{$base64}",
            ];
        } else {
            throw new RuntimeException('Formato não suportado para extração automática. Envie imagem ou PDF.');
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(60)
            ->post('https://api.openai.com/v1/responses', [
                'model' => env('OPENAI_DOCUMENT_MODEL', 'gpt-5.6-luna'),
                'input' => [['role' => 'user', 'content' => $content]],
                'max_output_tokens' => 1200,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Falha ao extrair os dados do documento.');
        }

        $payload = $response->json();
        $text = data_get($payload, 'output.0.content.0.text')
            ?? data_get($payload, 'output_text')
            ?? '';
        $text = trim((string) $text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new RuntimeException('A extração foi concluída, mas o resultado não pôde ser interpretado.');
        }

        $allowed = [
            'full_name', 'tax_id', 'birthdate', 'birthplace', 'document_type', 'document_number',
            'document_issuer', 'parent_1', 'parent_2', 'marital_status', 'occupation', 'address',
            'city', 'state', 'postal_code', 'confidence', 'warnings',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }
}
