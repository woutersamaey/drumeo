#!/usr/bin/env python3
"""Build a teacher-drum reference from a lesson MKV (or any audio file)."""

from __future__ import annotations

import os
import subprocess
import tempfile
from pathlib import Path

import numpy as np
import soundfile as sf

from analyze import SR, teacher_ref_payload


def find_source(media_dir: str, vimeo_id: str) -> str | None:
    vid = str(vimeo_id)
    root = Path(media_dir)
    if not root.is_dir():
        return None
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


def ffmpeg_wav(src: str, dst: str, audio_index: int = 0, extra_af: str | None = None) -> None:
    af = extra_af or "highpass=f=40,loudnorm=I=-16:LRA=11:TP=-1.5"
    cmd = [
        "ffmpeg", "-y", "-hide_banner", "-loglevel", "error",
        "-i", src,
        "-map", f"0:a:{audio_index}?",
        "-vn", "-ac", "1", "-ar", str(SR),
        "-af", af,
        dst,
    ]
    r = subprocess.run(cmd, capture_output=True, text=True)
    if r.returncode != 0 or not os.path.isfile(dst) or os.path.getsize(dst) < 1000:
        # Fallback: first audio stream, no loudnorm (some clips have odd layouts).
        cmd = [
            "ffmpeg", "-y", "-hide_banner", "-loglevel", "error",
            "-i", src, "-vn", "-ac", "1", "-ar", str(SR), dst,
        ]
        r = subprocess.run(cmd, capture_output=True, text=True)
        if r.returncode != 0:
            raise RuntimeError(r.stderr.strip() or "ffmpeg failed")


def load_wav(path: str) -> np.ndarray:
    y, sr = sf.read(path, always_2d=False)
    if sr != SR:
        # soundfile won't resample; ffmpeg already did.
        pass
    if y.ndim > 1:
        y = y.mean(axis=1)
    return np.asarray(y, dtype=np.float32)


def extract_teacher(media_dir: str, vimeo_id: str, out_wav: str, audio_index: int = 0) -> np.ndarray:
    src = find_source(media_dir, vimeo_id)
    if src is None:
        raise FileNotFoundError(f"no source mkv for {vimeo_id}")
    os.makedirs(os.path.dirname(out_wav) or ".", exist_ok=True)
    ffmpeg_wav(src, out_wav, audio_index=audio_index)
    return load_wav(out_wav)


def decode_upload(src: str, dst_wav: str) -> np.ndarray:
    os.makedirs(os.path.dirname(dst_wav) or ".", exist_ok=True)
    ffmpeg_wav(src, dst_wav, audio_index=0, extra_af="highpass=f=40")
    return load_wav(dst_wav)


def build_ref(media_dir: str, vimeo_id: str, ref_dir: str, audio_index: int = 0) -> dict:
    ref_dir_p = Path(ref_dir)
    ref_dir_p.mkdir(parents=True, exist_ok=True)
    wav = str(ref_dir_p / f"{vimeo_id}.wav")
    y = extract_teacher(media_dir, vimeo_id, wav, audio_index=audio_index)
    payload = teacher_ref_payload(y, SR, vimeo_id)
    json_path = ref_dir_p / f"{vimeo_id}.json"
    import json
    json_path.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
    return payload
