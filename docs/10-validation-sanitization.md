# 10. Validation & Sanitization

## 10.1 Overview
Validation enforces an input contract. Sanitization normalizes values but does not replace validation.

## 10.2 Recommended flow
1. Read canonical input (Request::validate preferred)
2. Sanitize selected fields
3. Validate according to rule set
4. Use validated output only

## 10.3 Example
```php
$data = $request->validate(
    ['email' => 'required|email', 'password' => 'required|min:8'],
    ['email.required' => 'Email is required'],
    ['email' => 'trim|lower', 'password' => 'trim']
);
```

## 10.4 Error handling
On failure, throw ValidationException and return 400 with structured errors.
