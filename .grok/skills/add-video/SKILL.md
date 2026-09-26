---
name: add-video
description: >
  Voeg een losse Drumeo-videoles toe vanaf een YouTube-URL: hoogste kwaliteit
  downloaden, als MKV bij de bronnen zetten, thumbnail en catalog.json bijwerken,
  en lokaal controleren dat de les speelt. Geen trim. Use when the user runs
  /add-video, or says "voeg een losse les toe", "YouTube-les", "videoles van
  deze URL", or "add a YouTube lesson".
---

# Losse les vanaf YouTube

Zet één YouTube-video om in een losse les. De les komt in `catalog.json` onder `loose`, niet in de Method-volgorde. Geen lesnummer, geen automatische volgende video.

Commit, push of deploy alleen als de gebruiker dat vraagt. Commit `grok-build-cli-sessions.zip` niet.

## Bron

Lees `DRUMEO_MEDIA` uit `.env`. Lokaal is dat de NAS-map met de andere `{id}.mkv`-bestanden. Dezelfde share staat op de productieserver op `/mnt/drumeo`.

Neem alleen de video-id uit de URL. Negeer playlist-, radio- en start-parameters. Voorbeeld: `https://www.youtube.com/watch?v=AJQuqSujhB0&list=...` wordt `AJQuqSujhB0`.

## Download

`yt-dlp -F` op `https://www.youtube.com/watch?v=ID`. Kies zelf het formaat. `bv*+ba` is niet de hoogste kwaliteit: die pakt vaak AV1 met een veel lagere bitrate.

Op de hoogste resolutie het videostream met de hoogste bitrate nemen, plus het beste AAC-audiospoor (meestal formaat `140`). Dat is H.264, zodat de speler kan remuxen.

```bash
mkdir -p /tmp/yt-$ID
yt-dlp --no-playlist -f "VIDEO+140/VIDEO_HTTPS+140" --merge-output-format mkv \
  --write-thumbnail --convert-thumbnails jpg \
  -o "/tmp/yt-$ID/%(id)s.%(ext)s" \
  "https://www.youtube.com/watch?v=$ID"
```

`VIDEO` is het gekozen formaat-id. Controleer met `ffprobe`: resolutie, codec, duur.

## Bestanden

- MKV naar `$DRUMEO_MEDIA/$ID.mkv`, zelfde rechten als de buurbestanden. Verwijder `com.apple.FinderInfo` en `com.apple.quarantine` als die xattrs erop zitten.
- Thumbnail naar `thumbs/$ID.jpg`. De yt-dlp-jpg is genoeg. Ontbreekt die, pak dan één frame een paar seconden na het begin.

## Catalogus

Voeg een object toe aan de `loose`-array in `catalog.json`. Niet aan `lessons` of `paths`. `Catalog.php` zet `role` op `loose` en houdt de les uit `order`.

- `id`: eerstvolgende vrije id vanaf `900001`
- `vimeoId`: de YouTube-id. Dat veld is de mediainaam, ook als het geen Vimeo is
- `seconds`: afgeronde duur uit ffprobe
- `role`: `loose`
- `title.en` en `title.nl`: allebei `Artiest - Nummer`, dezelfde tekst. De speler toont de titel van de gekozen audiotaal. Artiest is degene die het nummer opnam, niet het YouTube-kanaal. Voorbeeld: `AC/DC - Back in Black`
- `description`: `en` en `nl`
- `difficulty`: `Exercise` / `Oefening`
- `instructor`: kanaal- of persoonsnaam van de video
- `thumb`: `thumbs/$ID.jpg`

`python3 -c 'import json; json.load(open("catalog.json"))'` moet slagen.

## Lokaal laten zien

`Catalog.php` onthoudt de catalogus in het PHP-proces én in Redis (`catalog:v8`, `media-index:v3`). Na de JSON-wijziging:

```bash
docker compose exec -T redis redis-cli DEL catalog:v8 media-index:v3
docker kill --signal=USR2 drumeo-frontend-1
docker compose exec -T frontend rm -f /tmp/drumeo-media-index-v3.json
```

Controleer met het profielcookie `drumeo_profile=wouter`:

- `GET /app/bootstrap` bevat de les in `loose`, `lessonTotal` blijft het Method-aantal, en de id staat in `available`
- `POST /api/playback/query` met `audioIndex` 0 en AVC-1080-capabilities geeft een recipe terug, geen fout

YouTube heeft één audiospoor. De speler valt van Nederlands terug op dat spoor.

Open daarna `/loose` en `/watch/{id}` in de browser. De video moet starten, de eindkaart toont score, **Opnieuw** en **Klaar**, en er is geen knop **Volgende**.
