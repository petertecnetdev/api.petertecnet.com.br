# Cutinapp Event Discovery — operação segura

## Estado da primeira versão

Descobre eventos de fontes **JSON estruturadas e explicitamente autorizadas**.
Não extrai conteúdos indiscriminadamente do Instagram, Shotgun ou Sympla.
Os fornecedores exigem APIs/contratos apropriados, analisados caso a caso.

- Coleta via fila Laravel e scheduler a cada hora.
- Isolamento por `app_id`, origem e data de verificação.
- Deduplicação conservadora: evento candidato idêntico e possível duplicidade no catálogo.
- Revisão humana com perfis de moderação e eventos.
- Cadastro como **rascunho não publicado**, apenas em produção curatorial explicitamente `unclaimed`.
- Nunca cria ingressos, habilita vendas ou presume endosso do organizador.
- Desativado por padrão; nenhum domínio nem fonte são cadastrados automaticamente.

## Preparar

1. Revisar `Independence`, CI, migrações e claims do ciclo antes do merge.
2. Preparar backup e executar `php artisan migrate --force` após deploy autorizado.
3. Configurar em ambiente de produção, após revisão:

```dotenv
EVENT_DISCOVERY_ENABLED=true
EVENT_DISCOVERY_ALLOWED_HOSTS=agenda-parceira.example.org,feeds-produtor.example.net
```

4. Reiniciar workers/limpar cache de configuração conforme runbook do ambiente.
5. Registrar somente feeds cuja licença, contrato ou autorização permita leitura automatizada.

```bash
php artisan events:discovery-source cutinapp "Agenda autorizada" "https://agenda-parceira.example.org/events.json" --authorized --city="Brasília" --uf=DF --timezone=America/Sao_Paulo
php artisan events:discover --inline
# Execução normal: scheduler dispara events:discover e queue:work processa jobs.
```

## Contrato da fonte

Resposta HTTPS JSON, até 2 MiB e 300 eventos:

```json
{
  "events": [
    {
      "id": "evento-fonte-123",
      "title": "Festival Exemplo",
      "url": "https://agenda-parceira.example.org/eventos/123",
      "start_at": "2026-12-05T20:00:00-03:00",
      "end_at": "2026-12-06T02:00:00-03:00",
      "city": "Brasília",
      "uf": "DF",
      "venue": "Espaço Exemplo",
      "address": "Rua Exemplo, 100, Brasília - DF",
      "organizer": "Produtora Exemplo",
      "description": "Resumo factual autorizado do evento."
    }
  ]
}
```

Se `city` ou `uf` não vierem no evento, usa os padrões da fonte. O campo `id` é o identificador estável; duplicados da mesma fonte não recriam registros.

## Administração

Requer autenticação, sessão válida e autorização isolada por aplicação.

- `GET /api/v1/apps/{application}/admin/event-discovery/candidates?status=pending` exige `moderation.view`.
- `PATCH /api/v1/apps/{application}/admin/event-discovery/candidates/{candidateId}` exige **`moderation.manage` e `events.manage`**.

Para rejeitar: `{"decision":"reject"}`.

Para criar rascunho: `{"decision":"create_draft","production_id":123,"address":"Rua confirmada, 100"}`.
A produção deve pertencer à mesma aplicação e estar explicitamente não reivindicada. O campo `address` deve ser confirmado pelo revisor. A publicação pública depende do fluxo normal de verificação e curadoria existente.

## Operação e riscos

- O limite de bytes deve ser imposto no streaming HTTP antes de carregar feeds grandes em memória.
- A allowlist é exata por hostname e a checagem DNS recusa IPs privados/reservados; exigir também **bloqueio de tráfego de saída para redes internas no firewall**, pois verificações DNS não eliminam completamente rebinding.
- Não inserir URLs de terceiros como chamada para compra nem copiar fotos/logos sem licença.
- Não considerar o evento cancelado só porque sumiu temporariamente da fonte; registrar evidências e revisar.
- Para expansão nacional, desenvolver conectores individuais para APIs licenciadas, feeds iCal/RSS e fontes oficiais, respeitando termos e limites, além de UI operacional no Admin Center.
- Não habilitar a flag nem executar deploy/migrations na VPS sem revisão e autorização.
