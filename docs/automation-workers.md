# Peter Tecnet Automation Workers — fase inicial

O controle fica na API Laravel compartilhada. **Nada inicia com a instalação**:
AUTOMATION_WORKERS_ENABLED=false e controles por app desabilitados por padrão.

## Workers reais entregues
- discovery_quality: identifica candidatos com mesmo content_key em fontes da mesma aplicação; marca apenas pending como possible_duplicate. Não publica ou vende ingressos.
- discovery_health: monitora fontes autorizadas da aplicação, atrasos maiores que três horas e erros; produz histórico agregado sem expor URLs.
- events:discover / ScanEventDiscoverySource: pipeline preexistente de descoberta autorizada, mantido separado para evitar agendamento duplicado.

## Preparação e execução (após aprovação da release)
1. Validar php artisan migrate:status e aplicar php artisan migrate --force pela rotina de deploy.
2. Garantir QUEUE_CONNECTION=redis (ou database com tabela jobs existente), cache compartilhado e consumidor dedicado para curation.
3. Instalar/ajustar Supervisor com processo separado: php artisan queue:work redis --queue=curation --sleep=3 --tries=3 --timeout=60 --max-time=3600. Configurar stopwaitsecs > 60 e reinício automático.
4. Definir AUTOMATION_WORKERS_ENABLED=true somente após validar filas, migrations e fontes.
5. Habilitar individualmente: php artisan automation:configure cutinapp discovery_quality --enable --interval=120
6. Habilitar monitor: php artisan automation:configure cutinapp discovery_health --enable --interval=30
7. Validar sem despacho: php artisan automation:dispatch --app=cutinapp --dry-run
8. Despachar manualmente: php artisan automation:dispatch --app=cutinapp
9. Pausar: php artisan automation:configure cutinapp discovery_quality --disable
10. Auditar as tabelas automation_worker_controls e automation_worker_runs; erros completos permanecem na infraestrutura de logs configurada.

Apenas o agendador existente (php artisan schedule:run a cada minuto) despacha os jobs a cada cinco minutos; nenhuma segunda instância de cron deve ser instalada sem verificar o agendamento atual.

## Limites e próximos passos
O painel de gestão no Admin Center e workers para IA, arte, artistas e curadoria ampliada são próximas etapas. Nenhum scraping de Instagram/Shotgun/Sympla é iniciado por estes workers.
Não adicionar Horizon antes de verificar Redis, extensões e compatibilidade de dependências. Não implantar nem executar migrations automaticamente.
