"""Local, deterministic highlight heuristic. Standard library only; see docs/football-remix.md."""
import argparse
from array import array
import json
import math
from pathlib import Path
import re
import subprocess
import sys
import tempfile


def run(args, timeout=3600):
    return subprocess.run(args, check=True, capture_output=True, timeout=timeout,
                          creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0)).stdout


def progress(value):
    print(json.dumps({'progress': value}), flush=True)


def probe(binary, source):
    return json.loads(run([binary, '-v', 'error', '-protocol_whitelist', 'file,pipe',
                           '-show_format', '-show_streams', '-of', 'json', str(source)], 30))


def scene_scores(ffmpeg, source):
    # Two small frames/second; FFmpeg publishes lavfi.scene_score on selected frames.
    raw = run([ffmpeg, '-hide_banner', '-v', 'error', '-nostdin', '-threads', '2',
               '-protocol_whitelist', 'file,pipe', '-i', str(source), '-map', '0:v:0', '-an',
               '-filter_threads', '1', '-vf',
               "fps=2,scale=320:-2,select='gte(scene,0)',metadata=print:file=-",
               '-f', 'null', '-']).decode('utf-8', errors='replace')
    scores, timestamp = [], 0.0
    for line in raw.splitlines():
        match = re.search(r'pts_time:([\d.eE+-]+)', line)
        if match:
            timestamp = float(match.group(1))
        if line.startswith('lavfi.scene_score='):
            scores.append((timestamp, min(1.0, float(line.split('=')[1]) / 0.4)))
    return scores


def audio_scores(ffmpeg, source):
    raw = run([ffmpeg, '-v', 'error', '-nostdin', '-threads', '2', '-protocol_whitelist',
               'file,pipe', '-i', str(source), '-map', '0:a:0', '-vn', '-ac', '1', '-ar',
               '8000', '-f', 's16le', '-'])
    samples = array('h')
    samples.frombytes(raw)
    if sys.byteorder != 'little':
        samples.byteswap()
    values = []
    for start in range(0, len(samples), 4000):
        chunk = samples[start:start + 4000]
        rms = math.sqrt(sum(x * x for x in chunk) / len(chunk)) / 32768
        values.append(20 * math.log10(max(rms, 1e-6)))
    if not values:
        return []
    ordered = sorted(values)
    low, high = ordered[int((len(ordered) - 1) * .2)], ordered[int((len(ordered) - 1) * .9)]
    # Silence/constant crowd noise is not itself a peak.
    if high - low < 3:
        return []
    return [(i * .5, max(0, min(1, (value - low) / (high - low))))
            for i, value in enumerate(values) if value > -55]


def select_segments(duration, target, scenes, audio, variant='a'):
    """Rank windows, reject overlaps, then fill uncovered gaps to the requested budget."""
    budget = min(duration, target)
    length = min(8.0, budget)
    candidates = {round(t, 3) for t, s in scenes + audio if s >= .35}
    candidates.update(i * length + length / 2 for i in range(math.ceil(duration / length)))
    scored = []
    for moment in candidates:
        scene = max((s for t, s in scenes if abs(t - moment) <= 2), default=0)
        energy = max((s for t, s in audio if abs(t - moment) <= 2), default=0)
        if audio:
            # A favors visible scene changes; B favors crowd/commentary energy.
            scene_weight = .7 if variant == 'a' else .3
            score = scene_weight * scene + (1 - scene_weight) * energy
        else:
            score = scene
        start = max(0, min(duration - length, moment - length * .35))
        # Prefer a nearby shot boundary for the start without changing the time budget.
        boundaries = [t for t, s in scenes if s >= .5 and abs(t - start) <= 1 and t + length <= duration]
        if boundaries:
            start = min(boundaries, key=lambda t: abs(t - start))
        scored.append((score, start))
    selected, remaining = [], budget
    for score, start in sorted(scored, key=lambda item: (-item[0], item[1])):
        size = min(length, remaining)
        if size <= .001:
            break
        end = min(duration, start + size)
        if not any(start < b and end > a for a, b, _ in selected):
            selected.append((start, end, score))
            remaining -= end - start
    # Low-signal and near-full-length requests still return useful, non-overlapping footage.
    cursor = 0.0
    for start, end, _ in sorted(selected) + [(duration, duration, 0)]:
        gap = min(start - cursor, remaining)
        if gap > .001:
            selected.append((cursor, cursor + gap, 0))
            remaining -= gap
        cursor = end
    # Merge adjacent windows to avoid unnecessary cuts/encodes.
    merged = []
    for start, end, score in sorted(selected):
        if merged and abs(merged[-1]['end'] - start) < .001:
            merged[-1]['end'] = end
            merged[-1]['score'] = max(score, merged[-1]['score'])
        else:
            merged.append({'start': start, 'end': end, 'score': round(score, 4)})
    return merged


def process(source, output, target, ffmpeg, ffprobe, variant='a'):
    source, output = Path(source).resolve(), Path(output).resolve()
    if source == output or output.exists():
        raise ValueError('Output must be a new file')
    data = probe(ffprobe, source)
    duration = float(data['format']['duration'])
    if not math.isfinite(duration) or not 0 < duration <= 3600 or not 1 <= target <= 900:
        raise ValueError('Unsupported duration')
    has_audio = any(s['codec_type'] == 'audio' for s in data['streams'])
    progress(5)
    scenes = scene_scores(ffmpeg, source)
    progress(25)
    audio = audio_scores(ffmpeg, source) if has_audio else []
    progress(40)
    segments = select_segments(duration, target, scenes, audio, variant)
    output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='work-', dir=output.parent) as work:
        work = Path(work)
        for index, segment in enumerate(segments):
            size = segment['end'] - segment['start']
            args = [ffmpeg, '-v', 'error', '-nostdin', '-y', '-threads', '2',
                    '-protocol_whitelist', 'file,pipe', '-ss', str(segment['start']), '-i', str(source)]
            if not has_audio:
                args += ['-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo']
            # Fit the display aspect ratio (including anamorphic SAR), then letterbox to 720p.
            args += ['-map', '0:v:0', '-map', '0:a:0' if has_audio else '1:a:0',
                     '-t', str(size), '-filter_threads', '1', '-vf',
                     "scale=w='max(2,trunc(min(1280,720*dar)/2)*2)':h='max(2,trunc(min(720,1280/dar)/2)*2)',setsar=1,pad=1280:720:(ow-iw)/2:(oh-ih)/2,fps=25",
                     '-af', 'aresample=48000:async=1:first_pts=0,apad', '-ac', '2',
                     '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-threads', '2',
                     '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '128k', '-map_metadata', '-1',
                     str(work / f'clip-{index}.mp4')]
            run(args)
            progress(40 + int(50 * (index + 1) / len(segments)))
        manifest = work / 'concat.txt'
        manifest.write_text(''.join(f"file 'clip-{i}.mp4'\n" for i in range(len(segments))), encoding='utf-8')
        final = work / 'result.mp4'
        run([ffmpeg, '-v', 'error', '-nostdin', '-y', '-f', 'concat', '-safe', '1', '-i',
             str(manifest), '-c', 'copy', '-t', str(min(target, duration)), '-movflags', '+faststart', str(final)])
        result = probe(ffprobe, final)
        codecs = {stream['codec_name'] for stream in result['streams']}
        if not {'h264', 'aac'} <= codecs or float(result['format']['duration']) <= 0:
            raise ValueError('Invalid rendered output')
        final.rename(output)
    report = {'segments': segments, 'source_duration': duration, 'output_duration': float(result['format']['duration']),
              'has_source_audio': has_audio, 'scene_candidates': sum(s >= .35 for _, s in scenes),
              'audio_candidates': sum(s >= .35 for _, s in audio), 'heuristic_version': 1,
              'variant': variant, 'selection_profile': 'scene_led' if variant == 'a' else 'audio_led'}
    output.with_suffix('.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
    progress(100)
    return report


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--input', required=True)
    parser.add_argument('--output', required=True)
    parser.add_argument('--target', type=float, required=True)
    parser.add_argument('--variant', choices=('a', 'b'), default='a')
    parser.add_argument('--ffmpeg', required=True)
    parser.add_argument('--ffprobe', required=True)
    args = parser.parse_args()
    try:
        process(args.input, args.output, args.target, args.ffmpeg, args.ffprobe, args.variant)
    except Exception:
        # Do not expose source paths, command lines or FFmpeg stderr to HTTP clients.
        print('Remix processing failed. Check the source and local processor configuration.', file=sys.stderr)
        sys.exit(1)
