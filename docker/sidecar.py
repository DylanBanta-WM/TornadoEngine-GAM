#!/usr/bin/env python3
"""GAM sidecar for Tornado Engine.

Runs GAM for the website over HTTP on the private Docker network. Every request needs the token
the website made (storage/app/gam/sidecar-token, mounted at /tornado), or GAM_SIDECAR_TOKEN. Commands come as a list of arguments and never go through a shell.

  GET  /status   {"configured": bool}
  POST /run      {"args": [...], "slots": 5, "wait": 10, "timeout": 60}
                 ->  {"exit": int, "stdout": str, "stderr": str}
                 At most "slots" GAM commands run at once. A command that can't start within
                 "wait" seconds gets 429 (busy); one that runs past "timeout" is stopped (504).
  POST /config   {"files": {"oauth2.txt": "...", ...}}  saves GAM's credential files
  POST /config/remove                                   deletes them
"""
import hmac
import json
import os
import subprocess
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

TOKEN_FILE = '/tornado/sidecar-token'


def token():
    # GAM_SIDECAR_TOKEN from .env, or the one the website made (read each time: it may appear later).
    if os.environ.get('GAM_SIDECAR_TOKEN'):
        return os.environ['GAM_SIDECAR_TOKEN']
    try:
        with open(TOKEN_FILE) as handle:
            return handle.read().strip()
    except OSError:
        return ''
CONFIG_DIR = os.environ.get('GAMCFGDIR', '/gam')
CONFIG_FILES = ('oauth2.txt', 'oauth2service.json', 'client_secrets.json')
MAX_BODY = 1_000_000

# How many GAM commands are running, shared by every request thread. The limit comes with each
# request (the website's setting), so changing it never needs a restart.
running = 0
running_changed = threading.Condition()


def take_slot(slots, wait):
    global running
    deadline = time.monotonic() + wait
    with running_changed:
        while running >= slots:
            left = deadline - time.monotonic()
            if left <= 0:
                return False
            running_changed.wait(left)
        running += 1
        return True


def give_slot():
    global running
    with running_changed:
        running -= 1
        running_changed.notify_all()


def number(value, default, low, high):
    return min(high, max(low, value)) if isinstance(value, (int, float)) and not isinstance(value, bool) else default

def configured():
    return all(os.path.isfile(os.path.join(CONFIG_DIR, name)) for name in CONFIG_FILES)


class Handler(BaseHTTPRequestHandler):
    def reply(self, status, body):
        data = json.dumps(body).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def authorized(self):
        given = self.headers.get('Authorization', '').removeprefix('Bearer ')
        expected = token()
        return bool(expected) and hmac.compare_digest(given, expected)

    def body(self):
        length = int(self.headers.get('Content-Length') or 0)
        if length > MAX_BODY:
            raise ValueError('too large')
        return json.loads(self.rfile.read(length) or b'{}')

    def do_GET(self):
        if not self.authorized():
            return self.reply(401, {'error': 'unauthorized'})
        if self.path == '/status':
            return self.reply(200, {'configured': configured()})
        self.reply(404, {'error': 'not found'})

    def do_POST(self):
        if not self.authorized():
            return self.reply(401, {'error': 'unauthorized'})
        try:
            body = self.body()
        except (ValueError, json.JSONDecodeError):
            return self.reply(400, {'error': 'bad request'})

        if self.path == '/run':
            args = body.get('args')
            if not isinstance(args, list) or not args or not all(isinstance(a, str) for a in args):
                return self.reply(400, {'error': 'args must be a list of strings'})
            slots = int(number(body.get('slots'), 5, 1, 50))
            wait = number(body.get('wait'), 10, 0, 3600)
            timeout = number(body.get('timeout'), 60, 1, 6 * 3600)
            if not take_slot(slots, wait):
                return self.reply(429, {'error': 'GAM is busy'})
            try:
                # No keyboard: a command that asks a question fails instead of waiting forever.
                done = subprocess.run(['gam', *args], capture_output=True, text=True, timeout=timeout, stdin=subprocess.DEVNULL)
            except subprocess.TimeoutExpired:
                return self.reply(504, {'error': 'GAM took too long'})
            finally:
                give_slot()
            return self.reply(200, {'exit': done.returncode, 'stdout': done.stdout, 'stderr': done.stderr})

        if self.path == '/config/remove':
            for name in CONFIG_FILES:
                path = os.path.join(CONFIG_DIR, name)
                if os.path.isfile(path):
                    os.remove(path)
            return self.reply(200, {'configured': configured()})

        if self.path == '/config':
            files = body.get('files')
            if not isinstance(files, dict) or not files or any(n not in CONFIG_FILES or not isinstance(c, str) for n, c in files.items()):
                return self.reply(400, {'error': 'only ' + ', '.join(CONFIG_FILES)})
            old = os.umask(0o077)
            try:
                for name, content in files.items():
                    with open(os.path.join(CONFIG_DIR, name), 'w') as handle:
                        handle.write(content)
            finally:
                os.umask(old)
            return self.reply(200, {'configured': configured()})

        self.reply(404, {'error': 'not found'})

    def log_message(self, format, *args):
        # Log the request line only; never bodies, which can hold credentials or directory data.
        print(f'{self.address_string()} {format % args}', flush=True)


if __name__ == '__main__':
    ThreadingHTTPServer(('0.0.0.0', 8000), Handler).serve_forever()
