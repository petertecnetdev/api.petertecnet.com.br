<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const SERVICE_SLUGS = [
        'peter-crypto-market-analysis',
        'peter-crypto-portfolio-analysis',
        'peter-crypto-market-monitoring',
        'peter-crypto-smart-alerts',
        'peter-crypto-risk-analysis',
        'peter-crypto-dashboard',
        'peter-crypto-automation',
        'peter-crypto-platform-development',
    ];

    public function up(): void
    {
        $establishment = DB::table('establishments')
            ->whereNull('deleted_at')
            ->where(function ($query) {
                $query->where('slug', 'peter-tecnet')
                    ->orWhereRaw("LOWER(TRIM(name)) = ?", ['peter tecnet'])
                    ->orWhereRaw("LOWER(TRIM(fantasy)) = ?", ['peter tecnet']);
            })
            ->orderBy('id')
            ->first();

        if (! $establishment) {
            return;
        }

        $now = now();

        $services = [
            [
                'slug' => 'peter-crypto-market-analysis',
                'name' => 'Análise Inteligente de Mercado Cripto',
                'description' => 'Análise de criptomoedas utilizando dados de mercado, indicadores técnicos, tendências, volatilidade e inteligência artificial para auxiliar na tomada de decisões.',
                'subcategory' => 'market_analysis',
                'tags' => ['cripto', 'mercado', 'analise', 'inteligencia-artificial'],
                'is_featured' => true,
            ],
            [
                'slug' => 'peter-crypto-portfolio-analysis',
                'name' => 'Análise de Carteira Cripto',
                'description' => 'Avaliação da composição de uma carteira de criptomoedas, distribuição de ativos, exposição a risco, concentração e oportunidades de diversificação.',
                'subcategory' => 'portfolio_analysis',
                'tags' => ['cripto', 'carteira', 'portfolio', 'risco'],
                'is_featured' => true,
            ],
            [
                'slug' => 'peter-crypto-market-monitoring',
                'name' => 'Monitoramento de Criptomoedas',
                'description' => 'Monitoramento de ativos digitais com acompanhamento de preço, volume, volatilidade, tendências e movimentações relevantes do mercado.',
                'subcategory' => 'market_monitoring',
                'tags' => ['cripto', 'monitoramento', 'preco', 'volatilidade'],
                'is_featured' => false,
            ],
            [
                'slug' => 'peter-crypto-smart-alerts',
                'name' => 'Alertas Inteligentes de Mercado',
                'description' => 'Alertas personalizados para variações de preço, volume, volatilidade, rompimentos, indicadores técnicos e outras condições relevantes de mercado.',
                'subcategory' => 'smart_alerts',
                'tags' => ['cripto', 'alertas', 'mercado', 'indicadores'],
                'is_featured' => false,
            ],
            [
                'slug' => 'peter-crypto-risk-analysis',
                'name' => 'Análise de Risco Cripto',
                'description' => 'Avaliação de risco de ativos digitais considerando volatilidade, liquidez, histórico, concentração e demais indicadores relevantes.',
                'subcategory' => 'risk_analysis',
                'tags' => ['cripto', 'risco', 'liquidez', 'volatilidade'],
                'is_featured' => false,
            ],
            [
                'slug' => 'peter-crypto-dashboard',
                'name' => 'Dashboard Inteligente de Criptomoedas',
                'description' => 'Painel personalizado para acompanhamento de carteira, ativos favoritos, indicadores, movimentações, desempenho e informações relevantes do mercado.',
                'subcategory' => 'dashboard',
                'tags' => ['cripto', 'dashboard', 'carteira', 'indicadores'],
                'is_featured' => false,
            ],
            [
                'slug' => 'peter-crypto-automation',
                'name' => 'Automação para Mercado Cripto',
                'description' => 'Desenvolvimento de automações, integrações com APIs e sistemas personalizados para coleta, processamento, análise e acompanhamento de dados do mercado cripto.',
                'subcategory' => 'automation',
                'tags' => ['cripto', 'automacao', 'api', 'integracao'],
                'is_featured' => true,
            ],
            [
                'slug' => 'peter-crypto-platform-development',
                'name' => 'Desenvolvimento de Plataforma Cripto',
                'description' => 'Desenvolvimento de plataformas, aplicativos, dashboards e soluções digitais personalizadas para análise e acompanhamento do mercado de ativos digitais.',
                'subcategory' => 'software_development',
                'tags' => ['cripto', 'software', 'plataforma', 'desenvolvimento'],
                'is_featured' => true,
            ],
        ];

        foreach ($services as $service) {
            DB::table('items')->updateOrInsert(
                [
                    'entity_id' => $establishment->id,
                    'entity_name' => 'establishment',
                    'slug' => $service['slug'],
                ],
                [
                    'user_id' => $establishment->user_id ?? null,
                    'app_id' => $establishment->app_id ?? null,
                    'name' => $service['name'],
                    'type' => 'service',
                    'description' => $service['description'],
                    'price' => 0,
                    'stock' => 0,
                    'status' => true,
                    'limited_by_user' => false,
                    'category' => 'crypto',
                    'subcategory' => $service['subcategory'],
                    'is_featured' => $service['is_featured'],
                    'tags' => json_encode($service['tags'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_by' => $establishment->created_by ?? $establishment->user_id ?? null,
                    'updated_by' => $establishment->updated_by ?? $establishment->user_id ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        $establishment = DB::table('establishments')
            ->where(function ($query) {
                $query->where('slug', 'peter-tecnet')
                    ->orWhereRaw("LOWER(TRIM(name)) = ?", ['peter tecnet'])
                    ->orWhereRaw("LOWER(TRIM(fantasy)) = ?", ['peter tecnet']);
            })
            ->orderBy('id')
            ->first();

        if (! $establishment) {
            return;
        }

        DB::table('items')
            ->where('entity_id', $establishment->id)
            ->where('entity_name', 'establishment')
            ->whereIn('slug', self::SERVICE_SLUGS)
            ->delete();
    }
};
