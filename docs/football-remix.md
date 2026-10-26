# Football Highlights — Two Version Exports

Create two separate MP4 versions from footage you own or are authorized to edit. **Version A** ranks scenes more heavily; **Version B** ranks audio/crowd-energy peaks more heavily. Each version has its own requested output duration (A defaults to 30 seconds; B defaults to 20 seconds), job status and downloadable export. Their selection can overlap, especially for short, quiet, static or low-signal footage. These are heuristic edits, not verified goal/event detection, and do not provide copyright clearance or guarantee avoidance of Content ID. Original uploads remain unchanged. Voice conversion, logo overlays, contact text, and manual candidate approval are not part of this feature yet.

## Run locally

From PowerShell in `D:\AI-Video-Studio`:

```powershell
php artisan migrate
npm.cmd run build
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\start-remix-worker.ps1
```

The worker launcher starts a hidden PHP CLI process and prints its PID. It does not launch or change the HTTP server. Logs are `storage/logs/remix-worker.log` and `storage/logs/remix-worker-error.log`. For an interactive worker instead, run `php artisan remix:work` in a separate terminal. Do not run both; a file lock permits only one worker. `php artisan remix:work --once` processes one pending job and exits. The worker must be restarted after a computer restart or PHP worker code changes. Stop it when idle with `Stop-Process -Id <printed PID>`; interrupting an active job may leave it running until timeout recovery.

Keep the existing working server command exactly:

```powershell
php -d upload_max_filesize=2048M -d post_max_size=2100M -d memory_limit=512M -d max_execution_time=600 -d max_input_time=600 -S 127.0.0.1:8000 -t D:\AI-Video-Studio\public scripts/router.php
```

1. Open `http://127.0.0.1:8000`, open a project, and upload or select a video. Source videos may be up to 60 minutes or 2 GB.
2. Below the timeline, choose **Version A · Scene-led** or **Version B · Audio-led**. Adjust that version's 1–900 second target (A defaults to 30; B to 20; both are capped at the source duration).
3. Confirm you own or have permission to edit the footage and generate the selected version. Generate A and B separately; the one local worker processes jobs one at a time. A highlight can be up to 15 minutes.
4. Pending means queued for the separate worker. Running shows coarse stage/clip progress; analysis can take time on a slow CPU. The page polls every 2.5 seconds and restores the latest ten jobs after refresh or source switching.
5. Switch between A and B to preview/download each completed MP4. Filenames include `version-a` or `version-b`. Previous completed exports remain available among the latest ten jobs for that source. A failed job shows a safe error and allows a new attempt.

Python 3 with its standard library is sufficient. Optional `.env` setting: `PYTHON_PATH=C:/path/to/python.exe` (an executable path, not a command with arguments). FFmpeg/ffprobe use the existing `FFMPEG_PATH` and `FFPROBE_PATH` configuration. No pip/npm dependencies were added. The existing `QUEUE_CONNECTION` is not used; the `remix_jobs` table itself is the durable local queue.

## Selection heuristic v1

- FFmpeg samples video at 2 fps, scales it to 320 pixels wide and publishes `lavfi.scene_score`. Normalize each score as `min(1, scene_score / 0.4)`.
- When audio exists, FFmpeg decodes mono signed 16-bit PCM at 8 kHz. Python calculates RMS dBFS in 0.5-second windows. Normalize energy between the 20th and 90th percentiles and clamp to 0–1. Ignore windows below −55 dBFS and treat a percentile spread below 3 dB as no useful audio signal. Constant crowd noise/silence therefore does not automatically become a peak.
- Candidate moments are scene/audio scores at least 0.35, supplemented by a regular grid for low-signal footage. Score each candidate using the maximum signals within ±2 seconds. Version A uses **0.70 × scene + 0.30 × audio**; Version B uses **0.30 × scene + 0.70 × audio**. If useful audio is missing, both fall back to scene score alone.
- Greedily choose highest-scoring windows (up to 8 seconds, with 35% lead-in). Prefer a strong scene boundary within one second of the proposed start. Reject overlaps, cap to source bounds and the requested total duration, fill remaining uncovered gaps, then merge adjacent windows. Render in source chronology.
- For short sources or targets longer than the source, output at most the whole source. Quiet/static sources use deterministic fallback excerpts; these are not evidence of a football event.

The scene metric is documented in the [FFmpeg select filter reference](https://ffmpeg.org/ffmpeg-filters.html#select_002c-aselect). These score weights are application heuristics, not a trained model or validated football accuracy measure.

## Rendering and lifecycle

Python invokes FFmpeg/ffprobe with argument arrays, `shell=False` (the Python default), bounded subprocess timeouts and network protocols disabled for uploaded sources. Laravel invokes Python using Symfony Process argument arrays. HTTP creation only inserts a row; it never renders or starts a shell. The fixed-argument PowerShell launcher is independent of request data.

Sources up to 60 minutes and outputs up to 15 minutes are supported. Jobs transition `pending → running → completed/failed`. The worker atomically claims pending records and uses a machine-local file lock to serialize rendering. A second click reuses an existing active job for the same media and version. Progress is coarse: 5–40% analysis, 40–90% segment encodes, 100% only after a verified output and completed database update. The worker allows three hours for a long render. Interrupted running rows older than the timeout plus 60 seconds are marked failed by the worker; retry creates a new job. There is no automatic retry or cancel UI.

Output is H.264 (`libx264`, `veryfast`, CRF 23, two encoder threads), AAC stereo, 25 fps, 1280×720, with letterboxing/pillarboxing based on display aspect ratio. Small sources may be upscaled; silent sources receive silent AAC. Segments are encoded one at a time and concatenated with fast-start MP4 metadata. Frame/audio packet rounding can cause small duration differences. Analysis decodes compressed footage and can be slow for high-resolution one-hour sources; no GPU or trained event models are used. It cannot reliably classify boundaries, wickets, attacks, goals or dismissals. Previewing and manually approving candidate moments before export remains a planned improvement.

Files live under the private disk at `projects/<project>/remixes/<job>/`. Originals are read-only inputs. Temporary clips are removed after processing; failed-job directories are cleaned. Laravel stores the output path privately and exposes only scoped stream/download URLs with range support. Deleting a source/project cleans its remix files and cascade-deletes job records; deletion while a render is running is rejected to avoid races. Pending jobs can be deleted with their source.

This app has **no user authentication**. Authorization here means the existing local-app boundary plus checking that the media belongs to the project and the job belongs to both on every endpoint. The POST is covered by Laravel's web CSRF protection. This does not create multi-user security: keep the development server on loopback. Public hosting would require authentication and per-user project policies.

## Endpoints

All paths start with `/projects/{project}/media/{media}/remixes`:

| Method | Suffix | Behavior |
| --- | --- | --- |
| POST | none | JSON `target_duration` integer 1–900, optional `variant` (`a` or `b`), and `authorized_footage: true`; returns 202 |
| GET | none | Latest ten jobs for the source |
| GET | `/{remix}` | Job status/progress, safe error and analysis report |
| GET | `/{remix}/stream` | Completed result, including HTTP byte ranges |
| GET | `/{remix}/stream?download=1` | Completed result as an attachment |

## Verification

```powershell
npm.cmd run build
php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml
python -m unittest discover -s processing -p test_*.py -v
```

PHPUnit uses an in-memory SQLite database and fake storage. It covers creation/validation, duplicate requests, project/media/job boundaries, pending/running/completed/failed states, safe errors, retry, deletion, interrupted-job recovery, real encoding, decoding, stream byte ranges and downloads. Python tests cover segment bounds/budget/overlap, deterministic fallback, synthetic scene cuts/audio peaks and silent portrait rendering with a pixel check for pillarboxing. These synthetic checks do not establish football highlight quality.

The timeline remains a source-view UI foundation. This milestone adds a separate automatic remix renderer; it does not implement timeline sequence persistence, manual multi-track editing or multi-track export. Next: review scored candidates against real authorized football footage, allow candidate approval/adjustment, and improve cancellation/recovery before expanding editing scope.
