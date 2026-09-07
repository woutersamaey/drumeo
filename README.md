# Drumeo · The Method (homelab)

Lokale lesomgeving voor *The Drumeo Method*: 322 lessen in 9 hoofdstukken, Netflix-achtige UI, on-demand HLS van 4K-MKV’s op de NAS.

- Lokaal: [http://localhost:5050](http://localhost:5050)
- Productie: [https://drumeo.storefront.be](https://drumeo.storefront.be) (intern `192.168.0.188:5050`)
- Profielen: **Vic**, **Lenn**, **Wouter** (geen wachtwoord)

## Starten

```bash
cp .env.example .env
# pas DRUMEO_MEDIA aan als de NAS ergens anders hangt
docker compose up --build
```

GPU-encode (Intel/AMD via `/dev/dri`):

```bash
docker compose -f docker-compose.yml -f docker-compose.gpu.yml up -d
```

Open [http://localhost:5050](http://localhost:5050). CSS/JS in `frontend/public/assets` zijn bind-mounts: wijzigingen zijn meteen live. PHP in `frontend/src` zit **in de image** — zie [PHP wijzigen](#php-wijzigen).

## Architectuur

| Service | Rol |
|---|---|
| `nginx` | `/` SPA, `/assets`, `/img` (thumbs), `/app` frontend-API, `/api` + `/hls` video |
| `frontend` | PHP 8.4: catalogus, profielen, voortgang, image-scaler |
| `video-api` + `video-worker` | Probe, receptkeuze, FFmpeg, HLS. Geen database. |
| `mysql` | Profielen, voortgang, scores, notities, week-statistiek |
| `redis` | Catalogus-cache en media-index |

Bronbestanden op de NAS: alleen `{DRUMEO_MEDIA}/titel [vimeoId].mkv`. Intern is het id altijd het Vimeo-id. Thumbnails staan in `thumbs/{vimeoId}.jpg` in deze repo — de NAS heeft geen JPG’s meer nodig.

Ontwerp van de video-backend: [`video-backend/README.md`](video-backend/README.md) en de oorspronkelijke briefing [`video-backend/video-backend-briefing.md`](video-backend/video-backend-briefing.md).

## Catalogus

Eén bestand: [`catalog.json`](catalog.json) (~224 KB). Geen `lessons/`-map, geen `lesson-jsons/`, geen Sanity-fetch.

```json
{
  "intro": { "id", "vimeoId", "seconds", "role", "title": {"en","nl"}, "difficulty": {"en","nl"}, "instructor", "thumb", "pack" },
  "paths": [
    {
      "id", "slug",
      "title" / "difficulty" / "description": {"en","nl"},
      "order": [les-id, …],
      "packs": [{ "title": {"en","nl"}, "lessons": [les-id, …] }]
    }
  ],
  "lessons": { "<id>": { "id", "vimeoId", "seconds", "role", "title", "difficulty", "instructor", "thumb", "pack" } }
}
```

- `paths[].order` is de officiële Method-volgorde (CMS `videos[]`). Dat is de afspeelvolgorde.
- `paths[].packs` groepeert dezelfde lessen per skill (horizontale rijen).
- `thumb` is `thumbs/{vimeoId}.jpg` — lokaal, nooit een remote URL.
- Redis-cache: `catalog:v7` (10 min). Na catalogus- of PHP-wijziging: `docker compose exec redis redis-cli DEL catalog:v7 media-index:v2`.

`Catalog.php` nummert bij het laden:

- Lessen **1–322** globaal (`intro` is 1, daarna elk id in `paths[].order`).
- Hoofdstukken **1–9** in catalogusvolgorde.

Het lesnummer hoort **voor de titel** in de tekst (`3. De hi-hat en snare`), niet als badge op de thumbnail. Score-emoji en het vinkje “gezien” blijven op de thumbnail.

## UI

- Header: Drumeo-wordmark (SVG in `frontend/public/assets/logo-drumeo.svg`) · Home · Hoofdstukken · Oefenen · Geschiedenis · Statistieken. Desktop-nav vanaf `lg` (1024px) zodat iPad-portrait niet overloopt.
- Mobiel: Hoofdstukken in de header, dock onderaan (Home / Oefenen / Geschiedenis / Stats / Profiel).
- Taal (EN/NL) is de audiostream: index `0` Engels, `1` Nederlands. Sommige MKV’s hebben geen `nl` of heten de Engelse track `Original` i.p.v. `en`.
- Padweergave per profiel: **Lesvolgorde** (grid in Method-order, default) of **Per skill** (horizontale packs). `POST /app/path-view`.
- “Watched” = tot 15 s voor het einde. Resume springt nooit in de laatste 5 s van een afgewezen les; dan de volgende ongeziene.
- Score na afloop: 🤩😊😕😢, alle ratings blijven bewaard (opnieuw kijken mag). Oefenen = lessen zonder top-score.
- Notities per les/profiel. Notatiesleutel: [`/app/notation.pdf`](http://localhost:5050/app/notation.pdf) (`drumeo-method-notation-key.pdf`).
- CSS/JS cache-bust: SHA1 van het bestand als `?v=`.

Getest op oude iPads, iPad Pro, iPhone en desktop-Chrome. Touch-doelen min. 44px (`.tap`).

## Thumbnails

1. Bron: `thumbs/{vimeoId}.jpg` in de repo (322 stuks). Geen NAS-JPG, geen Sanity.
2. Nginx: `GET /img/{vimeoId}/{160|320|640|960|1280|1920}.{webp|jpg}` → cache op volume `image-cache`, anders PHP `ImageScaler` (GD) vanuit `thumbs/`.

Na ontbrekende thumbs: het JPG in `thumbs/` zetten en eventueel de image-cache voor dat id wissen.

## Video (HLS)

MKV blijft archief; de browser speelt HLS. De backend kiest remux of transcode op basis van client-capabilities. Cache per `(bron, recept, audioIndex)`. Safari speelt native HLS; andere browsers krijgen hls.js **in de API-response**, niet als aparte `/assets`-script.

```
FFMPEG_VCODEC=auto   # NVENC → QSV → VAAPI → VideoToolbox → libx264
GET /api/health      # encoder + encoderRequested
```

HLS-cache opruimen (raakt `SOURCE_DIR` nooit):

```bash
docker compose exec video-worker php /app/bin/cleanup.php --dry-run
docker compose exec video-worker php /app/bin/cleanup.php
```

## NAS

Standaardpad lokaal: `/Volumes/private/Drumeo`. Op de productieserver: `/mnt/drumeo` (zet `DRUMEO_MEDIA` in `.env`).

Finder kan MKV’s grijs maken door `com.apple.FinderInfo` (`brok`/`MACS`) terwijl het bestand gezond is. Strip alleen die xattr, niet andere:

```bash
xattr -d com.apple.FinderInfo "/Volumes/private/Drumeo/somefile [123].mkv"
```

Laat `.smbdelete*`-resten met rust tenzij je ze bewust opruimt.

## Video’s binnenhalen (yt-dlp)

Niet meer in de repo. Typisch recept, met referer Musora:

```bash
yt-dlp --referer "https://app.musora.com/drumeo" \
  --write-all-thumbnails -N 16 --audio-multistreams \
  -f "bv*+ba[language=en]+ba[language=nl]" \
  --merge-output-format mkv \
  --batch-file urls.txt --download-archive already_done.txt
```

Sommige clips hebben geen `en` maar `Original`, of geen Nederlands. Bestandsnaam in de NAS: `Titel [vimeoId].mkv`.

## PHP wijzigen

`frontend/src/*.php` zit in de image, niet in een bind-mount. Na `docker compose up --build` is de image leidend. Tussendoor, zonder rebuild:

```bash
docker compose cp frontend/src/Catalog.php frontend:/app/src/Catalog.php
docker compose cp frontend/src/Http.php frontend:/app/src/Http.php
docker compose cp frontend/src/Progress.php frontend:/app/src/Progress.php
docker compose exec redis redis-cli DEL catalog:v7 media-index:v2
docker compose exec frontend kill -USR2 1
```

`docker compose up` (recreate) gooit docker-cp’d PHP weg. Daarna opnieuw kopiëren of `--build`.

CSS herbouwen:

```bash
(cd frontend && npx @tailwindcss/cli -i src/input.css -o public/assets/app.css --minify)
```

## Productie

```bash
DRUMEO_MEDIA=/mnt/drumeo ./upload-to-production.sh
```

Host `root@192.168.0.188`, map `/home/wouter/drumeo`. Extern Nginx (storefront) doet TLS; deze stack luistert op `:5050`. Het script compileert CSS, rsync’t (zonder `.git`, `node_modules`, `vendor`, screenshots), start compose (GPU-overlay als `/dev/dri` of NVIDIA bestaat) en legt `catalog:v7` + `media-index:v2` leeg.

## Redis-sleutels

| Key | TTL | Inhoud |
|---|---|---|
| `catalog:v7` | 600 s | Gebouwde catalogus + `n` / prev/next |
| `media-index:v2` | 900 s | Vimeo-id → NAS-pad (alleen `.mkv`) |

Oude keys (`catalog:v3` … `v6`) mogen weg.

## Tests

```bash
# frontend (GD)
php frontend/tests/ImageScalerTest.php

# video-backend
(cd video-backend && ./vendor/bin/phpunit)
```

## Wat expres weg is

- `lessons/` en `lesson-jsons/` — vervangen door `catalog.json`
- Remote Sanity-thumbnails
- Het oude “D”-bloklogo — nu het officiële wordmark-SVG
- `downloads/` (yt-dlp-hulp) en screenshot-PNG’s uit ontwikkeling
