A round of fixes, and the wording brought in line with the website.

### Fixed

- The change badge appears in every view at once — it used to show up in the
  edit view only, and in the others after a reload
- Duplicates no longer show every file for a moment while they load
- Column headings are no longer cut off: they are measured in the font they
  are actually set in, capitals and letter-spacing included
- The translation indicator in the list view is centred and carries a tooltip
- One `empty-icon` too many in the files view

### Changed

- Files show their translation state, right next to the file name
- "Activate by hand" is now "Activate manually" in English; the other 33
  languages keep their own wording
- The license link under System → Plugins points to the repository

### Installation

    composer require kirbydesk/kirby-explorer

Or download the zip below and put it into `site/plugins/kirby-explorer`.

Requires Kirby 5 and PHP 8.2+.

### Free, with one activation per domain

The plugin is completely free. Every public domain is activated once, in a
single click from the panel; nothing but the domain is sent. Local domains
are never asked.
