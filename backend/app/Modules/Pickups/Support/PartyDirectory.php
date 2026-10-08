<?php

namespace App\Modules\Pickups\Support;

use Illuminate\Support\Facades\DB;

/**
 * Names of the companies and sites shown on a pickup, read once per id and per request (scoped service).
 * Read without tenant scope on purpose: the provider must know whom and where it collects, and only
 * these public fields of a pickup addressed to it are exposed.
 */
final class PartyDirectory
{
    /** @var array<int, array{ulid: string, legal_name: string}|null> */
    private array $companies = [];

    /** @var array<int, array<string, mixed>|null> */
    private array $sites = [];

    /**
     * @return array{ulid: string, legal_name: string}|null
     */
    public function company(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }

        if (! array_key_exists($id, $this->companies)) {
            $row = DB::table('companies')->where('id', $id)->first(['ulid', 'legal_name']);
            $this->companies[$id] = $row === null ? null : ['ulid' => $row->ulid, 'legal_name' => $row->legal_name];
        }

        return $this->companies[$id];
    }

    /**
     * A partner without an account. Only its name is shown to the other parties of the pickup.
     *
     * @return array{ulid: string, legal_name: string, external: true}|null
     */
    public function external(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }
        $row = DB::table('external_partners')->where('id', $id)->first(['ulid', 'legal_name']);

        return $row === null ? null : ['ulid' => $row->ulid, 'legal_name' => $row->legal_name, 'external' => true];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function site(int $id): ?array
    {
        if (! array_key_exists($id, $this->sites)) {
            $row = DB::table('sites')->where('id', $id)->first(['ulid', 'code', 'name', 'address_line1', 'city']);
            $this->sites[$id] = $row === null ? null : (array) $row;
        }

        return $this->sites[$id];
    }
}
