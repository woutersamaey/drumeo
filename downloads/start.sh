#!/bin/bash

yt-dlp --referer "https://app.musora.com/drumeo" \
--write-all-thumbnails  \
-N 16 \
--audio-multistreams -f "bv*+ba[language=en]+ba[language=nl]" \
--merge-output-format mkv \
--batch-file urls.txt \
--download-archive already_done.txt
