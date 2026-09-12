---
name: review-cal-feedback
description: >
  Read Wouter's kit-calibration test sessions (audio + click log + written
  feedback) and turn them into classifier/template fixes. Use when the user
  asks to process calibration feedback, review a cal session, improve drum
  detection from Wouter's notes, or runs /review-cal-feedback.
---

# Review calibration test feedback

After kit calibration Wouter plays a groove, watches the live staff, and writes whether it matched. That text, the session audio, hit log, and skipped pieces live together.

## Where

MySQL `coach_cal_sessions` (profile_id 3 = Wouter): `id`, `feedback`, `path`, `meta_path`, `duration_sec`, `created_at`.

Files (Docker volume `/media/coach`, host via `RECORDINGS_DIR`):

- `/media/coach/cal/<profileId>/<id>.m4a` (or `.webm`) — full calibration + testgroove
- `/media/coach/cal/<profileId>/<id>.json` — events, skipped, captures, `feedback`, `grooveHeard`

Local list:

```bash
docker compose exec -T mysql mysql -udrumeo -pdrumeo drumeo -e \
  "SELECT id, created_at, LEFT(feedback,120) FROM coach_cal_sessions WHERE profile_id=3 ORDER BY created_at DESC;"
```

Copy a session out:

```bash
docker compose cp frontend:/media/coach/cal/3/<id>.json /tmp/cal.json
docker compose cp frontend:/media/coach/cal/3/<id>.m4a /tmp/cal.m4a
```

API (Wouter cookie): `GET /app/coach/calibration/sessions` and `GET /app/coach/calibration/session/<id>`.

## What to do

1. Prefer rows with non-empty `feedback`, especially `meta.events` of type `detection_failed`.
2. Read `feedback` first. If detection failed, the user played the real piece ~8 times but the counter did not follow — **do not trust `captures` for that piece** (wrong drums may have been used earlier; we delete that piece's captures on abort).
3. Listen to the audio around the `detection_failed` timestamp: those hits are the ground truth for kick/hat/etc.
4. Open `meta.events` (`piece_start`, `hit`, `detection_failed` with `peak` rms/low/high/flux) and `meta.grooveHeard` when present.
5. Propose a concrete detector change (kick = low-band jump, hats = high-band, thresholds).
6. Re-test: `/calibratie` from scratch. Do not continue a polluted half-session.

## Do not

- Do not treat empty feedback as “staff was perfect”.
- Do not mix Vic/Lenn/Arthur into Wouter's kit templates.
- Do not require a cloud model to classify the take; local templates + this note are the loop.
