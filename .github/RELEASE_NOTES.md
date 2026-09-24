The first stable release. Kirby Explorer adds a panel area that shows the
whole project at once: every file and every page in one list, groupable,
sortable, searchable — and editable right there.

### What it does

- **Files** in four views — list, cardlets, cards and edit. Group them by
  page, template, type, usage or translation state; sort by any column;
  search across name, format, template and page
- **Pages** in the order of the site tree, with status, template and the
  storage each one takes, the files inside it included
- **Unused files** at a glance, checked against fields, structures, blocks
  and layouts — not only against the page they sit in
- **Duplicates**, and what their copies cost in storage
- **Missing translations**, for pages and for file meta data
- **Editing** the blueprint fields of many files or pages, one below the
  next, saved when you say so
- **Bulk actions** on any selection: download as a zip, change template or
  status, delete
- **Statistics** on storage, templates, usage and how far content and
  translations are along
- Light and dark mode, and all 34 panel languages

### Since 0.9.3

- The licence under System → Plugins is always called "Proprietary";
  whether a domain is activated shows as its status next to it
- Three translation keys that nothing read any more are gone

### Installation

    composer require kirbydesk/kirby-explorer

Or download the zip below and unpack it into `site/plugins`.

Requires Kirby 5.1 and PHP 8.2+.

### Free, with one activation per domain

The plugin is completely free. Every public domain is activated once, in a
single click from the panel; nothing but the domain is sent. Local domains
are never asked. What is stored, and what you may do with the plugin, is in
the [license](https://kirbydesk.com/explorer/license).
