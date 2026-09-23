<?php

namespace Kirbydesk\Explorer;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Exception\NotFoundException;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Panel\Ui\Item\PageItem;

/**
 * Page listing of the explorer. Actions on pages use Kirby's own page
 * options dropdown in the panel, so permissions and dialogs are native.
 */
final class Pages
{
    private readonly Content $content;

    public function __construct(private readonly App $kirby)
    {
        $this->content = new Content($kirby);
    }

    /** All pages of the site, drafts included, as list items. */
    public function list(): array
    {
        $items = [];

        foreach ($this->kirby->site()->index(true) as $page) {
            $items[] = $this->item($page);
        }

        return [
            'items'     => $items,
            // field types the fill rate cannot judge
            'skipped'   => $this->content->skipped(),
            'templates' => count(array_unique(array_map(fn ($item) => $item['template'], $items))),
            'languages' => Languages::list($this->kirby),
        ];
    }

    /** Number of pages with unsaved changes in one language */
    public function changes(?string $language = null): int
    {
        $language = $this->kirby->multilang()
            ? ($language ?: $this->kirby->defaultLanguage()->code())
            : 'default';

        $count = 0;
        foreach ($this->kirby->site()->index(true) as $page) {
            // a changes version without real differences does not count
            if ($page->version('changes')->exists($language) === true
                && $this->content->countChanges($page, $language) > 0) {
                $count++;
            }
        }

        return $count;
    }

    /** Fields of a page with the values of every language – for the details view */
    public function fields(string $id): array
    {
        return $this->content->fields($this->find($id));
    }

    /** Writes a field into the page's (unpublished) changes version */
    public function change(string $id, string $field, mixed $value, ?string $language): array
    {
        return $this->content->change($this->find($id), $field, $value, $language);
    }

    /** Publishes the changes of a page */
    public function publish(string $id, ?string $language): array
    {
        return $this->content->publish($this->find($id), $language);
    }

    /** Throws away the unsaved changes of a page */
    public function discard(string $id, ?string $language): array
    {
        return $this->content->discard($this->find($id), $language);
    }

    /**
     * Change the status of several pages via Kirby's own action
     * (permissions, blueprint rules). Pages already in that status
     * are skipped.
     * @param list<string> $ids
     */
    public function changeStatus(array $ids, string $status): array
    {
        if (!in_array($status, ['draft', 'unlisted', 'listed'], true)) {
            throw new InvalidArgumentException(message: 'Invalid status: ' . $status);
        }

        $changed = [];
        $skipped = [];
        $failed  = [];

        foreach ($ids as $id) {
            try {
                $page = $this->find($id);
                if ($page->status() === $status) {
                    $skipped[] = $id;
                    continue;
                }
                $page->changeStatus($status);
                $changed[] = $id;
            } catch (\Throwable $e) {
                $failed[$id] = $e->getMessage();
            }
        }

        return ['changed' => $changed, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Delete several pages. Deepest first, so a page whose children are
     * selected too can go; pages with other children are refused by Kirby.
     * @param list<string> $ids
     */
    public function delete(array $ids): array
    {
        usort($ids, fn ($a, $b) => substr_count($b, '/') <=> substr_count($a, '/'));

        $deleted = [];
        $failed  = [];

        foreach ($ids as $id) {
            try {
                $this->find($id)->delete();
                $deleted[] = $id;
            } catch (\Throwable $e) {
                $failed[$id] = $e->getMessage();
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    private function find(string $id): Page
    {
        // findPageOrDraft only walks drafts at the top level – the index
        // (drafts included) is what the list itself is built from
        return $this->kirby->site()->findPageOrDraft($id)
            ?? $this->kirby->site()->index(true)->find($id)
            ?? throw new NotFoundException(message: 'Page not found: ' . $id);
    }

    /** The page's ancestors, from the top down, each with its panel link */
    private function crumbs(Page $page, ?string $language = null): array
    {
        return array_map(
            fn ($parent) => [
                'title' => $language
                    ? $parent->content($language)->title()->value()
                    : $parent->title()->value(),
                'link'  => $parent->panel()->url(true),
            ],
            $page->parents()->flip()->values()
        );
    }

    /** Title and full slug path per language (multi-language sites) */
    private function translations(Page $page): ?array
    {
        if (!$this->kirby->multilang()) return null;

        $translations = [];
        foreach ($this->kirby->languages() as $language) {
            $code = $language->code();
            $translations[$code] = [
                'text'   => $page->content($code)->title()->value(),
                'slug'   => self::path($page->url($code)),
                'crumbs' => $this->crumbs($page, $code),
                // false: no content file in this language, Kirby falls back
                'exists' => $page->version('latest')->exists($code),
            ];
        }
        return $translations;
    }

    /**
     * Path of the page's real URL: language prefix as configured,
     * the home page as "/en" (not "/en/home"), own domains without prefix.
     */
    private static function path(string $url): string
    {
        return '/' . trim((string) parse_url($url, PHP_URL_PATH), '/');
    }

    private function item(Page $page): array
    {
        $parent = $page->parent();
        // preview image / icon as configured in the page blueprint
        $panel  = (new PageItem(page: $page, layout: 'cardlets'))->props();

        return [
            'id'       => $page->id(),
            'text'     => $page->title()->value(),
            'image'    => $panel['image'] ?? null,
            'icon'     => $panel['icon'] ?? null,
            'slug'     => self::path($page->url()), // e.g. /de/therapie/paediatrie
            // where the page sits in the tree: one entry per ancestor
            'crumbs'   => $this->crumbs($page),
            // everything in the page's own folder: content files and its
            // files – subpages have folders of their own and are left out
            'sizeRaw'  => $bytes = (int) Dir::size($page->root(), recursive: false),
            'size'     => F::niceSize($bytes),
            'files'    => $page->files()->count(),
            // how they split up: ['image' => 2, 'video' => 1, …]
            'fileTypes' => array_count_values(
                $page->files()->values(fn ($file) => $file->type() ?? 'file')
            ),
            'translations' => $this->translations($page),
            'status'   => $page->status(),
            'link'     => $page->panel()->url(true),
            // panel path for Kirby's options dropdown ("pages/a+b")
            'path'     => $page->panel()->path(),
            'url'      => $page->url(),
            'template' => $template = $page->intendedTemplate()->name(),
            // blueprint title (translated); "named" = no own title, only the name
            'templateTitle' => $title = $page->blueprint()->title() ?? $template,
            'templateNamed' => strcasecmp($title, $template) === 0,
            'changes'  => $this->content->changedFields($page),
            // how much of the content is filled in, per language
            'fill'     => $this->content->fillRates($page),
            'permissions' => [
                'changeStatus' => $page->permissions()->can('changeStatus'),
                'delete'       => $page->permissions()->can('delete'),
            ],
            'parent'   => $parent ? ['id' => $parent->id(), 'title' => $parent->title()->value()] : null,
        ];
    }
}
