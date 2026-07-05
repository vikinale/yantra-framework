# Handling File Uploads

This recipe takes an uploaded file end to end: read it off the request as an `UploadedFile`, validate it (type + size), and store it safely to disk. Yantra normalizes PHP's `$_FILES` superglobal into `System\Http\UploadedFile` objects, and gives you two ways to persist them — the low-level `moveTo()`/`moveToUnique()` and the sanitizing `Request::storeUploadedFile()` helper.

```php
public function upload(): Response
{
    $files  = $this->req()->allFiles();
    $avatar = $files['avatar'] ?? null;

    if ($avatar === null || $avatar->getError() !== UPLOAD_ERR_OK) {
        return $this->error('No file uploaded.', 422);
    }

    $path = $this->req()->storeUploadedFile($avatar, storage_path('uploads'));

    return $this->success(['stored_at' => $path]);
}
```

## 1. Read the file from the request

`allFiles(): array` normalizes `$_FILES` into `UploadedFile` objects keyed by the form field name. Fields the user left empty (`UPLOAD_ERR_NO_FILE`) are dropped, and array fields (`<input name="photos[]">`) become arrays of `UploadedFile` objects. The result is computed once and cached per request.

```php
$files  = $this->req()->allFiles();
$avatar = $files['avatar'] ?? null;      // UploadedFile, or null if the field is absent
```

Always check `getError()` before doing anything with the file. `UPLOAD_ERR_OK` (value `0`) means the upload succeeded; any other value is a PHP `UPLOAD_ERR_*` code (file too large per `php.ini`, partial upload, etc.).

```php
if ($avatar === null || $avatar->getError() !== UPLOAD_ERR_OK) {
    return $this->error('No file uploaded.', 422);
}
```

## 2. Inspect the `UploadedFile`

| Method | Returns | Notes |
| --- | --- | --- |
| `getClientFilename(): string` | Original filename | Client-supplied — never trust it for a filesystem path |
| `getClientMediaType(): string` | Client-declared MIME | Also client-controlled |
| `getSize(): int` | Size in bytes | |
| `getError(): int` | `UPLOAD_ERR_*` | `UPLOAD_ERR_OK` (0) is success |
| `moveTo(string $target): void` | — | Moves the temp file; creates the target dir if missing; throws `RuntimeException` on error, if already moved, or if the upload has an error code |
| `moveToUnique(string $target, int $maxTries = 10000): string` | Final path | Like `moveTo()` but appends `-1`, `-2`, … until the name is free; returns the path used |
| `movedPath(): ?string` | Destination | `null` until moved |
| `getStreamContent(): string` | Raw bytes | Empty string on error or missing temp file |

```php
$name = $avatar->getClientFilename();   // 'photo.png' (untrusted)
$size = $avatar->getSize();             // bytes
$err  = $avatar->getError();            // UPLOAD_ERR_OK
```

## 3. Validate the upload

Use the standalone [validator](/essentials/validation) with the file rules. `file` asserts the value is an uploaded file, `mimes` restricts the extension, and `max_file_size` caps the size — its value is in **bytes**, with `K`/`KB` and `M`/`MB` suffixes accepted (`2M` = 2 × 1024 × 1024 bytes).

```php
use System\Validation\Validator;

$validator = Validator::make($this->req()->allFiles(), [
    'avatar' => 'required|file|mimes:jpg,png|max_file_size:2M',
]);

if ($validator->fails()) {
    return $this->validationError($validator->errors()->all());
}
```

`UploadedFile` also carries its own imperative `validate()` if you prefer to check inline — it throws `RuntimeException` on an upload error, oversize file, disallowed extension, or an extension/MIME mismatch (verified via `finfo` for jpg/png/gif/webp/svg/pdf):

```php
$avatar->validate(['jpg', 'png'], 2 * 1024 * 1024);   // throws on failure
```

## 4. Store the file

The safest way to persist is `storeUploadedFile()`. It sanitizes the client filename (anything outside word characters, hyphens, and dots collapses to `-`, so `../../etc/passwd` can't escape the directory), moves the file, and returns the full target path.

```php
// Uses the sanitized client filename:
$path = $this->req()->storeUploadedFile($avatar, storage_path('uploads'));

// Or pass an explicit filename you generate yourself (used as-is):
$path = $this->req()->storeUploadedFile($avatar, storage_path('uploads'), 'avatar-' . $userId . '.png');
```

`storeUploadedFile(UploadedFile $file, string $destinationDir, ?string $filename = null): string`.

If you want to avoid overwriting an existing file, drop down to `moveToUnique()`, which returns the path it actually used:

```php
$path = $avatar->moveToUnique(storage_path('uploads') . '/' . $avatar->getClientFilename());
```

## Full example

```php
use System\Core\BaseController;
use System\Http\Response;
use System\Validation\Validator;

class AvatarController extends BaseController
{
    public function upload(): Response
    {
        $files  = $this->req()->allFiles();
        $avatar = $files['avatar'] ?? null;

        if ($avatar === null || $avatar->getError() !== UPLOAD_ERR_OK) {
            return $this->error('No file uploaded.', 422);
        }

        $validator = Validator::make($files, [
            'avatar' => 'required|file|mimes:jpg,png|max_file_size:2M',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->all());
        }

        $path = $this->req()->storeUploadedFile($avatar, storage_path('uploads'));

        return $this->success(['stored_at' => $path], 201);
    }
}
```

::: warning Gotchas
- **Always check `getError() === UPLOAD_ERR_OK` first.** `moveTo()` throws a `RuntimeException` if the upload has an error code, and again if you try to move the same file twice.
- **`getClientFilename()` and `getClientMediaType()` are attacker-controlled.** Never build a filesystem path from the client filename — use `storeUploadedFile()` (which sanitizes) or generate your own name.
- **`max_file_size` is in bytes.** `max_file_size:2` means 2 bytes, not 2 MB — use the `M`/`K` suffix (`max_file_size:2M`).
- **Empty file fields disappear.** A field the user submitted with no file (`UPLOAD_ERR_NO_FILE`) is not present in `allFiles()`, so use `$files['avatar'] ?? null` rather than assuming the key exists.
- **The `mimes` rule and `UploadedFile::validate()` sniff content** only for a fixed set of image/pdf types; other extensions are checked by extension name alone.
:::

## Related

- [Requests](/essentials/requests) — the full `Request`/`UploadedFile` API
- [Validation](/essentials/validation) — the `file`, `mimes`, and `max_file_size` rules
- [Responses](/essentials/responses)
