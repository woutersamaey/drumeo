#!/usr/bin/env python3
"""Poll MySQL for queued drum recordings and teacher-ref jobs."""

from __future__ import annotations

import json
import os
import time
import traceback
from pathlib import Path

import pymysql

from analyze import comments_for, run_engines
from extract_ref import build_ref, decode_upload, find_source

DB = dict(
    host=os.environ.get("DB_HOST", "mysql"),
    port=int(os.environ.get("DB_PORT", "3306")),
    user=os.environ.get("DB_USER", "drumeo"),
    password=os.environ.get("DB_PASS", "drumeo"),
    database=os.environ.get("DB_NAME", "drumeo"),
    charset="utf8mb4",
    cursorclass=pymysql.cursors.DictCursor,
    autocommit=False,
)

MEDIA_DIR = os.environ.get("MEDIA_DIR", "/media/nas")
RECORDINGS_DIR = os.environ.get("RECORDINGS_DIR", "/media/coach")
REF_DIR = os.path.join(RECORDINGS_DIR, "refs")


def connect():
    return pymysql.connect(**DB)


def wait_db():
    last = None
    for _ in range(60):
        try:
            c = connect()
            c.close()
            return
        except Exception as e:
            last = e
            time.sleep(2)
    raise last  # type: ignore


def ensure_ref(cur, vimeo_id: str) -> dict | None:
    json_path = Path(REF_DIR) / f"{vimeo_id}.json"
    if json_path.is_file() and json_path.stat().st_size > 50:
        return json.loads(json_path.read_text(encoding="utf-8"))
    if find_source(MEDIA_DIR, vimeo_id) is None:
        return None
    try:
        return build_ref(MEDIA_DIR, vimeo_id, REF_DIR, audio_index=0)
    except Exception:
        traceback.print_exc()
        return None


def load_teacher_wav(vimeo_id: str):
    wav = Path(REF_DIR) / f"{vimeo_id}.wav"
    if not wav.is_file():
        return None
    from extract_ref import load_wav
    try:
        return load_wav(str(wav))
    except Exception:
        return None


def pick_recording(cur):
    cur.execute(
        """SELECT id, profile_id, lesson_id, vimeo_id, path, mime
           FROM coach_recordings
           WHERE status = 'queued'
           ORDER BY id ASC
           LIMIT 1
           FOR UPDATE SKIP LOCKED"""
    )
    return cur.fetchone()


def save_evals(cur, recording_id: int, engines: dict, comments: list[str]) -> None:
    cur.execute("DELETE FROM coach_evaluations WHERE recording_id = %s", (recording_id,))
    meta = engines.pop("_meta", {}) if "_meta" in engines else engines.get("_meta") or {}
    for name, payload in engines.items():
        if name.startswith("_"):
            continue
        score = payload.get("score")
        detail = dict(payload.get("detail") or {})
        if name == "hybrid":
            detail["comments"] = comments
            detail["meta"] = {k: meta[k] for k in ("student_n", "teacher_n", "sr") if k in meta}
            detail["student_onsets"] = meta.get("student_onsets") or []
            detail["teacher_onsets"] = meta.get("teacher_onsets") or []
        cur.execute(
            """INSERT INTO coach_evaluations (recording_id, engine, score, summary, status)
               VALUES (%s, %s, %s, %s, 'ready')""",
            (recording_id, name, score, json.dumps(detail, ensure_ascii=False)),
        )


def process_one(conn) -> bool:
    cur = conn.cursor()
    row = pick_recording(cur)
    if not row:
        conn.rollback()
        return False
    rid = int(row["id"])
    cur.execute("UPDATE coach_recordings SET status = 'analyzing' WHERE id = %s", (rid,))
    conn.commit()

    try:
        src = row["path"]
        if not src or not os.path.isfile(src):
            raise FileNotFoundError(f"missing upload {src}")
        wav = os.path.join(os.path.dirname(src), f"{rid}.wav")
        student = decode_upload(src, wav)
        vimeo_id = str(row["vimeo_id"])
        ref = ensure_ref(cur, vimeo_id)
        teacher = load_teacher_wav(vimeo_id) if ref else None
        engines = run_engines(student, teacher)
        comments = comments_for(engines)
        save_evals(cur, rid, engines, comments)
        cur.execute("UPDATE coach_recordings SET status = 'ready' WHERE id = %s", (rid,))
        conn.commit()
        print(f"ready recording {rid} hybrid={engines.get('hybrid', {}).get('score')}", flush=True)
    except Exception as e:
        traceback.print_exc()
        conn.rollback()
        cur = conn.cursor()
        cur.execute(
            "UPDATE coach_recordings SET status = 'failed' WHERE id = %s",
            (rid,),
        )
        conn.commit()
        print(f"failed recording {rid}: {e}", flush=True)
    return True


def warmup_refs(conn) -> None:
    """Opportunistically build refs for queued/analyzing vimeo ids."""
    cur = conn.cursor()
    cur.execute("SELECT DISTINCT vimeo_id FROM coach_recordings WHERE status IN ('queued','analyzing','ready','uploading') LIMIT 20")
    ids = [str(r["vimeo_id"]) for r in (cur.fetchall() or [])]
    conn.rollback()
    for vid in ids:
        json_path = Path(REF_DIR) / f"{vid}.json"
        if json_path.is_file():
            continue
        try:
            ensure_ref(cur, vid)
            conn.commit()
        except Exception:
            conn.rollback()


def main() -> None:
    os.makedirs(RECORDINGS_DIR, exist_ok=True)
    os.makedirs(REF_DIR, exist_ok=True)
    print("coach-worker starting", flush=True)
    wait_db()
    idle = 0
    while True:
        conn = connect()
        try:
            did = process_one(conn)
            if not did:
                idle += 1
                if idle % 4 == 1:
                    warmup_refs(conn)
                time.sleep(1.5)
            else:
                idle = 0
        except Exception:
            traceback.print_exc()
            time.sleep(2)
        finally:
            conn.close()


if __name__ == "__main__":
    main()
