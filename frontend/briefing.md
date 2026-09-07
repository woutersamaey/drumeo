# Drumeo - The Method

This project is the frontend side of our Drumeo - The Method eLearning platform.

## Features
- Netflix-like experience in the browser
- Start screen shows 3 user profiles: Vic, Lenn, Wouter. No password protection
- Selecting a profile takes you to your homepage. You will see the Drumeo: The Method lessions. These are organized in chapters. Think of each chapter as a separate show (like Netflix has shows).
- Each show has episodes.
- The full video structure kan be found in "lessions".
- Details for 1 video, can be found in "lesson-json". The filename is the Vimeo ID.
- For each video, we have an MKV file in 4k + a JPG-thumbnail with the same filename. The source material is not usable as-is in the browser, so we have a video backend to convert and prepare the right formats. This backend is in the folder "video-backend". The backend is built separately from the frontend.
- Switching profiles is possible
- Keep track of what the user has seen.
- When a video is watched up until 15 sec from the end, the video is considered "watched" even though he did not see the last 15 sec.
- When a video is finished, show a pop-up teasing the next video. The next video auto-starts in 8 seconds with a countdown, for binge-watching. The pop-up also asks how the videolesson went and the user can click 1 of 4 emojis ranging for really great to crying. These should be large and easy to click. The user's "score" is stored, along with the timestamp of this rating. Don't delete old ratings. We encourage users to re-watch and re-rate the video lesson so we can track progress (first time may be bad, but a 2nd time may be better, we record everything). There is also a button to re-play the current video.
- The UI should be optimized for iPad (incl. 7 year old iPads), iPhone and Chrome.
- Video playback is preferred full-screen.
- Use the video backend to request videos and get the <video> tag for the player.
- The video backend generates video files on demand, so one may not be available right away. To help mitigate this, we will trigger the preparation of the next video when the current one is still playing. When exactly, is not decided yet, but we should leave ample time for the backend to process and prepare the next video (if needed - the video could be available from an earlier viewing).
- Make the UI nice and user-friendly, incl. kid-friendly, optimized for touch screens.
- The MKV-source videos and matching thumbnails are on the NAS at /Volumes/private/Drumeo . Videos are still being added. The JSON lesson files are complete, only videos and thumbnails are still in progress.
- The app must run with Docker Compose, and that setup must include both the frontend and the video backend
- Use MySQL as the database and Redis as the cache
- Tailwind CSS, latest version
- Code should be PHP 8.4
- Provide a section: Practice again. It should contain all videos that did not receive a top score
- On the homepage, the first item must be resuming from where the user last left off. Never start 5 seconds from the end of the last video they dismissed; instead, use the next video that is not marked as “watched”. If the user had not finished a video, that video can be resumed from that position (e.g. halfway through)
- Optimize the UI for fast loading (SPA).
- Make sure browser navigation (back, forward) works as expected.