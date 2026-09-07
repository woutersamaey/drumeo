# Drumeo · The Method (homelab)

Netflix-achtige lesomgeving plus on-demand HLS-backend voor lokale 4K-MKV’s.

## Starten

```bash
cp .env.example .env
# pas DRUMEO_MEDIA aan als de NAS ergens anders hangt
docker compose up --build
```

Open [http://localhost:5050](http://localhost:5050).

Profielen: **Vic**, **Lenn**, **Wouter** (geen wachtwoord).

## Onderdelen

| Service | Rol |
|---|---|
| `nginx` | `/` frontend, `/api` video-backend, `/hls` cache |
| `frontend` | PHP 8.4, Tailwind, MySQL, Redis |
| `video-api` + `video-worker` | Probe, receptkeuze, FFmpeg, HLS |
| `mysql` / `redis` | Voortgang, scores, catalogus-cache |

Bronnen: `{DRUMEO_MEDIA}/titel [vimeoId].mkv` + `.jpg`. De backend praat intern met id = Vimeo-id.

## GPU-encode

Standaard `FFMPEG_VCODEC=auto`: bij start wordt een mini-encode geprobeerd (NVENC → QSV → VAAPI → VideoToolbox → libx264). De winnaar staat in `GET /api/health` als `encoder`.

De GPU moet in de container zichtbaar zijn, anders valt hij terug op CPU:

```bash
docker compose -f docker-compose.yml -f docker-compose.gpu.yml up -d
```

Zie `video-backend/README.md`.

## Cache opruimen

```bash
docker compose exec video-worker php /app/bin/cleanup.php --dry-run
docker compose exec video-worker php /app/bin/cleanup.php
```
