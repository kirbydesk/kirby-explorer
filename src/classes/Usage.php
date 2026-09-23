<?php

namespace Kirbydesk\Explorer;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;

/**
 * Finds out which files are referenced anywhere in the site — in one pass
 * over the content of the site and all pages (all languages), instead of
 * searching each file's parent page separately.
 *
 * A file counts as used when its content is referenced
 *  - by UUID (`file://…`, files fields and links in Kirby 5),
 *  - by its full id (`page/slug/image.jpg`) anywhere, or
 *  - by its bare filename in its own parent's content (files fields
 *    without UUIDs, `(image: image.jpg)` in KirbyText).
 */
final class Usage
{
    /** @var array<string, true> */
    private array $uuids = [];

    /** @var array<string, true> lower-cased file-like tokens of all content */
    private array $tokens = [];

    /** @var array<string, array<string, true>> tokens per model id ('' = site) */
    private array $local = [];

    public function __construct(private readonly App $kirby)
    {
        $this->scan($kirby->site());
        foreach ($kirby->site()->index(true) as $page) {
            $this->scan($page);
        }
    }

    public function isUsed(File $file): bool
    {
        $uuid = $file->uuid()?->id();
        if ($uuid !== null && isset($this->uuids[strtolower($uuid)])) return true;

        if (isset($this->tokens[strtolower($file->id())])) return true;

        $parentId = $file->parent() instanceof \Kirby\Cms\Site ? '' : (string) $file->parent()?->id();
        return isset($this->local[$parentId][strtolower($file->filename())]);
    }

    private function scan(ModelWithContent $model): void
    {
        $id  = $model instanceof \Kirby\Cms\Site ? '' : $model->id();
        $raw = '';
        foreach ($this->languages() as $code) {
            $raw .= "\n" . json_encode($model->content($code)->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        // nested blocks/layouts are JSON strings inside JSON: unescape once more
        $raw = stripslashes($raw);

        if (preg_match_all('!file://([a-z0-9]+)!i', $raw, $m)) {
            foreach ($m[1] as $uuid) $this->uuids[strtolower($uuid)] = true;
        }

        // file-like tokens: path segments ending in an extension
        if (preg_match_all('![\w\-./@]+\.[a-z0-9]{2,5}\b!i', $raw, $m)) {
            foreach ($m[0] as $token) {
                $token = strtolower(ltrim($token, './'));
                $this->tokens[$token]   = true;
                $this->local[$id][$token] = true;
            }
        }
    }

    /** @return list<string|null> language codes (null for single-language sites) */
    private function languages(): array
    {
        return $this->kirby->multilang()
            ? $this->kirby->languages()->codes()
            : [null];
    }
}
