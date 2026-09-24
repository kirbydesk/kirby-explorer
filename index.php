<?php

use Kirby\Cms\App as Kirby;

/**
 * Kirby 5.1 brought Panel\Ui\Item\FileItem and PageItem, which the two
 * list views build on. On anything older the plugin would take the whole
 * panel down with a fatal error, so it stays out of the way instead.
 */
if (version_compare(Kirby::version() ?? '0.0.0', '5.1.0', '<') === true) {
    return;
}

// Autoload plugin classes (Kirbydesk\Explorer\…)
spl_autoload_register(function (string $class): void {
    $prefix = 'Kirbydesk\\Explorer\\';
    if (!str_starts_with($class, $prefix)) return;
    $path = __DIR__ . '/src/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require $path;
});

/**
 * What System → Plugins shows for this plugin: activated for which
 * domain, or a status that opens the activation dialog right there.
 */
$license = function ($plugin) {
    $activation = new \Kirbydesk\Explorer\Activation(kirby());
    $domain     = $activation->domain();

    if ($activation->isActive() === true) {
        return new \Kirby\Plugin\License(
            plugin: $plugin,
            name: t('explorer.license.name'),
            link: 'https://github.com/kirbydesk/kirby-explorer',
            status: \Kirby\Plugin\LicenseStatus::from([
                'value' => 'active',
                'icon'  => 'check',
                'label' => t('explorer.license.active.label'),
                'theme' => 'positive',
            ])
        );
    }

    // local domains need none, so nothing is missing there
    $local = $activation->isLocal();

    return new \Kirby\Plugin\License(
        plugin: $plugin,
        name: t('explorer.license.name'),
        link: 'https://github.com/kirbydesk/kirby-explorer',
        status: \Kirby\Plugin\LicenseStatus::from([
            'value'  => $local ? 'demo' : 'missing',
            'icon'   => $local ? 'preview' : 'key',
            'label'  => t($local ? 'explorer.license.local.label' : 'explorer.license.missing.label'),
            'theme'  => $local ? 'info' : 'love',
            // opens our own dialog, straight from the plugins table
            'dialog' => $local ? null : 'explorer/activate',
        ])
    );
};

Kirby::plugin('kirbydesk/kirby-explorer', [
    'areas'        => [
        'explorer' => require __DIR__ . '/src/extensions/areas.php',
        // the dialog the license status opens
        'system'   => fn () => [
            'dialogs' => [
                'explorer/activate' => [
                    'load' => fn () => [
                        'component' => 'k-explorer-activation-dialog',
                        'props'     => [
                            'domain' => (new \Kirbydesk\Explorer\Activation(kirby()))->domain(),
                        ],
                    ],
                    // the dialog talks to the API itself
                    'submit' => fn () => true,
                ],
            ],
        ],
    ],
    'api'          => require __DIR__ . '/src/extensions/api.php',
    'translations' => require __DIR__ . '/src/extensions/translations.php',
    // the activation server reads the challenge back from here, to see
    // that this domain really runs the plugin. Nothing else is exposed.
    'routes'       => [
        [
            'pattern' => '.kirby-explorer',
            'method'  => 'GET',
            'action'  => function () {
                // leading backslashes: "Kirby" is the alias for App here
                $challenge = (new \Kirbydesk\Explorer\Activation(kirby()))->challenge();

                return new \Kirby\Http\Response(
                    $challenge ?? '',
                    'text/plain',
                    $challenge === null ? 404 : 200
                );
            },
        ],
        [
            // the link in the key mail lands here: one click, and the
            // domain is licensed. Only for someone who is signed in –
            // nobody else gets to write into site/config.
            'pattern' => '.kirby-explorer/activate',
            'method'  => 'GET',
            'action'  => function () {
                $kirby = kirby();
                $say   = fn (string $message, int $code) => new \Kirby\Http\Response(
                    $message,
                    'text/plain',
                    $code
                );

                if ($kirby->user() === null) {
                    return $say(t('explorer.activate.link.login'), 403);
                }

                $activation = new \Kirbydesk\Explorer\Activation($kirby);

                if ($activation->apply((string) get('key')) === false) {
                    return $say(t('explorer.activate.link.invalid'), 400);
                }

                // the system view shows the license, so it says for itself
                // that this worked
                return \Kirby\Http\Response::redirect($kirby->url('panel') . '/system');
            },
        ],
    ],
], version: '1.0.0', license: $license);
