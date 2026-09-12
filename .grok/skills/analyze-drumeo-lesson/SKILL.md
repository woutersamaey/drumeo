---
name: analyze-drumeo-lesson
description: >
  Extract a timed drum-note score from Drumeo Method lesson videos (staff overlay +
  blue playhead) and store JSON for live dual-staff grading. Use when the user asks
  to analyse/analyze the next lessons, score a vimeo id, rebuild notation events,
  re-run a previously analysed video after a Grok/parser update, or runs
  /analyze-drumeo-lesson.
---

# Analyze Drumeo lesson videos

The staff in the video **is** the expected part. Teacher speech over a play-along does not matter.

## Output

`coach-worker/scores/<vimeoId>.json` (and `.debug.json`):

```json
{
  "vimeoId": "1098829785",
  "events": [{"t": 48.25, "piece": "crash"}, {"t": 48.25, "piece": "kick"}],
  "playWindows": [[20, 315]],
  "mustPlay": ["crash", "hat_closed", "kick", "snare"]
}
```

Pieces: `kick`, `snare`, `hat_closed`, `hat_open`, `crash`, `ride`, `tom_high`, `tom_mid`, `tom_floor`.

Kit aliases at grade time (not in the score file): any crash = `crash`; `tom_mid` → `tom_floor` if that profile skipped the 2nd tom.

Also copy into the coach volume so PHP can serve it:

`RECORDINGS_DIR/scores/<vimeoId>.json` → locally `/media/coach/scores/` in Docker.

## New lessons (ahead of the kids)

1. Find the profile's next lesson plus N ahead from catalog order + `watch_progress` (`watched=1`, furthest index).
2. Write a tiny JSON list `[{n,id,vimeo,title}, …]`.
3. Run:

```bash
python coach-worker/score_from_video.py \
  --batch that.json \
  --media "$DRUMEO_MEDIA" \
  --out coach-worker/scores \
  --fps 4
```

Skip existing files unless `--force`.

4. Copy `coach-worker/scores/*.json` to the coach volume (`docker compose cp` or rsync) **without** the `.debug.json` if you want it small — debug is fine to keep.
5. Spot-check: `eventCount` should be hundreds per play-along, not tens of thousands (hash churn) and not zero.
6. Confirm `mustPlay` matches the lesson title (crash lesson must include `crash`).

## Re-analyze an old video (parser/Grok update)

Same pipeline, add `--force` so the existing JSON is replaced:

```bash
python coach-worker/score_from_video.py \
  --vimeo 1098829785 \
  --media "$DRUMEO_MEDIA" \
  --out coach-worker/scores \
  --fps 4 \
  --force
```

Or `--batch` with `--force` for a set. Then copy JSON to the volume again. Live UI reads the file on the next page load; no DB migration.

## Local Python

`numpy`, `Pillow`, `ffmpeg` on PATH. Project venv or `/tmp/coach-venv`.

MKV lookup: `$DRUMEO_MEDIA/<vimeoId>.mkv` or `*[<vimeoId>].mkv`.

## Do not

- Do not send the student take to the cloud.
- Do not treat speech windows as the score; the playhead on the staff is the clock.
- Do not activate this coach UI for profiles with `coach_enabled=0` (only Wouter for now).
