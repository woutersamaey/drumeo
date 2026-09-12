#!/usr/bin/env python3
"""Drum-performance evaluation engines.

Compares a student recording to a teacher reference (extracted from the lesson
video). All engines are local DSP — numpy/scipy only.

Engines
-------
onset_match  Hit-by-hit timing against the teacher (primary score).
onset_dtw    Rhythm-pattern similarity via DTW on inter-onset intervals.
tempo        Global tempo ratio (too fast / too slow).
envelope     Dynamics alignment via RMS-envelope cross-correlation.
bands        Kick / snare / cymbal band onset match.
hybrid       Weighted blend of the above.
"""

from __future__ import annotations

import json
import math
from typing import Any

import numpy as np
from scipy.signal import butter, find_peaks, sosfilt, stft

SR = 22050
HOP = 256
N_FFT = 1024
REFRACTORY = 0.045
FLUX_K = 1.8
MATCH_WINDOW = 0.16


def _to_mono(y: np.ndarray) -> np.ndarray:
    y = np.asarray(y, dtype=np.float32)
    if y.ndim == 2:
        y = y.mean(axis=1)
    return y


def rms_envelope(y: np.ndarray, sr: int = SR, hop: int = HOP) -> np.ndarray:
    y = _to_mono(y)
    if y.size == 0:
        return np.zeros(0, dtype=np.float32)
    n = max(1, 1 + (len(y) - 1) // hop)
    out = np.zeros(n, dtype=np.float32)
    for i in range(n):
        sl = y[i * hop : i * hop + hop * 2]
        if sl.size:
            out[i] = float(np.sqrt(np.mean(sl * sl)))
    return out


def bandpass(y: np.ndarray, sr: int, lo: float, hi: float) -> np.ndarray:
    y = _to_mono(y)
    ny = sr / 2
    lo = max(20.0, lo) / ny
    hi = min(hi / ny, 0.98)
    if lo >= hi:
        return y
    sos = butter(2, [lo, hi], btype="band", output="sos")
    return sosfilt(sos, y).astype(np.float32)


def spectral_flux(y: np.ndarray, sr: int = SR, hop: int = HOP, n_fft: int = N_FFT) -> np.ndarray:
    y = _to_mono(y)
    if y.size < n_fft:
        return np.zeros(0, dtype=np.float32)
    _, _, z = stft(y, fs=sr, nperseg=n_fft, noverlap=n_fft - hop, boundary=None)
    mag = np.abs(z)
    # Emphasise drum transients (mid/high); speech is less impulsive.
    freqs = np.fft.rfftfreq(n_fft, 1 / sr)
    w = np.clip((freqs - 80.0) / 400.0, 0.15, 1.0)
    weighted = mag * w[:, None]
    diff = np.diff(weighted, axis=1, prepend=weighted[:, :1])
    flux = np.maximum(diff, 0.0).sum(axis=0)
    return flux.astype(np.float32)


def peak_times(flux: np.ndarray, sr: int = SR, hop: int = HOP, k: float = FLUX_K) -> np.ndarray:
    if flux.size < 8:
        return np.zeros(0, dtype=np.float64)
    med = float(np.median(flux))
    mad = float(np.median(np.abs(flux - med))) + 1e-9
    height = med + k * mad * 1.4826
    distance = max(1, int(REFRACTORY * sr / hop))
    idx, _ = find_peaks(flux, height=height, distance=distance)
    return (idx * hop / sr).astype(np.float64)


def detect_onsets(y: np.ndarray, sr: int = SR) -> np.ndarray:
    return peak_times(spectral_flux(y, sr), sr)


def detect_band_onsets(y: np.ndarray, sr: int = SR) -> dict[str, np.ndarray]:
    return {
        "kick": detect_onsets(bandpass(y, sr, 30, 140)),
        "snare": detect_onsets(bandpass(y, sr, 160, 450) + 0.6 * bandpass(y, sr, 1800, 4500)),
        "cymbal": detect_onsets(bandpass(y, sr, 6000, 11000)),
    }


def estimate_tempo(onsets: np.ndarray) -> float | None:
    if onsets.size < 8:
        return None
    ioi = np.diff(onsets)
    ioi = ioi[(ioi > 0.12) & (ioi < 1.6)]
    if ioi.size < 4:
        return None
    # Fold to eighth-note-ish range around 80–180 BPM.
    bpm = 60.0 / ioi
    bpm = np.where(bpm < 70, bpm * 2, bpm)
    bpm = np.where(bpm > 180, bpm / 2, bpm)
    bpm = bpm[(bpm >= 70) & (bpm <= 180)]
    if bpm.size == 0:
        return None
    return float(np.median(bpm))


def match_onsets(teacher: np.ndarray, student: np.ndarray, window: float = MATCH_WINDOW) -> dict[str, Any]:
    t = np.asarray(teacher, dtype=np.float64)
    s = np.asarray(student, dtype=np.float64)
    if t.size == 0:
        return {
            "hit_rate": 0.0,
            "precision": 1.0 if s.size == 0 else 0.0,
            "mean_abs_err": None,
            "median_err": None,
            "matched": 0,
            "teacher_n": 0,
            "student_n": int(s.size),
            "errors": [],
        }
    used = np.zeros(s.size, dtype=bool)
    errors: list[float] = []
    matched = 0
    for tv in t:
        if s.size == 0:
            break
        d = np.abs(s - tv)
        j = int(np.argmin(d))
        if used[j] or d[j] > window:
            continue
        used[j] = True
        errors.append(float(s[j] - tv))
        matched += 1
    hit_rate = matched / t.size
    precision = matched / s.size if s.size else 0.0
    arr = np.array(errors, dtype=np.float64) if errors else np.zeros(0)
    return {
        "hit_rate": float(hit_rate),
        "precision": float(precision),
        "mean_abs_err": float(np.mean(np.abs(arr))) if arr.size else None,
        "median_err": float(np.median(arr)) if arr.size else None,
        "matched": matched,
        "teacher_n": int(t.size),
        "student_n": int(s.size),
        "errors": [round(e, 4) for e in errors[:400]],
    }


def score_match(m: dict[str, Any], window: float = MATCH_WINDOW) -> float:
    timing = 0.0
    if m["mean_abs_err"] is not None:
        timing = max(0.0, 1.0 - float(m["mean_abs_err"]) / window)
    # F1 of hit-rate/precision, then blend timing.
    h, p = float(m["hit_rate"]), float(m["precision"])
    f1 = 0.0 if (h + p) == 0 else 2 * h * p / (h + p)
    return float(100.0 * (0.55 * f1 + 0.45 * timing))


def ioi_sequence(onsets: np.ndarray, max_n: int = 400) -> np.ndarray:
    o = np.asarray(onsets, dtype=np.float64)
    if o.size < 3:
        return np.zeros(0, dtype=np.float64)
    ioi = np.diff(o)
    ioi = ioi[(ioi > 0.06) & (ioi < 2.0)]
    if ioi.size > max_n:
        ioi = ioi[:max_n]
    med = float(np.median(ioi)) if ioi.size else 1.0
    if med > 1e-6:
        ioi = ioi / med  # tempo-invariant rhythm
    return ioi.astype(np.float64)


def dtw_distance(a: np.ndarray, b: np.ndarray) -> float:
    if a.size == 0 or b.size == 0:
        return 1.0
    # Cap length so n*m stays reasonable on a homelab box.
    a = a[:500]
    b = b[:500]
    n, m = len(a), len(b)
    inf = 1e9
    prev = np.full(m + 1, inf, dtype=np.float64)
    prev[0] = 0.0
    for i in range(1, n + 1):
        cur = np.full(m + 1, inf, dtype=np.float64)
        ai = a[i - 1]
        for j in range(1, m + 1):
            cost = abs(ai - b[j - 1])
            cur[j] = cost + min(prev[j], cur[j - 1], prev[j - 1])
        prev = cur
    path = n + m
    return float(prev[m] / max(1, path))


def envelope_correlation(a: np.ndarray, b: np.ndarray) -> dict[str, Any]:
    if a.size < 16 or b.size < 16:
        return {"corr": 0.0, "lag_frames": 0}
    a = a.astype(np.float64)
    b = b.astype(np.float64)
    a = a - a.mean()
    b = b - b.mean()
    na = np.linalg.norm(a) + 1e-9
    nb = np.linalg.norm(b) + 1e-9
    a = a / na
    b = b / nb
    n = min(len(a), len(b))
    a = a[:n]
    b = b[:n]
    corr = np.correlate(a, b, mode="full")
    lag = int(np.argmax(corr) - (n - 1))
    peak = float(corr.max()) if corr.size else 0.0
    return {"corr": max(-1.0, min(1.0, peak)), "lag_frames": lag}


def comments_for(engines: dict[str, dict[str, Any]], lang: str = "nl") -> list[str]:
    notes: list[str] = []
    match = engines.get("onset_match") or {}
    det = match.get("detail") or {}
    tempo = engines.get("tempo") or {}
    bands = engines.get("bands") or {}
    hybrid = engines.get("hybrid") or {}
    score = float(hybrid.get("score") or 0)

    student_n = int(det.get("student_n") or 0)
    teacher_n = int(det.get("teacher_n") or 0)
    if teacher_n >= 8 and student_n < max(4, teacher_n * 0.15):
        notes.append("We hoorden bijna geen drums. Speel stevig mee met de leraar.")
        return notes

    ratio = tempo.get("detail", {}).get("ratio")
    if isinstance(ratio, (int, float)):
        if ratio > 1.08:
            notes.append("Je speelde te snel. Adem rustig en volg de beat.")
        elif ratio < 0.92:
            notes.append("Je speelde te traag. Loop een tikkie strakker met de leraar.")

    hit = det.get("hit_rate")
    prec = det.get("precision")
    if isinstance(hit, (int, float)) and hit < 0.45 and teacher_n > 10:
        notes.append("Je sloeg minder noten mee dan de leraar.")
    if isinstance(prec, (int, float)) and prec < 0.45 and student_n > 10:
        notes.append("Je sloeg extra noten tussendoor. Houd het patroon eenvoudig.")

    med = det.get("median_err")
    if isinstance(med, (int, float)):
        if med > 0.04:
            notes.append("Je slagen kwamen vaak nét te laat.")
        elif med < -0.04:
            notes.append("Je slagen kwamen vaak nét te vroeg.")

    bd = bands.get("detail") or {}
    kick_s = (bd.get("kick") or {}).get("score")
    cym_s = (bd.get("cymbal") or {}).get("score")
    if isinstance(kick_s, (int, float)) and kick_s < 40 and teacher_n > 10:
        notes.append("We hoorden weinig bassdrum. Voel de kick in je rechtervoet.")
    if isinstance(cym_s, (int, float)) and cym_s < 40 and teacher_n > 10:
        notes.append("De hi-hat / bekkens liepen minder mee. Let op je linkerhand.")

    if score >= 85:
        notes.append(" Super! Je speelde strak mee.")
    elif score >= 70:
        notes.append("Goed bezig. Nog een keertje en het zit nog vaster.")
    elif score >= 50:
        notes.append("Je speelt mee — timing kan nóg strakker.")
    elif not notes:
        notes.append("Moeilijke les! Volg rustig de beat van de leraar.")
    return notes[:5]


def run_engines(
    student: np.ndarray,
    teacher: np.ndarray | None,
    sr: int = SR,
) -> dict[str, dict[str, Any]]:
    student = _to_mono(student)
    s_on = detect_onsets(student, sr)
    s_env = rms_envelope(student, sr)
    s_bands = detect_band_onsets(student, sr)
    s_tempo = estimate_tempo(s_on)

    engines: dict[str, dict[str, Any]] = {}
    if teacher is None or _to_mono(teacher).size < sr:
        activity = min(100.0, 12.0 * math.sqrt(max(0, s_on.size)))
        engines["activity"] = {
            "score": round(activity, 1),
            "detail": {"student_n": int(s_on.size), "tempo": s_tempo},
        }
        engines["hybrid"] = {
            "score": round(activity * 0.5, 1),
            "detail": {"note": "no_teacher_ref"},
        }
        return engines

    teacher = _to_mono(teacher)
    t_on = detect_onsets(teacher, sr)
    t_env = rms_envelope(teacher, sr)
    t_bands = detect_band_onsets(teacher, sr)
    t_tempo = estimate_tempo(t_on)

    m = match_onsets(t_on, s_on)
    engines["onset_match"] = {"score": round(score_match(m), 1), "detail": m}

    dtw = dtw_distance(ioi_sequence(t_on), ioi_sequence(s_on))
    dtw_score = max(0.0, 100.0 * math.exp(-3.2 * dtw))
    engines["onset_dtw"] = {
        "score": round(dtw_score, 1),
        "detail": {"dtw": round(dtw, 4), "teacher_ioi": int(ioi_sequence(t_on).size), "student_ioi": int(ioi_sequence(s_on).size)},
    }

    ratio = None
    tempo_score = 50.0
    if s_tempo and t_tempo:
        ratio = s_tempo / t_tempo
        tempo_score = max(0.0, 100.0 * math.exp(-8.0 * abs(math.log(max(ratio, 1e-6)))))
    engines["tempo"] = {
        "score": round(tempo_score, 1),
        "detail": {"student_bpm": s_tempo, "teacher_bpm": t_tempo, "ratio": ratio},
    }

    env = envelope_correlation(s_env, t_env)
    env_score = max(0.0, 100.0 * (env["corr"] + 1.0) / 2.0)
    engines["envelope"] = {
        "score": round(env_score, 1),
        "detail": {**env, "lag_sec": round(env["lag_frames"] * HOP / sr, 3)},
    }

    band_scores = {}
    band_acc = []
    for name in ("kick", "snare", "cymbal"):
        bm = match_onsets(t_bands[name], s_bands[name])
        sc = score_match(bm)
        band_scores[name] = {"score": round(sc, 1), **{k: bm[k] for k in ("hit_rate", "precision", "teacher_n", "student_n")}}
        band_acc.append(sc)
    engines["bands"] = {
        "score": round(float(np.mean(band_acc)) if band_acc else 0.0, 1),
        "detail": band_scores,
    }

    weights = {
        "onset_match": 0.32,
        "onset_dtw": 0.18,
        "tempo": 0.14,
        "envelope": 0.18,
        "bands": 0.18,
    }
    hybrid = 0.0
    wsum = 0.0
    for k, w in weights.items():
        hybrid += w * float(engines[k]["score"])
        wsum += w
    engines["hybrid"] = {
        "score": round(hybrid / wsum, 1),
        "detail": {"weights": weights},
    }
    engines["_meta"] = {
        "student_onsets": [round(float(x), 3) for x in s_on[:800]],
        "teacher_onsets": [round(float(x), 3) for x in t_on[:800]],
        "student_n": int(s_on.size),
        "teacher_n": int(t_on.size),
        "sr": sr,
    }
    return engines


def teacher_ref_payload(y: np.ndarray, sr: int = SR, vimeo_id: str = "") -> dict[str, Any]:
    y = _to_mono(y)
    on = detect_onsets(y, sr)
    bands = detect_band_onsets(y, sr)
    tempo = estimate_tempo(on)
    env = rms_envelope(y, sr)
    return {
        "vimeoId": vimeo_id,
        "sr": sr,
        "duration": round(len(y) / sr, 3),
        "tempo": tempo,
        "onsets": [round(float(x), 3) for x in on.tolist()],
        "bands": {k: [round(float(x), 3) for x in v.tolist()] for k, v in bands.items()},
        "envHop": HOP,
        "env": [round(float(x), 5) for x in env[::2].tolist()[:8000]],
    }


def dumps(obj: Any) -> str:
    return json.dumps(obj, ensure_ascii=False, separators=(",", ":"))
