# Yantra Framework - Validation Rules Reference

## Overview
The Yantra validation system provides a comprehensive set of validation rules for data validation. All rules support the `required`, `nullable`, and `sometimes` modifiers.

---

## 1. Presence & Emptiness

### `required`
Field must be present and not empty (after trim for strings).
```php
'email' => 'required|email'
```

### `nullable`
If value is null or empty string, skip other rules.
```php
'middle_name' => 'nullable|string|max_length:50'
```

### `sometimes` / `optional`
Only validate if key is present (handled by validator modifiers).
```php
// Automatically handled - field only validated if present
```

---

## 2. Type Validations

### `string`
Value must be a string.
```php
'name' => 'required|string'
```

### `integer` / `int`
Value must be an integer.
```php
'age' => 'required|integer|min:18'
```

### `numeric`
Value must be numeric (int or float).
```php
'price' => 'required|numeric|min:0'
```

### `boolean` / `bool`
Value must be boolean or boolean-like (true/false, 1/0, "true"/"false").
```php
'is_active' => 'required|boolean'
```

### `array`
Value must be an array.
```php
'tags' => 'required|array'
```

### `json`
Value must be a valid JSON string.
```php
'metadata' => 'nullable|json'
```

---

## 3. Length, Size & Boundaries

### `min`
For strings: minimum length; for numbers: minimum value; for arrays: minimum item count.
```php
'username' => 'required|string|min:3'
'age' => 'required|integer|min:18'
```

### `max`
For strings: maximum length; for numbers: maximum value; for arrays: maximum item count.
```php
'username' => 'required|string|max:50'
'quantity' => 'required|integer|max:100'
```

### `min_length`
Explicit minimum string length validation.
```php
'password' => 'required|min_length:8'
```

### `max_length`
Explicit maximum string length validation.
```php
'bio' => 'nullable|max_length:500'
```

### `between`
Value must be between min and max (works for numbers, strings, arrays).
```php
'age' => 'required|integer|between:18,65'
```

### `digits`
Must be exactly N digits.
```php
'otp' => 'required|digits:6'  // Exactly 6 digits
```

### `digits_between`
Digit count must be between min and max.
```php
'account_number' => 'required|digits_between:10,16'
```

---

## 4. Text & Formatting

### `alpha`
Letters only (optionally with spaces).
```php
'first_name' => 'required|alpha'
'full_name' => 'required|alpha:true'  // Allow spaces
```

### `alpha_num`
Letters and numbers (optionally with spaces).
```php
'username' => 'required|alpha_num'
```

### `alpha_dash`
Letters, numbers, underscores, and hyphens.
```php
'slug' => 'required|alpha_dash'
```

### `slug`
URL-friendly string (lowercase, alphanumeric, hyphens only).
```php
'product_slug' => 'required|slug|unique:products,slug'
```

### `lowercase`
Value must be entirely lowercase.
```php
'email' => 'required|email|lowercase'
```

### `uppercase`
Value must be entirely uppercase.
```php
'country_code' => 'required|uppercase|max_length:2'
```

### `starts_with`
String must start with one of the specified prefixes.
```php
'phone' => 'required|starts_with:+1,+44,+91'
```

### `ends_with`
String must end with one of the specified suffixes.
```php
'filename' => 'required|ends_with:.jpg,.png,.gif'
```

---

## 5. Pattern Matching

### `regex`
Must match the specified regular expression pattern.
```php
'hex_color' => 'required|regex:/^#[0-9A-F]{6}$/i'
```

### `email`
Must be a valid email address.
```php
'email' => 'required|email'
```

### `url`
Must be a valid URL.
```php
'website' => 'nullable|url'
```

### `phone`
General phone number validation (7-15 digits, allows formatting).
```php
'contact' => 'required|phone'
```

### `mobile`
Mobile number validation with country-specific formats.
```php
'mobile' => 'required|mobile'           // Generic
'mobile_in' => 'required|mobile:IN'     // Indian mobile
'mobile_us' => 'required|mobile:US'     // US mobile
```

### `ip`
IP address validation (IPv4 and/or IPv6).
```php
'ip_address' => 'required|ip'
'ipv4_only' => 'required|ip:v4'
'ipv6_only' => 'required|ip:v6'
```

### `uuid`
UUID validation (optionally version-specific).
```php
'id' => 'required|uuid'
'uuid_v4' => 'required|uuid:4'
```

---

## 6. Geographic & Postal Codes

### `zip_code` / `zipcode`
Postal code validation with country-specific formats.
```php
'zip' => 'required|zip_code'          // Generic
'zip_us' => 'required|zip_code:US'    // US ZIP code
'zip_uk' => 'required|zipcode:UK'     // UK postcode
```

**Supported countries:** US, CA, UK, AU, DE, FR

### `pin_code` / `pincode`
Indian PIN code (6 digits).
```php
'pincode' => 'required|pin_code'
```

---

## 7. Name & Identity

### `full_name` / `fullname`
Full name validation (minimum word count, valid name characters).
```php
'name' => 'required|full_name'        // At least 2 words
'author' => 'required|full_name:3'    // At least 3 words
```

---

## 8. Value Lists

### `in`
Value must be in the allowed list.
```php
'status' => 'required|in:active,inactive,pending'
```

### `not_in`
Value must NOT be in the forbidden list.
```php
'username' => 'required|not_in:admin,root,system'
```

---

## 9. Cross-Field Validations

### `same`
Value must match another field.
```php
'password_confirmation' => 'required|same:password'
```

### `different`
Value must be different from another field.
```php
'new_email' => 'required|email|different:old_email'
```

### `confirmed`
Checks for a matching `{field}_confirmation` field.
```php
'password' => 'required|min_length:8|confirmed'
// Expects 'password_confirmation' field to match
```

---

## 10. Date & Time

### `date`
Value must be a valid date (optionally with specific format).
```php
'birth_date' => 'required|date'
'event_date' => 'required|date:Y-m-d'
```

---

## Usage Examples

### Basic Form Validation
```php
use System\Validation\Validator;

$validator = Validator::make($request->all(), [
    'name' => 'required|full_name|max_length:100',
    'email' => 'required|email|lowercase',
    'phone' => 'required|mobile:IN',
    'age' => 'required|integer|between:18,65',
    'website' => 'nullable|url',
]);

if ($validator->fails()) {
    $errors = $validator->errors();
    // Handle errors
}

$validated = $validator->validated();
```

### Registration Form
```php
$validator = Validator::make($data, [
    'username' => 'required|alpha_num|min_length:3|max_length:20',
    'email' => 'required|email|lowercase',
    'password' => 'required|min_length:8|confirmed',
    'password_confirmation' => 'required',
    'phone' => 'required|mobile:IN',
    'pincode' => 'required|pin_code',
    'terms_accepted' => 'required|boolean',
]);
```

### Product Form
```php
$validator = Validator::make($data, [
    'name' => 'required|string|max_length:200',
    'slug' => 'required|slug|unique:products,slug',
    'price' => 'required|numeric|min:0',
    'sku' => 'required|alpha_dash|max_length:50',
    'description' => 'nullable|string|max_length:2000',
    'status' => 'required|in:draft,published,archived',
    'tags' => 'nullable|array',
]);
```

---

## Summary of Implemented Rules

✅ **Presence:** required, nullable, sometimes  
✅ **Types:** string, integer, numeric, boolean, array, json  
✅ **Length:** min, max, min_length, max_length, between, digits, digits_between  
✅ **Text:** alpha, alpha_num, alpha_dash, slug, lowercase, uppercase  
✅ **Patterns:** regex, email, url, phone, mobile, ip, uuid  
✅ **Geographic:** zip_code, pin_code, full_name  
✅ **Lists:** in, not_in  
✅ **Cross-field:** same, different, confirmed  
✅ **Strings:** starts_with, ends_with  
✅ **Date:** date  

**Total: 40+ validation rules implemented**
