<?php

namespace App\Services;

final class CuratorialEditorialPolicy
{
    public static function isTicketingSource(?string $url): bool
    {
        if (!$url) return false;
        $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));
        return (bool) preg_match('/(^|\\.)(shotgun\\.live|sympla\\.com\\.br)$/', $host);
    }

    public static function removePromotionalReferences(?string $content): string
    {
        $content = trim((string) $content);
        if ($content === '') return '';
        $paragraphs = preg_split('/\\R[\\t ]*\\R/u', $content) ?: [];
        $filtered = [];
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') continue;
            // Informações sobre a curadoria pertencem à interface e aos metadados,
            // não à apresentação editorial do evento.
            if (preg_match('/^(?:AVISO:\\s*evento cadastrado|Cadastro informativo feito pela curadoria|Evento publicado pela curadoria|Evento cadastrado pela curadoria)/iu', $paragraph)) continue;
            if (preg_match('/^Ingresso\\(s\\)\\s*[—:-].*(?:valores não verificados|consulte a divulgação oficial)/iu', $paragraph)) continue;
            if (preg_match('/^(?:Origem|Fonte oficial|Fonte de referência|Link para compra)\\s*:/iu', $paragraph)) continue;
            $paragraph = preg_replace_callback('~https?://[^\\s<>"\']+~iu', function ($match) {
                $url = rtrim($match[0], '.,);');
                return self::isTicketingSource($url) ? '' : $match[0];
            }, $paragraph);
            $paragraph = preg_replace('/(?:Consulte horários, disponibilidade, valores e condições diretamente na fonte oficial|Confirme horários, preços e disponibilidade na página oficial|consulte a divulgação oficial)[\\s:.-]*/iu', '', $paragraph);
            $paragraph = trim((string) preg_replace('/(?:Fonte oficial|Fonte de referência)\s*:?\s*$/iu', '', $paragraph));
            if ($paragraph !== '') $filtered[] = $paragraph;
        }
        return trim(implode("\n\n", $filtered));
    }

    public static function timeDetails(object $event): string
    {
        $start = new \DateTimeImmutable((string) $event->start_date);
        $end = new \DateTimeImmutable((string) $event->end_date);
        if ($start->diff($end)->days >= 2) {
            return sprintf('Período: de %s a %s; confira os dias e horários específicos informados na programação acima.',
                $start->format('d/m/Y'), $end->format('d/m/Y'));
        }
        return sprintf('Data e horário: %s, das %s às %s%s.',
            $start->format('d/m/Y'),
            $start->format('H:i'),
            $end->format('H:i'),
            $start->format('Y-m-d') === $end->format('Y-m-d') ? '' : ' do dia '.$end->format('d/m/Y')
        );
    }

    public static function buildEventDescription(object $event, string $summary, ?string $ticketInfo = null): string
    {
        $sections = [];
        if ($summary !== '') $sections[] = self::removePromotionalReferences($summary);
        $sections[] = self::timeDetails($event);
        $address = trim(implode(' — ', array_filter([$event->venue ?? null, $event->address ?? null])));
        $city = trim(implode('/', array_filter([$event->city ?? null, $event->uf ?? null])));
        if (!preg_match('/\bLocal\s*:/iu', $summary)) {
            $sections[] = 'Local: '.trim($address.($city ? ', '.$city : ''), ' ,').'.';
        }
        if (!empty($event->organizer_name)) $sections[] = 'Organização anunciada: '.$event->organizer_name.'.';
        if ($ticketInfo) $sections[] = 'Ingressos e acesso (valores divulgados, sujeitos a alterações): '.self::removePromotionalReferences($ticketInfo);
        return trim(implode("\n\n", array_filter($sections)));
    }
}
