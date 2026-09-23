<?php

use Kirby\Panel\Panel;
use Kirbydesk\Explorer\Activation;
use Kirbydesk\Explorer\Preferences;

/*
 * Explorer area: one view component, one route per tab. The open tab is
 * remembered per user; "explorer" itself leads back to it.
 */
$tabs = ['files', 'pages'];

return function () use ($tabs) {
    $preferences = fn () => ($user = kirby()->user()) ? new Preferences($user) : null;

    $view = function (string $tab) use ($preferences) {
        $preferences()?->setLastTab($tab);
        $request = kirby()->request();

        // switching tabs is not a new visit: only count what comes from
        // outside the explorer
        $activation = new Activation(kirby());
        $remind = str_starts_with(Panel::referrer(), '/explorer')
            ? false
            : $activation->visit();

        return [
            'component' => 'k-explorer-view',
            'title'     => t('explorer.name'),
            'props'     => [
                'tab' => $tab,
                // the dialog asks to activate this domain, once in a while
                'remind' => $remind,
                'activation' => $activation->status(),
                // a link may bring filters along, e.g. the files of one page
                'preset' => array_filter([
                    'page' => $request->get('page'),
                    'type' => $request->get('type'),
                ], fn ($value) => is_string($value) && $value !== ''),
            ],
        ];
    };

    $views = [[
        'pattern' => 'explorer',
        'action'  => fn () => Panel::go('explorer/' . ($preferences()?->lastTab($tabs) ?? 'files')),
    ]];
    foreach ($tabs as $tab) {
        $views[] = ['pattern' => 'explorer/' . $tab, 'action' => fn () => $view($tab)];
    }

    return [
        'label' => t('explorer.name'),
        'icon'  => 'explorer',
        'menu'  => true,
        'link'  => 'explorer',
        'views' => $views,
    ];
};
