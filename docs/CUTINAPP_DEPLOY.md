# Cutinapp — ativação em produção

A Cutinapp usa a API Peter Tecnet e possui domínio próprio de equipe, promoters, vendas, promoções, emissão, check-in e PIX.

## 1. Backend

Após o merge da PR da API:

```bash
cd /var/www/api.petertecnet.com.br
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
```

Confirme que existe uma aplicação ativa com `slug = cutinapp` na tabela `applications`.

## 2. PIX Efí

O certificado e as credenciais nunca devem ser versionados. Configure no `.env` do servidor:

```env
EFI_API_BASE_URL=https://pix.api.efipay.com.br
EFI_CLIENT_ID=
EFI_CLIENT_SECRET=
EFI_CERT_PATH=/caminho/fora/do/repositorio/certificado.pem
EFI_CERT_PASSWORD=
EFI_PIX_KEY=
EFI_WEBHOOK_HMAC=gere-um-segredo-longo-e-aleatorio
EFI_API_TIMEOUT=15
```

Depois de alterar o `.env`:

```bash
php artisan config:clear
php artisan config:cache
```

Cadastre na Efí o webhook da chave PIX apontando para:

`https://api.petertecnet.com.br/api/cutinapp/payments/pix/webhook?hmac=SEU_HMAC`

A infraestrutura HTTPS deve respeitar os requisitos de mTLS da Efí. O HMAC é uma proteção adicional da aplicação.

## 3. Frontend

Após o merge da PR da Cutinapp:

```bash
cd /var/www/cutinapp.petertecnet.com.br
git pull origin main
npm install
npm run build
```

`npm install` é necessário no primeiro deploy desta versão porque foi adicionada a dependência `qrcode.react` e o lockfile legado precisa ser atualizado no ambiente de desenvolvimento/deploy.

## 4. Smoke test obrigatório

1. criar/login em uma conta;
2. cadastrar produção;
3. criar evento e publicá-lo;
4. criar lote de ingresso;
5. criar produto extra;
6. cadastrar promoter e copiar seu link;
7. criar cupom;
8. abrir a página pública com `?ref=CODIGO`;
9. finalizar pedido PIX;
10. confirmar que o pagamento emite um ingresso em `Meus ingressos`;
11. ler o QR no check-in;
12. tentar reutilizar o mesmo QR e confirmar que a API bloqueia;
13. conferir receita e comissão no dashboard do produtor e no portal do promoter.

## 5. Ordem de deploy

Sempre publique **API primeiro** e **frontend depois**. O frontend novo depende das rotas `/api/cutinapp/*` e das novas tabelas do backend.
