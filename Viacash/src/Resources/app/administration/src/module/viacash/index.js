import './extension/sw-order';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

const { Module } = Shopware;

Module.register('viacash', {
    type: 'plugin',
    name: 'Viacash',
    title: 'viacash.general.mainMenuItemGeneral',
    description: 'viacash.general.descriptionTextModule',
    version: '1.0.0',
    targetVersion: '1.0.0',
    color: '#333',
    icon: 'default-action-settings',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB
    }
});
