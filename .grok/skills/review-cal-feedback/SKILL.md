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

1. Prefer rows with non-empty `feedback`.
2. Read `feedback` first — that is the ground truth of what the staff got wrong.
3. Open `meta.events` (clicks, skips, per-hit RMS/spectrum) and `meta.grooveHeard` (classified notes during the 20s test).
4. Listen to the audio if the text is ambiguous (wrong piece vs timing vs missing notes).
5. Propose a concrete change: skip-alias, template merge (two crashes → one `crash`), threshold, or staff mapping (`tom_mid` → `tom_floor`).
6. If you change the classifier, say how to re-test: `/calibratie` groove + new feedback row.

## Do not

- Do not treat empty feedback as “staff was perfect”.
- Do not mix Vic/Lenn/Arthur into Wouter's kit templates.
- Do not require a cloud model to classify the take; local templates + this note are the loop.
