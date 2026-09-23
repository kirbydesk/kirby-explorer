<?php

namespace Kirbydesk\Explorer;

use Kirby\Cms\File;

/**
 * File templates: which ones a file may use and how they are named.
 * Stateless helper – the blueprint is the only source.
 */
final class Templates
{
    /**
     * Templates a file may use: name => title, without the " (name)"
     * suffix Kirby adds.
     * @return array<string, string>
     */
    public static function list(File $file): array
    {
        $templates = [];
        foreach ($file->blueprints() as $blueprint) {
            $name = $blueprint['name'] ?? null;
            if (!is_string($name)) continue;

            $title = trim(preg_replace('/\s+\(' . preg_quote($name, '/') . '\)$/', '', (string) ($blueprint['title'] ?? '')));

            // No translation (empty, placeholder or an unresolved key): show the name.
            $untranslated = in_array($title, ['', '-', '–'], true) || preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)+$/i', $title);
            $templates[$name] = $untranslated ? $name : $title;
        }

        return $templates;
    }

    /** Title of the template a file currently uses */
    public static function title(File $file): ?string
    {
        $template = $file->template() ?? 'default';
        return static::list($file)[$template] ?? $file->template();
    }

    /**
     * Template choices for a select: titles, plus the name where two
     * templates share a title (a select offers no tooltip).
     * @param array<string, string> $templates name => title
     */
    public static function distinct(array $templates): array
    {
        $counts = array_count_values($templates);
        foreach ($templates as $name => $title) {
            if ($counts[$title] > 1 && $title !== $name) $templates[$name] = $title . ' (' . $name . ')';
        }
        return $templates;
    }
}
