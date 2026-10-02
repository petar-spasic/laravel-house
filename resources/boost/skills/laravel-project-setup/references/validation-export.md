# Validation export

The FormRequest is the single source of every rule, and Laravel stays the authority. `php artisan
validation:export` gives the SvelteKit frontend the same rules and the same messages as Zod 4 modules for
sveltekit-superforms, so the browser reports what the 422 would report. The browser may be laxer than Laravel,
never stricter: a rule it cannot mirror exactly is left to the server and listed in the module. The output is
committed, because the Node image stage has no PHP.

## Marking a form

```php
use PetarSpasic\LaravelHouse\Validation\ExportValidation;

#[ExportValidation('register')]
final class RegisterRequest extends FormRequest
```

- The name is kebab-case, unique in the app, and becomes `register.ts`.
- Every class under `app/` is scanned, so a marked form outside `app/Requests/` is exported too.
- The package is a dev dependency. Without it the attribute is inert: Laravel never instantiates an unknown class
  attribute on a FormRequest, so production validates as usual.
- `dataType: 'json'` posts the form as JSON even when it has no nested field. It is the only value: a flat form is
  already `'form'`, and nested data never travels as FormData.
- `maps: ['settings']` names the array fields keyed by strings (`settings => array`, `settings.* => string`).
  Rules alone cannot tell such a map from a list, so without it `settings` is exported as a list. A map is an
  `array` field with no named keys, never a `list`.

```php
#[ExportValidation('preferences', maps: ['settings'])]
```

## Running it

- `php artisan validation:export` writes `frontend/src/lib/validation/generated/`: `_runtime.ts` plus one module
  per marked form. It rewrites only changed files, removes generated files whose form is gone, and refuses to
  touch a file it did not generate. Dotfiles there (`.gitkeep`) are left alone.
- `--check` writes nothing; it lists `changed`, `missing` or `stale` files and exits 1 when there are any. It
  also lists `unproven <name>` and exits 1 for each exported form without its parity proof,
  `frontend/e2e/parity/<name>.spec.ts` (`frontend` being the first directory of `--path`). The export itself
  never needs the proof, so the page can be built against the module first.
- `--path=<dir>` changes the output directory (project-relative).
- No marked form and no `frontend/` directory: it prints `no exported forms` and exits 0. A marked form without
  `frontend/` is refused.
- `rules()`, `messages()` and `attributes()` run once per locale with no request, no user and no tenant:
  `$this->route()` and `$this->user()` return null and the input is empty. They run once more with Carbon's
  clock moved 400 days and some hours on: a rule that changes (`'before:'.now()->subYears(18)->toDateString()`)
  is server-only, so the module never carries the export date. PHP's own `date()` and `time()` are not caught.
- The locales are `app.locale`, `app.fallback_locale` and every `lang/<locale>/validation.php`.
- The output depends on `lang/` and on `APP_ENV` (through `Password::defaults()`), so export and check in the same
  environment.
- Any refusal fails the whole run and writes nothing. Each line names `class › field › rule: reason`.

## The module

```ts
import * as register from '$lib/validation/generated/register';

// load and the form action
const form = await superValidate(zod4(register.schema(locale)));
const posted = await superValidate(request, zod4(register.schema(locale)));

// the page
superForm(data.form, { validators: zod4Client(register.schema(locale)), dataType: register.dataType });
```

- `schema(locale = defaultLocale)` returns one instance per locale, built once at module load.
- `messages[locale]` holds every message, rendered by Laravel's own Validator, keyed `<field>.<rule>`
  (wildcards as `items.*.name.max`); the same rule again with other parameters is `<field>.<rule>.2`.
- `attributes[locale]` holds each field's display name.
- `dataType` is `'json'` when the form has nested objects, arrays of objects, arrays of arrays or maps, or when
  the attribute says so; else `'form'`.
- `type Input` is the schema's input type.
- The header comment lists the rules only Laravel checks and the fields left out of the schema.
- `_runtime.ts` exports `locales`, `type Locale`, `defaultLocale` and the helpers the modules use.

## Empty values and trimming

Laravel `Str::trim`s every input except the TrimStrings list, turns '' into null, and on a blank value runs only
the implicit rules (required, filled, accepted, declined, required_*). superforms posts '' for required strings,
null for nullable ones and empty numbers, and false for unchecked boxes. Hence:

- Every check runs on the value as Laravel will see it: trimmed (except the TrimStrings list read from
  `bootstrap/app.php`), with '' as null.
- Each field runs Laravel's loop: a blank value meets only implicit rules; an implicit failure stops the field,
  and so does any failure under `bail`.
- Every string or number field, including `*` elements, carries `required`, `nullable`, `filled`, `accepted` or
  `declined`. `sometimes`, `present` and the conditional required_* rules do not settle it on their own.
- Without `nullable`, `required` comes before every other rule: Laravel runs `string|required` on the null
  that '' became and reports both messages.
- Number fields are always nullable in the schema, with the required check as a rule step.
- Array fields are never nullable in the schema: an empty list posts `[]` (a map `{}`), so an optional one
  carries no lower bound.
- Every input the backend reads is declared in `rules()` with a type, because the schema strips undeclared keys.

## Mapping

Presence:
- `required`, `filled`: a not-blank step that stops the field.
- `nullable`: `.nullable()`.
- `sometimes`: `.optional()`.
- `present`: no browser check.
- `bail`: the field stops at its first failing rule.

Types (with no type rule, a field that has value rules is a string):
- `string`; `integer`/`int` (`Number.isInteger`; `integer:strict` exports the same test); `numeric` (`Number.isFinite`); `boolean`/`bool`;
  `array`/`list`; dotted keys build nested objects, `*` builds arrays, and a field in `maps` is a
  `z.record(z.string(), …)`.
- A backed `Rule::enum` with int values and no type rule gives a number field.

Formats:
- `email`, alone or with `rfc`, `strict`, `filter`, `filter_unicode`: `/^.+@.+$/su`. `dns` and `spoof` are
  server-only.
- `url`, `url:<schemes>`: `<scheme>://` followed by anything, plus the scheme list.
- `uuid`: `z.regexes.guid`. A version parameter is server-only.
- `date`: a full Y-m-d must be a real calendar date; other text is left to the server.
- `date_format`: `Y-m-d`, `Y-m-d\TH:i` and `H:i`, exactly; any other format is server-only.
- `before`, `after`, `before_or_equal`, `after_or_equal`, `date_equals`: with a static Y-m-d, a string compare
  when the value is a full Y-m-d; with another field, a cross check on two full Y-m-d values; relative dates are
  server-only.

Sizes (`min`, `max`, `between`, `size`):
- with a numeric rule: the value;
- on an array: its length; on a map: its key count;
- otherwise: code points of the trimmed string (`mb_strlen`).

Value sets:
- `in`, `Rule::in`, `not_in`: an includes check on the string, or on `String(v)` for a number field.
- `Rule::enum` (backed; `only` and `except` honoured): an includes check on the case values.
- No `z.enum` and no `z.literal`: superforms would preselect the first case or tick the box.

Strings:
- `regex`, `not_regex`: see Regex below.
- `digits`, `digits_between`.
- `alpha`, `alpha_num`, `alpha_dash`: `\p{L}\p{M}` (and `\p{N}`, `_-`) classes; `:ascii` uses ASCII classes.
- `lowercase`, `uppercase`.
- `starts_with`, `ends_with`, `doesnt_start_with`, `doesnt_end_with`.

Booleans:
- `accepted`: `v === true`; `declined`: `v === false`. Both stop the field.

Password (`Password::min(…)` and `Password::defaults()`):
- `chars ≥ min`, `≤ max`;
- mixed case `/(\p{Ll}+.*\p{Lu})|(\p{Lu}+.*\p{Ll})/u`, letters `/\p{L}/u`, symbols `/\p{Z}|\p{S}|\p{P}/u`,
  numbers `/\p{N}/u`;
- the checks run together, as one rule, and only on a non-blank value;
- a `messages()` entry for the Password class replaces every Password message, as in Laravel;
- `uncompromised()` and custom rules are server-only.

Cross-field checks (object-level, on the field's own path, never aborting):
- `confirmed[:field]`, `same`, `different`;
- `required_if` and `required_unless` with one value;
- `required_with`, `required_with_all`, `required_without`, `required_without_all`;
- a date comparison with another field.
- A field's later required_* check runs only when its earlier ones pass, as Laravel stops there; on a
  `sometimes` field none runs when the key is absent.

`confirmed` declares `<field>_confirmation` when `rules()` does not.

Regex: the pattern becomes a JS literal with the `u` flag. Translated: delimiters, the `s`, `u` and `D` modifiers,
`i` with `u`, a leading `(?i)`, `\A`, `\z`, `(?P<name>`, `(?:`, lookarounds, `\p{…}` general categories, escaped
punctuation. Without `s`, `.` becomes `[^\n]` (JS `.` also stops at `\r`, U+2028 and U+2029); without `u`, `\s`
becomes PHP's six ASCII spaces. Without `D`, PCRE's `$` also matches before a final newline; on an untrimmed field
the literal keeps that. Everything else is refused, among it:
- the `m` modifier (JS also breaks lines at `\r`, U+2028 and U+2029);
- atomic groups, possessive quantifiers, recursion, conditionals, backtracking verbs, inline flags elsewhere;
- POSIX classes, `\Q…\E`, `\x{…}`, `\R`, `\h`, `\v`, `\K`, `\G`, `\Z`, `\X`;
- `\d \w \s \b` and their negations under `u` (Unicode in PHP, ASCII in JS): write `[0-9]` or `\p{L}`;
- without `u`: `i` (JS folds case by Unicode), `.`, negated classes, `\D \W \S` and non-ASCII characters (PHP
  then matches bytes): add `u`.

TypeScript compile-checks every emitted literal in `npm run check`.

Not in the schema (stripped, listed in the header):
- `prohibited`, `exclude`, `missing`;
- upload fields (`file`, `image`, `mimes`, `mimetypes`, `extensions`, `dimensions`, `File`): the form action
  forwards those from the request itself.

Server-only (the check is dropped, the field kept, the rule listed, and the 422 shows it on the field): every
other Laravel rule and every rule object, among them:
- `unique`, `exists`, `current_password`, `active_url`;
- `decimal`, `gt`, `gte`, `lt`, `lte`, `json`, `ip*`, `mac_address`, `multiple_of`, `distinct`, `array:<keys>`;
- `accepted_if`, `declined_if`, `prohibited_if`, `prohibited_unless`, `missing_*`, `present_*`;
- `required_if`/`required_unless` with several values, `required_if_accepted`, `required_array_keys`;
- `Rule::email()`, `Rule::anyOf()`, `Rule::can()`, `Rule::forEach()`;
- `Rule::when(<closure>)`, `Rule::requiredIf(<closure>)`, `Rule::prohibitedIf(<closure>)`;
- closures, custom rule objects, registered extensions;
- cross-field rules inside `*` paths, on a `bail` field, or naming an upload or other field left out of the schema;
- rules whose text changes with the date;
- `withValidator()`, `after()`, `passedValidation()`.

Refused (the export fails):
- an unknown rule string;
- `exclude_if`, `exclude_unless`, `exclude_with`, `exclude_without`, `Rule::excludeIf(<closure>)`;
- an untranslatable regex;
- a string or number field without a settling presence rule, or without `nullable` and with a rule before
  `required`;
- a field with only presence and server-only rules and no type rule;
- an array without `required` or `filled` whose `min`, `size` or `between` asks for an item: write
  `required|array|min:1` for a list that needs items, or drop the bound for an optional one;
- a `dataType` other than `'json'`; a `maps` entry that is not a declared `array` field without named keys;
- `prepareForValidation()`, `validationData()` or `validator()` on the form;
- request, user or tenant state that fails without them (`$this->user()->id`, an eager `CurrentTenant::id()`);
- `rules()` that differ by locale; `messages()` or `attributes()` that change with the date;
- a message using `:input`, `:index` or `:position`;
- a `*` field whose messages change with the index: give it a name in `attributes()`;
- `confirmed` without its confirmation field on a `#[FailOnUnknownFields]` form;
- a non-backed `Rule::enum`.

Request, user or tenant values may only feed server-only rules, read null-safely or inside a closure:
`->ignore($this->route('user'))`, `->ignore($this->user()?->id)`,
`Rule::unique('projects')->where(fn ($q) => $q->where('tenant_id', CurrentTenant::id()))`.

## The gate

`frontend/package.json`:

```json
"check": "php ../artisan validation:export --check && svelte-kit sync && svelte-check --tsconfig ./tsconfig.json"
```

- It never runs in `build`, `prebuild` or `postinstall`.

## Project checks

- `npm run check` passes; it fails while an exported form lacks its parity proof (`tests/CLAUDE.md`).
