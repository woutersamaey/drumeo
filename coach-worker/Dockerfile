FROM python:3.12-slim-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg libsndfile1 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r /app/requirements.txt

COPY analyze.py extract_ref.py worker.py score_from_video.py /app/
ENV PYTHONUNBUFFERED=1
CMD ["python", "-u", "/app/worker.py"]
