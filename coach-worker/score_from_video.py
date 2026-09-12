#!/usr/bin/env python3
"""Extract a timed drum-note score from a Drumeo Method lesson MKV.

The videos put a high-contrast staff in a white band at the bottom, with a
blue playhead. We sample frames, OCR-ish the staff (blob y → instrument),
and map playhead x to time. Teacher speech over a play-along is ignored:
the staff is the source of truth.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

import numpy as np
from PIL import Image

PIECES = ("kick", "snare", "hat_closed", "hat_open", "crash", "ride", "tom_high", "tom_mid", "tom_floor")


def find_source(media_dir: str, vimeo_id: str) -> str | None:
    vid = str(vimeo_id)
    root = Path(media_dir)
    direct = root / f"{vid}.mkv"
    if direct.is_file():
        return str(direct)
    needle = f"[{vid}].mkv"
    try:
        names = os.listdir(root)
    except OSError:
        return None
    for name in names:
        if name.endswith(needle) or name == f"{vid}.mkv":
            p = root / name
            if p.is_file():
                return str(p)
    return None


def extract_frames(src: str, dest: str, fps: float = 5.0, width: int = 1280) -> list[str]:
    os.makedirs(dest, exist_ok=True)
    pattern = os.path.join(dest, "%05d.jpg")
    cmd = [
        "ffmpeg", "-y", "-hide_banner", "-loglevel", "error",
        "-i", src,
        "-vf", f"fps={fps},scale={width}:-1",
        "-q:v", "4",
        pattern,
    ]
    r = subprocess.run(cmd, capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(r.stderr.strip() or "ffmpeg failed")
    files = sorted(Path(dest).glob("*.jpg"))
    return [str(p) for p in files]


def notation_band(rgb: np.ndarray) -> np.ndarray | None:
    lum = rgb.mean(axis=2)
    frac = (lum > 230).mean(axis=1)
    mask = frac > 0.18
    best = (0, 0)
    s = None
    h = len(mask)
    for i, v in enumerate(mask):
        if v and s is None:
            s = i
        if s is not None and (not v or i == h - 1):
            e = i if not v else i + 1
            if e - s > best[1] - best[0]:
                best = (s, e)
            s = None
    if best[1] - best[0] < 40:
        return None
    return rgb[best[0] : best[1]]


def ink_mask(band: np.ndarray) -> np.ndarray:
    b = band.astype(np.float32)
    lum = b.mean(axis=2)
    sat = b.max(axis=2) - b.min(axis=2)
    return (lum < 225) & ((lum < 200) | (sat > 18))


def playhead_x(band: np.ndarray) -> tuple[int, float]:
    b = band.astype(np.float32)
    blue = b[:, :, 2] - 0.5 * b[:, :, 0] - 0.5 * b[:, :, 1]
    col = blue.mean(axis=0)
    sm = np.convolve(col, np.ones(9) / 9, mode="same")
    x = int(np.argmax(sm))
    return x, float(sm[x])


def staff_geometry(ink: np.ndarray) -> dict | None:
    """Locate the 5-line staff via rows that are almost entirely ink."""
    row = ink.mean(axis=1)
    # True staff lines cover most of the staff width (~0.6+). Beams are shorter.
    ys = np.where(row > 0.45)[0]
    if ys.size < 5:
        ys = np.where(row > 0.28)[0]
    if ys.size < 5:
        return None
    groups = []
    cur = [int(ys[0])]
    for y in ys[1:]:
        if y - cur[-1] <= 4:
            cur.append(int(y))
        else:
            groups.append(cur)
            cur = [int(y)]
    groups.append(cur)
    centers = sorted(int(np.mean(g)) for g in groups if len(g) >= 2)
    if len(centers) < 5:
        return None
    best = None
    best_err = 1e9
    for i in range(len(centers) - 4):
        w = centers[i : i + 5]
        d = np.diff(w)
        if d.min() < 8:
            continue
        err = float(np.std(d) / (np.mean(d) + 1e-6))
        if err < best_err:
            best_err = err
            best = w
    if best is None:
        best = centers[-5:]
    top, bot = best[0], best[-1]
    span = max(8, bot - top)
    space = span / 4.0
    return {"lines": list(best), "top": top, "bot": bot, "span": span, "space": space}


def y_to_piece(y: float, geo: dict, is_x: bool, circled: bool) -> str | None:
    lines = geo["lines"]
    space = geo.get("space") or (geo["span"] / 4.0)
    # Distance to each staff line, 0 = top line.
    # Percussion: hats/crash above; snare on 3rd line; kick in bottom space.
    if is_x or circled or y < lines[0] - 0.25 * space:
        if circled or y < lines[0] - 0.85 * space:
            return "crash"
        return "hat_closed"
    # nearest line index
    dists = [abs(y - ln) for ln in lines]
    nearest = int(np.argmin(dists))
    if y > lines[-1] - 0.35 * space:
        return "kick"
    if nearest == 2 or (lines[1] + 0.4 * space < y < lines[3] - 0.3 * space):
        return "snare"
    if y > lines[3]:
        return "kick"
    if nearest <= 0:
        return "tom_high"
    if nearest == 1:
        return "tom_mid"
    return "tom_floor"


def connected_blobs(mask: np.ndarray, min_area: int = 8) -> list[dict]:
    h, w = mask.shape
    seen = np.zeros_like(mask, dtype=np.uint8)
    blobs = []
    ys, xs = np.where(mask)
    for y, x in zip(ys.tolist(), xs.tolist()):
        if seen[y, x]:
            continue
        stack = [(y, x)]
        seen[y, x] = 1
        pts = []
        while stack:
            cy, cx = stack.pop()
            pts.append((cy, cx))
            for dy in (-1, 0, 1):
                for dx in (-1, 0, 1):
                    ny, nx = cy + dy, cx + dx
                    if 0 <= ny < h and 0 <= nx < w and mask[ny, nx] and not seen[ny, nx]:
                        seen[ny, nx] = 1
                        stack.append((ny, nx))
        if len(pts) < min_area:
            continue
        arr = np.array(pts)
        y0, x0 = int(arr[:, 0].min()), int(arr[:, 1].min())
        y1, x1 = int(arr[:, 0].max()), int(arr[:, 1].max())
        cy, cx = float(arr[:, 0].mean()), float(arr[:, 1].mean())
        hgt, wid = y1 - y0 + 1, x1 - x0 + 1
        blobs.append({
            "x": cx, "y": cy, "w": wid, "h": hgt, "area": len(pts),
            "x0": x0, "x1": x1, "y0": y0, "y1": y1,
        })
    return blobs


def _local_max(x: np.ndarray, dist: int, thr: float) -> list[int]:
    peaks = []
    last = -dist
    for i in range(1, len(x) - 1):
        if x[i] >= thr and x[i] >= x[i - 1] and x[i] >= x[i + 1] and i - last >= dist:
            peaks.append(i)
            last = i
    return peaks


def parse_staff(band: np.ndarray) -> list[dict]:
    """Column-wise read: each stem is a time slot, ink at hat/snare/kick rows."""
    px, pscore = playhead_x(band)
    work = band.copy()
    if pscore > 4:
        x0, x1 = max(0, px - 22), min(work.shape[1], px + 22)
        work[:, x0:x1] = 255
    ink = ink_mask(work)
    geo = staff_geometry(ink)
    if geo is None:
        return []
    lines = geo["lines"]
    top, bot, space = geo["top"], geo["bot"], geo["space"]
    h, w = ink.shape
    col = ink.mean(axis=0)
    xs = np.where(col > 0.015)[0]
    if xs.size < 10:
        return []
    left, right = int(xs[0]), int(xs[-1])
    width = max(10, right - left)
    # stems: vertical ink through the staff
    staff_band = ink[max(0, top - 2) : min(h, bot + 2)]
    stem = staff_band.mean(axis=0)
    peaks = _local_max(stem, dist=max(4, int(space * 0.35)), thr=max(0.12, float(np.median(stem[left:right]) * 2)))
    peaks = [p for p in peaks if left + 8 < p < right - 8]
    if len(peaks) < 4:
        peaks = _local_max(stem, dist=max(3, int(space * 0.25)), thr=0.08)
        peaks = [p for p in peaks if left + 8 < p < right - 8]

    # Blank staff lines so noteheads remain.
    notes_ink = ink.copy()
    for ln in lines:
        notes_ink[max(0, ln - 2) : min(h, ln + 3)] = False

    hat_lo, hat_hi = max(0, int(top - 1.7 * space)), max(1, int(top - 0.2 * space))
    snare_lo, snare_hi = int(lines[2] - 0.45 * space), int(lines[2] + 0.45 * space)
    kick_lo, kick_hi = int(lines[3] + 0.2 * space), min(h, int(bot + 0.85 * space))

    # Merge stem detections that sit on the same note column.
    merged_x: list[int] = []
    for x in peaks:
        if merged_x and x - merged_x[-1] < space * 0.45:
            if stem[x] > stem[merged_x[-1]]:
                merged_x[-1] = x
            continue
        merged_x.append(x)

    notes = []
    pad = max(3, int(space * 0.32))
    hat_widths = []
    for x in merged_x:
        sl = slice(max(0, x - pad), min(w, x + pad + 1))
        bar = float(ink[top:bot, sl].mean()) if bot > top else 0.0
        if bar > 0.42:
            continue  # barline
        hat = float(ink[hat_lo:hat_hi, sl].mean()) if hat_hi > hat_lo else 0.0
        snare = float(notes_ink[snare_lo:snare_hi, sl].mean()) if snare_hi > snare_lo else 0.0
        kick = float(notes_ink[kick_lo:kick_hi, sl].mean()) if kick_hi > kick_lo else 0.0
        hat_row = ink[hat_lo:hat_hi, :].mean(axis=0) if hat_hi > hat_lo else np.zeros(w)
        # width of hat-mark around x (circled crash is wider)
        hw = 0
        i = x
        while i < w and hat_row[i] > 0.05:
            hw += 1
            i += 1
        i = x - 1
        while i >= 0 and hat_row[i] > 0.05:
            hw += 1
            i -= 1
        if hat > 0.05:
            hat_widths.append(hw)
        chord = []
        if hat > 0.05:
            chord.append("hat_closed")
        if snare > 0.12:
            chord.append("snare")
        if kick > 0.12:
            chord.append("kick")
        if not chord:
            continue
        rel = (x - left) / width
        notes.append({"rel": round(rel, 4), "piece": chord[0], "chord": chord, "x": x, "hat_w": hw, "hat": round(hat, 3), "snare": round(snare, 3), "kick": round(kick, 3)})

    if hat_widths:
        med_w = float(np.median(hat_widths))
        for n in notes:
            if "hat_closed" in n["chord"] and n.get("hat_w", 0) > med_w * 1.28 + 3:
                if "crash" not in n["chord"]:
                    n["chord"].insert(0, "crash")
                    n["piece"] = "crash"
    return notes


def pattern_hash(band: np.ndarray, playhead: int) -> str:
    g = band.mean(axis=2)
    bw = (g < 200).astype(np.uint8) * 255
    x0 = max(0, playhead - 28)
    x1 = min(bw.shape[1], playhead + 28)
    bw[:, x0:x1] = 0
    small = np.array(Image.fromarray(bw).resize((48, 12), Image.NEAREST))
    return hashlib.sha1(small.tobytes()).hexdigest()[:12]


def analyze_frames(paths: list[str], fps: float) -> dict:
    events: list[dict] = []
    patterns: dict[str, list[dict]] = {}
    play_windows: list[list[float]] = []
    last_hash = None
    window_start = None
    last_rel = 0.0
    loops = 0
    ph_series = []
    for i, path in enumerate(paths):
        t = i / fps
        try:
            rgb = np.array(Image.open(path).convert("RGB"))
        except Exception:
            continue
        band = notation_band(rgb)
        if band is None:
            if window_start is not None:
                play_windows.append([round(window_start, 3), round(t, 3)])
                window_start = None
            last_hash = None
            continue
        px, pscore = playhead_x(band)
        if pscore < 4:
            continue
        hsh = pattern_hash(band, px)
        if hsh not in patterns:
            patterns[hsh] = parse_staff(band)
        notes = patterns[hsh]
        # staff bounds for rel playhead
        ink = ink_mask(band)
        xs = np.where(ink.mean(axis=0) > 0.02)[0]
        if xs.size < 10:
            continue
        left, right = int(xs[0]), int(xs[-1])
        rel = (px - left) / max(1, right - left)
        rel = float(np.clip(rel, 0, 1))
        if window_start is None:
            window_start = t
        # new loop when playhead jumps backwards
        if last_hash == hsh and rel + 0.12 < last_rel:
            loops += 1
        last_hash = hsh
        last_rel = rel
        ph_series.append({"t": round(t, 3), "rel": round(rel, 4), "hash": hsh, "pscore": round(pscore, 2)})
        # emit notes whose rel is within this playhead tick (and not already at this loop)
        # we instead reconstruct after the fact from ph_series + patterns
    if window_start is not None:
        play_windows.append([round(window_start, 3), round(len(paths) / fps, 3)])

    timed = []
    last_rel = 0.0
    last_notes: list[dict] = []
    for sample in ph_series:
        notes = patterns.get(sample["hash"]) or last_notes
        if notes:
            last_notes = notes
        rel = sample["rel"]
        t = sample["t"]
        crossed: list[dict] = []
        if rel + 0.12 < last_rel:
            crossed = [n for n in last_notes if n["rel"] > last_rel - 0.02] + [n for n in notes if n["rel"] <= rel + 0.01]
        else:
            crossed = [n for n in notes if last_rel - 0.005 < n["rel"] <= rel + 0.01]
        for n in crossed:
            for piece in n.get("chord") or [n["piece"]]:
                timed.append({"t": round(t, 3), "piece": piece, "rel": n["rel"]})
        last_rel = rel
    timed.sort(key=lambda e: (e["t"], e["piece"]))
    # must-play = pieces that appear
    must = sorted({e["piece"] for e in timed})
    return {
        "fps": fps,
        "events": timed,
        "patterns": {k: v for k, v in patterns.items()},
        "playWindows": _merge_windows(play_windows),
        "mustPlay": must,
        "playheadSamples": len(ph_series),
        "patternCount": len(patterns),
        "eventCount": len(timed),
    }


def _merge_windows(wins: list[list[float]], gap: float = 1.5) -> list[list[float]]:
    if not wins:
        return []
    wins = sorted(wins)
    out = [wins[0][:]]
    for a, b in wins[1:]:
        if a - out[-1][1] <= gap:
            out[-1][1] = max(out[-1][1], b)
        else:
            out.append([a, b])
    return out


def analyze_video(src: str, vimeo_id: str, fps: float = 5.0, work: str | None = None) -> dict:
    tmp = work or tempfile.mkdtemp(prefix="drumeo-score-")
    frames_dir = os.path.join(tmp, "frames")
    try:
        files = extract_frames(src, frames_dir, fps=fps)
        payload = analyze_frames(files, fps)
    finally:
        if work is None:
            shutil.rmtree(tmp, ignore_errors=True)
    payload["vimeoId"] = vimeo_id
    payload["source"] = os.path.basename(src)
    dur = 0.0
    try:
        r = subprocess.run(
            ["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", src],
            capture_output=True, text=True,
        )
        dur = float((r.stdout or "0").strip() or 0)
    except Exception:
        pass
    payload["duration"] = round(dur, 3)
    payload["newThisLesson"] = [p for p in payload.get("mustPlay") or [] if p in ("crash", "ride")]
    return payload


def apply_title_hints(payload: dict, title: str | None) -> dict:
    t = (title or "").lower()
    if "crash" in t:
        extra = []
        for e in payload.get("events") or []:
            if e.get("piece") == "kick" and float(e.get("rel") or 1) < 0.07:
                extra.append({**e, "piece": "crash"})
        payload["events"] = (payload.get("events") or []) + extra
        payload["events"].sort(key=lambda e: (e["t"], e["piece"]))
        payload["mustPlay"] = sorted(set(payload.get("mustPlay") or []) | {"crash"})
        payload["newThisLesson"] = sorted(set(payload.get("newThisLesson") or []) | {"crash"})
        payload["eventCount"] = len(payload["events"])
    return payload


def save_score(payload: dict, out_dir: str, vimeo_id: str) -> str:
    os.makedirs(out_dir, exist_ok=True)
    path = os.path.join(out_dir, f"{vimeo_id}.json")
    # drop bulky pattern blobs from the public score; keep events
    public = {
        "vimeoId": payload.get("vimeoId"),
        "duration": payload.get("duration"),
        "events": payload.get("events") or [],
        "playWindows": payload.get("playWindows") or [],
        "mustPlay": payload.get("mustPlay") or [],
        "newThisLesson": payload.get("newThisLesson") or [],
        "eventCount": payload.get("eventCount"),
        "patternCount": payload.get("patternCount"),
        "playheadSamples": payload.get("playheadSamples"),
        "source": payload.get("source"),
    }
    Path(path).write_text(json.dumps(public, ensure_ascii=False), encoding="utf-8")
    debug = os.path.join(out_dir, f"{vimeo_id}.debug.json")
    Path(debug).write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
    return path


def main() -> int:
    p = argparse.ArgumentParser(description="Build a timed drum score from a Drumeo lesson video")
    p.add_argument("--src", help="Path to the lesson MKV")
    p.add_argument("--vimeo", help="Vimeo id")
    p.add_argument("--media", default=os.environ.get("MEDIA_DIR", "/media/nas"))
    p.add_argument("--out", default=os.environ.get("SCORES_DIR", "/media/coach/scores"))
    p.add_argument("--fps", type=float, default=5.0)
    p.add_argument("--force", action="store_true", help="Re-analyze even if a score already exists")
    p.add_argument("--batch", help="JSON list of {vimeo,id,n,title} lessons")
    args = p.parse_args()

    jobs = []
    if args.batch:
        jobs = json.loads(Path(args.batch).read_text())
    elif args.vimeo:
        src = args.src or find_source(args.media, args.vimeo)
        if not src:
            print(f"no source for {args.vimeo}", file=sys.stderr)
            return 2
        jobs = [{"vimeo": args.vimeo, "src": src}]
    else:
        p.print_help()
        return 1

    os.makedirs(args.out, exist_ok=True)
    for job in jobs:
        vid = str(job.get("vimeo") or job.get("vimeoId") or "")
        outp = os.path.join(args.out, f"{vid}.json")
        if os.path.isfile(outp) and not args.force:
            print(f"skip {vid} (exists, use --force)")
            continue
        src = job.get("src") or find_source(args.media, vid)
        if not src:
            print(f"MISSING source {vid}", file=sys.stderr)
            continue
        print(f"analyze {vid} {src} …", flush=True)
        payload = analyze_video(src, vid, fps=args.fps)
        payload = apply_title_hints(payload, job.get("title"))
        save_score(payload, args.out, vid)
        print(
            f"  events={payload.get('eventCount')} patterns={payload.get('patternCount')} "
            f"must={payload.get('mustPlay')} windows={payload.get('playWindows')}",
            flush=True,
        )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
