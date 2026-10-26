from pathlib import Path
import tempfile
import unittest

from football_remix import audio_scores, probe, process, run, scene_scores, select_segments

ROOT = Path(__file__).resolve().parents[1]
FFMPEG = str(ROOT / 'tools/ffmpeg/bin/ffmpeg.exe')
FFPROBE = str(ROOT / 'tools/ffmpeg/bin/ffprobe.exe')


class SelectionTest(unittest.TestCase):
    def test_budget_bounds_and_non_overlap(self):
        for duration, target in [(.2, 30), (2, 1), (40, 30), (3600, 900), (12, 900)]:
            segments = select_segments(duration, target, [(5, 1), (6, .8), (20, 1)], [(5, 1)])
            self.assertAlmostEqual(sum(s['end'] - s['start'] for s in segments), min(duration, target), places=3)
            for i, segment in enumerate(segments):
                self.assertGreaterEqual(segment['start'], 0)
                self.assertLessEqual(segment['end'], duration)
                self.assertGreater(segment['end'], segment['start'])
                if i:
                    self.assertGreaterEqual(segment['start'], segments[i - 1]['end'])

    def test_strong_moment_is_selected_and_static_fallback_is_deterministic(self):
        result = select_segments(100, 8, [(72, 1)], [(72, 1)])
        self.assertTrue(any(s['start'] <= 72 <= s['end'] for s in result))
        self.assertEqual(select_segments(100, 30, [], []), select_segments(100, 30, [], []))

    def test_two_profiles_choose_different_authorized_source_moments(self):
        scenes, audio = [(10, 1), (30, .5)], [(10, 0), (30, 1)]
        scene_led = select_segments(100, 8, scenes, audio, 'a')
        audio_led = select_segments(100, 8, scenes, audio, 'b')
        self.assertTrue(any(s['start'] <= 10 <= s['end'] for s in scene_led))
        self.assertTrue(any(s['start'] <= 30 <= s['end'] for s in audio_led))
        self.assertNotEqual(scene_led, audio_led)

    def test_silent_short_portrait_render_and_scene_metrics(self):
        with tempfile.TemporaryDirectory(prefix='remix-test-') as folder:
            source, output = Path(folder) / 'source.mp4', Path(folder) / 'output.mp4'
            run([FFMPEG, '-v', 'error', '-y', '-f', 'lavfi', '-i',
                 'color=black:s=90x160:r=25:d=1', '-f', 'lavfi', '-i', 'color=white:s=90x160:r=25:d=1',
                 '-filter_complex', '[0:v][1:v]concat=n=2:v=1:a=0[v]', '-map', '[v]', '-c:v', 'libx264', str(source)])
            scenes = scene_scores(FFMPEG, source)
            self.assertTrue(any(score > .5 for _, score in scenes))
            report = process(source, output, 900, FFMPEG, FFPROBE, 'b')
            self.assertEqual(report['variant'], 'b')
            self.assertEqual(report['selection_profile'], 'audio_led')
            self.assertFalse(report['has_source_audio'])
            self.assertAlmostEqual(report['output_duration'], 2, delta=.15)
            data = probe(FFPROBE, output)
            self.assertEqual({s['codec_name'] for s in data['streams']}, {'h264', 'aac'})
            self.assertEqual((data['streams'][0]['width'], data['streams'][0]['height']), (1280, 720))
            run([FFMPEG, '-v', 'error', '-i', str(output), '-f', 'null', '-'])
            # Read a white frame: black side bars prove portrait footage wasn't stretched.
            rgb = run([FFMPEG, '-v', 'error', '-ss', '1.5', '-i', str(output), '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'])
            self.assertLess(rgb[(360 * 1280 + 100) * 3], 10)
            self.assertGreater(rgb[(360 * 1280 + 640) * 3], 240)
            with self.assertRaises(ValueError):
                process(source, output, 30, FFMPEG, FFPROBE)

    def test_audio_energy_finds_loud_interval(self):
        with tempfile.TemporaryDirectory(prefix='remix-audio-') as folder:
            source = Path(folder) / 'audio.wav'
            run([FFMPEG, '-v', 'error', '-y', '-f', 'lavfi', '-i',
                 "aevalsrc=0.5*sin(2*PI*440*t)*if(between(t\\,2\\,3)\\,1\\,0.02):s=8000:d=5", str(source)])
            peaks = audio_scores(FFMPEG, source)
            best = max(peaks, key=lambda item: item[1])
            self.assertTrue(2 <= best[0] <= 3)
            self.assertGreater(best[1], .9)

    def test_multiple_segments_and_subsecond_source_render(self):
        with tempfile.TemporaryDirectory(prefix='remix-segments-') as folder:
            folder = Path(folder)
            source = folder / 'source.mp4'
            run([FFMPEG, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=black:s=64x64:r=25:d=10',
                 '-f', 'lavfi', '-i', 'color=white:s=64x64:r=25:d=10', '-filter_complex',
                 '[0:v][1:v]concat=n=2:v=1:a=0[v]', '-map', '[v]', '-c:v', 'libx264', str(source)])
            report = process(source, folder / 'output.mp4', 10, FFMPEG, FFPROBE)
            self.assertGreaterEqual(len(report['segments']), 2)
            self.assertAlmostEqual(report['output_duration'], 10, delta=.15)
            run([FFMPEG, '-v', 'error', '-i', str(folder / 'output.mp4'), '-f', 'null', '-'])
            tiny = folder / 'tiny.mp4'
            run([FFMPEG, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=green:s=64x64:r=25:d=0.2', '-c:v', 'libx264', str(tiny)])
            report = process(tiny, folder / 'tiny-output.mp4', 30, FFMPEG, FFPROBE)
            self.assertAlmostEqual(report['output_duration'], .2, delta=.1)


if __name__ == '__main__':
    unittest.main()
