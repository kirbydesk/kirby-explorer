<?php

namespace Kirbydesk\Explorer;

use Kirby\Cms\User;
use Kirby\Data\Json;
use Kirby\Exception\InvalidArgumentException;

/**
 * View preferences per user and tab (view, sorting, grouping, filters),
 * stored next to the user account: site/accounts/<id>/explorer.json.
 */
final class Preferences
{
    /** Keys a tab may store, and their accepted shape. */
    private const KEYS = [
        'view'          => 'string',
        'sortBy'        => 'string',
        'sortDirection' => 'string',
        'groupBy'       => 'string',
        'filters'       => 'array',
        'language'      => 'string',
        'editing'       => 'bool',
        'charts'        => 'array',
        'labels'        => 'bool',
    ];

    public function __construct(private readonly User $user)
    {
    }

    /** Keys that belong to all tabs at once, not to a single one. */
    private const SHARED = ['language'];

    public function get(string $tab): array
    {
        $all = $this->read();

        // the shared keys win over what a tab may still have stored
        return array_merge($all[self::tab($tab)] ?? [], $all['shared'] ?? []);
    }

    public function set(string $tab, array $values): array
    {
        $clean = [];
        foreach (self::KEYS as $key => $type) {
            if (!array_key_exists($key, $values)) continue;
            $value = $values[$key];
            if ($type === 'string' && (is_string($value) || $value === null)) $clean[$key] = $value;
            if ($type === 'array' && is_array($value)) $clean[$key] = array_filter($value, fn ($v) => is_scalar($v) || $v === null);
            if ($type === 'bool' && is_bool($value)) $clean[$key] = $value;
        }

        // the language is the same in every tab: switching tabs keeps it
        $shared = array_intersect_key($clean, array_flip(self::SHARED));
        $own    = array_diff_key($clean, $shared);

        $all = $this->read();
        $all[self::tab($tab)] = $own;
        $all['shared']        = array_merge($all['shared'] ?? [], $shared);
        Json::write($this->file(), $all);

        return $clean;
    }

    /** The tab the user had open last (null if none/unknown) */
    public function lastTab(array $tabs): ?string
    {
        $tab = $this->read()['lastTab'] ?? null;
        return in_array($tab, $tabs, true) ? $tab : null;
    }

    /** Remember the open tab ("lastTab" cannot clash: tab keys are lowercase) */
    public function setLastTab(string $tab): void
    {
        $all = $this->read();
        if (($all['lastTab'] ?? null) === $tab) return;

        $all['lastTab'] = self::tab($tab);
        Json::write($this->file(), $all);
    }

    private function read(): array
    {
        try {
            return is_file($this->file()) ? Json::read($this->file()) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function file(): string
    {
        return $this->user->root() . '/explorer.json';
    }

    private static function tab(string $tab): string
    {
        if (!preg_match('/^[a-z0-9-]+$/', $tab)) {
            throw new InvalidArgumentException(message: 'Invalid tab');
        }
        return $tab;
    }
}
