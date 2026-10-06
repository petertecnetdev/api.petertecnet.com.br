<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\ContentEntry;
use Illuminate\Database\Seeder;

class CutinappGrowthContentSeeder extends Seeder
{
    public function run(): void
    {
        $applicationId = Application::query()->where('slug', 'cutinapp')->value('id');

        if (! $applicationId) {
            $this->command?->warn('CutinappGrowthContentSeeder: application slug "cutinapp" not found.');
            return;
        }

        foreach ($this->articles() as $index => $article) {
            $entry = ContentEntry::query()->firstOrNew([
                'application_id' => $applicationId,
                'type' => 'article',
                'slug' => $article['slug'],
            ]);

            $wasNew = ! $entry->exists;
            $entry->fill([
                'status' => 'published',
                'title' => $article['title'],
                'excerpt' => $article['excerpt'],
                'content' => $article['content'],
                'category' => $article['category'],
                'tags' => $article['tags'],
                'cluster' => $article['cluster'],
                'search_intent' => $article['search_intent'],
                'seo_title' => $article['seo_title'],
                'seo_description' => $article['seo_description'],
                'related' => ['keywords' => $article['related_keywords']],
                'metadata' => [
                    'audience' => $article['audience'],
                    'evergreen' => true,
                    'seed_version' => 1,
                    'primary_cta' => $article['primary_cta'],
                    'secondary_cta' => $article['secondary_cta'],
                ],
            ]);

            if ($wasNew || ! $entry->published_at) {
                $entry->published_at = now()->subDays(max(0, count($this->articles()) - $index - 1));
            }

            $entry->save();
        }
    }

    private function articles(): array
    {
        return [
            [
                'slug' => 'eventos-hoje-como-encontrar-o-que-fazer',
                'title' => 'Eventos hoje: como encontrar o que fazer e escolher uma experiência que combina com você',
                'excerpt' => 'Um guia prático para descobrir festas, shows, experiências e eventos perto de você sem depender de dezenas de perfis e links soltos.',
                'category' => 'Descoberta',
                'audience' => 'participant',
                'cluster' => 'descoberta-de-eventos',
                'search_intent' => 'eventos hoje perto de mim festas shows ingressos',
                'tags' => ['eventos hoje', 'festas', 'shows', 'ingressos', 'vida noturna', 'descobrir eventos'],
                'related_keywords' => ['festa', 'show', 'balada', 'club', 'festival', 'música', 'noite'],
                'seo_title' => 'Eventos hoje: encontre festas, shows e experiências | Cutinapp',
                'seo_description' => 'Descubra como encontrar eventos hoje, comparar opções, conhecer produções e artistas e chegar à compra de ingressos com menos fricção.',
                'primary_cta' => ['label' => 'Ver eventos', 'path' => '/event'],
                'secondary_cta' => ['label' => 'Explorar produções', 'path' => '/productions'],
                'content' => <<<'MD'
## Procurar “eventos hoje” deveria levar você direto ao que está acontecendo

Quando alguém decide sair, normalmente quer responder poucas perguntas: **o que vai acontecer, onde, quando, quem participa e como entrar**. O problema é que essas informações costumam ficar espalhadas entre redes sociais, links de venda e perfis diferentes.

A Cutinapp organiza essa jornada em um só lugar. Você pode começar pela descoberta, abrir a página do evento, conhecer a produção responsável, conferir artistas relacionados e seguir para os ingressos disponíveis.

## O que observar antes de escolher um evento

- data e horário reais;
- local e cidade;
- proposta da experiência;
- produção responsável;
- artistas ou atrações;
- disponibilidade de ingressos e itens;
- informações atualizadas publicadas pelo organizador.

Quanto mais completa estiver a página do evento, menos você precisa procurar respostas em outros lugares.

## Eventos, produções e artistas formam uma rede

Um evento não existe isoladamente. Ele é criado por uma produção, pode ter artistas vinculados, itens próprios e uma comunidade interessada. Por isso, depois de encontrar algo interessante, vale abrir também a produção e os artistas relacionados.

Essa navegação ajuda a descobrir próximos eventos parecidos e acompanhar quem costuma produzir experiências do seu interesse.

## Use a descoberta como ponto de partida

A página de [eventos](/event) reúne experiências disponíveis na plataforma. Se você já conhece uma produtora, também pode navegar pelas [produções](/productions). Para música e atrações, explore os [artistas](/artists).

## Antes de comprar

Confira data, local, regras do ingresso e informações publicadas na página oficial do evento dentro da Cutinapp. Evite depender de prints antigos ou links reenviados sem contexto.

> A melhor descoberta é aquela que termina em uma página atualizada, com o evento, sua produção e as opções de participação conectadas.
MD
            ],
            [
                'slug' => 'boates-balada-e-vida-noturna-como-descobrir',
                'title' => 'Boates, baladas e vida noturna: como descobrir lugares, festas e programações',
                'excerpt' => 'Descubra uma forma mais organizada de acompanhar casas noturnas, festas recorrentes, produtores e atrações.',
                'category' => 'Vida noturna',
                'audience' => 'participant',
                'cluster' => 'vida-noturna',
                'search_intent' => 'boates baladas festas hoje casas noturnas programação',
                'tags' => ['boates', 'baladas', 'vida noturna', 'clubes', 'festas', 'programação'],
                'related_keywords' => ['boate', 'balada', 'club', 'pub', 'festa', 'dj', 'night'],
                'seo_title' => 'Boates e baladas: descubra festas e programações | Cutinapp',
                'seo_description' => 'Veja como acompanhar boates, baladas, festas recorrentes, produções e artistas usando uma experiência de descoberta conectada.',
                'primary_cta' => ['label' => 'Descobrir festas', 'path' => '/event'],
                'secondary_cta' => ['label' => 'Ver produções', 'path' => '/productions'],
                'content' => <<<'MD'
## Vida noturna muda rápido — a informação também precisa mudar

Casas noturnas e festas trabalham com programação dinâmica. Uma atração pode ser confirmada, um lote pode virar, um horário pode mudar e uma nova edição pode ser anunciada.

Por isso, procurar apenas pelo nome de uma casa nem sempre basta. O ideal é enxergar **a programação atual**, a produção responsável e os próximos eventos ligados àquele universo.

## Como escolher melhor

Procure páginas que deixem claro:

- qual é o evento da noite;
- quem produz;
- quais artistas participam;
- localização e horário;
- tipos de ingresso;
- itens ou experiências adicionais disponíveis.

Na Cutinapp, essas entidades podem ficar conectadas. Você sai de uma busca ampla e entra em uma trilha de descoberta.

## Festas recorrentes também merecem páginas vivas

Muitas produções realizam eventos semanais ou mensais. Em vez de depender de um flyer isolado, acompanhe a produção e veja seus próximos eventos. Isso reduz a chance de confundir uma edição antiga com a atual.

Abra a área de [produções](/productions) e navegue pelas agendas disponíveis.

## Artistas ajudam a descobrir novos eventos

Se você gostou de um DJ, banda ou atração, visite a área de [artistas](/artists). O caminho inverso também funciona: descobrir um artista pode levar você até eventos em que ele participa.

## Da pesquisa à experiência

A busca orgânica pode começar com “boate perto de mim”, “balada hoje” ou “festa neste fim de semana”. O objetivo da Cutinapp é transformar essa intenção em descoberta útil, contexto e, quando houver venda disponível, ingresso.
MD
            ],
            [
                'slug' => 'como-comprar-ingresso-online-com-seguranca',
                'title' => 'Como comprar ingresso online com mais segurança e menos confusão',
                'excerpt' => 'Saiba o que conferir antes de pagar um ingresso e por que a página oficial do evento importa.',
                'category' => 'Ingressos',
                'audience' => 'participant',
                'cluster' => 'compra-de-ingressos',
                'search_intent' => 'comprar ingresso online seguro evento',
                'tags' => ['ingressos', 'comprar ingresso', 'checkout', 'evento', 'segurança'],
                'related_keywords' => ['ingresso', 'ticket', 'entrada', 'lote', 'evento'],
                'seo_title' => 'Como comprar ingresso online com segurança | Cutinapp',
                'seo_description' => 'Veja o que conferir antes de comprar ingressos online e como usar a página oficial do evento para reduzir erros e links desatualizados.',
                'primary_cta' => ['label' => 'Encontrar eventos', 'path' => '/event'],
                'secondary_cta' => ['label' => 'Conhecer produções', 'path' => '/productions'],
                'content' => <<<'MD'
## Comece pela página correta do evento

Antes de pagar, confirme se você está vendo a edição certa. Festas recorrentes podem ter nomes parecidos, mas datas, locais e lotes diferentes.

Uma página completa deve apresentar o evento, a produção responsável, data, local e opções de ingresso de forma coerente.

## Confira os dados principais

1. nome do evento;
2. data e horário;
3. endereço ou formato online;
4. produção responsável;
5. tipo e lote do ingresso;
6. regras de acesso;
7. resumo do pedido antes do pagamento.

## Evite comprar a partir de informação isolada

Flyers, mensagens encaminhadas e posts antigos ajudam na divulgação, mas não devem ser a única referência. Sempre que possível, avance até a página atual do evento.

Na Cutinapp você pode partir da lista de [eventos](/event), abrir a experiência desejada e seguir a jornada dentro da própria plataforma.

## Depois da compra

Guarde o acesso ao seu ingresso e acompanhe atualizações do evento. Mudanças relevantes devem aparecer na experiência oficial do evento, evitando depender de informação fragmentada.

## Descoberta e compra devem conversar

Quando conteúdo, evento, produção e ingresso estão conectados, a jornada fica mais simples: você entende o que está comprando antes de chegar ao checkout.
MD
            ],
            [
                'slug' => 'como-vender-ingressos-online-para-eventos',
                'title' => 'Como vender ingressos online para eventos e transformar descoberta em público',
                'excerpt' => 'Estruture divulgação, página do evento, lotes e jornada de compra para reduzir atrito e aumentar a chance de conversão.',
                'category' => 'Produtores',
                'audience' => 'producer',
                'cluster' => 'venda-de-ingressos',
                'search_intent' => 'como vender ingressos online para evento',
                'tags' => ['produtores', 'vender ingressos', 'eventos', 'lotes', 'checkout', 'divulgação'],
                'related_keywords' => ['produção', 'produtora', 'festa', 'festival', 'show', 'ingresso'],
                'seo_title' => 'Como vender ingressos online para eventos | Cutinapp',
                'seo_description' => 'Guia para produtores estruturarem página do evento, lotes, divulgação e compra de ingressos em uma jornada conectada.',
                'primary_cta' => ['label' => 'Criar produção', 'path' => '/production/create'],
                'secondary_cta' => ['label' => 'Ver eventos publicados', 'path' => '/event'],
                'content' => <<<'MD'
## Vender ingresso começa antes do checkout

O checkout é importante, mas a decisão de compra começa quando a pessoa entende **por que aquele evento merece atenção**. Uma página fraca transfere trabalho para direct, WhatsApp e comentários. Uma página rica responde perguntas antes que elas virem objeções.

## Estruture a página do evento

Apresente:

- proposta clara;
- flyer legível;
- data, horário e local;
- produção responsável;
- atrações e artistas;
- descrição objetiva;
- lotes e tipos de ingresso;
- itens ou experiências adicionais quando existirem.

## Transforme sua produção em ativo de descoberta

Não publique apenas eventos isolados. Tenha uma página de produção consistente, com identidade, próximos eventos e histórico. Isso permite que uma pessoa que chegou por um único evento descubra o restante da sua agenda.

Você pode começar criando sua [produção](/production/create).

## Trabalhe intenção de busca

Pessoas pesquisam por estilos, artistas, cidades, festas, datas e tipos de experiência. Um bom conteúdo editorial pode capturar essas buscas e levar o visitante diretamente para eventos relacionados.

É por isso que blog e catálogo de eventos não devem ser áreas separadas: conteúdo atrai; entidades reais convertem.

## Meça o caminho completo

Acompanhe visualização, interesse, abertura do evento, avanço para ingresso e compra. A meta não é apenas gerar tráfego, mas reduzir a distância entre descoberta e participação.

> Para o produtor, conteúdo orgânico funciona melhor quando cada leitura oferece um próximo passo concreto dentro da plataforma.
MD
            ],
            [
                'slug' => 'como-divulgar-evento-sem-depender-so-de-anuncios',
                'title' => 'Como divulgar um evento sem depender somente de anúncios pagos',
                'excerpt' => 'Use SEO, páginas públicas, artistas, produção, conteúdo e distribuição orgânica para construir descoberta contínua.',
                'category' => 'Produtores',
                'audience' => 'producer',
                'cluster' => 'marketing-de-eventos',
                'search_intent' => 'como divulgar evento organicamente seo eventos',
                'tags' => ['divulgação de eventos', 'seo', 'marketing de eventos', 'produtores', 'orgânico'],
                'related_keywords' => ['produção', 'produtora', 'promoter', 'artista', 'festival', 'show'],
                'seo_title' => 'Como divulgar evento organicamente e atrair público | Cutinapp',
                'seo_description' => 'Estratégias de divulgação orgânica para eventos usando SEO, conteúdo, páginas públicas, artistas, produções e distribuição conectada.',
                'primary_cta' => ['label' => 'Publicar meu evento', 'path' => '/production/create'],
                'secondary_cta' => ['label' => 'Explorar o blog', 'path' => '/blog'],
                'content' => <<<'MD'
## Divulgação orgânica é um sistema, não uma postagem

Um post tem vida curta. Uma página pública bem estruturada pode continuar sendo encontrada por busca, compartilhamentos e links internos. O melhor cenário combina os dois.

## Crie ativos que continuem trabalhando

Para cada evento, mantenha:

- página indexável e atualizada;
- descrição útil;
- produção vinculada;
- artistas vinculados;
- informações de cidade e local;
- conteúdo editorial relacionado;
- links internos entre as entidades.

## Use o blog para capturar intenção

Artigos respondem perguntas que o público já faz: onde sair, o que fazer hoje, quais festas existem, como funciona determinado estilo de evento ou como acompanhar um artista.

Quando essas páginas levam para eventos reais, o tráfego deixa de ser apenas informativo e passa a alimentar descoberta.

## Distribua com quem já participa do evento

Artistas, promoters, parceiros e a própria produção podem compartilhar a página oficial. Isso concentra sinais e reduz a quantidade de links diferentes circulando.

## Atualize, não abandone

Conteúdo evergreen pode ser revisado e continuar relevante. Eventos mudam, mas temas como descoberta, ingressos, produção e experiência continuam trazendo novas pessoas.

Use o [blog](/blog) como camada editorial e os [eventos](/event) como camada transacional.
MD
            ],
            [
                'slug' => 'promoter-de-eventos-como-aumentar-alcance-e-conversao',
                'title' => 'Promoter de eventos: como transformar alcance em presença real',
                'excerpt' => 'O promoter pode ser muito mais do que um canal de divulgação quando trabalha com páginas rastreáveis e jornadas claras.',
                'category' => 'Promoters',
                'audience' => 'promoter',
                'cluster' => 'promoters-de-eventos',
                'search_intent' => 'promoter de eventos divulgação vender ingressos',
                'tags' => ['promoter', 'eventos', 'divulgação', 'ingressos', 'conversão'],
                'related_keywords' => ['promoter', 'produção', 'festa', 'club', 'ingresso', 'show'],
                'seo_title' => 'Promoter de eventos: alcance, divulgação e conversão | Cutinapp',
                'seo_description' => 'Como promoters podem organizar divulgação, direcionar público para páginas oficiais e transformar alcance em participação mensurável.',
                'primary_cta' => ['label' => 'Descobrir eventos', 'path' => '/event'],
                'secondary_cta' => ['label' => 'Ver produções', 'path' => '/productions'],
                'content' => <<<'MD'
## O trabalho do promoter termina onde a conversão começa?

Não deveria. Alcance sem uma jornada clara pode gerar conversa, mas não necessariamente presença. O promoter ganha força quando consegue direcionar a audiência para uma página oficial, atualizada e mensurável.

## Um link precisa responder o básico

A pessoa que recebe a indicação deve conseguir entender rapidamente o evento, local, horário, atrações, produção e ingresso.

Quanto menos etapas paralelas, menor o risco de abandono.

## Conecte promoter, produção e evento

O promoter não substitui a produção; ele amplia distribuição. A página da produção ajuda a dar contexto e credibilidade para quem ainda não conhece o organizador.

Explore as [produções](/productions) e veja como diferentes agendas podem ser apresentadas.

## Use conteúdo como argumento

Um artigo pode explicar um estilo de festa, apresentar uma cena musical ou orientar quem procura o que fazer. Esse conteúdo pode continuar trazendo visitantes muito depois de uma postagem social.

## Foque no próximo passo

Cada divulgação deve ter um objetivo claro: abrir o evento, ver atrações, comprar ingresso ou acompanhar a produção. Evite jogar o usuário em uma sequência de páginas sem direção.
MD
            ],
            [
                'slug' => 'artistas-e-eventos-como-ser-descoberto-pelo-publico',
                'title' => 'Artistas e eventos: como transformar presença em descoberta para novos públicos',
                'excerpt' => 'Conectar perfil artístico, agenda e eventos ajuda o público a descobrir onde ver um artista e quais experiências acompanhar.',
                'category' => 'Artistas',
                'audience' => 'artist',
                'cluster' => 'artistas-e-agenda',
                'search_intent' => 'artistas eventos agenda shows dj festas',
                'tags' => ['artistas', 'agenda', 'shows', 'dj', 'eventos', 'descoberta'],
                'related_keywords' => ['artista', 'dj', 'banda', 'show', 'música', 'festival'],
                'seo_title' => 'Artistas e eventos: agenda, descoberta e novos públicos | Cutinapp',
                'seo_description' => 'Entenda como conectar perfil artístico, eventos e produções para facilitar descoberta e levar o público até experiências reais.',
                'primary_cta' => ['label' => 'Explorar artistas', 'path' => '/artists'],
                'secondary_cta' => ['label' => 'Ver eventos', 'path' => '/event'],
                'content' => <<<'MD'
## Quem descobre um artista quer saber onde encontrá-lo

Uma bio isolada é útil, mas a pergunta seguinte costuma ser prática: **onde esse artista vai tocar, se apresentar ou participar?**

Conectar perfil e agenda encurta essa resposta.

## Perfil artístico como ponto de descoberta

Um bom perfil deve ajudar o público a reconhecer o artista e navegar para experiências relacionadas. Nome artístico, foto, descrição, estilos e vínculos com eventos formam uma identidade pesquisável.

Veja os [artistas](/artists) disponíveis na plataforma.

## Eventos também apresentam o artista

A relação funciona nos dois sentidos. Uma pessoa pode descobrir o artista por um evento e, depois, passar a acompanhar sua presença em outras experiências.

Para o produtor, vincular corretamente atrações melhora a qualidade da página e cria mais caminhos internos de descoberta.

## Conteúdo editorial amplia contexto

Artigos podem explicar gêneros, cenas, formatos de evento e tendências. Quando um artista aparece como recomendação relacionada, o visitante tem um próximo passo natural.

## Mantenha informações públicas consistentes

Nomes, fotos, descrições e links devem representar a identidade atual. A consistência reduz confusão e melhora a capacidade de busca.
MD
            ],
            [
                'slug' => 'producao-de-eventos-checklist-para-publicar-e-vender',
                'title' => 'Produção de eventos: checklist para publicar, divulgar e vender com uma página completa',
                'excerpt' => 'Um checklist direto para produtores organizarem as informações que o público precisa antes de participar.',
                'category' => 'Produtores',
                'audience' => 'producer',
                'cluster' => 'producao-de-eventos',
                'search_intent' => 'checklist produção de eventos divulgar vender ingressos',
                'tags' => ['produção de eventos', 'checklist', 'produtores', 'ingressos', 'divulgação'],
                'related_keywords' => ['produção', 'produtora', 'evento', 'festa', 'show', 'festival'],
                'seo_title' => 'Produção de eventos: checklist para publicar e vender | Cutinapp',
                'seo_description' => 'Checklist de produção de eventos para organizar página pública, atrações, ingressos, divulgação e informações essenciais ao participante.',
                'primary_cta' => ['label' => 'Criar produção', 'path' => '/production/create'],
                'secondary_cta' => ['label' => 'Ver produções', 'path' => '/productions'],
                'content' => <<<'MD'
## Antes de publicar

Confirme identidade da produção, descrição do evento, data, local, capacidade e responsáveis. A página pública precisa nascer com informação suficiente para ser compartilhada.

## Conteúdo essencial

- título claro;
- flyer;
- descrição;
- data e duração;
- local;
- produção;
- artistas;
- lotes e ingressos;
- itens adicionais;
- regras e observações importantes.

## Depois de publicar

Revise a página em mobile. Compartilhe o link oficial. Garanta que artistas e parceiros recebam a mesma referência. Atualize informações sempre que houver mudança material.

## Use a produção como continuidade

Um evento pode acabar, mas a produção continua. Mantenha a página da [produção](/productions) rica para que visitantes de um evento descubram o próximo.

## Pense além da venda

A experiência começa na descoberta, passa pela compra e continua no relacionamento pós-evento. Estruturar dados e páginas desde o início deixa espaço para essa continuidade.
MD
            ],
            [
                'slug' => 'itens-do-evento-como-aumentar-a-experiencia-e-o-ticket',
                'title' => 'Itens do evento: como produtos e experiências adicionais podem enriquecer a participação',
                'excerpt' => 'Combos, produtos e experiências podem fazer parte da jornada do evento quando aparecem com contexto e sem confundir o checkout.',
                'category' => 'Experiência',
                'audience' => 'producer',
                'cluster' => 'itens-de-eventos',
                'search_intent' => 'itens produtos combos experiências evento ingresso',
                'tags' => ['itens', 'eventos', 'combos', 'experiência', 'produtores', 'checkout'],
                'related_keywords' => ['combo', 'item', 'produto', 'experiência', 'produção', 'evento'],
                'seo_title' => 'Itens do evento: produtos, combos e experiências | Cutinapp',
                'seo_description' => 'Como apresentar itens, produtos e experiências adicionais de forma conectada ao evento e à produção.',
                'primary_cta' => ['label' => 'Explorar eventos', 'path' => '/event'],
                'secondary_cta' => ['label' => 'Ver produções', 'path' => '/productions'],
                'content' => <<<'MD'
## O ingresso não precisa ser a única oferta

Dependendo do evento, a produção pode trabalhar com itens, produtos, reservas, combos ou experiências adicionais. O ponto central é apresentar essas opções com contexto.

## Evite catálogo solto

Um item fica mais compreensível quando o visitante sabe:

- qual produção oferece;
- em quais eventos ele está disponível;
- preço;
- descrição;
- imagem;
- regras de retirada ou utilização.

## Use o conteúdo para preparar a decisão

Um artigo pode explicar uma experiência antes de oferecer o item. Isso é mais útil do que mostrar um card sem contexto.

## Conecte as entidades

Na Cutinapp, o objetivo é que item, produção e evento possam ser navegados como partes da mesma experiência. Essa estrutura cria novas rotas de descoberta e ajuda o visitante a entender onde cada oferta faz sentido.

## Mantenha a página leve

Carrosséis curtos, imagens otimizadas e carregamento sob demanda são melhores do que tentar exibir todo o catálogo de uma vez.
MD
            ],
            [
                'slug' => 'como-escolher-evento-pelo-artista-producao-e-estilo',
                'title' => 'Como escolher um evento pelo artista, pela produção e pelo estilo da experiência',
                'excerpt' => 'Use sinais além do flyer para encontrar eventos mais próximos do que você realmente gosta.',
                'category' => 'Descoberta',
                'audience' => 'participant',
                'cluster' => 'descoberta-de-eventos',
                'search_intent' => 'como escolher festa evento artista produtora estilo',
                'tags' => ['eventos', 'artistas', 'produções', 'festas', 'descoberta', 'experiência'],
                'related_keywords' => ['artista', 'produção', 'festa', 'show', 'música', 'club'],
                'seo_title' => 'Como escolher eventos por artista, produção e estilo | Cutinapp',
                'seo_description' => 'Descubra como usar artistas, produções, estilos e páginas relacionadas para escolher eventos com mais contexto.',
                'primary_cta' => ['label' => 'Ver eventos', 'path' => '/event'],
                'secondary_cta' => ['label' => 'Explorar artistas', 'path' => '/artists'],
                'content' => <<<'MD'
## O flyer chama atenção; o contexto ajuda a decidir

Design pode despertar interesse, mas outros sinais ajudam a entender se a experiência combina com você.

## Comece pelo artista

Se você já acompanha um artista, veja em quais eventos ele aparece. A partir daí, conheça a produção e outras atrações.

## Ou comece pela produção

Produções costumam desenvolver uma identidade ao longo de vários eventos. Seguir seus próximos projetos pode ser uma forma eficiente de descobrir experiências semelhantes.

Abra [produções](/productions) para explorar esse caminho.

## Compare o conjunto

Considere proposta, artistas, local, horário, produção e formato. Um único elemento raramente conta a história inteira.

## Deixe a plataforma conectar os pontos

Ao relacionar conteúdo editorial a eventos, artistas, produções e itens, a Cutinapp cria caminhos de navegação que ajudam você a sair de uma pesquisa ampla para uma decisão mais informada.
MD
            ],
        ];
    }
}
