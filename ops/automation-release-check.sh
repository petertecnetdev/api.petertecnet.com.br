#!/usr/bin/env bash
# Verificacao somente leitura: nenhuma migration, deploy ou reinicio de processo.
set -euo pipefail
cd /var/www/api.petertecnet.com.br
test -f artisan || { echo 'ERRO: diretorio Laravel nao encontrado'; exit 1; }

echo '=== Origem e alteracoes locais ==='
git branch --show-current
git rev-parse HEAD
if test -n "$(git status --porcelain=v1)"; then
  echo 'BLOQUEIO: diretorio de producao possui modificacoes locais.'
  git status --short | head -50
  exit 2
fi

echo '=== Dependencias ==='
php -v | head -1
php -m | grep -Eiq '^redis$' || { echo 'BLOQUEIO: extensao PHP Redis ausente'; exit 3; }
redis-cli ping | grep -Fxq PONG || { echo 'BLOQUEIO: Redis indisponivel'; exit 4; }

echo '=== Migrations (sem executar) ==='
php artisan migrate:status
echo '=== Agendamentos ==='
php artisan schedule:list | grep -E 'automation:(dispatch|recover-stale)' || {
  echo 'BLOQUEIO: agendamentos de automacao nao encontrados'; exit 5;
}
echo '=== Simulacao de despacho ==='
php artisan automation:dispatch --dry-run
echo 'OK: pre-condicoes locais verificadas. Requer CI e revisao de release.'
