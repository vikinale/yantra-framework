# Validation

Yantra ships a standalone validator with 60+ rules — from basic types and lengths to database-aware `unique`/`exists`, uploaded-file rules, and India-specific identifiers (Aadhaar, PAN, GSTIN, IFSC). Rules are declared as pipe-separated strings per field; parameters follow a colon. All rules respect the `required`, `nullable`, and `sometimes` modifiers.

```php
use System\Validation\Validator;

$validator = new Validator($request->all(), [
    'name'  => 'required|string|max:100',
    'email' => 'required|email|unique:users',
]);

if ($validator->fails()) {
    return $this->validationError($validator->errors()->all());
}
```

## Basic Usage

Construct a `Validator` directly or via the static `make()` factory:

```php
use System\Validation\Validator;

$validator = new Validator($request->all(), [
    'name'     => 'required|string|max:100',
    'email'    => 'required|email|unique:users',
    'password' => 'required|string|min:8|confirmed',
    'age'      => 'integer|gte:18|lte:120',
    'role'     => 'required|in:admin,editor,user',
]);

if ($validator->fails()) {
    $errors = $validator->errors();
    $firstError = $errors->first('email');
    $allErrors = $errors->all();
    return $this->validationError($allErrors);
}

// Validation passed — proceed
```

```php
$validator = Validator::make($request->all(), [
    'name'  => 'required|full_name|max_length:100',
    'email' => 'required|email|lowercase',
]);

if ($validator->fails()) {
    // handle errors
}

// Only the fields that passed validation
$validated = $validator->validated();
```

## Rule Reference

### Presence & Emptiness

| Rule | Description |
|------|-------------|
| `required` | Must be present and not empty (after trim for strings) |
| `nullable` | If value is null or empty string, skip the other rules |
| `sometimes` / `optional` | Only validate the field if the key is present |

```php
'email'       => 'required|email',
'middle_name' => 'nullable|string|max_length:50',
```

### Conditional Presence

| Rule | Description |
|------|-------------|
| `required_if:field,value,...` | Required when another field equals one of the given values |
| `required_with:field` | Required when the other field is present |
| `required_without:field` | Required when the other field is absent |
| `prohibited_if:field,value,...` | Must be absent when another field equals one of the given values |

```php
'company_name'          => 'required_if:account_type,business,enterprise',
'password_confirmation' => 'required_with:password',
'phone_number'          => 'required_without:email',
'discount_code'         => 'prohibited_if:plan,free',
```

### Types

| Rule | Description |
|------|-------------|
| `string` | Must be a string |
| `integer` / `int` | Must be an integer |
| `numeric` | Must be numeric (int or float) |
| `boolean` / `bool` | Boolean or boolean-like (`true`/`false`, `1`/`0`, `"true"`/`"false"`) |
| `array` | Must be an array |
| `json` | Must be a valid JSON string |

```php
'age'       => 'required|integer|min:18',
'price'     => 'required|numeric|min:0',
'is_active' => 'required|boolean',
'metadata'  => 'nullable|json',
```

### Length, Size & Boundaries

| Rule | Description |
|------|-------------|
| `min:N` | Strings: minimum length; numbers: minimum value; arrays: minimum item count |
| `max:N` | Strings: maximum length; numbers: maximum value; arrays: maximum item count |
| `min_length:N` | Explicit minimum string length |
| `max_length:N` | Explicit maximum string length |
| `between:min,max` | Between min and max (numbers, strings, arrays) |
| `digits:N` | Exactly N digits |
| `digits_between:min,max` | Digit count between min and max |

```php
'username'       => 'required|string|min:3|max:50',
'password'       => 'required|min_length:8',
'bio'            => 'nullable|max_length:500',
'age'            => 'required|integer|between:18,65',
'otp'            => 'required|digits:6',
'account_number' => 'required|digits_between:10,16',
```

### Numeric Comparison

| Rule | Description |
|------|-------------|
| `gte:N` | Greater than or equal to N |
| `lte:N` | Less than or equal to N |
| `decimal:N` | Numeric with at most N decimal places |

```php
'age'      => 'required|integer|gte:18',
'quantity' => 'required|integer|lte:100',
'price'    => 'required|numeric|decimal:2',
```

### Text & Formatting

| Rule | Description |
|------|-------------|
| `alpha` | Letters only (`alpha:true` allows spaces) |
| `alpha_num` | Letters and numbers (optionally with spaces) |
| `alpha_dash` | Letters, numbers, underscores, hyphens |
| `slug` | URL-friendly string (lowercase, alphanumeric, hyphens) |
| `lowercase` | Entirely lowercase |
| `uppercase` | Entirely uppercase |
| `starts_with:a,b,...` | Must start with one of the given prefixes |
| `ends_with:a,b,...` | Must end with one of the given suffixes |

```php
'first_name'   => 'required|alpha',
'full_name'    => 'required|alpha:true',   // allow spaces
'username'     => 'required|alpha_num',
'product_slug' => 'required|slug|unique:products,slug',
'country_code' => 'required|uppercase|max_length:2',
'contact'      => 'required|starts_with:+1,+44,+91',
'filename'     => 'required|ends_with:.jpg,.png,.gif',
```

### Pattern Matching & Formats

| Rule | Description |
|------|-------------|
| `regex:/pattern/` | Must match the regular expression |
| `email` | Valid email address |
| `url` | Valid URL |
| `mobile` | Mobile number; country-specific with `mobile:IN`, `mobile:US` |
| `ip` | IP address; restrict with `ip:v4` or `ip:v6` |
| `uuid` | UUID; version-specific with `uuid:4` |

```php
'hex'       => 'required|regex:/^#[0-9A-F]{6}$/i',
'email'     => 'required|email',
'website'   => 'nullable|url',
'mobile_in' => 'required|mobile:IN',
'ipv4_only' => 'required|ip:v4',
'id'        => 'required|uuid:4',
```

### Date & Time

| Rule | Description |
|------|-------------|
| `date` | Valid date; specific format with `date:Y-m-d` |
| `before:date` | Date must be before the given date |
| `after:date` | Date must be after the given date |

```php
'birth_date' => 'required|date|before:2010-01-01',
'event_date' => 'required|date:Y-m-d|after:2025-01-01',
```

### Value Lists

| Rule | Description |
|------|-------------|
| `in:a,b,c` | Must be in the allowed list |
| `not_in:a,b,c` | Must NOT be in the forbidden list |

```php
'status'   => 'required|in:active,inactive,pending',
'username' => 'required|not_in:admin,root,system',
```

### Cross-Field

| Rule | Description |
|------|-------------|
| `same:field` | Must match another field |
| `different:field` | Must differ from another field |
| `confirmed` | Requires a matching `{field}_confirmation` field |

```php
'password_confirmation' => 'required|same:password',
'new_email'             => 'required|email|different:old_email',
'password'              => 'required|min_length:8|confirmed',
```

### Arrays

| Rule | Description |
|------|-------------|
| `distinct` | Array values must be unique |
| `each:rules` | Apply a rule set to every element |
| `key_exists:a,b,...` | Array must contain the given keys |

```php
'tags'    => 'array|distinct',
'ids'     => 'required|array|each:integer',
'address' => 'required|array|key_exists:street,city,zip',
```

### Database

| Rule | Description |
|------|-------------|
| `unique:table,column[,ignore_id[,id_column]]` | Value must not already exist in the table |
| `exists:table,column` | Value must exist in the table |

```php
'email'       => 'required|email|unique:users,email',
// Ignore the current record when updating:
'email'       => "required|email|unique:users,email,{$userId}",
'category_id' => 'required|integer|exists:categories,id',
```

### Files

| Rule | Description |
|------|-------------|
| `file` | Must be an uploaded file |
| `mimes:jpg,png,...` | Uploaded file must match one of the allowed types |
| `max_file_size:N` | Maximum size in **bytes** — `K`/`KB` and `M`/`MB` suffixes supported |

```php
'avatar' => 'required|file|mimes:jpg,png|max_file_size:2M',
```

### Geographic & Postal

| Rule | Description |
|------|-------------|
| `zip_code` / `zipcode` | Postal code; country-specific with `zip_code:US`, `zipcode:UK` (supported: US, CA, UK, AU, DE, FR) |
| `pin_code` / `pincode` | Indian PIN code (6 digits) |

```php
'zip_us'  => 'required|zip_code:US',
'pincode' => 'required|pin_code',
```

### Name & Identity

| Rule | Description |
|------|-------------|
| `full_name` / `fullname` | Full name (minimum word count, valid name characters); `full_name:3` requires 3 words |

```php
'name'   => 'required|full_name',      // at least 2 words
'author' => 'required|full_name:3',    // at least 3 words
```

### Security & Special

| Rule | Description |
|------|-------------|
| `password_strength` | Password complexity. Params: `MinLen,MinUpper,MinLower,MinDigit,MinSymbol` (defaults `8,1,1,1,1`) |
| `honeypot` | Anti-spam field — must be empty (bots tend to fill it) |
| `credit_card` | Credit card number (Luhn check) |
| `mac` | MAC address |
| `hex_color` | Hex color code (`#fff` or `#ffffff`) |

```php
'password'        => 'required|password_strength:12,2,2,2,2',
'website_url'     => 'honeypot',
'card_number'     => 'required|credit_card',
'device_mac'      => 'required|mac',
'brand_color'     => 'required|hex_color',
```

### India-Specific Identifiers

| Rule | Description |
|------|-------------|
| `aadhaar` | Indian Aadhaar number |
| `pan` | Indian PAN number |
| `gstin` | Indian GSTIN |
| `ifsc` | Indian bank IFSC code |

```php
'aadhaar_no' => 'required|aadhaar',
'pan_no'     => 'required|pan',
'gst_no'     => 'required|gstin',
'ifsc_code'  => 'required|ifsc',
```

## Error Bag

`$validator->errors()` returns an error bag:

```php
$errors = $validator->errors();

$errors->has('email');          // true/false
$errors->first('email');        // First error for field
$errors->get('email');          // All errors for field (array)
$errors->all();                 // All errors (flat array)
```

## Custom Validation Rules

Implement `System\Validation\Contracts\RuleInterface` with two methods: `passes()` decides, `message()` explains.

```php
use System\Validation\Contracts\RuleInterface;

class StrongPasswordRule implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        return preg_match('/[A-Z]/', $value)
            && preg_match('/[a-z]/', $value)
            && preg_match('/[0-9]/', $value)
            && strlen($value) >= 10;
    }

    public function message(string $field): string
    {
        return "{$field} must contain uppercase, lowercase, number, and be at least 10 chars.";
    }
}
```

See the [Custom Validation Rule cookbook](/cookbook/custom-validation-rule) for a complete walkthrough.

## Complete Examples

```php
// Registration form
$validator = Validator::make($data, [
    'username'              => 'required|alpha_num|min_length:3|max_length:20',
    'email'                 => 'required|email|lowercase',
    'password'              => 'required|min_length:8|confirmed',
    'password_confirmation' => 'required',
    'mobile'                => 'required|mobile:IN',
    'pincode'               => 'required|pin_code',
    'terms_accepted'        => 'required|boolean',
]);
```

```php
// Product form
$validator = Validator::make($data, [
    'name'        => 'required|string|max_length:200',
    'slug'        => 'required|slug|unique:products,slug',
    'price'       => 'required|numeric|min:0',
    'sku'         => 'required|alpha_dash|max_length:50',
    'description' => 'nullable|string|max_length:2000',
    'status'      => 'required|in:draft,published,archived',
    'tags'        => 'nullable|array',
]);
```

::: warning Gotchas
- Custom rules implement `passes()` + `message()` — there is no `validate()` method on `RuleInterface`.
- `max_file_size` values are **bytes** unless you use a `K`/`KB` or `M`/`MB` suffix — `max_file_size:2` means 2 bytes, not 2 MB.
- `min`/`max` are polymorphic (length for strings, value for numbers, count for arrays); use `min_length`/`max_length` when you specifically mean string length.
:::

## Related

- [Controllers](/essentials/controllers) — `validationError()` responses
- [Requests](/essentials/requests)
- [Validator API](/api/validator)
- [Custom Validation Rule cookbook](/cookbook/custom-validation-rule)
- [File Uploads cookbook](/cookbook/file-uploads)
