#!/bin/bash

yt-dlp --referer "https://app.musora.com/drumeo" \
--write-all-thumbnails  \
-N 16 \
--audio-multistreams -f "bv*+ba[language=en]+ba[language=nl]" \
--merge-output-format mkv \
--batch-file urls.txt \
--download-archive already_done.txt


# Sommige videos hebben geen English [en] track, maar heet die gewoon "Original":
# yt-dlp --referer "https://app.musora.com/drumeo" --write-all-thumbnails  -N 16 --audio-multistreams -f "bv*+ba[format_id*=Original]+ba[language=nl]" --merge-output-format mkv "https://player.vimeo.com/video/1208247277

# Of sommige hebben geen Nederlands, wel Engels:
#yt-dlp --referer "https://app.musora.com/drumeo" --write-all-thumbnails  -N 16 --audio-multistreams -f "bv*+ba[language=en]" --merge-output-format mkv "https://player.vimeo.com/video/1109152907"