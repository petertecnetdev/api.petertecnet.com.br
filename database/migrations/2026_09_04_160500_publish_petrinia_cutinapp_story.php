<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SLUG = 'petrinia-cutinapp-persistencia-tecnologia';

    public function up(): void
    {
        if (! Schema::hasTable('content_entries')) {
            return;
        }

        $applicationId = null;

        if (Schema::hasTable('applications') && Schema::hasColumn('applications', 'slug')) {
            $applicationId = DB::table('applications')
                ->where('slug', 'cutinapp')
                ->value('id');
        }

        $now = now();

        DB::table('content_entries')->updateOrInsert(
            [
                'application_id' => $applicationId,
                'type' => 'article',
                'slug' => self::SLUG,
            ],
            [
                'status' => 'published',
                'title' => 'Petrínia e a Cutinapp: quando persistência transforma uma ideia em tecnologia',
                'excerpt' => 'Conheça Petrínia, uma garota determinada que enfrentou erros, dúvidas e um grande desafio técnico até transformar uma ideia na Cutinapp e provar o que a persistência pode construir.',
                'content' => <<<'MARKDOWN'
Petrínia era uma garota cheia de energia, curiosidade e uma vontade enorme de criar alguma coisa que realmente fizesse diferença.

Simpática, divertida e sempre disposta a ajudar, ela era daquele tipo de pessoa que conquistava todo mundo ao redor. Mas, por trás do sorriso fácil, existia uma cabeça que não parava. Petrínia gostava de observar problemas e imaginar maneiras melhores de resolvê-los.

## Uma ideia que parecia simples

Um dia, uma pergunta começou a ocupar seus pensamentos: e se existisse uma forma mais simples de descobrir eventos, comprar ingressos, divulgar produções e conectar pessoas a experiências incríveis?

A ideia parecia ótima. Na cabeça de Petrínia, tudo se encaixava: produtores poderiam organizar seus eventos, participantes encontrariam novas experiências, ingressos seriam digitais e o check-in poderia acontecer de maneira rápida e segura.

Então ela decidiu transformar aquela ideia em uma aplicação.

Foi assim que começou a nascer a Cutinapp.

## Quando os problemas apareceram

A empolgação dos primeiros dias logo encontrou a realidade do desenvolvimento de software.

Vieram telas que não se comportavam como deveriam, integrações que pareciam simples até serem testadas de verdade, fluxos que precisavam ser repensados e bugs que surgiam justamente quando tudo parecia estar funcionando.

Em determinado momento, Petrínia enfrentou o maior problema do projeto. Uma falha técnica começou a atingir várias partes da aplicação ao mesmo tempo. Corrigir uma funcionalidade parecia provocar um problema em outra. Testes falhavam. Integrações deixavam de responder. Algumas tentativas de solução simplesmente criavam novos desafios.

Ela ficou cansada. Em alguns momentos, chegou a se perguntar se tinha sonhado grande demais.

Mas desistir nunca combinou muito com Petrínia.

## Quando a história encontrou a Peter Tecnet

Foi no meio dessa caminhada que o projeto encontrou espaço dentro da Peter Tecnet.

A ideia deixou de ser apenas um projeto isolado e passou a fazer parte de um ecossistema maior de tecnologia. Com arquitetura, planejamento, testes, integrações e uma visão mais ampla de produto, Petrínia continuou trabalhando para fazer a Cutinapp evoluir.

O objetivo não era apenas colocar uma aplicação no ar. Era construir algo que pudesse ser útil de verdade.

## O problema que quase parou tudo

O desafio técnico continuava ali.

Petrínia revisou código, refez fluxos, testou possibilidades, descartou soluções que não eram suficientemente boas e voltou várias vezes ao mesmo problema. Houve erros de integração, testes quebrados e momentos em que a solução parecia estar a apenas um passo de distância — até um novo detalhe aparecer.

Só que cada tentativa deixava uma pista.

Pouco a pouco, aquilo que parecia um enorme problema começou a ser dividido em partes menores. Uma correção resolveu um fluxo. Outra estabilizou uma integração. Os testes começaram a passar. A aplicação ficou mais rápida, mais organizada e mais confiável.

Até que chegou o momento em que tudo finalmente funcionou junto.

## A Cutinapp ganhou vida

A Cutinapp deixou de ser apenas uma ideia.

Ela passou a representar uma nova maneira de aproximar produtores, eventos e pessoas. Divulgação, descoberta de eventos, ingressos digitais, experiências e check-in passaram a fazer parte de uma mesma jornada.

E o resultado foi maior do que simplesmente concluir um projeto.

O trabalho trouxe aprendizado, evolução técnica, novas possibilidades comerciais e espaço para crescimento. Aquilo que começou com uma pergunta se transformou em uma plataforma capaz de gerar oportunidades e ajudar negócios ligados a eventos a avançar.

## O que Petrínia aprendeu

Quando olhou para tudo que havia construído, Petrínia percebeu que desenvolver tecnologia nunca foi sobre não encontrar problemas.

Foi sobre continuar quando eles apareceram.

Foi sobre transformar erro em informação, dificuldade em aprendizado e uma ideia em algo que outras pessoas pudessem realmente utilizar.

A história da Cutinapp não terminou quando o primeiro grande problema foi resolvido. Na verdade, foi ali que uma nova etapa começou.

Porque produtos evoluem. Empresas evoluem. Pessoas evoluem.

E algumas das melhores histórias da tecnologia começam exatamente assim: com alguém olhando para uma ideia difícil e dizendo — eu acho que consigo fazer isso.

Cutinapp por Peter Tecnet. Tecnologia transformando ideias em possibilidades.
MARKDOWN,
                'category' => 'Histórias de tecnologia',
                'tags' => json_encode([
                    'Cutinapp',
                    'tecnologia',
                    'desenvolvimento de software',
                    'inovação',
                    'eventos',
                    'Peter Tecnet',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'cluster' => 'tecnologia-e-produtos-digitais',
                'search_intent' => 'história de desenvolvimento de aplicativo para eventos e inovação em tecnologia',
                'cover_image' => 'https://petertecnet.com.br/blog/petrinia-cutinapp-cover.svg',
                'og_image' => 'https://petertecnet.com.br/blog/petrinia-cutinapp-cover.svg',
                'seo_title' => 'Petrínia e a Cutinapp: persistência, tecnologia e inovação | Peter Tecnet',
                'seo_description' => 'A história de Petrínia e da criação da Cutinapp: desafios técnicos, persistência, desenvolvimento de software e a transformação de uma ideia em uma plataforma para eventos.',
                'canonical_url' => 'https://petertecnet.com.br/blog/'.self::SLUG,
                'related' => json_encode([
                    ['type' => 'application', 'slug' => 'cutinapp'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'metadata' => json_encode([
                    'related_platform' => 'cutinapp',
                    'read_time' => '5 min',
                    'featured' => true,
                    'story_character' => 'Petrínia',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'scheduled_at' => null,
                'published_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('content_entries')) {
            return;
        }

        DB::table('content_entries')
            ->where('type', 'article')
            ->where('slug', self::SLUG)
            ->delete();
    }
};
