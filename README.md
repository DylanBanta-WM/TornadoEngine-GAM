# Tornado Engine GAM

A [Tornado Engine](https://github.com/DylanBanta-WM/TornadoEngineCore) module: [GAM](https://github.com/GAM-team/GAM) for Google Workspace, for other modules to use.

GAM runs in its own container (the GAM sidecar), which only the website can reach. The sidecar only runs the read-only commands listed in `docker/sidecar.py`.

## Setup

1. Add a shared password for the sidecar to `.env` (make it with `openssl rand -hex 32`):
   ```
   GAM_SIDECAR_TOKEN=
   ```
2. Run `docker compose up -d` twice: the first run installs the module, the second starts its container.
3. In Administration, upload GAM's `oauth2.txt`, `oauth2service.json` and `client_secrets.json`, then click Check connection.

## Using GAM from a module

```php
use TornadoEngine\Gam\Gam;

$gam = app(Gam::class);
$gam->orgUnits();                       // [['path' => '/Staff', 'name' => 'Staff'], ...]
$gam->groups();                         // [['email' => 'teachers@example.org', 'name' => 'Teachers'], ...]
$gam->groupMembers('teachers@example.org'); // ['ann@example.org', ...]
$gam->usersIn('/Staff');                // [['email' => ..., 'name' => ..., 'ou' => ...], ...]
```

A command the sidecar doesn't allow throws `GamException`. To allow a new one, add it to `ALLOWED` in `docker/sidecar.py`.
