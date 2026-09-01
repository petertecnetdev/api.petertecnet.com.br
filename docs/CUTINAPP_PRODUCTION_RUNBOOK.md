# Cutinapp — Runbook de Produção

Este documento define o procedimento seguro para liberar a Cutinapp para uma produção real com vendas de ingressos e itens.

## Política financeira do primeiro release

- Novas vendas pagas devem usar **Mercado Pago conectado pelo produtor via OAuth**.
- O modo esperado é `automatic_split`.
- `CUTINAPP_ALLOW_PLATFORM_COLLECTION=false` deve permanecer desativado no primeiro release.
- `CUTINAPP_ENABLE_MANUAL_PAYOUT_REQUESTS=false` deve permanecer desativado até existir e ser homologada uma liquidação automática de repasses.
- O PIX e a reserva local de estoque usam 30 minutos por padrão. Não reduzir abaixo de 30 minutos.
- Nunca habilitar vendas pagas para uma produção sem confirmar que a conta Mercado Pago do produtor aparece como conectada na Cutinapp.

## 1. Antes do deploy

1. Confirmar que o CI do frontend está verde: instalação limpa, testes e build.
2. Confirmar que o CI da API está verde: instalação Composer, sintaxe, migrations em banco limpo, auditoria de migrations, rotas e testes.
3. Confirmar que `main` e a branch usada pelo servidor estão alinhadas no mesmo release.
4. Fazer backup do banco de dados da API e confirmar que o arquivo de backup existe e não está vazio.
5. Registrar o commit atualmente implantado para permitir rollback do código.

## 2. Variáveis obrigatórias da API

Não registrar valores secretos neste arquivo ou no Git.

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.petertecnet.com.br

CUTINAPP_FRONTEND_URL=https://cutinapp.petertecnet.com.br
CUTINAPP_PLATFORM_FEE_PERCENT=8
CUTINAPP_ALLOW_PLATFORM_COLLECTION=false
CUTINAPP_ENABLE_MANUAL_PAYOUT_REQUESTS=false
CUTINAPP_ORDER_EXPIRATION_MINUTES=30

MERCADOPAGO_CLIENT_ID=...
MERCADOPAGO_CLIENT_SECRET=...
MERCADOPAGO_PUBLIC_KEY=...
MERCADOPAGO_ACCESS_TOKEN=...
MERCADOPAGO_WEBHOOK_SECRET=...
MERCADOPAGO_REDIRECT_URI=https://api.petertecnet.com.br/api/cutinapp/payments/mercadopago/oauth/callback

GOOGLE_CLIENT_ID=...
```

Também confirmar as variáveis já usadas pelo Laravel Reverb/WebSocket no ambiente ativo e a configuração de e-mail usada para autenticação, convites e comunicação com participantes.

Depois de editar `.env`, nunca manter cache antigo de configuração.

## 3. Deploy da API

Executar no diretório da API, usando a branch de produção definida para o servidor:

```bash
set -e

cd /var/www/api.petertecnet.com.br

git status
git fetch origin
git pull --ff-only

composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

php artisan down || true
php artisan migrate --force
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan up

php artisan migrate:status
php artisan route:list | grep cutinapp
```

Se o servidor possuir workers de fila, scheduler ou Reverb sob Supervisor/systemd, reiniciar os processos usando a configuração já existente da VPS. Não inventar um novo nome de processo: primeiro consultar a configuração ativa do servidor.

## 4. Deploy do frontend

Executar no diretório da Cutinapp:

```bash
set -e

cd /var/www/cutinapp.petertecnet.com.br

git status
git fetch origin
git pull --ff-only

npm ci
CI=true npm test -- --watchAll=false
npm run build
```

Confirmar que o Nginx continua servindo a pasta `build` correta e que as rotas SPA retornam `index.html`.

## 5. Mercado Pago — homologação obrigatória

Antes de abrir vendas:

1. Confirmar no painel do Mercado Pago que a aplicação usada é a aplicação de produção correta.
2. Confirmar a URL OAuth de retorno exatamente igual à configurada na API.
3. Confirmar o Webhook de pagamentos apontando para:

```text
https://api.petertecnet.com.br/api/cutinapp/payments/mercadopago/webhook
```

4. Confirmar o segredo de assinatura do webhook na variável `MERCADOPAGO_WEBHOOK_SECRET`.
5. Entrar como produtor na Cutinapp e conectar a conta Mercado Pago.
6. Voltar para `/producer/finance` e confirmar que a conta aparece conectada e que as vendas estão habilitadas com split automático.
7. Não ativar `platform_collection` para contornar falha de OAuth do produtor.

## 6. Smoke test funcional sem dinheiro

Executar nesta ordem:

1. Abrir a home pública.
2. Registrar/login com e-mail e senha.
3. Testar login Google no domínio real.
4. Criar uma produção.
5. Editar a produção.
6. Criar um evento futuro.
7. Adicionar ingresso pago.
8. Adicionar item pago do evento.
9. Vincular artista/line-up quando aplicável.
10. Criar cortesia.
11. Publicar o evento.
12. Abrir o evento em janela anônima e confirmar que ele aparece publicamente.
13. Confirmar que evento privado/cancelado/não publicado não entra no catálogo pago.
14. Retirar uma cortesia com outro usuário.
15. Abrir o QR da cortesia.
16. Validar na portaria somente durante a janela válida do evento.
17. Tentar usar o mesmo QR novamente e confirmar rejeição de ingresso já utilizado.

## 7. Compra real de baixo valor

Fazer uma compra real controlada antes de anunciar o evento.

### PIX

1. Criar um lote de baixo valor e quantidade pequena.
2. Comprar 1 ingresso com uma conta de participante real.
3. Confirmar que o checkout cria um PIX com vencimento compatível com a reserva de 30 minutos.
4. Confirmar que a tela não consulta a API em frequência excessiva.
5. Pagar o PIX.
6. Confirmar que o Webhook ou a sincronização reconhece o pagamento.
7. Confirmar `order.status=paid` e pagamento `paid`.
8. Confirmar criação de exatamente 1 `EventPass` por unidade comprada.
9. Confirmar o ingresso em “Meus ingressos”.
10. Confirmar o QR na portaria.

### Cartão

1. Confirmar que a forma cartão só aparece quando a chave pública do vendedor está disponível.
2. Fazer uma compra real controlada de baixo valor.
3. Confirmar aprovação e criação idempotente do passe.
4. Recarregar/repetir sincronização e confirmar que não são emitidos ingressos duplicados.

## 8. Estoque e concorrência

Validar pelo menos uma vez antes do primeiro evento:

- dois compradores tentando adquirir o último ingresso;
- pedido pendente reservando estoque;
- reserva expirada deixando de bloquear estoque;
- PIX expirado não podendo posteriormente gerar overselling;
- quantidade de passes emitidos nunca excedendo a quantidade do lote;
- itens do evento respeitando o mesmo princípio de reserva e venda.

## 9. Reembolso e chargeback

Antes da operação em escala, executar uma homologação controlada ou simulação validada:

1. Reembolso/chargeback deve alterar o pagamento e o pedido.
2. Passes vinculados devem receber `refunded` ou `charged_back`.
3. Esses passes devem ser explicitamente recusados no check-in.
4. Passes invalidados não devem contar como ingressos válidos na estatística da portaria.
5. Lançamentos financeiros devem ser revertidos no ledger.

## 10. Checklist de segurança

- `APP_DEBUG=false`.
- Nenhum segredo no Git ou no bundle React.
- Bearer token continua sendo exigido em rotas administrativas/financeiras.
- Todas as operações Cutinapp de produção/evento/ingresso/pagamento permanecem escopadas à aplicação Cutinapp.
- Webhook do Mercado Pago exige assinatura válida.
- Checkout nunca confia no preço enviado pelo frontend; preço é lido novamente no banco.
- Checkout usa bloqueio de banco e reserva de inventário.
- `X-Idempotency-Key` é enviado ao criar pagamento.
- A confirmação remota valida ID, referência externa e valor antes de liberar ingresso.
- Conta/tokens OAuth do produtor permanecem criptografados no banco.
- QR de outro evento, reembolsado, cancelado ou com chargeback é recusado.

## 11. Observabilidade durante a primeira produção

Durante as primeiras vendas e na abertura da portaria, acompanhar:

```bash
cd /var/www/api.petertecnet.com.br

tail -f storage/logs/laravel.log
```

Também observar os workers/realtime já configurados no servidor e o painel do Mercado Pago para pagamentos/webhooks recusados.

Alertas prioritários:

- HTTP 500/502/503 no checkout;
- HTTP 429 em sincronização de pagamento;
- webhook 401/502;
- pagamento aprovado sem `EventPass`;
- quantidade vendida acima do estoque;
- falha de descriptografia/renovação OAuth do produtor;
- QR válido recusado ou QR invalidado aceito.

## 12. Rollback

Se um problema grave surgir antes de haver vendas pagas:

1. Desabilitar/publicamente retirar o evento das vendas.
2. Voltar o código para o commit anteriormente registrado.
3. Reexecutar `composer install`, limpar/recriar caches e reconstruir o frontend conforme necessário.

Se já houver pagamentos reais, **não executar rollback de banco destrutivo** nem restaurar backup por cima dos dados atuais. Preservar pedidos, pagamentos, passes e ledger; corrigir o código para frente ou executar uma migração corretiva auditável.

Nunca usar `migrate:rollback`, `migrate:fresh`, `db:wipe` ou restaurar backup de forma destrutiva em produção com vendas reais sem uma análise específica dos dados financeiros envolvidos.

## Critério de GO

Abrir vendas reais somente quando todos os itens abaixo forem verdadeiros:

- CI frontend verde;
- CI API verde;
- migrations aplicadas sem erro na VPS;
- `APP_DEBUG=false` confirmado na VPS;
- produção conectada ao Mercado Pago;
- `automatic_split` confirmado;
- webhook de produção validado;
- login Google validado no domínio real;
- compra PIX real de baixo valor aprovada e ingresso emitido;
- compra cartão real controlada aprovada, se cartão for oferecido;
- QR emitido validado na portaria;
- QR duplicado/reembolsado/chargeback recusado;
- backup e rollback operacional conhecidos.
