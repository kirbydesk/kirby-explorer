<?php

namespace Kirbydesk\Explorer;

use Kirby\Cms\App;

/** Content languages for the language switcher of the tabs. */
final class Languages
{
    /** Languages of a multi-language site, default first; [] otherwise */
    public static function list(App $kirby): array
    {
        if (!$kirby->multilang()) return [];

        $languages = [];
        foreach ($kirby->languages() as $language) {
            $entry = ['code' => $language->code(), 'name' => $language->name(), 'default' => $language->isDefault()];
            $language->isDefault() ? array_unshift($languages, $entry) : $languages[] = $entry;
        }
        return $languages;
    }
}
