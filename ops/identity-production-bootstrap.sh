#!/usr/bin/env bash
set -Eeuo pipefail

log(){ printf '[Peter Identity bootstrap] %s\n' "$*"; }
fail(){ printf '[Peter Identity bootstrap] ERROR: %s\n' "$*" >&2; exit 78; }

env_value(){
  local key="$1" default_value="$2" value=""
  if [[ -f .env ]]; then
    value="$(grep -E "^${key}=" .env | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//" || true)"
  fi
  printf '%s' "${value:-$default_value}"
}

REDIS_HOST_VALUE="$(env_value REDIS_HOST 127.0.0.1)"
REDIS_PORT_VALUE="$(env_value REDIS_PORT 6379)"
REDIS_CLIENT_VALUE="$(env_value REDIS_CLIENT phpredis)"

if [[ "$REDIS_CLIENT_VALUE" == "phpredis" ]] && ! php -m | grep -qi '^redis$'; then
  if [[ "$(id -u)" -ne 0 ]]; then
    fail "A extensão phpredis não está instalada e o usuário de deploy não é root. Instale php8.3-redis antes do rollout."
  fi
  log "Instalando extensão php8.3-redis..."
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -y
  apt-get install -y php8.3-redis
  systemctl restart php8.3-fpm 2>/dev/null || true
fi

if [[ "$REDIS_CLIENT_VALUE" == "phpredis" ]] && ! php -m | grep -qi '^redis$'; then
  fail "A extensão phpredis continua indisponível após o bootstrap."
fi

case "$REDIS_HOST_VALUE" in
  127.0.0.1|localhost|::1)
    if ! command -v redis-cli >/dev/null 2>&1; then
      if [[ "$(id -u)" -ne 0 ]]; then
        fail "Redis local não está instalado e o usuário de deploy não é root."
      fi
      log "Instalando redis-server local..."
      export DEBIAN_FRONTEND=noninteractive
      apt-get update -y
      apt-get install -y redis-server
    fi

    if [[ "$(id -u)" -eq 0 ]]; then
      systemctl enable redis-server >/dev/null 2>&1 || true
      systemctl start redis-server >/dev/null 2>&1 || service redis-server start >/dev/null 2>&1 || true
    fi

    if command -v redis-cli >/dev/null 2>&1; then
      if ! redis-cli -h "$REDIS_HOST_VALUE" -p "$REDIS_PORT_VALUE" ping 2>/dev/null | grep -q '^PONG$'; then
        fail "Redis local não respondeu PONG em ${REDIS_HOST_VALUE}:${REDIS_PORT_VALUE}."
      fi
    fi
    ;;
  *)
    log "Redis externo configurado em ${REDIS_HOST_VALUE}:${REDIS_PORT_VALUE}; instalação de servidor local não será feita."
    ;;
esac

log "Infraestrutura base do Peter Identity pronta. O artisan preflight fará a validação autenticada/final após migrations e config:cache."
