<?php

namespace TornadoEngine\Gam;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * GAM for other modules. It talks to the GAM sidecar container, which only runs the read-only
 * commands it allows (see docker/sidecar.py).
 *
 *     app(Gam::class)->groupMembers('teachers@example.org');
 */
class Gam
{
    public function __construct(private string $url, private ?string $token) {}

    /**
     * Whether GAM's credential files have been uploaded.
     */
    public function configured(): bool
    {
        return (bool) ($this->request()->get('/status')->throw()->json('configured'));
    }

    /**
     * Saves GAM's credential files in the sidecar.
     *
     * @param  array<string, string>  $files  oauth2.txt, oauth2service.json, client_secrets.json
     */
    public function saveConfig(array $files): void
    {
        $this->request()->post('/config', ['files' => $files])->throw();
    }

    /**
     * Deletes GAM's credential files from the sidecar.
     */
    public function removeConfig(): void
    {
        $this->request()->post('/config/remove')->throw();
    }

    /**
     * Whether GAM can reach the Google Workspace domain.
     */
    public function check(): bool
    {
        return $this->run(['info', 'domain'])['exit'] === 0;
    }

    /** @return list<array{path: string, name: string}> */
    public function orgUnits(): array
    {
        return array_map(fn ($row) => [
            'path' => $this->field($row, 'orgUnitPath', 'path'),
            'name' => $this->field($row, 'name'),
        ], $this->csv(['print', 'orgs', 'fields', 'orgunitpath,name']));
    }

    /** @return list<array{email: string, name: string}> */
    public function groups(): array
    {
        return array_map(fn ($row) => [
            'email' => strtolower($this->field($row, 'email')),
            'name' => $this->field($row, 'name'),
        ], $this->csv(['print', 'groups', 'fields', 'email,name']));
    }

    /**
     * The email addresses of a group's user members, including members of groups inside it.
     *
     * @return list<string>
     */
    public function groupMembers(string $group): array
    {
        $rows = $this->csv(['print', 'group-members', 'group', $group, 'recursive', 'noduplicates', 'types', 'user', 'fields', 'email']);

        return array_values(array_unique(array_filter(array_map(fn ($row) => strtolower($this->field($row, 'email')), $rows))));
    }

    /**
     * The people in an organizational unit (and its sub-OUs, if asked), leaving out suspended accounts.
     *
     * @return list<array{email: string, name: string, ou: string}>
     */
    public function usersIn(string $orgUnit, bool $subOrgUnits = true): array
    {
        $rows = $this->csv([$subOrgUnits ? 'ou_and_children' : 'ou', $orgUnit, 'print', 'users', 'fields', 'primaryemail,fullname,ou,suspended']);

        $users = [];
        foreach ($rows as $row) {
            if (strtolower($this->field($row, 'suspended')) === 'true') {
                continue;
            }
            $users[] = [
                'email' => strtolower($this->field($row, 'primaryEmail', 'email')),
                'name' => $this->field($row, 'name.fullName', 'fullName', 'name'),
                'ou' => $this->field($row, 'orgUnitPath', 'ou'),
            ];
        }

        return $users;
    }

    /**
     * Runs an allowed GAM command.
     *
     * @param  list<string>  $args
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public function run(array $args): array
    {
        try {
            $response = $this->request()->timeout(620)->post('/run', ['args' => $args]);
        } catch (ConnectionException $e) {
            throw new GamException(__('Can\'t reach the GAM container.'), previous: $e);
        }

        if ($response->status() === 403) {
            throw new GamException(__('The GAM container doesn\'t allow that command.'));
        }

        $response->throw();

        return [
            'exit' => (int) $response->json('exit'),
            'stdout' => (string) $response->json('stdout'),
            'stderr' => (string) $response->json('stderr'),
        ];
    }

    /**
     * Runs a GAM "print" command and returns its CSV rows.
     *
     * @param  list<string>  $args
     * @return list<array<string, string>>
     */
    private function csv(array $args): array
    {
        $result = $this->run($args);

        if ($result['exit'] !== 0) {
            throw new GamException(trim($result['stderr']) ?: __('GAM failed.'));
        }

        $lines = array_values(array_filter(preg_split('/\r?\n/', trim($result['stdout'])), fn ($line) => $line !== ''));
        if (! $lines) {
            return [];
        }

        $header = str_getcsv(array_shift($lines), escape: '');

        return array_map(fn ($line) => array_combine($header, array_pad(str_getcsv($line, escape: ''), count($header), '')), $lines);
    }

    /**
     * The first of these columns that the row has. GAM's column names vary a little between versions.
     *
     * @param  array<string, string>  $row
     */
    private function field(array $row, string ...$names): string
    {
        $lower = array_change_key_case($row);
        foreach ($names as $name) {
            if (isset($lower[strtolower($name)])) {
                return trim($lower[strtolower($name)]);
            }
        }

        return '';
    }

    private function request(): PendingRequest
    {
        if (! $this->token) {
            throw new GamException(__('GAM_SIDECAR_TOKEN isn\'t set in .env.'));
        }

        return Http::baseUrl($this->url)->withToken($this->token)->acceptJson()->timeout(30);
    }
}
