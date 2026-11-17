<?php

namespace TornadoEngine\Gam;

use RuntimeException;

/**
 * What identifies people in Google, for the broker (when it's installed): their addresses and
 * names, and anything else on their Google account that could identify them (IDs, phones,
 * relations, addresses, custom fields, notes…). Each value is labelled with its field.
 */
class BrokerSource
{
    public function __construct(private Gam $gam) {}

    /**
     * @param  list<string>  $emails  the people given pseudonyms
     * @return array<string, list<array{0: string, 1: string}>>
     */
    public function values(array $emails): array
    {
        $wanted = array_fill_keys(array_map('strtolower', $emails), true);
        $values = [];
        foreach ($this->users() as $user) {
            $email = strtolower((string) ($user['primaryEmail'] ?? ''));
            if (isset($wanted[$email])) {
                $values[$email] = $this->identifying($user);
            }
        }

        return $values;
    }

    /** @return list<array<string, mixed>> */
    private function users(): array
    {
        $result = $this->gam->forJob()->run(['print', 'users', 'allfields', 'formatjson']);
        if ($result['exit'] !== 0) {
            throw new RuntimeException(trim($result['stderr']) ?: __('GAM failed.'));
        }

        $lines = preg_split('/\r?\n/', trim($result['stdout']));
        $header = str_getcsv((string) array_shift($lines), escape: '');
        $json = array_search('JSON', $header, true);

        $users = [];
        foreach (array_filter($lines) as $line) {
            $row = str_getcsv($line, escape: '');
            $user = json_decode($row[$json === false ? count($row) - 1 : $json] ?? '', true);
            if (is_array($user)) {
                $users[] = $user;
            }
        }

        return $users;
    }

    /** @return list<array{0: string, 1: string}> */
    private function identifying(array $user): array
    {
        $values = [];
        $add = function (mixed $value, string $label) use (&$values) {
            if (is_scalar($value) && ! is_bool($value) && trim((string) $value) !== '') {
                $values[] = [trim((string) $value), $label];
            }
        };

        foreach (array_merge($user['aliases'] ?? [], $user['nonEditableAliases'] ?? []) as $alias) {
            $add($alias, 'email');
        }
        foreach ($user['emails'] ?? [] as $email) {
            $add($email['address'] ?? null, 'email');
        }
        $add($user['name']['fullName'] ?? null, 'name');
        $add($user['name']['givenName'] ?? null, 'given');
        $add($user['name']['familyName'] ?? null, 'family');

        $fields = [
            'externalIds' => fn ($item) => [$item['value'] ?? null],
            'phones' => fn ($item) => [$item['value'] ?? null],
            'relations' => fn ($item) => [$item['value'] ?? null],
            'addresses' => fn ($item) => [$item['formatted'] ?? null, $item['streetAddress'] ?? null, $item['poBox'] ?? null, $item['postalCode'] ?? null],
            'organizations' => fn ($item) => [$item['costCenter'] ?? null, $item['description'] ?? null],
            'ims' => fn ($item) => [$item['im'] ?? null],
            'keywords' => fn ($item) => [$item['value'] ?? null],
            'websites' => fn ($item) => [$item['value'] ?? null],
        ];
        foreach ($fields as $field => $pick) {
            foreach ($user[$field] ?? [] as $item) {
                foreach ($pick(is_array($item) ? $item : []) as $value) {
                    $add($value, $field);
                }
            }
        }

        foreach (['recoveryEmail', 'recoveryPhone'] as $field) {
            $add($user[$field] ?? null, $field);
        }

        foreach ($user['customSchemas'] ?? [] as $schema => $schemaFields) {
            foreach ((array) $schemaFields as $name => $value) {
                $leaves = [];
                is_array($value) ? array_walk_recursive($value, function ($leaf) use (&$leaves) {
                    $leaves[] = $leaf;
                }) : $leaves[] = $value;
                foreach ($leaves as $leaf) {
                    $add($leaf, "customSchemas.{$schema}.{$name}");
                }
            }
        }

        $add($user['notes']['value'] ?? null, 'notes');

        return $values;
    }
}
