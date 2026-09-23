<?php

use Kirby\Http\Response;
use Kirbydesk\Explorer\Activation;
use Kirbydesk\Explorer\Files;
use Kirbydesk\Explorer\Pages;
use Kirbydesk\Explorer\Preferences;

/*
 * Thin routes: the work happens in Files / Preferences. Errors are
 * thrown as Kirby exceptions, which the panel API turns into error
 * responses ($api calls reject with the message).
 */

$files = fn () => new Files(kirby());
$pages = fn () => new Pages(kirby());
$ids   = fn () => array_values(array_filter((array) kirby()->request()->get('ids'), 'is_string'));

return [
    'routes' => [
        [
            'pattern' => 'explorer/preferences/(:any)',
            'method'  => 'GET',
            'action'  => fn (string $tab) => (new Preferences(kirby()->user()))->get($tab),
        ],
        [
            'pattern' => 'explorer/preferences/(:any)',
            'method'  => 'POST',
            'action'  => fn (string $tab) => (new Preferences(kirby()->user()))->set($tab, kirby()->request()->body()->toArray()),
        ],
        [
            'pattern' => 'explorer/pages',
            'method'  => 'GET',
            'action'  => fn () => $pages()->list(),
        ],
        [
            'pattern' => 'explorer/pages/status',
            'method'  => 'POST',
            'action'  => fn () => $pages()->changeStatus($ids(), (string) kirby()->request()->get('status')),
        ],
        [
            'pattern' => 'explorer/pages/fields',
            'method'  => 'GET',
            'action'  => fn () => $pages()->fields((string) kirby()->request()->get('id')),
        ],
        [
            'pattern' => 'explorer/pages/change',
            'method'  => 'POST',
            'action'  => function () use ($pages) {
                $request = kirby()->request();
                return $pages()->change(
                    (string) $request->get('id'),
                    (string) $request->get('field'),
                    $request->get('value'),
                    $request->get('language'),
                );
            },
        ],
        [
            'pattern' => 'explorer/pages/publish',
            'method'  => 'POST',
            'action'  => fn () => $pages()->publish((string) kirby()->request()->get('id'), kirby()->request()->get('language')),
        ],
        [
            'pattern' => 'explorer/pages/discard',
            'method'  => 'POST',
            'action'  => fn () => $pages()->discard((string) kirby()->request()->get('id'), kirby()->request()->get('language')),
        ],
        [
            'pattern' => 'explorer/pages/delete',
            'method'  => 'POST',
            'action'  => fn () => $pages()->delete($ids()),
        ],
        [
            // one click in the dialog: fetch this domain's key
            'pattern' => 'explorer/activate',
            'method'  => 'POST',
            'action'  => fn () => (new Activation(kirby()))->activate(),
        ],
        [
            // badge on the tabs: entries with unsaved changes
            'pattern' => 'explorer/changes',
            'method'  => 'GET',
            'action'  => fn () => [
                'files' => $files()->changes($language = kirby()->request()->get('language')),
                'pages' => $pages()->changes($language),
            ],
        ],
        [
            'pattern' => 'explorer/files',
            'method'  => 'GET',
            'action'  => fn () => $files()->list(),
        ],
        [
            'pattern' => 'explorer/files/duplicates',
            'method'  => 'GET',
            'action'  => fn () => ['duplicates' => $files()->duplicates()],
        ],
        [
            'pattern' => 'explorer/files/fields',
            'method'  => 'GET',
            'action'  => fn () => $files()->fields((string) kirby()->request()->get('id')),
        ],
        [
            'pattern' => 'explorer/files/change',
            'method'  => 'POST',
            'action'  => function () use ($files) {
                $request = kirby()->request();
                return $files()->change(
                    (string) $request->get('id'),
                    (string) $request->get('field'),
                    $request->get('value'),
                    $request->get('language'),
                );
            },
        ],
        [
            'pattern' => 'explorer/files/publish',
            'method'  => 'POST',
            'action'  => fn () => $files()->publish((string) kirby()->request()->get('id'), kirby()->request()->get('language')),
        ],
        [
            'pattern' => 'explorer/files/discard',
            'method'  => 'POST',
            'action'  => fn () => $files()->discard((string) kirby()->request()->get('id'), kirby()->request()->get('language')),
        ],
        [
            'pattern' => 'explorer/files/delete',
            'method'  => 'POST',
            'action'  => fn () => $files()->delete($ids()),
        ],
        [
            'pattern' => 'explorer/files/templates',
            'method'  => 'POST',
            'action'  => fn () => ['templates' => $files()->commonTemplates($ids())],
        ],
        [
            'pattern' => 'explorer/files/template',
            'method'  => 'POST',
            'action'  => fn () => $files()->changeTemplate($ids(), (string) kirby()->request()->get('template')),
        ],
        [
            // Streams a ZIP; the temporary archive is removed after sending.
            'pattern' => 'explorer/files/download',
            'method'  => 'POST',
            'action'  => function () use ($files, $ids) {
                $zip = $files()->zip($ids());
                register_shutdown_function(fn () => @unlink($zip['path']));
                return Response::download($zip['path'], $zip['filename']);
            },
        ],
    ],
];
