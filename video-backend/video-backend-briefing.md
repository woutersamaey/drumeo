# Technische briefing — homelab video-backend

Oorspronkelijk ontwerpcontract. Operationele docs: [`README.md`](README.md) en de stack-README in de repo-root.

Bindend voor de coding agent. Geen extra productfeatures buiten deze tekst. Geen database.

## 1. Opdracht

Kleine backend die lokale MKV’s on-demand afspeelbaar maakt voor een eigen website.

- Homelab, Docker Compose, geen cloud.
- Bronnen: MKV, vaak 4K, clips van 4–6 minuten.
- Clients: oude iPad (2019–2020), moderne iPhone/iPad, desktop-Chrome.
- Levering: HLS. Safari speelt native. Overige browsers via JavaScript die **deze API zelf teruggeeft** (geen `<script src>` naar een JS-bestand).
- Audiokeuze via spoorindex (`0`, `1`, later `x`), niet via taal.
- Niets vooraf encoderen. Resultaat cachen. Prefetch van de volgende clip tijdens het kijken.
- Flexibel in recept, formaat en audiostream. Geen live, DRM, DASH, WebRTC of AV1-encode.

## 2. Doelen

1. MKV blijft archief; nooit als ruwe `<video src="*.mkv">`.
2. Remux als de codecs het toelaten; anders minimaal transcoden.
3. Capability-driven API: client beschrijft wat hij aankan, server kiest een recept.
4. Cache per `(bron, recept, audioIndex)`.
5. Afspelen pas als de HLS-variant VOD is (`ENDLIST`). Geen EVENT/live-start op de client: Safari (iPad) behandelt een groeiende playlist als live, start middenin en blokkeert seek.
6. Prefetch van N+1 verstoort N nooit.
7. Eén metadata-JSON per film in één schrijfbare map.
8. Laatst-afgespeeld bewaren.
9. Kapotte bronnen of encodes mogen FFmpeg niet eindeloos herstarten.
10. Eén GET-endpoint levert de player als `{ "html", "js" }`.

## 3. Niet-doelen

- Geen ABR-ladder (geen 480/720/1080/4K tegelijk).
- Geen live, RTMP, WebRTC, verplichte DASH, DRM, accounts, scrapers, upload-API.
- Geen AV1-encode, geen WebM als hoofdlevering.
- Geen User-Agent-detectie van iPad-modellen.
- Geen ondertitel-inbranding.
- Geen tweede FFmpeg per seek.
- Geen SQL-database, geen Redis, geen object storage.
- Geen extra sidecars naast `{id}.json` (geen `source.json`, `access.json`, `state`, `job.json`, `{id}.audio.json`).
- Geen link vanuit de player-response naar een extern JS-bestand of CDN.

## 4. Architectuur

```
[site] --REST--> [api] --queue/locks--> [worker + ffmpeg]
                      |
                      v
              CACHE_DIR  <--- nginx /hls
SOURCE_DIR (ro)
METADATA_DIR (rw, één json per id)
```

Compose-services:

| Service | Rol |
|---|---|
| `api` | REST, probe, offers, player-snippet, lastPlayed |
| `worker` | FFmpeg, queue, variantstatus in JSON |
| `nginx` | statische HLS + reverse proxy `/api` |

`api` en `worker` mogen dezelfde image zijn met een ander commando.

Volumes:

```
./bron      →  /media/bron:ro
./cache     →  /media/cache
./meta      →  /media/meta
```

Poorten: nginx naar buiten; API intern. GPU optioneel (`/dev/dri` of NVIDIA). Ontbreekt GPU → `libx264`.

## 5. Schijf

```
SOURCE_DIR/
  film1.mkv

METADATA_DIR/
  film1.json                 # enige sidecar per film

CACHE_DIR/
  film1/remux/0/
    master.m3u8
    index.m3u8
    seg_000.ts
  film1/avc_1080/1/
    …
```

- `id`: `^[a-zA-Z0-9_-]+$`. Geen submappen, geen `..`.
- Bron: `SOURCE_DIR/{id}.mkv`.
- Metadata: `METADATA_DIR/{id}.json`.
- HLS-pad: `CACHE_DIR/{id}/{recipe}/{audioIndex}/`.
- Publieke URL: `{PUBLIC_HLS_BASE}/{id}/{recipe}/{audioIndex}/master.m3u8`.
- Lockfiles alleen in `/tmp` tijdens een job, geen persistente state.

JSON atomair schrijven: tempfile in `METADATA_DIR` + rename.

## 6. Metadata-JSON

Eén bestand per video. Variants is een **array van objecten**, geen map met code-keys.

```json
{
  "id": "film1",
  "source": {
    "mtimeMs": 1730000000000,
    "size": 682344448,
    "probeError": null,
    "failCount": 0
  },
  "durationSec": 312.4,
  "video": {
    "index": 0,
    "codec": "hevc",
    "width": 3840,
    "height": 2160,
    "pixFmt": "yuv420p"
  },
  "audioTracks": [
    { "index": 0, "codec": "aac", "channels": 2 },
    { "index": 1, "codec": "dts", "channels": 6 }
  ],
  "lastPlayedAt": "2026-09-07T08:51:00Z",
  "variants": [
    {
      "recipe": "remux",
      "audioIndex": 0,
      "state": "ready",
      "mode": "remux",
      "protocol": "hls",
      "video": {
        "codec": "hvc1",
        "width": 3840,
        "height": 2160
      },
      "audio": {
        "index": 0,
        "codec": "aac",
        "channels": 2
      },
      "playlistPath": "film1/remux/0/master.m3u8",
      "segmentCount": 53,
      "durationReadySec": 312.4,
      "lastAccessAt": "2026-09-07T08:51:00Z",
      "readyAt": "2026-09-07T08:40:12Z",
      "failCount": 0,
      "lastFailAt": null,
      "error": null
    }
  ]
}
```

Variant-identiteit: combinatie `recipe` + `audioIndex`. Opzoeken in de array.

`missing` staat niet in `variants`. Eerste prepare voegt het object toe.

### Verplichte variantvelden

| Veld | Rol |
|---|---|
| `recipe` | `remux` \| `audio_aac` \| `avc_1080` |
| `audioIndex` | bronnenspoor 0, 1, … |
| `state` | `starting` \| `running` \| `ready` \| `failed` |
| `mode` | wat FFmpeg deed |
| `protocol` | nu altijd `hls` |
| `video` / `audio` | output, niet de bron |
| `playlistPath` | relatief t.o.v. `CACHE_DIR` |
| `segmentCount` / `durationReadySec` | voortgang |
| `lastAccessAt` | aanraking / cleanup |
| `readyAt` | moment VOD |
| `failCount` / `lastFailAt` / `error` | retry-stop |

`lastPlayedAt` staat op filmniveau. Alleen zetten bij `intent=play` zodra `playlistUrl` bestaat. Prefetch telt niet als afspelen.

## 7. Probe

Eerste request naar een id zonder JSON, of bron-mtime/size ≠ JSON: ffprobe, JSON aanmaken/bijwerken. Geen encode.

Na 3 mislukte probes: `source.probeError` gevuld, geen nieuwe probe tot `force` of tot mtime/size van de MKV verandert.

Audio: alleen indexes en codecs. Geen taalmapping.

## 8. Recepten

| recipe | Wanneer | Output |
|---|---|---|
| `remux` | video `h264` of `hevc`, 8-bit `yuv420p`, gekozen audio `aac` | copy + HLS; HEVC-tag `hvc1` |
| `audio_aac` | video zoals `remux`, audio niet AAC | video copy, audio AAC-LC stereo 128k |
| `avc_1080` | overige gevallen, of client zonder HEVC / maxHeight ≤ 1080 | H.264 High `yuv420p`, max 1920 breed, ~5 Mbps, AAC stereo 128k |

`FFMPEG_VCODEC=libx264\|h264_nvenc\|h264_qsv\|h264_vaapi`.  
`libx264`: `-preset veryfast`.  
GOP = 2× fps (24 fps → 48), `-sc_threshold 0`, `-hls_time 6`.

10-bit / 4:2:2 / 4:4:4 of HDR-bron → `avc_1080` (SDR). Geen HDR-remux in v1.

## 9. HLS

- Segmenten: MPEG-TS, 6 seconden.
- Tijdens encode: `EVENT`, geen `ENDLIST` (alleen voor voortgang op schijf).
- Na FFmpeg exit 0: `VOD` + `ENDLIST` + `#EXT-X-START:TIME-OFFSET=0`.
- `master.m3u8`: `BANDWIDTH`, `AVERAGE-BANDWIDTH`, `RESOLUTION`, `FRAME-RATE`, `CODECS`, `VIDEO-RANGE=SDR`.
- `BANDWIDTH` = piek (ruim).
- Muxed A/V per variant (geen alternate-audio-groepen).
- `playlistUrl` / player pas bij `ENDLIST`. Niet starten na 2 segmenten.

Schijf blijft leidend voor “bestaat het bestand?”:

- JSON `ready` + playlist met `ENDLIST` → klaar.
- JSON `ready` maar playlist weg → `missing`, JSON bijwerken.
- JSON weg, HLS wél aanwezig → JSON herstellen bij volgende query.

## 10. Capabilities

Client stuurt constraints. Server kiest recept + `audioIndex`.

```json
{
  "protocols": ["hls"],
  "videoCodecs": ["avc1", "hvc1"],
  "audioCodecs": ["mp4a.40.2"],
  "maxWidth": 3840,
  "maxHeight": 2160,
  "hdr": false,
  "nativeHls": true
}
```

Matching:

1. Recepten filteren op codecs en max-afmetingen.
2. Geen `hvc1` in `videoCodecs` → geen HEVC-`remux` / `audio_aac`.
3. `maxHeight` lager dan bron en bron > 1080 → `avc_1080` als AVC mag.
4. Onder kandidaten: bestaande `ready` eerst; anders lichtste mode (`remux` < `audio_aac` < `avc_1080`).
5. `audioIndex` verplicht of default `0`. Ongeldige index → `400` + beschikbare indexes.

Presets zitten in de frontend, niet in de API:

- moderne iOS: `hvc1`+`avc1`, max 4K, `nativeHls: true`
- oude iPad / Chrome: alleen `avc1`, max 1080, Chrome `nativeHls: false`

## 11. REST API

JSON UTF-8. Als `API_TOKEN` gezet is: `Authorization: Bearer …`.

### `GET /api/health`

`{ "ok": true, "ffmpeg": true }`

### `GET /api/videos`

Lijst ids uit `SOURCE_DIR`. Per item: `durationSec` indien bekend, `audioTracks`, `lastPlayedAt`, `variants` (korte state).

Kapotte bronnen blijven in de lijst met `source.probeError`; ze starten geen probe-loop.

### `GET /api/videos/{id}`

Probe-on-demand. Hele metadata-JSON (plus geen interne paden die buiten `playlistPath` vallen).  
404 als de MKV ontbreekt.

### `POST /api/playback/query`

Body: `videoId`, `audioIndex`, `capabilities`.

Response: één offer-object, zelfde velden als een variant plus:

```json
{
  "videoId": "film1",
  "recipe": "remux",
  "audioIndex": 0,
  "state": "missing",
  "mode": "remux",
  "protocol": "hls",
  "video": { "codec": "hvc1", "width": 3840, "height": 2160 },
  "audio": { "index": 0, "codec": "aac", "channels": 2 },
  "playlistUrl": null,
  "playerUrl": "/api/playback/player?videoId=film1&recipe=remux&audioIndex=0"
}
```

Geen geconcateneerde stringkey als identiteit. Identiteit = `videoId` + `recipe` + `audioIndex`.

### `POST /api/playback/prepare`

Body:

```json
{
  "videoId": "film1",
  "recipe": "remux",
  "audioIndex": 0,
  "intent": "play",
  "force": false
}
```

Of `videoId` + `audioIndex` + `capabilities` (server query’t intern het recept).

- `intent`: `play` \| `prefetch`
- `force: true` wist die cachemap, zet `failCount=0` / `error=null`, start opnieuw
- ready → `200` + status
- nieuw of lopend → `202` + status
- prefetch en queue vol → `429` + `Retry-After: 15`
- variant `failed` zonder force → `409`, geen FFmpeg
- onbekende audio-index / geen recept → `400`
- geen bron → `404`

### `GET /api/playback/status`

Query: `videoId`, `recipe`, `audioIndex`.

Zelfde statusobject als query, inclusief `progress` tijdens `running`:

```json
{
  "segmentCount": 8,
  "durationReadySec": 48,
  "durationTotalSec": 312.4
}
```

`playlistUrl` pas bij VOD (`ENDLIST`), anders `null`. Voortgang (`segmentCount` / `durationReadySec`) wél tijdens encode.

`intent=play` + speelbare url → `lastPlayedAt` en variant `lastAccessAt` bijwerken.  
Prefetch-poll: geen `lastPlayedAt`; `lastAccessAt` mag.

### `GET /api/playback/playlist`

Query: `videoId`, `recipe`, `audioIndex`.

`200`: `{ "playlistUrl": "…", "protocol": "hls" }`  
`409` bij `not_prepared` of `failed`.

### `GET /api/playback/player`

Query: `videoId`, `recipe`, `audioIndex`.

Response **altijd** JSON:

```json
{
  "html": "<div class=\"vp\">…</div>",
  "js": "(function(){ … })();"
}
```

Alleen deze twee keys: `html` en `js`.

Eisen aan dit endpoint:

- `html` is een fragment (geen volledig HTML-document). Bevat een `<video>` met `controls`, `playsinline`, `preload="metadata"`. Geen `<script src>`. Geen `<link>` naar JS.
- `js` is de **brontekst van een inline script**, zonder `<script>`-tags. De site doet zelf `script.textContent = response.js`.
- Geen URL naar hls.js, CDN of `/static/player.js`. Alles wat nodig is zit in `js` (inclusief een vendored HLS-MSE-speler als native HLS ontbreekt).
- `js` is zelfstandig: zoekt de video in het zojuist geïnjecteerde `html` (vast `id` of `data-vp` op de wrapper). Geen afhankelijkheid van globale site-bundels.
- Playlist-URL zit in het fragment of in het script (data-attribuut op `<video>`), afkomstig van deze server.
- Gedrag:
  - iOS / iPadOS: native `video.src = playlistUrl`.
  - Anders: ingebouwde HLS-via-MSE-logica. Niet `canPlayType('application/vnd.apple.mpegurl')` op Chrome als enige beslissing.
  - Player krijgt alleen een VOD-playlist. Geen live-duration / live-edge.
- Endpoint geeft `409` als de variant nog geen VOD-playlist heeft (`ENDLIST`). Eerst `prepare` + poll tot `state=ready`.
- Endpoint werkt niet als HTML-pagina (`Content-Type: application/json`).

### `GET /api/jobs/{jobId}`

Debug: videoId, recipe, audioIndex, pid, mode, intent, startedAt, exitCode.

### `DELETE /api/videos/{id}/cache`

Query optioneel `recipe`, `audioIndex`. Zonder filters: alle cachemapen van dat id. JSON behouden; betreffende variantrecords opkuisen of verwijderen. Nooit de MKV.

## 12. Queue, fails, prefetch

- Lock per `{id}/{recipe}/{audioIndex}` in `/tmp`.
- In-process queue. Geen Redis.
- `MAX_CONCURRENT_JOBS=2`
- `MAX_CONCURRENT_VIDEO_TRANSCODES=1`
- `play` vóór `prefetch`
- Zelfde variant al bezig + nieuwe `play` → upgrade, geen tweede FFmpeg
- Prefetch annuleert nooit `play`
- `remux` / `audio_aac` mogen naast één `avc_1080`
- Tweede `avc_1080` wacht
- Timeout `JOB_TIMEOUT_SECONDS=14400` → kill + fail
- Restart: geen FFmpeg + geen `ENDLIST` → abandoned = één fail

### Retry-stop

- `MAX_VARIANT_FAILS=3`. Daarna state `failed`. Prepare zonder force start geen FFmpeg.
- Probe: zelfde drempel op `source.failCount`.
- Abandoned EVENT zonder proces telt als fail.
- Prefetch op `failed` → status terug, niet enqueue’en.

Client prefetch: alleen N+1, zelfde `audioIndex` en capabilities. Geen hele lijst.

## 13. Cleanup

Apart commando in dezelfde image (`--dry-run` ondersteunen).

- Nooit `SOURCE_DIR`.
- Nooit mappen van varianten in `starting`/`running`.
- Leeftijd: `lastPlayedAt`, anders `lastAccessAt`, anders map-mtime.
- Ouder dan `MAX_AGE_DAYS` of cache > `MAX_CACHE_GB` (LRU): wis `CACHE_DIR/{id}/{recipe}/{audioIndex}/`.
- JSON blijft; variantrecord verwijderen of impliciet missing.
- `failed`-cache mag eerder weg; `failCount` blijft in JSON zodat hij niet meteen opnieuw encodeert.

## 14. Frontend-flow

Huidige clip:

1. Capabilities bepalen (preset in de site).
2. `POST /playback/query` met `audioIndex`.
3. `POST /playback/prepare` `intent=play`.
4. Poll `GET /playback/status` tot `state=ready` en `playlistUrl`.
5. `GET /playback/player` → `{ html, js }` in de pagina zetten en het script uitvoeren.

Tegelijk:

6. Zelfde capabilities + `audioIndex` voor N+1: `prepare` `intent=prefetch`.
7. Poll N+1 traag (5 s). Speler van N niet blokkeren.
8. Overgang: speelbaar → player-endpoint; anders `intent=play` (upgrade).

Chrome HEVC-fout: nieuwe query met alleen `avc1` en maxHeight 1080.

Site kiest spoor 0 of 1 in de UI (“spoor 1/2”), niet via locale.

## 15. Nginx

- `/api/` → api
- `/hls/` → `CACHE_DIR`
- `.m3u8` → `application/vnd.apple.mpegurl`
- `.ts` → `video/mp2t`
- Range-requests aan
- CORS als de site op een andere origin draait
- gzip alleen playlists
- HTTPS mag op de host; intern HTTP is oké

## 16. Config (env)

```
SOURCE_DIR=/media/bron
CACHE_DIR=/media/cache
METADATA_DIR=/media/meta
API_HOST=0.0.0.0
API_PORT=8080
API_TOKEN=
FFMPEG_BIN=ffmpeg
FFPROBE_BIN=ffprobe
FFMPEG_VCODEC=libx264
MAX_CONCURRENT_JOBS=2
MAX_CONCURRENT_VIDEO_TRANSCODES=1
MAX_VARIANT_FAILS=3
PREPARE_MIN_SEGMENTS=2   # unused for start (VOD-only); kept for env compat
JOB_TIMEOUT_SECONDS=14400
MAX_AGE_DAYS=90
MAX_CACHE_GB=80
PUBLIC_HLS_BASE=/hls
```

## 17. Compose

- `restart: unless-stopped`
- healthcheck op `/api/health`
- bron read-only
- `CACHE_DIR` en `METADATA_DIR` writable voor de worker-user
- FFmpeg in de image (niet een kale Alpine zonder encoders)
- README: hoe QSV/NVENC aan te zetten; default CPU

## 18. Acceptatie

- HEVC 8-bit + AAC + client met `hvc1` → `remux`, geen video-herencode.
- DTS + speelbare video → `audio_aac`.
- Client alleen `avc1` / maxHeight 1080 → `avc_1080`.
- `audioIndex=0` maakt geen cache voor spoor 1.
- Tweede prepare zelfde triple → geen tweede FFmpeg.
- Prefetch tijdens `avc_1080`-play start geen tweede video-transcode.
- Drie fails → geen nieuwe encode zonder `force`.
- `lastPlayedAt` wijzigt bij play, niet bij prefetch.
- Safari speelt pas VOD (seekbaar, start bij 0 of resume). Geen LIVE-indicator op iPad.
- `GET /playback/player` geeft precies `{ html, js }`; `js` bevat geen URL naar een JS-file; fragment + script speelt op iOS native en op Chrome via de meegeleverde JS.
- Ready overleeft restart zonder rework.
- Cleanup `--dry-run` raakt geen MKV.
- Ongeldige id → 400/404, geen padtraversal of shell-injectie.
- Geen database. Maximaal één JSON per id in `METADATA_DIR`.

## 19. Implementatievoorkeur

- Eén stack die de repo al gebruikt. Code in PHP.
- FFmpeg-beslisboom in één module.
- `METADATA_DIR/{id}.json` + HLS op schijf = bron van waarheid. RAM + lockfile = lopende job.
- Player-`js` mag hls.js **vendored en inline** bevatten. Niet laden via netwerk.
- Testdekking minimaal: receptmatching, idempotent prepare, fail-drempel, stale mtime, path safety, player-JSON-vorm.

## MKV-source
The MKV-source videos and matching thumbnails are on the NAS, currently mounted at "/Volumes/private/Drumeo" on this machine. Videos and thumbnails are still being added.


Dit is de hele scope.
