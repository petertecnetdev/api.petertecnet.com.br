<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ForecastAnalysisService
{
    public function analyze(string $text): array
    {
        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        $moderation = $this->moderate($clean);
        $result = $this->fallback($clean);
        $apiKey = (string) config('services.openai.api_key');

        if ($apiKey !== '') {
            try {
                $ai = $this->withModel($clean);
                if (is_array($ai)) {
                    $result = array_merge($result, array_filter($ai, fn ($v) => $v !== null));
                }
            } catch (\Throwable) {
                // Fallback keeps drafting available during provider degradation.
            }
        }

        $result['original_statement'] = $clean;
        $result['moderation'] = $moderation;
        $result['verifiable'] = (bool) ($result['verifiable'] ?? false)
            && ! empty($result['deadline_at'])
            && mb_strlen((string) ($result['resolution_criteria'] ?? '')) >= 12;
        $result['platform_probability'] = null;
        $result['platform_confidence'] = 0;
        $result['model_version'] = $apiKey !== '' ? (string) config('services.openai.text_model') : 'heuristic-v1';

        return $result;
    }

    public function moderate(string $text): array
    {
        $requiresReview = false;
        $reasons = [];

        if (preg_match('/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/u', $text)) {
            $requiresReview = true;
            $reasons[] = 'possible_personal_identifier';
        }

        if (preg_match('/\b(é|foi|será)\s+(corrupt[oa]|pedófil[oa]|estuprador|assassino|criminos[oa])\b/iu', $text)) {
            $requiresReview = true;
            $reasons[] = 'accusatory_claim';
        }

        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey !== '') {
            try {
                $r = Http::withToken($apiKey)->timeout(12)
                    ->post(rtrim((string) config('services.openai.base_url'), '/') . '/moderations', [
                        'model' => 'omni-moderation-latest',
                        'input' => $text,
                    ]);
                if ($r->successful() && data_get($r->json(), 'results.0.flagged') === true) {
                    $requiresReview = true;
                    $reasons[] = 'provider_moderation';
                }
            } catch (\Throwable) {
            }
        }

        return ['status'=>$requiresReview ? 'review' : 'approved', 'reasons'=>array_values(array_unique($reasons))];
    }

    private function withModel(string $text): ?array
    {
        $prompt = <<<'PROMPT'
Estruture uma previsão probabilística verificável. Não invente fontes nem uma probabilidade da plataforma.
Retorne somente JSON com: statement, summary, category, topics(array), entities(array), deadline_at(ISO-8601 ou null),
resolution_criteria, source_requirements(array), verifiable(boolean), ambiguity(array), suggested_author_probability(number 0..100 ou null).
Uma previsão precisa ter prazo e critério observável. Se for vaga, verifiable=false e descreva a ambiguidade.
PROMPT;

        $r = Http::withToken((string) config('services.openai.api_key'))
            ->timeout((int) config('services.openai.timeout', 45))
            ->post(rtrim((string) config('services.openai.base_url'), '/') . '/responses', [
                'model' => (string) config('services.openai.text_model'),
                'input' => [
                    ['role'=>'system','content'=>[['type'=>'input_text','text'=>$prompt]]],
                    ['role'=>'user','content'=>[['type'=>'input_text','text'=>$text]]],
                ],
                'text' => [
                    'format' => [
                        'type'=>'json_schema','name'=>'forecast_analysis','strict'=>true,
                        'schema'=>[
                            'type'=>'object','additionalProperties'=>false,
                            'required'=>['statement','summary','category','topics','entities','deadline_at','resolution_criteria','source_requirements','verifiable','ambiguity','suggested_author_probability'],
                            'properties'=>[
                                'statement'=>['type'=>'string'],
                                'summary'=>['type'=>'string'],
                                'category'=>['type'=>'string'],
                                'topics'=>['type'=>'array','items'=>['type'=>'string']],
                                'entities'=>['type'=>'array','items'=>['type'=>'string']],
                                'deadline_at'=>['type'=>['string','null']],
                                'resolution_criteria'=>['type'=>'string'],
                                'source_requirements'=>['type'=>'array','items'=>['type'=>'string']],
                                'verifiable'=>['type'=>'boolean'],
                                'ambiguity'=>['type'=>'array','items'=>['type'=>'string']],
                                'suggested_author_probability'=>['type'=>['number','null']],
                            ],
                        ],
                    ],
                ],
            ]);

        if (! $r->successful()) return null;

        $payload = $r->json();
        $output = (string) data_get($payload, 'output_text', '');
        if ($output === '') {
            foreach ((array) data_get($payload, 'output', []) as $item) {
                foreach ((array) ($item['content'] ?? []) as $content) {
                    if (isset($content['text'])) { $output .= $content['text']; }
                }
            }
        }
        if ($output === '') return null;

        $d = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($d)) return null;

        if (! empty($d['deadline_at'])) {
            try { $d['deadline_at'] = Carbon::parse($d['deadline_at'])->toIso8601String(); }
            catch (\Throwable) { $d['deadline_at'] = null; }
        }

        return [
            'statement'=>trim((string) ($d['statement'] ?? '')),
            'summary'=>trim((string) ($d['summary'] ?? '')),
            'category'=>Str::limit(trim((string) ($d['category'] ?? 'Geral')),100,''),
            'topics'=>array_values(array_slice((array) ($d['topics'] ?? []),0,10)),
            'entities'=>array_values(array_slice((array) ($d['entities'] ?? []),0,10)),
            'deadline_at'=>$d['deadline_at'] ?? null,
            'resolution_criteria'=>trim((string) ($d['resolution_criteria'] ?? '')),
            'source_requirements'=>array_values(array_slice((array) ($d['source_requirements'] ?? []),0,8)),
            'verifiable'=>(bool) ($d['verifiable'] ?? false),
            'ambiguity'=>array_values(array_slice((array) ($d['ambiguity'] ?? []),0,8)),
            'suggested_author_probability'=>isset($d['suggested_author_probability']) ? max(0,min(100,(float)$d['suggested_author_probability'])) : null,
        ];
    }

    private function fallback(string $text): array
    {
        $category = match (true) {
            preg_match('/bitcoin|cripto|ethereum|blockchain/iu',$text)===1 => 'Cripto',
            preg_match('/inflação|juros|selic|economia|pib|dólar/iu',$text)===1 => 'Economia',
            preg_match('/ia|inteligência artificial|software|tecnologia|openai|apple|google/iu',$text)===1 => 'Tecnologia',
            preg_match('/ciência|pesquisa|solar|vacina|espaço/iu',$text)===1 => 'Ciência',
            preg_match('/futebol|jogo|campeonato|esporte/iu',$text)===1 => 'Esportes',
            default => 'Geral',
        };

        return [
            'statement'=>$text,'summary'=>Str::limit($text,180),'category'=>$category,'topics'=>[],'entities'=>[],
            'deadline_at'=>null,'resolution_criteria'=>'','source_requirements'=>['fonte pública confiável e datada'],
            'verifiable'=>false,'ambiguity'=>['Confirme um prazo e um critério objetivo de resolução.'],
            'suggested_author_probability'=>null,
        ];
    }
}
