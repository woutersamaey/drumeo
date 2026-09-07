#!/usr/bin/env bash
# Deploy Drumeo naar de homelab-host. Publiek: https://drumeo.storefront.be
# (externe Nginx doet reverse proxy + SSL; deze stack luistert op :5050).
#
# Eerste keer, met NAS-pad op de host:
#   DRUMEO_MEDIA=/mnt/drumeo ./upload-to-production.sh
set -euo pipefail

HOST="${DEPLOY_HOST:-root@192.168.0.188}"
REMOTE_DIR="${DEPLOY_DIR:-/home/wouter/drumeo}"
PUBLIC_URL="${PUBLIC_URL:-https://drumeo.storefront.be}"
ROOT="$(cd "$(dirname "$0")" && pwd)"

log() { printf '\n==> %s\n' "$*"; }

cd "$ROOT"

if [[ -d frontend/node_modules ]]; then
  log "CSS compileren"
  (cd frontend && npx @tailwindcss/cli -i src/input.css -o public/assets/app.css --minify)
fi

log "rsync → $HOST:$REMOTE_DIR"
ssh -o StrictHostKeyChecking=accept-new "$HOST" "mkdir -p $(printf %q "$REMOTE_DIR")"
rsync -az --delete --stats \
  --exclude '.git/' \
  --exclude '.env' \
  --exclude '.idea/' \
  --exclude '.playwright-mcp/' \
  --exclude '.DS_Store' \
  --exclude 'downloads/' \
  --exclude 'data/' \
  --exclude 'frontend/node_modules/' \
  --exclude 'frontend/vendor/' \
  --exclude 'video-backend/vendor/' \
  --exclude 'video-backend/.phpunit.cache/' \
  --exclude 'frontend/.phpunit.cache/' \
  --exclude '*.png' \
  --exclude '*.log' \
  "$ROOT/" "$HOST:$REMOTE_DIR/"

log "Compose op de host"
ssh "$HOST" "cd $(printf %q "$REMOTE_DIR") && REMOTE_MEDIA=$(printf %q "${DRUMEO_MEDIA:-}") bash -s" <<'REMOTE'
set -euo pipefail

if [[ ! -f .env ]]; then
  cp .env.example .env
  if [[ -n "${REMOTE_MEDIA:-}" ]]; then
    sed -i "s|^DRUMEO_MEDIA=.*|DRUMEO_MEDIA=${REMOTE_MEDIA}|" .env
  fi
fi

if [[ -n "${REMOTE_MEDIA:-}" ]]; then
  if grep -q '^DRUMEO_MEDIA=' .env; then
    sed -i "s|^DRUMEO_MEDIA=.*|DRUMEO_MEDIA=${REMOTE_MEDIA}|" .env
  else
    printf '\nDRUMEO_MEDIA=%s\n' "$REMOTE_MEDIA" >> .env
  fi
fi

media="$(awk -F= '/^DRUMEO_MEDIA=/{print $2; exit}' .env | tr -d '"' | tr -d "'")"
if [[ -z "$media" || "$media" == /Volumes/* ]]; then
  echo "Zet DRUMEO_MEDIA in $PWD/.env naar het NAS-pad op deze host." >&2
  echo "Lokaal: DRUMEO_MEDIA=/mnt/drumeo ./upload-to-production.sh" >&2
  exit 1
fi
if [[ ! -d "$media" ]]; then
  echo "DRUMEO_MEDIA=$media bestaat niet op deze host." >&2
  exit 1
fi

files=(-f docker-compose.yml)
if [[ -e /dev/dri || -e /dev/nvidia0 ]]; then
  files+=(-f docker-compose.gpu.yml)
  echo "GPU-devices gevonden; docker-compose.gpu.yml gaat mee."
fi

docker compose "${files[@]}" up --build -d --remove-orphans

echo "Wachten op nginx health…"
ok=0
for _ in $(seq 1 60); do
  if docker compose "${files[@]}" exec -T nginx wget -qO- http://127.0.0.1/app/health >/dev/null 2>&1; then
    ok=1
    break
  fi
  sleep 3
done
if [[ "$ok" -ne 1 ]]; then
  docker compose "${files[@]}" ps
  echo "Health-check faalde. Zie: docker compose logs --tail=80" >&2
  exit 1
fi

docker compose "${files[@]}" exec -T redis redis-cli DEL catalog:v3 catalog:v4 catalog:v5 catalog:v6 catalog:v7 media-index:v1 media-index:v2 >/dev/null || true
docker compose "${files[@]}" ps
REMOTE

log "Klaar. Intern http://192.168.0.188:5050 — publiek $PUBLIC_URL"
