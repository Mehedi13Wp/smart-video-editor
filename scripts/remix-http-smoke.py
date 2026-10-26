"""Optional live smoke test against the existing loopback server and running remix worker.
Creates only a synthetic test project, then deletes that project on success.
"""
import http.cookiejar
import html
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[1]
BASE = os.environ.get('STUDIO_BASE_URL', 'http://127.0.0.1:8000')
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
page = opener.open(BASE, timeout=10).read().decode()
token = re.search(r'<meta name="csrf-token" content="([^"]+)"', page).group(1)
page_state = json.loads(html.unescape(re.search(r'data-page="([^"]+)"', page).group(1)))
inertia_version = page_state['version']


def request(path, method='GET', data=None, headers=None, csrf=True):
    values = {'Accept': 'application/json'}
    if csrf:
        values['X-CSRF-TOKEN'] = token
    values.update(headers or {})
    if isinstance(data, dict):
        data = json.dumps(data).encode()
        values['Content-Type'] = 'application/json'
    return opener.open(urllib.request.Request(BASE + path, data=data, headers=values, method=method), timeout=15)


with tempfile.TemporaryDirectory(prefix='remix-http-') as directory:
    source = Path(directory) / 'synthetic.mp4'
    subprocess.run([str(ROOT / 'tools/ffmpeg/bin/ffmpeg.exe'), '-v', 'error', '-y', '-f', 'lavfi', '-i',
                    'color=green:s=128x72:r=25:d=2', '-c:v', 'libx264', str(source)], check=True,
                   creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
    created = request('/projects', 'POST', {'name': 'Remix HTTP smoke ' + uuid.uuid4().hex[:8], 'description': 'Synthetic automated test only'})
    project_path = urllib.parse.urlparse(created.url).path
    assert re.fullmatch(r'/projects/\d+', project_path), created.url
    try:
        boundary = uuid.uuid4().hex
        body = (f'--{boundary}\r\nContent-Disposition: form-data; name="video"; filename="synthetic.mp4"\r\n'
                'Content-Type: video/mp4\r\n\r\n').encode() + source.read_bytes() + f'\r\n--{boundary}--\r\n'.encode()
        request(project_path + '/media', 'POST', body, {'Content-Type': 'multipart/form-data; boundary=' + boundary})
        workspace = json.load(request(project_path, headers={'X-Inertia': 'true', 'X-Inertia-Version': inertia_version}))
        media = workspace['props']['project']['media'][0]
        base = project_path + f'/media/{media["id"]}/remixes'
        try:
            request(base, 'POST', {'target_duration': 1, 'authorized_footage': True}, csrf=False)
            raise AssertionError('CSRF-free request was accepted')
        except urllib.error.HTTPError as failure:
            assert failure.code == 419, failure.code
        exports = {}
        for variant in ('a', 'b'):
            started = time.monotonic()
            response = request(base, 'POST', {'target_duration': 1, 'variant': variant, 'authorized_footage': True})
            assert response.status == 202
            job = json.load(response)
            assert job['variant'] == variant
            print(f'Version {variant.upper()} queued in {time.monotonic() - started:.2f}s', flush=True)
            deadline = time.monotonic() + 120
            while job['status'] in ('pending', 'running') and time.monotonic() < deadline:
                time.sleep(1)
                job = json.load(request(base + '/' + str(job['id'])))
            assert job['status'] == 'completed', job
            assert job['analysis']['selection_profile'] == ('scene_led' if variant == 'a' else 'audio_led')
            stream = base + f'/{job["id"]}/stream'
            range_response = request(stream, headers={'Range': 'bytes=0-9'})
            assert range_response.status == 206 and len(range_response.read()) == 10
            response = request(stream + '?download=1')
            assert 'attachment' in response.headers['Content-Disposition']
            assert f'version-{variant}-' in response.headers['Content-Disposition']
            result = Path(directory) / f'version-{variant}.mp4'
            result.write_bytes(response.read())
            subprocess.run([str(ROOT / 'tools/ffmpeg/bin/ffmpeg.exe'), '-v', 'error', '-i', str(result), '-f', 'null', '-'], check=True,
                           creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
            exports[variant] = job['id']
        assert exports['a'] != exports['b']
        print('PASS: CSRF, real upload, separate A/B jobs, scene/audio profiles, progress, range preview, labeled downloads and full decode.', flush=True)
    finally:
        try:
            request(project_path, 'DELETE')
            print('Removed only the synthetic smoke-test project.', flush=True)
        except urllib.error.HTTPError:
            print('Synthetic test project retained (possibly still rendering): ' + project_path, flush=True)
