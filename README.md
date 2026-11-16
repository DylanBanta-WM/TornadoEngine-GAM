# Tornado Engine GAM

A [Tornado Engine](https://github.com/DylanBanta-WM/TornadoEngineCore) module: [GAM](https://github.com/GAM-team/GAM) for Google Workspace, for other modules to use.

GAM runs in its own container (the GAM sidecar), which only the website can reach. It runs any GAM command the website sends, as a list of arguments (never through a shell).

## Setup

1. Install it from `/modules`, then click Rebuild to start its container.
2. In Administration, upload GAM's `oauth2.txt`, `oauth2service.json` and `client_secrets.json`, then click Check connection.

## Using GAM from a module

```php
use TornadoEngine\Gam\Gam;

$gam = app(Gam::class);
$gam->orgUnits();                       // [['path' => '/Staff', 'name' => 'Staff'], ...]
$gam->groups();                         // [['email' => 'teachers@example.org', 'name' => 'Teachers'], ...]
$gam->groupMembers('teachers@example.org'); // ['ann@example.org', ...]
$gam->usersIn('/Staff');                // [['email' => ..., 'name' => ..., 'ou' => ...], ...]
```

For any other command, `$gam->run(['update', 'user', 'ann@example.org', 'org', '/Staff'])` returns its exit code and output. A GAM container that can't be reached throws `GamException`.
