# API Reference: Validator

`System\Validation\Validator`

A rule-based validator. You give it a `data` array and a `rules` map (`field => 'rule1|rule2:param' ` or an array of string rules / `RuleInterface` objects), then ask whether it `passes()`/`fails()`, read `errors()`, or pull `validated()` data. Validation is **lazy** — it runs on first inspection and caches its `ValidationResult`. For a guide, see [Validation](/essentials/validation).

Both construction styles work:

```php
use System\Validation\Validator;

$v = new Validator($data, $rules);
// or
$v = Validator::make($data, $rules);
```

## Method Table

| Signature | Returns | Description |
| --- | --- | --- |
| `__construct(array $data, array $rules = [])` | — | Rules are optional at construction; add them later with `rules()`. |
| `static make(array $data, array $rules): self` | `self` | Factory equivalent to the constructor. |
| `rules(array $rules): self` | `self` | Set/replace the rule map (fluent). |
| `validate(): ValidationResult` | `ValidationResult` | Run validation once and cache the result. |
| `passes(): bool` | `bool` | Whether all rules passed. |
| `fails(): bool` | `bool` | Inverse of `passes()`. |
| `errors(): array` | `array` | `['field' => ['message', …], …]`. |
| `firstError(): ?string` | `?string` | First message of the first failing field, or `null`. |
| `validated(): array` | `array` | Flat map of fields whose rules passed. |
| `validatedNested(): array` | `array` | Same, but expanded into a nested array (dot keys → nesting). |
| `validateOrThrow(): array` | `array` | Return `validated()`, or throw `ValidationException` on failure. |

## Modifiers

Three tokens are treated as **modifiers**, not rules, and change how the field is processed:

| Modifier | Effect |
| --- | --- |
| `required` | Field must be present and non-empty (runs the `Required` rule first; on failure, other rules are skipped). |
| `nullable` | If the value is `null` or an empty/whitespace string, it is accepted as `null` and remaining rules are skipped. |
| `sometimes` | If the field is absent from the data, skip it entirely. (When not `required` and absent, the field is skipped anyway.) |

```php
$v = Validator::make($data, [
    'email' => 'required|email',
    'bio'   => 'nullable|max_length:500',
    'age'   => 'sometimes|integer|between:18,120',
]);
```

## Rules Table (authoritative)

This is the complete list from `makeRule()`. Rule tokens are lower-cased; parameters follow a colon and are comma-separated. An **unknown rule name always fails** with the message `Unknown validation rule: <name>`.

### Type rules

| Rule | Parameters | Description |
| --- | --- | --- |
| `string` | — | Value is a string. |
| `integer`, `int` | — | Value is an integer. |
| `numeric` | — | Value is numeric. |
| `boolean`, `bool` | — | Value is boolean-like. |
| `array` | — | Value is an array. |
| `json` | — | Value is a valid JSON string. |

### Size / range

| Rule | Parameters | Description |
| --- | --- | --- |
| `min` | `min:N` | Minimum numeric value. |
| `max` | `max:N` | Maximum numeric value. |
| `between` | `between:MIN,MAX` | Numeric value within an inclusive range. |
| `min_length` | `min_length:N` | Minimum string length. |
| `max_length` | `max_length:N` | Maximum string length. |
| `gte` | `gte:VALUE` | Greater than or equal to a value/field. |
| `lte` | `lte:VALUE` | Less than or equal to a value/field. |
| `digits` | `digits:N` | Exactly N digits. |
| `digits_between` | `digits_between:MIN,MAX` | Digit count within a range. |
| `decimal` | `decimal:PLACES` (default 2) | Decimal with a given number of places. |

### String format

| Rule | Parameters | Description |
| --- | --- | --- |
| `regex` | `regex:/pattern/` | Matches a regular expression. |
| `email` | — | Valid email address. |
| `url` | — | Valid URL. |
| `slug` | — | URL-friendly slug. |
| `alpha` | `alpha:allowSpaces?` | Alphabetic characters. |
| `alpha_num` | `alpha_num:allowSpaces?` | Alphanumeric characters. |
| `alpha_dash` | — | Letters, numbers, dashes, underscores. |
| `starts_with` | `starts_with:a,b,…` | Starts with one of the given values. |
| `ends_with` | `ends_with:a,b,…` | Ends with one of the given values. |
| `lowercase` | — | Entirely lower-case. |
| `uppercase` | — | Entirely upper-case. |
| `uuid` | `uuid:VERSION?` | Valid UUID (optionally a specific version 1–5). |
| `hex_color` | `hex_color:allowShort?` (default true) | Hex color code. |
| `mac` | — | MAC address. |
| `ip` | `ip:v4\|v6?` | IP address (constrain to v4 or v6). |

### Comparison with other fields

| Rule | Parameters | Description |
| --- | --- | --- |
| `same` | `same:field` | Equals another field's value. |
| `different` | `different:field` | Differs from another field. |
| `confirmed` | — | Matches a `<field>_confirmation` companion. |
| `in` | `in:a,b,c` | Value is in the set. |
| `not_in` | `not_in:a,b,c` | Value is not in the set. |
| `distinct` | `distinct:strict?` (default true) | Array items are unique. |

### Conditional presence

| Rule | Parameters | Description |
| --- | --- | --- |
| `required_with` | `required_with:a,b,…` | Required when any listed field is present. |
| `required_without` | `required_without:a,b,…` | Required when any listed field is absent. |
| `required_if` | `required_if:field,val1,val2,…` | Required when another field equals a given value. |
| `prohibited_if` | `prohibited_if:field,val1,val2,…` | Must be absent when another field equals a given value. |

### Dates

| Rule | Parameters | Description |
| --- | --- | --- |
| `date` | `date:FORMAT` (default `Y-m-d`) | Parses against a format. |
| `before` | `before:DATE` | Earlier than a date. |
| `after` | `after:DATE` | Later than a date. |

### Regional / identity formats

| Rule | Parameters | Description |
| --- | --- | --- |
| `mobile` | `mobile:REGION` (default `any`) | Mobile number for a region. |
| `zip_code`, `zipcode` | `zip_code:REGION` (default `any`) | Postal/ZIP code. |
| `pin_code`, `pincode` | — | Indian PIN code. |
| `full_name`, `fullname` | `full_name:MIN_PARTS` (default 2) | Full name with a minimum number of parts. |
| `pan` | — | Indian PAN. |
| `aadhaar` | — | Indian Aadhaar number. |
| `gstin` | — | Indian GSTIN. |
| `ifsc` | — | Indian bank IFSC code. |
| `credit_card` | `credit_card:luhn?` (default true) | Credit-card number. |

### Database

| Rule | Parameters | Description |
| --- | --- | --- |
| `exists` | `exists:table,column` (column default `id`) | A row with the value exists. |
| `unique` | `unique:table,column,ignoreId,idColumn` | No other row has the value. |

### Files

| Rule | Parameters | Description |
| --- | --- | --- |
| `file` | — | An uploaded file. |
| `mimes` | `mimes:jpg,png,pdf` | File extension/type is allowed. |
| `max_file_size` | `max_file_size:1024` / `:2MB` | Max size (accepts `K`/`KB`/`M`/`MB` suffixes). |

### Other

| Rule | Parameters | Description |
| --- | --- | --- |
| `password_strength` | `password_strength:len,upper,lower,digit,symbol` (defaults `8,1,1,1,1`) | Composition requirements. |
| `key_exists` | `key_exists:k1,k2,…` | Given keys exist. |
| `each` | `each:rule` | Apply a rule to each array element. |
| `honeypot` | — | Anti-spam honeypot field (should be empty). |

::: danger `phone` is broken — do not use it
`makeRule()` maps `'phone'` to `new Phone()`, but **`System\Validation\Rules\Phone` does not exist** in `src/System/Validation/Rules/`. Using the `phone` rule triggers a fatal "class not found" error at validation time. Use `mobile` instead. (This is a bug in the framework, not a documentation omission.)
:::

::: warning Also note
- There are **no `gt`, `lt`, or `size` rules** — use `gte`/`lte` and `min`/`max`/`between`/`min_length`/`max_length`.
- Unknown rule names do not throw at parse time; they fail validation with an "Unknown validation rule" message.
:::

## Selected examples

### Reading results

```php
$v = Validator::make($request->all(), [
    'name'  => 'required|string|max_length:100',
    'email' => 'required|email|unique:users,email',
    'age'   => 'nullable|integer|between:18,120',
]);

if ($v->fails()) {
    return $this->res()->json(['errors' => $v->errors()], 422);
}

$data = $v->validated();   // only the fields whose rules passed
```

### Throw on failure

```php
$data = Validator::make($input, $rules)->validateOrThrow();
// throws System\Validation\Exceptions\ValidationException on failure
```

### Passing rule objects directly

The rule array may contain `RuleInterface` instances alongside string rules:

```php
Validator::make($data, [
    'token' => ['required', new \System\Validation\Rules\Uuid()],
]);
```

## Related

- [Validation guide](/essentials/validation)
- [API Reference: Request](/api/request)
- [API Reference: Response](/api/response)
