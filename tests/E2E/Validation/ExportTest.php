<?php

use PetarSpasic\LaravelHouse\Tests\Support\ExportSandbox;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;
use PetarSpasic\LaravelHouse\Tests\Support\UiSandbox;

beforeEach(function () {
    $this->sandbox = ExportSandbox::create();
});

it('exports an upload-only form with an empty message map per locale', function () {
    $this->sandbox->form('AcmeAvatarRequest', <<<'PHP'
        #[ExportValidation('acme-avatar')]
        final class AcmeAvatarRequest extends FormRequest
        {
            public function rules(): array
            {
                return ['avatar' => 'required|image'];
            }
        }
        PHP);

    [$code, $output] = $this->sandbox->export();
    $module = $this->sandbox->module('acme-avatar');

    expect($code)->toBe(0, $output)
        ->and($module)->toContain("export const messages = {\n  en: {},\n} as const")
        ->and($module)->toContain("export const attributes = {\n  en: {},\n} as const")
        ->and($module)->toContain('.object({})')
        ->and($module)->toContain('//   avatar: handled by the upload path');
});

/** A form named $name: $rules and $attributes are PHP source, $options follow the name in the attribute. */
function acmeForm(ExportSandbox $sandbox, string $class, string $name, string $rules, string $options = '', string $attributes = '[]'): void
{
    $sandbox->form($class, <<<PHP
        #[ExportValidation('{$name}'{$options})]
        final class {$class} extends FormRequest
        {
            public function rules(): array
            {
                return {$rules};
            }

            public function attributes(): array
            {
                return {$attributes};
            }
        }
        PHP);
}

it('writes each message key of a nested required_with field once', function () {
    acmeForm($this->sandbox, 'AcmeContactRequest', 'acme-contact', "['contact.email' => 'nullable|email', 'contact.phone' => 'nullable|string|required_with:contact.email']");

    [$code, $output] = $this->sandbox->export();
    $module = $this->sandbox->module('acme-contact');

    expect($code)->toBe(0, $output)
        ->and(substr_count($module, '"contact.phone.required_with": '))->toBe(1)
        ->and(substr_count($module, '"contact.email.email": '))->toBe(1)
        ->and($module)->toContain('error: m["contact.phone.required_with"]');
});

it('refuses an optional list with a lower bound and exports a required one', function () {
    acmeForm($this->sandbox, 'AcmeTagsRequest', 'acme-tags', "['tags' => 'nullable|array|min:1', 'tags.*' => 'required|string']");

    [$code, $output] = $this->sandbox->export();

    expect($code)->toBe(1)
        ->and($output)->toContain('AcmeTagsRequest › tags › min:1: an empty list posts [], which min:1 refuses: write required|array|min:1 for a list that needs items, or drop min:1 for an optional one')
        ->and($output)->toContain('Nothing was written.');

    $sandbox = ExportSandbox::create();
    acmeForm($sandbox, 'AcmeLabelsRequest', 'acme-labels', "['labels' => 'required|array|min:1', 'labels.*' => 'required|string', 'notes' => 'nullable|array|max:3']", attributes: "['labels.*' => 'label']");

    [$code, $output] = $sandbox->export();

    expect($code)->toBe(0, $output)
        ->and($sandbox->module('acme-labels'))->toContain('(v) => v.length >= 1')
        ->and($sandbox->module('acme-labels'))->toContain('(v) => v.length <= 3');
});

it('forces the json data type when the attribute asks for it', function () {
    acmeForm($this->sandbox, 'AcmeNoteRequest', 'acme-note', "['title' => 'required|string']", ", dataType: 'json'");

    [$code, $output] = $this->sandbox->export();

    expect($code)->toBe(0, $output)
        ->and($this->sandbox->module('acme-note'))->toContain("export const dataType: 'form' | 'json' = \"json\";");
});

it('refuses any data type but json', function () {
    acmeForm($this->sandbox, 'AcmeAddressRequest', 'acme-address', "['address.city' => 'required|string']", ", dataType: 'form'");

    [$code, $output] = $this->sandbox->export();

    expect($code)->toBe(1)
        ->and($output)->toContain("AcmeAddressRequest › (form) › #[ExportValidation]: dataType takes only 'json'");
});

it('exports a named map as a record and posts it as json', function () {
    acmeForm($this->sandbox, 'AcmeSettingsRequest', 'acme-settings', "['settings' => 'required|array|min:2', 'settings.*' => 'required|string|max:40', 'tags' => 'array', 'tags.*' => 'required|string', "
        ."'steps' => 'required|array', 'steps.*.config' => 'array', 'steps.*.config.*' => 'nullable|string', 'fields' => 'array', 'fields.*.label' => 'required|string', 'values' => ['array', fn (\$attribute, \$value, \$fail) => null]]",
        ", maps: ['settings', 'steps.*.config', 'fields', 'values']", "['settings.*' => 'setting', 'tags.*' => 'tag', 'steps.*.config' => 'step config', 'steps.*.config.*' => 'step setting', 'fields.*.label' => 'label']");

    [$code, $output] = $this->sandbox->export();
    $module = $this->sandbox->module('acme-settings');

    expect($code)->toBe(0, $output)
        ->and($module)->toContain('settings: z.record(z.string(), z.string(')
        ->and($module)->toContain('field<Record<string, unknown>>')
        ->and($module)->toContain('(v) => Object.keys(v).length >= 2')
        ->and($module)->toContain('tags: z.array(z.string(')
        ->and($module)->toContain('config: z.record(z.string(), z.string(')
        ->and($module)->toContain('fields: z.record(z.string(), z.object(')
        ->and($module)->toContain('values: z.record(z.string(), z.unknown()')
        ->and($module)->toContain("export const dataType: 'form' | 'json' = \"json\";");
});

it('refuses a map that is not a declared array', function (string $rules, string $reason) {
    acmeForm($this->sandbox, 'AcmePrefsRequest', 'acme-prefs', $rules, ", maps: ['prefs']");

    [$code, $output] = $this->sandbox->export();

    expect($code)->toBe(1)->and($output)->toContain("AcmePrefsRequest › prefs › (field): {$reason}");
})->with([
    'undeclared' => ["['title' => 'required|string']", 'maps names a field rules() does not declare'],
    'list' => ["['prefs' => 'required|list', 'prefs.*' => 'required|string']", 'a map takes array, never list: list requires the keys 0, 1, 2…'],
    'object' => ["['prefs' => 'required|array', 'prefs.theme' => 'required|string']", 'a map has no named keys: drop it from maps for an object'],
    'string' => ["['prefs' => 'required|string']", 'a map is an array field'],
]);

it('refuses an optional map with a lower bound', function () {
    acmeForm($this->sandbox, 'AcmePrefsRequest', 'acme-prefs', "['prefs' => 'nullable|array|size:2']", ", maps: ['prefs']");

    [$code, $output] = $this->sandbox->export();

    expect($code)->toBe(1)
        ->and($output)->toContain('AcmePrefsRequest › prefs › size:2: an empty map posts {}, which size:2 refuses: write required|array|size:2 for a map that needs items, or drop size:2 for an optional one');
});

it('fails the check until every exported form has its parity spec', function () {
    acmeForm($this->sandbox, 'AcmeNoteRequest', 'acme-note', "['title' => 'required|string']");

    [$code, $output] = $this->sandbox->export();
    expect($code)->toBe(0, $output);

    [$code, $output] = $this->sandbox->export(['--check' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('unproven acme-note')
        ->and($output)->toContain('add frontend/e2e/parity/acme-note.spec.ts')
        ->and($output)->not->toContain('run php artisan validation:export');

    $this->sandbox->prove('acme-note');
    [$code, $output] = $this->sandbox->export(['--check' => true]);

    expect($code)->toBe(0, $output)->and($output)->toContain('validation export is current');
});

it('exports the same trimming whether or not the board UI is booted', function () {
    $board = Sandbox::create();
    $board->install('ACME');
    UiSandbox::boot($board->root);
    $sandbox = ExportSandbox::create();
    acmeForm($sandbox, 'AcmeNoteRequest', 'acme-note', "['title' => 'required|string|max:120']");

    [$code, $output] = $sandbox->export();

    expect($code)->toBe(0, $output)
        ->and($sandbox->module('acme-note'))->not->toContain('skipWhen')->not->toContain('trimming may differ');
});

it('strips a server-owned field under missing or prohibited and lists it', function () {
    acmeForm($this->sandbox, 'AcmeNoteRequest', 'acme-note', "['title' => 'required|string', 'user_id' => ['missing'], 'status' => ['prohibited']]");

    [$code, $output] = $this->sandbox->export();
    $module = $this->sandbox->module('acme-note');

    expect($code)->toBe(0, $output)
        ->and($module)->toContain('//   user_id: missing')
        ->and($module)->toContain('//   status: prohibited')
        ->and($module)->not->toContain('user_id: z.')
        ->and($module)->not->toContain('status: z.');
});

it('keeps an array of uploads as a list of unknowns with its count rules', function () {
    acmeForm($this->sandbox, 'AcmeAttachRequest', 'acme-attach', "['files' => 'required|array|min:1|max:5', 'files.*' => 'required|file|max:2048']");

    [$code, $output] = $this->sandbox->export();
    $module = $this->sandbox->module('acme-attach');

    expect($code)->toBe(0, $output)
        ->and($module)->toContain('files: z.array(z.unknown()')
        ->and($module)->not->toContain('z.object({})')
        ->and($module)->toContain('(v) => v.length >= 1')
        ->and($module)->toContain('(v) => v.length <= 5')
        ->and($module)->toContain('//   files.*: handled by the upload path');
});
