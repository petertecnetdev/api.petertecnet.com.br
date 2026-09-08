<?php

namespace App\Mail;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProspectInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Application $application,
        public string $persona,
        public ?string $recipientName = null,
    ) {
    }

    public function build()
    {
        $context = $this->context();

        return $this
            ->subject($context['subject'])
            ->view('emails.prospect-invitation')
            ->with([
                'application' => $this->application,
                'recipientName' => $this->recipientName,
                'persona' => $this->persona,
                'personaLabel' => $context['label'],
                'headline' => $context['headline'],
                'intro' => $context['intro'],
                'benefits' => $context['benefits'],
                'ctaLabel' => $context['cta'],
                'applicationUrl' => $this->applicationUrl(),
            ]);
    }

    private function context(): array
    {
        $appName = trim((string) $this->application->name) ?: 'Peter Tecnet';
        $slug = strtolower(trim((string) $this->application->slug));

        if (in_array($slug, ['cutinapp', 'cutin-app', 'catchnap', 'catinapp'], true)) {
            return match ($this->persona) {
                'producer' => [
                    'label' => 'Produtor',
                    'subject' => "Convite para produtores: leve seus eventos para {$appName}",
                    'headline' => 'Venda, divulgue e movimente seus eventos em um só lugar.',
                    'intro' => 'A plataforma foi criada para reduzir atrito na operação do evento e aproximar sua produção do público antes, durante e depois da experiência.',
                    'benefits' => [
                        'Publicar e divulgar eventos com uma página própria e pronta para compartilhamento.',
                        'Vender ingressos antecipadamente e facilitar a entrada do público.',
                        'Cadastrar e vender itens, produtos e experiências do estabelecimento ou da produção.',
                        'Receber interações, curtidas, comentários e outros sinais de interesse dos participantes.',
                        'Manter o público informado sobre alterações e novidades do evento.',
                        'Dar mais velocidade à compra e ao relacionamento com participantes-clientes.',
                    ],
                    'cta' => 'Conhecer a plataforma',
                ],
                'artist' => [
                    'label' => 'Artista',
                    'subject' => "Convite para artistas: ganhe presença nos eventos do {$appName}",
                    'headline' => 'Mais presença, conexão com produtores e destaque nos eventos.',
                    'intro' => 'Seu perfil pode se tornar uma vitrine dentro do ecossistema de eventos, conectando sua atuação a produtores, casas e participantes.',
                    'benefits' => [
                        'Ter presença e destaque vinculados aos eventos em que você participa.',
                        'Ser encontrado por produtores e novas oportunidades dentro da plataforma.',
                        'Fortalecer seu perfil com agenda, participações e interação do público.',
                        'Aproximar sua audiência dos eventos em que você estará presente.',
                        'Compartilhar sua participação e ampliar a descoberta do seu trabalho.',
                    ],
                    'cta' => 'Criar presença na plataforma',
                ],
                'promoter' => [
                    'label' => 'Promoter',
                    'subject' => "Convite para promoters: conecte público e eventos no {$appName}",
                    'headline' => 'Transforme sua rede em presença, relacionamento e vendas.',
                    'intro' => 'A plataforma ajuda promoters a divulgar eventos, conectar pessoas às experiências certas e acompanhar melhor o movimento gerado pela sua atuação.',
                    'benefits' => [
                        'Divulgar eventos e direcionar o público para páginas oficiais de compra.',
                        'Compartilhar links de eventos de forma rápida e rastreável.',
                        'Aproximar sua audiência de produtores, artistas e casas parceiras.',
                        'Usar a rede social do evento para aumentar interação antes e depois da festa.',
                        'Reduzir o atrito entre descoberta, interesse e compra do ingresso.',
                    ],
                    'cta' => 'Conhecer as possibilidades',
                ],
                default => [
                    'label' => 'Participante',
                    'subject' => "Você foi convidado para descobrir o {$appName}",
                    'headline' => 'Descubra eventos, compre com rapidez e participe de verdade.',
                    'intro' => 'O evento começa antes da porta abrir. Na plataforma você encontra o que vai acontecer, garante seu acesso e interage com toda a experiência.',
                    'benefits' => [
                        'Descobrir eventos e navegar por experiências relacionadas.',
                        'Comprar ingressos antecipadamente de forma rápida.',
                        'Comprar itens e produtos disponibilizados pela produção ou estabelecimento.',
                        'Curtir, comentar, compartilhar e acompanhar atualizações dos eventos.',
                        'Interagir com produtores, artistas, promoters e outros participantes.',
                        'Acompanhar seus ingressos e movimentações dentro da própria plataforma.',
                    ],
                    'cta' => 'Descobrir eventos agora',
                ],
            };
        }

        return match ($this->persona) {
            'professional' => [
                'label' => 'Profissional',
                'subject' => "Convite profissional para conhecer o {$appName}",
                'headline' => "Use o {$appName} para ampliar sua operação digital.",
                'intro' => 'A Peter Tecnet conecta produtos e serviços em um ecossistema integrado, com uma experiência centralizada e preparada para crescer.',
                'benefits' => [
                    'Acessar recursos da plataforma a partir de uma conta integrada ao ecossistema.',
                    'Reduzir tarefas manuais por meio de fluxos digitais e automações.',
                    'Centralizar informações importantes em uma experiência simples e responsiva.',
                    'Aproveitar integrações com outras soluções Peter Tecnet conforme a operação evoluir.',
                ],
                'cta' => "Conhecer o {$appName}",
            ],
            'partner' => [
                'label' => 'Parceiro',
                'subject' => "Convite de parceria: conheça o {$appName}",
                'headline' => 'Uma nova possibilidade de parceria dentro do ecossistema Peter Tecnet.',
                'intro' => 'Queremos aproximar parceiros que possam gerar valor em conjunto, conectando audiência, operação e tecnologia.',
                'benefits' => [
                    'Conhecer uma solução integrada ao ecossistema Peter Tecnet.',
                    'Explorar oportunidades comerciais e operacionais em conjunto.',
                    'Conectar serviços, clientes e fluxos digitais de forma mais eficiente.',
                    'Construir uma relação preparada para novas integrações e produtos.',
                ],
                'cta' => 'Conhecer a oportunidade',
            ],
            default => [
                'label' => 'Cliente',
                'subject' => "Você foi convidado para conhecer o {$appName}",
                'headline' => "Descubra o que o {$appName} pode facilitar para você.",
                'intro' => 'A plataforma faz parte do ecossistema Peter Tecnet e foi pensada para tornar tarefas, serviços e experiências digitais mais simples e conectadas.',
                'benefits' => [
                    'Acesso simples a uma plataforma integrada ao ecossistema Peter Tecnet.',
                    'Experiência moderna, responsiva e preparada para uso no celular.',
                    'Fluxos digitais mais rápidos e centralizados.',
                    'Integração progressiva com outros recursos do ecossistema.',
                ],
                'cta' => "Conhecer o {$appName}",
            ],
        };
    }

    private function applicationUrl(): string
    {
        $url = trim((string) $this->application->url);

        if (filter_var($url, FILTER_VALIDATE_URL) && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https') {
            return rtrim($url, '/');
        }

        return 'https://petertecnet.com.br';
    }
}
