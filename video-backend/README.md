# Homelab video-backend

PHP 8.4 service that turns local MKVs into HLS on demand. No database. Stack-docs: repo-root [`README.md`](../README.md). Ontwerpcontract: [`video-backend-briefing.md`](video-backend-briefing.md).

Bronnamen: `titel [vimeoId].mkv` of `{vimeoId}.mkv`. Audio is spoorindex, niet taal: `0` Engels, `1` Nederlands. Sommige bronnen hebben `Original` i.p.v. `en`, of geen NL-spoor.

## Layout

| Path | Role |
|---|---|
| `SOURCE_DIR` | Read-only MKVs (`{id}.mkv` or `title [id].mkv`) |
| `CACHE_DIR` | HLS output `{id}/{recipe}/{audioIndex}/` |
| `METADATA_DIR` | One `{id}.json` per video |

Compose services: `video-api` (php-fpm), `video-worker` (FFmpeg queue), nginx for `/api` + `/hls`.

## Hardware encode

Default is `FFMPEG_VCODEC=auto`. At worker/API startup a tiny encode is tried, in this order:

`h264_nvenc` → `h264_qsv` → `h264_vaapi` → `h264_videotoolbox` → `libx264`

The result is cached per machine (hostname + GPU device fingerprint). Moving the stack to another server re-probes. Force a codec with `FFMPEG_VCODEC=libx264` (or nvenc/qsv/vaapi); if that codec fails, we still fall back to `libx264`.

`GET /api/health` reports `encoder` and `encoderRequested`.

The GPU must be visible **inside** the container:

```bash
# Intel / AMD (VAAPI / QSV)
docker compose -f docker-compose.yml -f docker-compose.gpu.yml up -d

# NVIDIA
# in docker-compose.gpu.yml, use gpus: all on video-worker + video-api
```

## Cleanup

```
docker compose exec video-worker php bin/cleanup.php --dry-run
docker compose exec video-worker php bin/cleanup.php
```

Never touches `SOURCE_DIR`.

## Tests

```
composer install
./vendor/bin/phpunit
```
