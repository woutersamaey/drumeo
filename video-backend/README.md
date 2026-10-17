# Homelab video-backend

PHP 8.4 service that turns local MKVs into HLS on demand. No database. Stack-docs: repo-root [`README.md`](../README.md). This README describes the current behaviour and is leading; [`video-backend-briefing.md`](video-backend-briefing.md) is the original design brief.

Bronnamen: `titel [vimeoId].mkv` of `{vimeoId}.mkv`. Audio is spoorindex, niet taal: `0` Engels, `1` Nederlands. Sommige bronnen hebben `Original` i.p.v. `en`, of geen NL-spoor.

## Layout

| Path | Role |
|---|---|
| `SOURCE_DIR` | Read-only MKVs (`{id}.mkv` or `title [id].mkv`). Never written or deleted. |
| `CACHE_DIR` | HLS output `{id}/{recipe}/{audioIndex}/` (served by nginx at `/hls`) |
| `METADATA_DIR` | One `{id}.json` per video; `logs/` (FFmpeg log per variant); `.cleanup-last-run` |
| `$TMPDIR` | `vb-locks/` (job locks + flock mutexes), `vb-jobs/` (job history), encoder/source-index caches. Compose mounts the shared `video-tmp` volume at `/shared-tmp`, so api and worker see the same locks and they survive restarts. |

Compose services: `video-api` (php-fpm), `video-worker` (`bin/worker.php`: FFmpeg queue + daily cleanup), nginx for `/api` + `/hls`.

## Playback states

- A variant becomes `ready` only when the worker sees FFmpeg **exit 0** and a playlist with `ENDLIST` whose duration covers the source (slack: max(2 s, 2 %)). Anything else is `failed` with an `error` (e.g. `ffmpeg exit 1`, `incomplete output: 24.0s of 300.0s`, `timeout after 14400s`, `abandoned`).
- The api never promotes a variant to `ready` from disk; `failed` is never served (`status` has `playlistUrl: null`, `GET /api/playback/playlist` returns 409). A `ready` variant whose playlist turns out shorter than the source is demoted to `failed` on the next load.
- `MAX_VARIANT_FAILS` (3): after that, prepare without `force` returns 409. A failed prefetch never re-queues.
- Worker restart: jobs that were running count as one fail (`abandoned`); queued jobs simply start.

## Client flow

The site plays HLS itself (hls.js is a static frontend asset; Safari plays natively). The api has no player endpoint.

1. `POST /api/playback/query` `{videoId, audioIndex, capabilities}` → offer with `recipe` (`capabilities.nativeHls` is ignored and optional).
2. `POST /api/playback/prepare` `{videoId, recipe, audioIndex, intent}`: `200` ready, `202` queued/running, `409` failed (or `busy` for `force`), `429` + `Retry-After: 15` for a prefetch while the queue is full.
3. Poll `GET /api/playback/status?videoId=&recipe=&audioIndex=[&intent=play]` until `state=ready` and `playlistUrl` is set (`playlistPath` is the path relative to `/hls`). `GET /api/playback/playlist` returns just the URL (debugging).

Repeating `prepare` is safe and cheap: one lock per `(id, recipe, audioIndex)` means one job; a prepare for a queued/running/ready variant changes nothing (no log line, metadata writes at most once a minute for `lastAccessAt`). The next-lesson prefetch polls `prepare` with `intent: prefetch` every 5 s; it never re-queues a failed variant (409).

## Jobs, locks, heartbeat

- One lock per `(id, recipe, audioIndex)` in `vb-locks/`. Every read-modify-write holds a per-key `flock`; files are replaced atomically (tempfile + rename). The api only creates queued locks and patches `intent`/`cancel`; `pid`, `startedUnix` and `heartbeatUnix` are written by the worker only.
- api and worker have separate PID namespaces, so the api never looks at PIDs: a running job is "active" while its heartbeat (refreshed every 5 s) is younger than 30 s.
- Job records (`vb-jobs/{jobId}.json`, `GET /api/jobs/{jobId}`) are history only. The worker prunes finished records after 7 days and queued/running records that no lock refers to.

## Admin-only operations

Need header `X-Drumeo-Admin: $ADMIN_TOKEN` (empty `ADMIN_TOKEN` = disabled, 401):

- `GET /api/admin/overview`, `POST /api/admin/cleanup` (`{"confirm":true}`)
- `POST /api/playback/prepare` with `"force": true` (deletes that cache, resets `failCount`, re-encodes; 409 `busy` while a job for it is queued/running)
- `DELETE /api/videos/{id}/cache[?recipe=&audioIndex=]` (cancels queued/running jobs for those variants, deletes their cache dirs and variant records; JSON and MKV stay)

Playback endpoints are open (trusted LAN; external access is behind htpasswd on the outer proxy). `API_TOKEN`, if set, requires `Authorization: Bearer …` on everything except `/api/health`. No CORS headers: the site is same-origin. `audioIndex` must be a non-negative integer (400 otherwise). Unexpected errors return JSON `500 {"error":"internal error"}` and are logged.

## Hardware encode

`FFMPEG_VCODEC=libx264` (image default; production) or `auto`, `h264_nvenc`, `h264_qsv`, `h264_vaapi`, `h264_videotoolbox`. `auto` tries:

`h264_nvenc` → `h264_qsv` → `h264_vaapi` → `h264_videotoolbox` → `libx264`

A hardware candidate is smoke-tested with the exact `avc_1080` argv a job uses (`FfmpegCommand`), on a one-second H.264 sample. The result is cached per machine (hostname + GPU device fingerprint) in `$TMPDIR/vb-encoder.json`. An explicit codec that fails falls back to `libx264`. If an `avc_1080` job fails on a hardware encoder, it is retried once with `libx264` before it counts as a fail. VAAPI uses `-vaapi_device` + `-hwaccel vaapi` before `-i`; `h264_videotoolbox` only exists when the worker runs natively on macOS.

`GET /api/health` reports `ffmpeg`, `encoder` and `encoderRequested`.

The GPU must be visible **inside** the container:

```bash
# Intel / AMD (VAAPI / QSV)
docker compose -f docker-compose.yml -f docker-compose.gpu.yml up -d

# NVIDIA
# in docker-compose.gpu.yml, use gpus: all on video-worker + video-api
```

## Cleanup

The worker runs it automatically every `CLEANUP_INTERVAL_SECONDS` (default 86400; `0` = off), tracked in `METADATA_DIR/.cleanup-last-run`. Manually:

```
docker compose exec video-worker php bin/cleanup.php --dry-run
docker compose exec video-worker php bin/cleanup.php
```

Rules: failed variants; variants unused for `MAX_AGE_DAYS` (age = most recent of `lastPlayedAt` and `lastAccessAt`, else dir mtime); then least recently used while the cache exceeds `MAX_CACHE_GB`. Never a variant with a job lock or in `starting`/`running`. Deletes only under `CACHE_DIR` and refuses anything inside `SOURCE_DIR`.

## Config (env)

Besides the paths: `ADMIN_TOKEN`, `API_TOKEN`, `FFMPEG_VCODEC`, `FFMPEG_BIN`, `FFPROBE_BIN`, `MAX_CONCURRENT_JOBS` (2), `MAX_CONCURRENT_VIDEO_TRANSCODES` (1), `MAX_VARIANT_FAILS` (3), `JOB_TIMEOUT_SECONDS` (14400), `FFPROBE_TIMEOUT_SECONDS` (60), `MAX_AGE_DAYS` (90), `MAX_CACHE_GB` (80), `CLEANUP_INTERVAL_SECONDS` (86400), `PUBLIC_HLS_BASE` (`/hls`), `TMPDIR`.

php-fpm pool: `docker/zz-root.conf` (runs as root, `request_terminate_timeout = 300`).

## Tests

```
composer install
./vendor/bin/phpunit
```

`FfmpegIntegrationTest` uses the real `ffmpeg`/`ffprobe` and is skipped when they are not installed.
