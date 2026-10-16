const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');

// Exercise the installed Livewire proxy, including its reserved method aliases.
// PHP component tests bypass this browser-side action resolution.
test('attachment submit resolves to a server action, not Livewire file upload API', () => {
    const source = readFileSync('vendor/livewire/livewire/dist/livewire.esm.js', 'utf8');
    const proxy = source.slice(source.indexOf('var properties = {};'), source.indexOf('import_alpinejs3.default.magic("wire"'));
    const context = vm.createContext({});
    vm.runInContext(proxy + `
        wireProperty('$upload', () => () => 'temporary-upload-api');
        wireFallback(() => (method) => () => 'server:' + method);
        globalThis.wire = generateWireObject({}, {});
    `, context);
    assert.equal(context.wire.upload(), 'temporary-upload-api');
    const view = readFileSync('resources/views/livewire/attachments-manager.blade.php', 'utf8');
    const action = view.match(/wire:submit="([a-zA-Z]+)"/)[1];
    assert.equal(context.wire[action](), 'server:' + action);
    assert.ok(view.includes('public function ' + action + '('));
    assert.ok(view.includes('wire:target="' + action + '"'));
});
