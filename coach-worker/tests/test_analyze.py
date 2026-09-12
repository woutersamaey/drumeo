#!/usr/bin/env python3
"""Synthetic-click tests for the drum evaluation engines."""

from __future__ import annotations

import sys
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from analyze import detect_onsets, run_engines, score_match, match_onsets, estimate_tempo


SR = 22050


def clicks(times, sr=SR, n=int(0.012 * SR)):
    y = np.zeros(int(max(times) * sr) + sr, dtype=np.float32)
    burst = np.random.default_rng(0).normal(0, 1, n).astype(np.float32)
    burst *= np.hanning(n)
    for t in times:
        i = int(t * sr)
        y[i : i + n] += burst
    peak = np.max(np.abs(y)) + 1e-9
    return y / peak * 0.9


def test_detect_regular_clicks():
    times = np.arange(0.5, 4.0, 0.5)
    y = clicks(times)
    on = detect_onsets(y)
    assert on.size >= len(times) - 1
    # each expected click has a nearby onset
    for t in times:
        assert np.min(np.abs(on - t)) < 0.04


def test_match_late_student():
    t = np.arange(1.0, 6.0, 0.5)
    s = t + 0.03
    m = match_onsets(t, s)
    assert m["matched"] >= len(t) - 1
    assert m["mean_abs_err"] < 0.05
    assert score_match(m) > 70


def test_aligned_scores_high():
    times = np.arange(0.4, 8.0, 0.4)
    teacher = clicks(times)
    student = clicks(times)
    eng = run_engines(student, teacher)
    assert eng["hybrid"]["score"] >= 70
    assert eng["onset_match"]["score"] >= 70


def test_fast_student_tempo():
    t = np.arange(0.4, 8.0, 0.5)
    s = np.arange(0.4, 8.0, 0.5 / 1.15)
    teacher = clicks(t)
    student = clicks(s)
    eng = run_engines(student, teacher)
    ratio = eng["tempo"]["detail"]["ratio"]
    assert ratio is None or ratio > 1.05


def test_silence_is_low():
    times = np.arange(0.4, 6.0, 0.4)
    teacher = clicks(times)
    student = np.zeros(int(6 * SR), dtype=np.float32) * 1e-6
    eng = run_engines(student, teacher)
    assert eng["hybrid"]["score"] < 40
    assert eng["onset_match"]["detail"]["student_n"] < 5


if __name__ == "__main__":
    test_detect_regular_clicks()
    print("ok detect")
    test_match_late_student()
    print("ok match")
    test_aligned_scores_high()
    print("ok aligned", flush=True)
    test_fast_student_tempo()
    print("ok tempo")
    test_silence_is_low()
    print("ok silence")
    print("all ok")
