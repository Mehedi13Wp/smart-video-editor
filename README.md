# AI Video Studio — Local Sports Video Studio

A local, single-user Laravel 12 + Inertia 2 + React 19 + TypeScript application with SQLite. No Docker, paid APIs, external media services, GPU, or trained AI models.

## Start on Windows

From PowerShell:

```powershell
Set-Location D:\AI-Video-Studio
powershell -ExecutionPolicy Bypass -File scripts/setup.ps1
powershell -ExecutionPolicy Bypass -File scripts/start.ps1
```

Open http://127.0.0.1:8000. Setup installs Composer/npm dependencies, initializes `.env` and the application key, creates SQLite if missing, runs migrations, and checks TypeScript before building Vite assets. It stops on any failure and does not overwrite an existing database or application key. Registry/network access is required on the first successful setup.

The start script runs this exact server command:

```powershell
php -d upload_max_filesize=2048M -d post_max_size=2100M -d max_execution_time=600 -d max_input_time=600 -S 127.0.0.1:8000 -t public scripts/router.php
```

For frontend development, leave the PHP server running and run `npm.cmd run dev` in a second terminal. Stop Vite and run `npm.cmd run build` to return to compiled assets. If an interrupted Vite session leaves `public/hot`, remove that file before serving the production build.

## Features implemented

- Create, browse, search, rename, describe, and delete projects.
- Upload supported video containers, up to 2 GB and **3,600 seconds (60 minutes) inclusive**. The server checks MIME type, runs FFprobe against the actual file, requires a video stream with a known positive duration, and rejects corrupt files, audio-only media, and longer videos.
- Persistent media metadata: original filename, private storage path, size, duration, dimensions, codec, and trim markers.
- For large local files, copy a supported video into `D:\AI-Video-Studio\storage\app\private\incoming`, click **Check folder**, then **Add**. This avoids sending the video through the browser. FFprobe validates it and the app references the inbox original directly; it stays in the inbox even if its project media record is removed. The inbox folder is private to the local app and must not be deleted while an imported video is in use.
- HTML5 video preview with HTTP byte-range responses for seeking. MP4 with H.264/AAC is recommended for browser compatibility. A valid uploaded container can still contain a codec unsupported by the browser; the editor provides an error message and original download link.
- Set trim start/end with numeric inputs, sliders, or the playhead; preview the selection; save or reset trim markers. The server enforces `0 <= start < end <= duration`.
- Responsive dark dashboard, project counts, upload progress, validation feedback, media list, empty states, and deletion confirmations.
- Generate **Version A** (scene-change-led) and **Version B** (audio-energy-led) from the same source. Choose a separate target duration up to **900 seconds / 15 minutes** for each. Preview and download each MP4 independently; the source remains unchanged. Processing runs in a separate local worker and uses FFmpeg/FFprobe plus Python's standard library.
- Highlight selection uses scene and audio heuristics. It cannot reliably recognize cricket boundaries/wickets or football attacks/goals. Sports-specific event recognition, human review of candidate moments before rendering, voice changes, logo overlays and channel contact text are planned improvements.

Basic trim markers remain non-destructive metadata. Remix exports are separate MP4s. This local workspace has no authentication or multi-user isolation; the server binds only to loopback.

## Local configuration

Both `.env.example` and `config/video.php` specify absolute executable paths:

```dotenv
FFMPEG_PATH=D:/AI-Video-Studio/tools/ffmpeg/bin/ffmpeg.exe
FFPROBE_PATH=D:/AI-Video-Studio/tools/ffmpeg/bin/ffprobe.exe
DB_DATABASE=D:/AI-Video-Studio/database/database.sqlite
```

FFmpeg on PATH is never required. Upload validation uses FFprobe; FFmpeg generates real test fixtures. Uploaded videos live under `storage/app/private/projects`; no public storage symlink is needed. SQLite lives at `database/database.sqlite`. Back up both the database and private media directory together. Deleting a project removes its uploaded files and cascades its media records.

## Checks

After dependency installation:

```powershell
php artisan migrate --force
composer test
npm.cmd run typecheck
npm.cmd run build
php scripts/backend-smoke.php
```

The feature suite covers project CRUD, real uploads and metadata, byte ranges, trim validation, project-scoped media/remixes, A/B job creation and output streaming, file cleanup, corrupt/audio-only rejection, and the 3,600/3,601-second source boundary. The live smoke script uploads a synthetic source, generates both versions, tests preview/download, decodes both outputs, and removes its temporary project.

See [IMPLEMENTATION_STATUS.md](IMPLEMENTATION_STATUS.md) for the checks actually completed in this environment and any blockers. Source availability is not a claim that blocked integration checks passed.
