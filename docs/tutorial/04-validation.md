# 4. Validation

In this step you'll validate the post form with `System\Validation\Validator`, redirect back with the errors when it fails, repopulate the fields with `old()`, and display per-field messages. Right now the controller trusts whatever the form sends — after this step it won't.

::: tip You'll need step 3 first
This continues from [3. CRUD & Views](/tutorial/03-crud) — the `PostController`, the routes, and the `posts/create` + `posts/edit` forms. We'll modify `store()` and `update()` and touch the two form views.

Also make sure your CSRF checks and `csrf_field()` agree on scope — this step assumes `$this->validateCsrf('default')` as noted at the end of step 3.
:::

## Declare the rules

Validation rules are pipe-separated strings, one per field. For a post we want a required title and slug (bounded length), a URL-safe slug, and a required body:

```php
use System\Validation\Validator;

$validator = new Validator($this->req()->only(['title', 'slug', 'body']), [
    'title' => 'required|string|max:200',
    'slug'  => 'required|slug|max:200',
    'body'  => 'required|string',
]);

if ($validator->fails()) {
    // handle failure
}
```

`Validator::make($data, $rules)` is an equivalent static factory. The rules used here:

- `required` — present and non-empty (after trimming strings).
- `string` — must be a string.
- `max:200` — for strings, maximum length 200. (`max` is polymorphic; for numbers it caps the value, for arrays the count.)
- `slug` — lowercase, alphanumeric, hyphens — perfect for a URL segment.

The full rule catalogue is in [Validation](/essentials/validation).

::: warning Gotchas
- `max` measures **string length** here because the value is a string. If you specifically mean length regardless of type, use `max_length:200`.
- There is no `gt`/`lt`/`size` rule — use `gte`/`lte`/`between`, and `min`/`max`/`digits` for sizing.
:::

## The error bag

When validation fails, `$validator->errors()` returns an error bag:

```php
$errors = $validator->errors();

$errors->all();          // flat array of all messages
$errors->has('title');   // bool
$errors->first('title'); // first message for a field
$errors->get('title');   // all messages for a field (array)
```

For a JSON endpoint you'd return `$this->validationError($errors->all())` (HTTP 422). For an HTML form we instead **flash** the errors and the submitted input, then redirect back so the user sees their form again with messages inline.

## Flash-back on failure

There's no magic `->withInput()` here — the `old()` helper reads flashed values from the session key `_old_input.<field>`, so we flash them ourselves. Add a small private helper to `PostController` and call it from `store()` and `update()`:

```php
use System\Validation\Validator;
use System\Session\SessionStore;

// ... inside PostController

/**
 * Flash validation errors + submitted input, then redirect back.
 */
private function backWithErrors(array $errors, array $input, string $to): Response
{
    SessionStore::setFlash('errors', $errors);

    foreach ($input as $key => $value) {
        SessionStore::setFlash('_old_input.' . $key, $value);
    }

    return $this->redirectAfterPost($to);
}
```

Now update `store()`:

```php
// POST /posts
public function store(): Response
{
    if (!$this->validateCsrf('default')) {
        return $this->error('Invalid CSRF token', 419);
    }

    $input = $this->req()->only(['title', 'slug', 'body']);

    $validator = new Validator($input, [
        'title' => 'required|string|max:200',
        'slug'  => 'required|slug|max:200',
        'body'  => 'required|string',
    ]);

    if ($validator->fails()) {
        return $this->backWithErrors(
            $validator->errors()->all(),
            $input,
            '/posts/create'
        );
    }

    (new Post)->create([
        'title'     => $input['title'],
        'slug'      => $input['slug'],
        'body'      => $input['body'],
        'published' => true,
    ]);

    return $this->redirectAfterPost('/posts');
}
```

And `update()` — same rules, redirecting back to the edit screen:

```php
// POST /posts/{id}
public function update(int $id): Response
{
    if (!$this->validateCsrf('default')) {
        return $this->error('Invalid CSRF token', 419);
    }

    $post  = (new Post)->findModel($id) ?? abort(404);
    $input = $this->req()->only(['title', 'slug', 'body']);

    $validator = new Validator($input, [
        'title' => 'required|string|max:200',
        'slug'  => 'required|slug|max:200',
        'body'  => 'required|string',
    ]);

    if ($validator->fails()) {
        return $this->backWithErrors(
            $validator->errors()->all(),
            $input,
            '/posts/' . $id . '/edit'
        );
    }

    (new Post)->updateById($id, [
        'title' => $input['title'],
        'slug'  => $input['slug'],
        'body'  => $input['body'],
    ]);

    return $this->redirectAfterPost('/posts/' . $id);
}
```

Because flash data is auto-removed the first time it's read, the errors and old input survive exactly one redirect — precisely the lifetime we want.

## Show the errors in the view

The `old()` calls in the forms from step 3 already repopulate the fields — they read the same `_old_input.<field>` keys we just flashed. All that's left is to render the error list. Add this near the top of the `content` section in **both** `views/posts/create.php` and `views/posts/edit.php`:

```php
<?php $errors = session('errors', []); ?>
<?php if (!empty($errors)): ?>
    <div class="errors">
        <ul>
        <?php foreach ($errors as $message): ?>
            <li><?= e($message) ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
```

`session('errors', [])` pulls the flashed `errors` array (empty when there's nothing to show). Since the form inputs already use `old('title')`, `old('slug')`, and `old('body')`, a failed submission comes back fully populated with the user's text and the list of problems above it.

::: warning Gotchas
- `old('field')` returns `''` by default. In `edit.php` we pass a fallback — `old('title', $post->title)` — so an unedited field still shows the stored value, but a rejected edit shows what the user typed.
- Flash values are single-read. If you need the errors more than once in a template, assign them to a local variable (as above) rather than calling `session('errors')` repeatedly.
- Custom rules implement `passes()` + `message()` (there is no `validate()` method on the rule interface) — see [Validation](/essentials/validation#custom-validation-rules).
:::

## Try it

Run the app and submit the create form with an empty title or a slug containing spaces:

```bash
php -S localhost:8000 -t public
```

You'll be redirected back to the form, your input preserved, with the validation messages listed above it. Fix the fields and it saves.

## Next

The blog validates input, but anyone can create and delete posts. Let's put that behind a login.

→ **[5. Authentication](/tutorial/05-auth)**
← [3. CRUD & Views](/tutorial/03-crud)

## Related

- [Validation](/essentials/validation)
- [Controllers](/essentials/controllers) — `validationError()` responses
- [Session & Cookies](/essentials/session) — flash data
- [Flash Messages cookbook](/cookbook/flash-messages)
