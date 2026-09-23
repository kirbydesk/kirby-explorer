<?php

namespace Kirbydesk\Explorer;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Exception\LogicException;
use Kirby\Exception\NotFoundException;
use Kirby\Filesystem\Mime;
use Kirby\Panel\Ui\Item\FileItem;
use Kirby\Toolkit\Str;
use ZipArchive;

/**
 * All file operations of the explorer. Every write goes through Kirby's
 * own actions (update, delete, changeTemplate), so the user's file
 * permissions and blueprint rules apply. Errors are thrown as Kirby
 * exceptions; the panel API turns them into proper error responses.
 *
 * The class is the facade the API routes talk to; the content of a file
 * (fields, changes, fill rate) lives in {@see Content}, the blueprint
 * templates in {@see Templates}.
 */
final class Files
{
    private readonly Content $content;

    public function __construct(private readonly App $kirby)
    {
        $this->content = new Content($kirby);
    }

    /**
     * All files of the site and its pages as list items.
     * Kept lean: fields for the details view are loaded per file.
     */
    public function list(): array
    {
        $usage = new Usage($this->kirby);
        $items = [];

        foreach ($this->all() as $file) {
            $items[] = $this->item($file, $usage->isUsed($file));
        }

        return [
            'items'     => $items,
            // field types the fill rate cannot judge
            'skipped'   => $this->content->skipped(),
            // distinct templates, "without template" counts as one of them
            'templates' => count(array_unique(array_map(fn ($item) => $item['template'] ?? '', $items))),
            'languages' => Languages::list($this->kirby),
        ];
    }

    /** Number of files with unsaved changes in one language */
    public function changes(?string $language = null): int
    {
        $languages = $this->kirby->multilang()
            ? [$language ?: $this->kirby->defaultLanguage()->code()]
            : ['default'];

        $count = 0;
        foreach ($this->all() as $file) {
            $changes = $file->version('changes');
            foreach ($languages as $language) {
                // a changes version without real differences does not count
                if ($changes->exists($language) === true && $this->content->countChanges($file, $language) > 0) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * Files with identical content, as id => group number. Cheap first pass
     * by size, checksums only where sizes match (cached per file + mtime).
     */
    public function duplicates(): array
    {
        $bySize = [];
        foreach ($this->all() as $file) {
            $bySize[$file->size()][] = $file;
        }

        // off unless the site enables it: kirbydesk.kirby-explorer.cache.duplicates
        $cache  = $this->kirby->cache('kirbydesk.kirby-explorer.duplicates');
        $groups = [];

        foreach ($bySize as $files) {
            if (count($files) < 2) continue;

            foreach ($files as $file) {
                $key  = md5($file->root()) . '-' . $file->modified();
                $hash = $cache->get($key);

                if ($hash === null) {
                    $hash = sha1_file($file->root());
                    $cache->set($key, $hash);
                }

                $groups[$hash][] = $file->id();
            }
        }

        $result = [];
        $number = 0;
        foreach ($groups as $ids) {
            if (count($ids) < 2) continue;
            $number++;
            foreach ($ids as $id) $result[$id] = $number;
        }

        return $result;
    }

    /** @return iterable<File> */
    private function all(): iterable
    {
        yield from $this->kirby->site()->files();
        foreach ($this->kirby->site()->index(true) as $page) {
            yield from $page->files();
        }
    }

    /**
     * The file's mime type. Kirby reads it from the content and falls back to
     * "application/octet-stream" for some files (PDFs among them); the
     * extension knows better in that case.
     */
    private function mime(File $file): ?string
    {
        $mime = $file->mime();

        if ($mime === null || $mime === 'application/octet-stream') {
            $mime = Mime::fromExtension($file->extension()) ?? $mime;
        }

        return $mime;
    }

    /** The page and its parents, from the top down */
    private function crumbPages(Page $parent): array
    {
        return [...$parent->parents()->flip()->values(), $parent];
    }

    /** Titles of the path, in the given (or current) language */
    private function crumbTitles(Page $parent, ?string $language = null): array
    {
        return array_map(
            fn ($page) => $language
                ? $page->content($language)->title()->value()
                : $page->title()->value(),
            $this->crumbPages($parent)
        );
    }

    /** One entry per page of the path, each with its own panel link */
    private function crumbs(Page $parent, ?string $language = null): array
    {
        return array_map(
            fn ($page) => [
                'title' => $language
                    ? $page->content($language)->title()->value()
                    : $page->title()->value(),
                'link'  => $page->panel()->url(true),
            ],
            $this->crumbPages($parent)
        );
    }

    /**
     * Which languages a file has meta data of its own in. A file's
     * caption and alt text live in one content file per language, just
     * like a page's – without its own file Kirby falls back to the
     * default language.
     */
    private function translations(File $file): ?array
    {
        if (!$this->kirby->multilang()) return null;

        $translations = [];

        foreach ($this->kirby->languages() as $language) {
            $code = $language->code();
            $translations[$code] = [
                'exists' => $file->version('latest')->exists($code),
            ];
        }

        return $translations;
    }

    /** Path of the parent page per language, so the list can switch without reloading */
    private function parentTranslations(Page $parent): ?array
    {
        if (!$this->kirby->multilang()) return null;

        $translations = [];
        foreach ($this->kirby->languages() as $language) {
            $code = $language->code();
            $translations[$code] = [
                'title'  => $parent->content($code)->title()->value(),
                'path'   => implode(' › ', $this->crumbTitles($parent, $code)),
                'crumbs' => $this->crumbs($parent, $code),
            ];
        }
        return $translations;
    }

    private function item(File $file, bool $used): array
    {
        $panel  = (new FileItem(file: $file, layout: 'cards'))->props();
        $parent = $file->parent();
        $width  = $file->type() === 'image' ? (int) $file->width() : 0;
        $height = $file->type() === 'image' ? (int) $file->height() : 0;

        if (is_array($panel['image'] ?? null)) {
            $panel['image']['cover'] = true;
        }

        return [
            'id'            => $file->id(),
            'text'          => $file->filename(),
            'image'         => $panel['image'] ?? null,
            'icon'          => $panel['icon'] ?? null,
            'link'          => $file->panel()->url(true),
            'url'           => $file->url(),
            'filename'      => $file->filename(),
            'extension'     => $file->extension(),
            'mime'          => $this->mime($file),
            'type'          => $file->type(),
            'size'          => $file->niceSize(),
            'sizeRaw'       => $file->size(),
            'modified'      => $file->modified(),
            'width'         => $width,
            'height'        => $height,
            'dimensions'    => $width && $height ? $width . ' × ' . $height : null,
            'template'      => $file->template(),
            'templateTitle' => $title = Templates::title($file),
            'templateNamed' => $title === ($file->template() ?? 'default'),
            'used'          => $used,
            'changes'       => $this->content->changedFields($file),
            'fill'          => $this->content->fillRates($file),
            // the file's own meta data per language (multi-language sites)
            'translations'  => $this->translations($file),
            'parent'        => $parent instanceof Page ? [
                'id'     => $parent->id(),
                'title'  => $parent->title()->value(),
                // the whole path, e.g. "Therapie › Pädiatrie"
                'path'   => implode(' › ', $this->crumbTitles($parent)),
                // the same path, one entry per page (each with its own link)
                'crumbs' => $this->crumbs($parent),
                // titles and crumbs per language (multi-language sites)
                'translations' => $this->parentTranslations($parent),
                'link'   => $parent->panel()->url(true),
                'status' => $parent->status(),
            ] : null,
            'permissions'   => [
                'update'         => $file->permissions()->can('update'),
                'delete'         => $file->permissions()->can('delete'),
                'changeTemplate' => $file->permissions()->can('changeTemplate') && count(Templates::list($file)) > 1,
            ],
        ];
    }

    /** Fields of a file with the values of every language – for the details view */
    public function fields(string $id): array
    {
        return $this->content->fields($this->find($id));
    }

    /** Writes a field into the file's (unpublished) changes version */
    public function change(string $id, string $field, mixed $value, ?string $language): array
    {
        return $this->content->change($this->find($id), $field, $value, $language);
    }

    /** Publishes the changes of a file */
    public function publish(string $id, ?string $language): array
    {
        return $this->content->publish($this->find($id), $language);
    }

    /** Throws away the unsaved changes of a file */
    public function discard(string $id, ?string $language): array
    {
        return $this->content->discard($this->find($id), $language);
    }

    /**
     * Delete files. Used files are refused — also here, not only in the UI.
     * @param list<string> $ids
     */
    public function delete(array $ids): array
    {
        $usage   = new Usage($this->kirby);
        $deleted = [];
        $failed  = [];

        foreach ($ids as $id) {
            try {
                $file = $this->find($id);
                if ($usage->isUsed($file)) {
                    throw new LogicException(message: 'The file is in use: ' . $file->filename());
                }
                $file->delete();
                $deleted[] = $id;
            } catch (\Throwable $e) {
                $failed[$id] = $e->getMessage();
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Templates all given files can switch to (intersection).
     * @param list<string> $ids
     */
    public function commonTemplates(array $ids): array
    {
        $common = null;
        $titles = [];

        foreach ($ids as $id) {
            $templates = Templates::list($this->find($id));
            $titles   += $templates;
            $common    = $common === null ? array_keys($templates) : array_values(array_intersect($common, array_keys($templates)));
        }

        $titles = Templates::distinct(array_intersect_key($titles, array_flip($common ?? [])));
        return array_map(fn ($name) => ['value' => $name, 'text' => $titles[$name]], $common ?? []);
    }

    /**
     * Change the template of files (Kirby's changeTemplate: permissions
     * and blueprint rules apply).
     * @param list<string> $ids
     */
    public function changeTemplate(array $ids, string $template): array
    {
        $changed = [];
        $skipped = [];
        $failed  = [];

        foreach ($ids as $id) {
            try {
                $file = $this->find($id);
                if (!array_key_exists($template, Templates::list($file))) {
                    $skipped[] = $id;
                    continue;
                }
                $file->changeTemplate($template);
                $changed[] = $id;
            } catch (\Throwable $e) {
                $failed[$id] = $e->getMessage();
            }
        }

        return ['changed' => $changed, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Build a ZIP of the given files and return its path (caller streams
     * and removes it). Duplicate filenames get a numeric suffix.
     * @param list<string> $ids
     */
    public function zip(array $ids): array
    {
        $dir = $this->kirby->root('cache') . '/explorer';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $path = $dir . '/' . uniqid('files-', true) . '.zip';
        $zip  = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new LogicException(message: 'Could not create the ZIP archive');
        }

        $names = [];
        foreach ($ids as $id) {
            $file = $this->find($id);
            if (!$file->permissions()->can('access')) continue;

            $name = $file->filename();
            for ($i = 1; isset($names[$name]); $i++) {
                $name = $file->name() . '-' . $i . ($file->extension() ? '.' . $file->extension() : '');
            }
            $names[$name] = true;
            $zip->addFile($file->root(), $name);
        }
        $zip->close();

        if ($names === []) {
            @unlink($path);
            throw new NotFoundException(message: 'No files to download');
        }

        $title = Str::slug($this->kirby->site()->title()->value()) ?: 'files';
        return ['path' => $path, 'filename' => $title . '.zip'];
    }

    public function find(string $id): File
    {
        $file = $this->kirby->file(rawurldecode($id));
        if ($file === null) {
            throw new NotFoundException(message: 'File not found: ' . $id);
        }
        return $file;
    }
}
