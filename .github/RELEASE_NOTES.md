Housekeeping: the plugin now says what it needs, and says it early.

### Fixed

- On Kirby 5.0 the plugin took the panel down with a fatal error: the two
  list views build on `Panel\Ui\Item\FileItem`, which arrived in 5.1. It
  now checks the version and stays out of the way instead

### Changed

- `composer.json` asks for Kirby 5.1, PHP 8.2 and the sodium extension, so
  Composer says no before installing rather than leaving a broken panel

### Installation

    composer require kirbydesk/kirby-explorer

Or download the zip below and put it into `site/plugins/kirby-explorer`.

Requires Kirby 5.1 and PHP 8.2+.

### Free, with one activation per domain

The plugin is completely free. Every public domain is activated once, in a
single click from the panel; nothing but the domain is sent. Local domains
are never asked.
