<?php

namespace Kirbydesk\Explorer;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Form\Form;

/**
 * Everything the explorer knows about the *content* of a page or a file:
 * the fields of the details view, unsaved changes (Kirby's "changes"
 * version) and how much of the content is filled in.
 *
 * All values run through Kirby's form, so blueprint defaults, fallbacks of
 * the default language and field permissions apply the same way the panel
 * applies them.
 */
final class Content
{
    /** field names per template and language, built on demand */
    private array $fieldNames = [];

    /** field types left out of the fill rate, as type => true */
    private array $skipped = [];

    /** Field types without a value (layout only). */
    private const LAYOUT_FIELDS = ['line', 'headline', 'info', 'gap'];

    /** Types that always carry a value: they say nothing about progress. */
    private const ALWAYS_FILLED = ['toggle', 'toggles', 'checkboxes'];

    /** Field types the details view can edit inline. */
    private const EDITABLE_FIELDS = [
        // own flat inputs
        'text', 'textarea', 'toggle', 'slug', 'email', 'url', 'tel', 'number', 'select', 'radio', 'toggles',
        // Kirby's own inputs in the cell
        'checkboxes', 'multiselect', 'tags', 'date', 'time', 'color', 'range',
    ];

    public function __construct(private readonly App $kirby)
    {
    }

    /** Field types the fill rate cannot judge (collected while counting) */
    public function skipped(): array
    {
        return array_keys($this->skipped);
    }

    /** All language codes, "default" for a single-language site */
    public function languages(): array
    {
        return $this->kirby->multilang()
            ? array_map(fn ($language) => $language->code(), $this->kirby->languages()->values())
            : ['default'];
    }

    /**
     * Changed, not yet published fields per language: ['de' => 2, …].
     * Only languages with a changes version are listed.
     */
    public function changedFields(ModelWithContent $model): array
    {
        $changes = $model->version('changes');
        $result  = [];

        foreach ($this->languages() as $language) {
            if ($changes->exists($language) === false) continue;

            $count = $this->countChanges($model, $language);
            if ($count > 0) $result[$language] = $count;
        }

        return $result;
    }

    /**
     * Fields that really differ from what is published – the same comparison
     * the details view shows: values run through the form (so a missing
     * translation falls back to the default language) and layout or hidden
     * fields do not count.
     */
    public function countChanges(ModelWithContent $model, string $language): int
    {
        $code = $language === 'default' ? null : $language;
        $form = Form::for($model, language: $code);

        $published = [];
        foreach ($form->fields() as $field) {
            $published[$field->name()] = $field->toArray()['value'] ?? null;
        }

        $form->fill(input: $model->version('changes')->content($language)->toArray());

        $count = 0;
        foreach ($form->fields() as $field) {
            $props = $field->toArray();
            $type  = $props['type'] ?? 'text';

            if (in_array($type, self::LAYOUT_FIELDS, true)) continue;
            if ($type === 'hidden' || ($props['hidden'] ?? false) === true) continue;

            if (($props['value'] ?? null) !== ($published[$props['name']] ?? null)) $count++;
        }

        return $count;
    }

    /**
     * How much of the content is filled in, per language:
     * ['de' => ['filled' => 3, 'fields' => 8], …].
     *
     * The raw language file is read on purpose – $model->content() would fall
     * back to the default language and report every file as complete.
     */
    public function fillRates(ModelWithContent $model): array
    {
        $latest = $model->version('latest');
        $result = [];

        foreach ($this->languages() as $language) {
            $fields = $this->fieldNames($model, $language);
            $raw    = $latest->read($language) ?? [];
            $filled = 0;

            foreach ($fields as $name) {
                $value = $raw[$name] ?? null;
                if (is_string($value) ? trim($value) !== '' : !empty($value)) $filled++;
            }

            $result[$language] = ['filled' => $filled, 'fields' => count($fields)];
        }

        return $result;
    }

    /**
     * Names of the fields that say something about the editing progress:
     * the ones the details view can edit, without layout, hidden, disabled
     * and complex types, without the ones that always carry a value
     * (toggles, required fields, fields with a default) and, in other
     * languages, without untranslatable ones. The list only depends on the
     * blueprint: built once per template.
     */
    private function fieldNames(ModelWithContent $model, string $language): array
    {
        $key = $this->template($model) . '|' . $language;

        if (isset($this->fieldNames[$key])) return $this->fieldNames[$key];

        $code      = $language === 'default' ? null : $language;
        $isDefault = $code === null || $this->kirby->language($code)?->isDefault() === true;
        $names     = [];

        foreach (Form::for($model, language: $code)->fields() as $field) {
            $props = $field->toArray();
            $type  = $props['type'] ?? 'text';

            if (in_array($type, self::LAYOUT_FIELDS, true)) continue;
            if ($type === 'hidden' || ($props['hidden'] ?? false) === true) continue;
            // only what the details view can actually edit
            if (!in_array($type, self::EDITABLE_FIELDS, true)) {
                $this->skipped[$type] = true;
                continue;
            }
            // a toggle is on or off, never empty
            if (in_array($type, self::ALWAYS_FILLED, true)) continue;
            // nobody can fill in a disabled field: it does not count
            if (($props['disabled'] ?? false) === true) continue;
            // required fields and fields with a default are filled anyway
            if (($props['required'] ?? false) === true) continue;
            if (($props['default'] ?? null) !== null && $props['default'] !== '') continue;
            // only the default language holds untranslatable fields
            if (!$isDefault && ($props['translate'] ?? true) === false) continue;

            $names[] = $props['name'];
        }

        return $this->fieldNames[$key] = $names;
    }

    /**
     * Fields of a file's blueprint with the values of every language —
     * for the details view.
     */
    public function fields(ModelWithContent $model): array
    {
        $languages = $this->kirby->multilang() ? $this->kirby->languages()->values() : [null];
        $canUpdate = $model->permissions()->can('update');
        $result    = [];

        // values of the default language – shown greyed out where a
        // translation is still missing (like the page titles in the list)
        $fallback = [];
        if ($this->kirby->multilang() === true) {
            $default = $this->kirby->defaultLanguage()->code();
            foreach (Form::for($model, language: $default)->fields() as $field) {
                $fallback[$field->name()] = $field->toArray()['value'] ?? null;
            }
        }

        foreach ($languages as $language) {
            $form       = Form::for($model, language: $language?->code());
            $changes    = $model->version('changes');
            $hasChanges = $changes->exists($language?->code() ?? 'default');

            // published values first, unsaved changes win afterwards
            $latest = [];
            foreach ($form->fields() as $field) {
                $latest[$field->name()] = $field->toArray()['value'] ?? null;
            }

            if ($hasChanges === true) {
                $form->fill(input: $changes->content($language?->code() ?? 'default')->toArray());
            }

            $fields    = [];
            $values    = [];
            // the published values, to tell which fields really differ
            $published = [];

            foreach ($form->fields() as $field) {
                $props = $field->toArray();
                $type  = $props['type'] ?? 'text';
                $name  = $props['name'];

                if (in_array($type, self::LAYOUT_FIELDS, true)) continue;
                // fields that never show in the panel
                if ($type === 'hidden' || ($props['hidden'] ?? false) === true) continue;

                // not translatable: shown in other languages, but locked
                $locked = $language !== null
                    && $language->isDefault() === false
                    && ($props['translate'] ?? true) === false;

                // Kirby's own field props for <k-fieldset>, as in the drawer;
                // types we cannot save inline are shown disabled
                $values[$name]    = $props['value'] ?? null;
                $published[$name] = $latest[$name] ?? null;
                unset($props['value']);
                $blocked = $locked || !$canUpdate || ($props['disabled'] ?? false);
                $props['disabled'] = $blocked || !in_array($type, self::EDITABLE_FIELDS, true);
                // why it cannot be edited here: not translatable, locked, or
                // a field type we do not edit inline
                $props['locked'] = match (true) {
                    $locked  => 'translate',
                    $blocked => 'disabled',
                    default  => null,
                };
                $fields[$name] = $props;
            }

            $result[] = [
                'code'    => $language?->code(),
                'name'    => $language?->name(),
                'default' => $language?->isDefault() ?? true,
                'fields'  => $fields,
                'values'    => $values,
                'published' => $published,
                'fallback'  => $language !== null && $language->isDefault() === false ? $fallback : [],
                // no content of its own: Kirby shows the default language
                'inherited' => $language !== null
                    && $language->isDefault() === false
                    && $model->version('latest')->exists($language->code()) === false,
                'changes'  => $hasChanges,
                'modified' => $hasChanges ? date('c', $changes->modified($language?->code() ?? 'default')) : null,
            ];
        }

        // someone else is editing this file (Kirby's content lock)
        $lock = $model->lock();

        return [
            'languages' => $result,
            'editable'  => $canUpdate,
            'locked'    => $lock->isLocked(),
            'editor'    => $lock->isLocked() ? $lock->user()?->name()?->value() ?? $lock->user()?->email() : null,
        ];
    }

    /**
     * Writes a field into Kirby's "changes" version (not published yet).
     * The version is created from the published content, so it stays complete.
     */
    public function change(ModelWithContent $model, string $field, mixed $value, ?string $language): array
    {
        $this->field($model, $field);

        $language = $this->language($language);
        $changes  = $model->version('changes');

        if ($changes->exists($language) === false) {
            // base it on what the form shows for this language – for a language
            // without content that is not the raw (empty) file, otherwise Kirby
            // would see every other field as changed. The displayed values also
            // carry the fallback of the default language, so toggles and the
            // like do not silently jump back to their default.
            $form    = Form::for($model, language: $language);
            $display = [];
            // careful: $field is the name of the field being saved
            foreach ($form->fields() as $formField) {
                $display[$formField->name()] = $formField->toArray()['value'] ?? null;
            }

            $form->fill(input: $display);
            $content = array_merge($model->version('latest')->read($language) ?? [], $form->toStoredValues());

            $changes->create($content, $language);
        }

        $changes->save([$field => $value], $language);

        return ['field' => $field, 'language' => $language];
    }

    /** Publishes the changes of a file (Kirby validates the fields). */
    public function publish(ModelWithContent $model, ?string $language): array
    {
        $language = $this->language($language);
        $changes  = $model->version('changes');

        if ($changes->exists($language) === true) {
            $changes->publish($language);
        }

        return ['published' => true];
    }

    /** Throws away the unsaved changes of a file. */
    public function discard(ModelWithContent $model, ?string $language): array
    {
        $language = $this->language($language);
        $changes  = $model->version('changes');

        if ($changes->exists($language) === true) {
            $changes->delete($language);
        }

        return ['discarded' => true];
    }

    /** Refuses fields that are not in the blueprint */
    private function field(ModelWithContent $model, string $name): void
    {
        $fields = array_change_key_case($model->blueprint()->fields());
        if (!isset($fields[strtolower($name)])) {
            throw new InvalidArgumentException(message: 'Unknown field: ' . $name);
        }
    }

    /** Blueprint the model uses – pages report their intended template */
    private function template(ModelWithContent $model): string
    {
        return match (true) {
            $model instanceof Page => $model->intendedTemplate()->name(),
            $model instanceof File => $model->template() ?? 'default',
            default                => $model->blueprint()->name(),
        };
    }

    /** The language to write to, "default" for a single-language site */
    private function language(?string $language): string
    {
        return $this->kirby->multilang() ? ($language ?: $this->kirby->defaultLanguage()->code()) : 'default';
    }
}
