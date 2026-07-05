# Write a Custom Validation Rule

When the 60+ [built-in rules](/essentials/validation) don't cover your case, implement `System\Validation\Contracts\RuleInterface` and drop the object straight into a ruleset. A rule has exactly two methods: `passes()` decides, `message()` explains. This recipe builds a `DisposableEmailRule` that rejects throwaway email domains.

```php
use System\Validation\Validator;

$validator = Validator::make($request->all(), [
    'email' => ['required', 'email', new DisposableEmailRule()],
]);

if ($validator->fails()) {
    return $this->validationError($validator->errors());
}
```

## Step 1 — Implement `RuleInterface`

`passes(string $field, mixed $value, array $data): bool` returns `true` when the value is valid. It receives the field name, the value under test, and the full `$data` array (so cross-field rules are possible). `message(string $field): string` returns the error shown when it fails:

```php
<?php
namespace App\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

class DisposableEmailRule implements RuleInterface
{
    private const BLOCKED = ['mailinator.com', 'tempmail.com', 'guerrillamail.com'];

    public function passes(string $field, mixed $value, array $data): bool
    {
        if (!is_string($value) || !str_contains($value, '@')) {
            return true;   // leave "is it an email at all?" to the `email` rule
        }

        $domain = strtolower(substr(strrchr($value, '@'), 1));

        return !in_array($domain, self::BLOCKED, true);
    }

    public function message(string $field): string
    {
        return "The {$field} may not use a disposable email address.";
    }
}
```

::: tip
Keep each rule focused. Don't re-check that the value is a valid email here — chain the built-in `email` rule before your custom one and let this rule assume it runs on plausible input. Return `true` for values outside your concern so you don't produce misleading messages.
:::

## Step 2 — Use it in a ruleset

A field's rules can be an **array** mixing built-in string rules with rule objects. They run left to right, and every failing rule contributes its message:

```php
use System\Validation\Validator;

$validator = Validator::make($data, [
    'email' => ['required', 'email', 'lowercase', new DisposableEmailRule()],
    'name'  => 'required|string|max:100',
]);

if ($validator->fails()) {
    $errors = $validator->errors();      // [field => [messages...]]
    // handle errors
}

$clean = $validator->validated();        // only fields whose rules all passed
```

The `required` and `nullable` modifiers still apply to the whole field: a `nullable` field that is null skips your rule entirely, and a missing non-`required` field is never validated.

## Cross-field rules

Because `passes()` receives the entire `$data` array, a rule can compare fields — useful when the built-in `same`/`different` don't fit:

```php
class DiffersFromUsernameRule implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        return $value !== ($data['username'] ?? null);
    }

    public function message(string $field): string
    {
        return "The {$field} must not match the username.";
    }
}
```

```php
$validator = Validator::make($data, [
    'password' => ['required', 'min_length:8', new DiffersFromUsernameRule()],
]);
```

## Reusable configured rules

Give the constructor parameters when the same rule needs different thresholds in different places:

```php
class DomainAllowlistRule implements RuleInterface
{
    /** @param string[] $allowed */
    public function __construct(private array $allowed) {}

    public function passes(string $field, mixed $value, array $data): bool
    {
        $domain = strtolower(substr(strrchr((string) $value, '@'), 1));
        return in_array($domain, $this->allowed, true);
    }

    public function message(string $field): string
    {
        return "The {$field} must be a company email address.";
    }
}

// staff signup — only company domains
$validator = Validator::make($data, [
    'email' => ['required', 'email', new DomainAllowlistRule(['acme.com', 'acme.io'])],
]);
```

::: warning Gotchas
- **The interface method is `passes()`, not `validate()`.** `RuleInterface` has only `passes()` and `message()` — there is no `validate()` method.
- **Rule objects only work in the array form** of a ruleset (`['required', new MyRule()]`), not inside a pipe-separated string. The string form is for built-in rules by name.
- **`$value` can be any type** (`mixed`) — including `null` or non-strings. Guard with `is_string()` / type checks before calling string functions, and return `true` for values that aren't your concern so you don't emit misleading errors.
- **`passes()` runs even after earlier rules failed** — every rule in the array is evaluated so all messages surface at once. Make each rule tolerant of input the previous rule would have rejected.
:::

## Related

- [Validation](/essentials/validation) — the full rule reference and error bag
- [Controllers](/essentials/controllers) — returning validation errors from a controller
- [CSV Import cookbook](/cookbook/csv-import) — reuse a rule object inside a row validator
