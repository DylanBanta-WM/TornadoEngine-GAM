#!/usr/bin/env python3
"""GAM sidecar for Tornado Engine.

Runs GAM for the website over HTTP on the private Docker network. Only the read-only commands
in ALLOWED can run, and every request needs the shared GAM_SIDECAR_TOKEN.

  GET  /status   {"configured": bool}
  POST /run      {"args": [...]}  ->  {"exit": int, "stdout": str, "stderr": str}
  POST /config   {"files": {"oauth2.txt": "...", ...}}  saves GAM's credential files
  POST /config/remove                                   deletes them
"""
import hmac
import json
import os
import re
import subprocess
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

TOKEN = os.environ.get('GAM_SIDECAR_TOKEN', '')
CONFIG_DIR = os.environ.get('GAMCFGDIR', '/gam')
CONFIG_FILES = ('oauth2.txt', 'oauth2service.json', 'client_secrets.json')
MAX_BODY = 1_000_000

EMAIL = re.compile(r"^[A-Za-z0-9._%+'-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$")
OU = re.compile(r'^/[^\x00-\x1f]{0,500}$')

USER_FIELDS = 'primaryemail,fullname,ou,suspended'

# Each entry is an exact command; "EMAIL" and "OU" stand for a checked value.
ALLOWED = [
    ['info', 'domain'],
    ['print', 'orgs', 'fields', 'orgunitpath,name'],
    ['print', 'groups', 'fields', 'email,name'],
    ['print', 'group-members', 'group', 'EMAIL', 'recursive', 'noduplicates', 'types', 'user', 'fields', 'email'],
    ['ou', 'OU', 'print', 'users', 'fields', USER_FIELDS],
    ['ou_and_children', 'OU', 'print', 'users', 'fields', USER_FIELDS],
]


def allowed(args):
    for pattern in ALLOWED:
        if len(pattern) != len(args):
            continue
        if all(
            (p == 'EMAIL' and EMAIL.match(a)) or (p == 'OU' and OU.match(a)) or p == a
            for p, a in zip(pattern, args)
        ):
            return True
    return False


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
        return bool(TOKEN) and hmac.compare_digest(given, TOKEN)

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
            if not isinstance(args, list) or not all(isinstance(a, str) for a in args) or not allowed(args):
                return self.reply(403, {'error': 'command not allowed'})
            try:
                done = subprocess.run(['gam', *args], capture_output=True, text=True, timeout=600)
            except subprocess.TimeoutExpired:
                return self.reply(504, {'error': 'GAM took too long'})
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
    if not TOKEN:
        raise SystemExit('GAM_SIDECAR_TOKEN is not set.')
    ThreadingHTTPServer(('0.0.0.0', 8000), Handler).serve_forever()
