#!/usr/bin/env python3
"""Exercise the shipped .htaccess with real Apache, not PHP's built-in server."""
import email.utils
import os
from pathlib import Path
import pwd
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

root = Path(__file__).resolve().parents[1]
apache = os.environ.get('APACHE_EXECUTABLE') or shutil.which('apache2') or shutil.which('httpd')
modules = Path(os.environ.get('APACHE_MODULE_DIRECTORY', '/usr/lib/apache2/modules'))
if not apache:
    raise SystemExit('Apache is required: install apache2 or set APACHE_EXECUTABLE/APACHE_MODULE_DIRECTORY.')

with tempfile.TemporaryDirectory(prefix='barelytics-apache-') as directory:
    work = Path(directory)
    work.chmod(0o755)
    assets = work / 'public' / 'barelytics'
    assets.mkdir(parents=True)
    shutil.copyfile(root / 'public/barelytics/.htaccess', assets / '.htaccess')
    shutil.copyfile(root / 'public/barelytics/track.js', assets / 'track.js')
    (assets / 'admin.js').write_text('// unrelated asset\n')
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    user = 'nobody' if os.getuid() == 0 else pwd.getpwuid(os.getuid()).pw_name
    # Parent hosting defaults intentionally give every asset a long lifetime.
    # Run again without optional header/expiry modules to verify graceful loading.
    for optional in (True, False):
        loads = ['mpm_event', 'authz_core'] + (['headers', 'expires'] if optional else [])
        configuration = '\n'.join([
            f'ServerRoot "{work}"', f'PidFile "{work}/httpd.pid"',
            f'Listen 127.0.0.1:{port}', 'ServerName localhost',
            *[f'LoadModule {name}_module "{modules}/mod_{name}.so"' for name in loads],
            f'User {user}', f'Group #{pwd.getpwnam(user).pw_gid}',
            f'ErrorLog "{work}/error.log"', f'DocumentRoot "{work}/public"',
            f'<Directory "{work}/public">', 'Require all granted', 'AllowOverride All',
            *(['Header set Cache-Control "public, max-age=86400"', 'ExpiresActive On', 'ExpiresDefault "access plus 1 day"'] if optional else []),
            '</Directory>',
        ])
        conf = work / 'httpd.conf'
        conf.write_text(configuration + '\n')
        subprocess.run([apache, '-t', '-f', str(conf)], check=True)
        process = subprocess.Popen([apache, '-X', '-f', str(conf)], stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        def request(name, headers=None):
            req = urllib.request.Request(f'http://127.0.0.1:{port}/barelytics/{name}', headers=headers or {})
            try:
                return urllib.request.urlopen(req, timeout=3)
            except urllib.error.HTTPError as response:
                return response
        try:
            for attempt in range(50):
                try:
                    response = request('track.js')
                    break
                except (OSError, urllib.error.URLError):
                    if process.poll() is not None:
                        raise RuntimeError(process.stderr.read().decode() + (work / 'error.log').read_text())
                    time.sleep(.1)
            else:
                raise RuntimeError('Apache did not start.')
            with response:
                assert response.status == 200, response.status
                assert response.read() == (assets / 'track.js').read_bytes()
                etag = response.headers['ETag']
                modified = response.headers['Last-Modified']
                assert etag and email.utils.parsedate_to_datetime(modified)
                if optional:
                    assert response.headers.get_all('Cache-Control') == ['no-cache, must-revalidate'], response.headers
                    assert response.headers.get('Expires') is None
            if optional:
                for validator in ({'If-None-Match': etag}, {'If-Modified-Since': modified}):
                    with request('track.js', validator) as response:
                        assert response.status == 304, response.status
                        assert response.headers.get_all('Cache-Control') == ['no-cache, must-revalidate'], response.headers
                with request('track.js?v=1.2.1') as response:
                    assert response.headers['Cache-Control'] == 'no-cache, must-revalidate'
                with request('admin.js') as response:
                    assert response.status == 200
                    assert 'max-age=86400' in response.headers['Cache-Control']
                    assert 'no-cache' not in response.headers['Cache-Control']
                    assert response.headers['Expires']
                # A normal FTP replacement with changed validators delivers new bytes.
                tracker = assets / 'track.js'
                tracker.write_bytes(tracker.read_bytes() + b'\n// synthetic update\n')
                stamp = tracker.stat().st_mtime + 2
                os.utime(tracker, (stamp, stamp))
                with request('track.js', {'If-None-Match': etag}) as response:
                    assert response.status == 200
                    assert response.headers['ETag'] != etag
                    assert response.read() == tracker.read_bytes()
            print(f'PASS Apache tracker cache, optional modules={optional}')
        finally:
            process.terminate()
            process.communicate(timeout=10)
