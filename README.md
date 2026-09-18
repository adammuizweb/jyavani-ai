# Jyavani AI Plugin Development Guide

Jyavani AI is an installable Jyavani CMS plugin for assisted drafting and rewriting inside Article and Page editors. Version `0.4.0` requires Jyavani `2.3.139` for the editor API and declarative plugin-owned sidebar icon contract.

## Installation

1. Build a flat package with `php tools/build-package.php /tmp/jyavani-ai-0.4.0.zip`.
2. Upload the ZIP through Jyavani Plugin Manager and choose **Install & Activate**.
3. Open **Tools > Jyavani AI** as the Site Owner and configure the provider.
4. Optionally configure environment overrides described below for a managed deployment.

The plugin will not install on an older Core because those releases do not expose the required editor API.

## Provider Configuration

Only the Site Owner can open **Tools > Jyavani AI**. The page provides fixed ChatGPT (OpenAI) and Gemini presets, stores a separate API key for each provider, and lets the Site Owner switch providers without re-entering keys. Saved keys are decrypted for display on this Site Owner-only page; no additional password confirmation is required when saving.

The active-provider selector displays only that provider's API key field. Timeout, input-byte limit, output-token limit, and per-user request rate are shared settings that apply to both providers.

The encryption master key remains outside the database. The plugin checks `JYAVANI_AI_SECRET_KEY`, then `APP_KEY`, then the existing Core `SESSION_SECRET`; the selected value must contain at least 32 characters. Rotating that master secret requires re-entering the provider API key.

Environment variables remain available and override matching dashboard values:

```dotenv
JYAVANI_AI_SECRET_KEY=...at-least-32-random-characters...
JYAVANI_AI_OPENAI_API_KEY=...
JYAVANI_AI_GEMINI_API_KEY=...
JYAVANI_AI_TIMEOUT_SECONDS=60
JYAVANI_AI_MAX_INPUT_BYTES=200000
JYAVANI_AI_MAX_OUTPUT_TOKENS=4000
JYAVANI_AI_REQUESTS_PER_MINUTE=10
```

The fixed provider endpoints use the OpenAI-compatible Chat Completions request and response shape. The plugin validates and pins their globally routable IPv4 addresses and disables environment proxies for provider requests. The legacy `JYAVANI_AI_API_KEY`, `JYAVANI_AI_API_URL`, and `JYAVANI_AI_MODEL` variables remain supported together for managed deployments.

## Goal

The plugin will assist users inside the Article and Page editors without bypassing Core save behavior. Initial operations may include:

- Generate a new draft from instructions.
- Rewrite the selected text.
- Rewrite the complete document.
- Summarize, expand, translate, or change tone.
- Return rich HTML for Quill or source HTML for CodeMirror.
- Preview or compare a result before applying it.

The AI endpoint should return a proposed draft mutation. It must not update the `posts` table directly. Users review the result and save it through the normal Article/Page form, preserving Core authorization, sanitization, lifecycle hooks, and unsaved-change protection.

## Core Contract

Core exposes `window.JyavaniEditor` on Article/Page add/edit screens.

```js
const editor = await window.JyavaniEditor.ready();
if (!editor) return;

const context = editor.context();
const mode = editor.getMode();
const html = editor.getContent();
const selection = editor.getSelection();
const revision = editor.getRevision();
```

The context has schema 1:

```js
{
  schema: 1,
  resourceType: 'article' | 'page',
  operation: 'add' | 'edit',
  resourceId: number | null,
  formId: string,
  canUpdate: boolean,
  canUseUnfilteredHtml: boolean,
  csrfToken: string,
  adminBasePath: string
}
```

Available handle methods:

- `getMode()` and `setMode(mode)`
- `getContent()` and `setContent(html, options)`
- `getSelection()` and `setSelection(selection)`
- `insert(value, options)`
- `sync()`
- `isDirty()`
- `focus()`
- `getRevision()`

Core rejects unsafe asynchronous mutations with stable error codes:

- `EDITOR_REVISION_CONFLICT`: content changed after the request started.
- `EDITOR_SELECTION_MODE_MISMATCH`: selection was captured in another editor mode.
- `EDITOR_LOSSY_MODE_CHANGE`: complex CodeMirror HTML cannot safely become Quill content.
- `EDITOR_UNSUPPORTED_MODE`: selection insertion is unavailable in an extension-owned editor mode.

Do not access `window.ADIWIRA.quill`, `window.ADIWIRA.codemirror`, `window.__adam_quill_instance`, or editor DOM IDs from the plugin. Those are Core implementation details.

## Registering An Action

Plugin JavaScript can add one engine-neutral action:

```js
window.JyavaniEditor.registerButton({
  id: 'plugin.jyavani-ai.assist',
  label: 'AI Assistant',
  title: 'Generate or rewrite content',
  priority: 20,
  enabled({ context }) {
    return context.canUpdate;
  },
  async onActivate({ editor, context, selection }) {
    // Open the plugin-owned assistant UI here.
  }
});
```

Action IDs must be lowercase and namespaced. Core preserves the selection on pointer down before focus moves to the button.

## Applying An Async Result

Always bind an AI request to the captured revision. This prevents a slow response from overwriting text edited while the request was running.

```js
const selection = editor.getSelection();
const revision = editor.getRevision();

const result = await generateWithPluginEndpoint({
  content: editor.getContent(),
  selection,
  revision
});

if (selection && selection.to > selection.from) {
  editor.insert(result.html, {
    format: 'html',
    range: selection,
    source: 'plugin.jyavani-ai'
  });
} else {
  editor.setContent(result.html, {
    ifRevision: revision,
    selection: 'preserve',
    source: 'plugin.jyavani-ai'
  });
}
```

`insert()` automatically checks the revision carried by its range. `setContent()` requires `ifRevision` explicitly for asynchronous use.

For Quill, HTML goes through Quill clipboard conversion. For CodeMirror, inserted HTML remains source text. Save-time permissions and sanitization may still transform or reject provider output.

## Events

Either use the registry:

```js
const unsubscribe = window.JyavaniEditor.on('change', detail => {
  console.debug(detail.mode, detail.revision, detail.source);
});
```

or DOM events:

```js
document.addEventListener('jyavani:editor:modechange', event => {
  console.debug(event.detail.mode);
});
```

Supported events are `ready`, `change`, and `modechange`.

## PHP Editor Hooks

Core calls these hooks on all four Article/Page add/edit forms:

```php
add_action('content_editor_before', function (array $context, array $resource, PDO $pdo): void {
    // Optional server-rendered UI before the editor.
}, 10);

add_action('content_editor_actions', function (array $context, array $resource, PDO $pdo): void {
    // Optional UI beside the Core-owned JavaScript action bar.
}, 10);

add_action('content_editor_after', function (array $context, array $resource, PDO $pdo): void {
    // Optional UI after all editor areas.
}, 10);
```

Prefer `JyavaniEditor.registerButton()` for interactive actions. PHP hooks are useful for non-JavaScript fallback notices or plugin-owned panels.

## Plugin Endpoint

The hidden generation route is protected by this plugin-owned unscoped permission:

```text
plugin.jyavani-ai.assistant.generate
```

The browser should POST to the configured admin path, for example:

```text
{adminBasePath}/?page=admin/tools/jyavani-ai/generate&action=generate
```

The endpoint must enforce all of the following:

1. Exact `POST` method.
2. Plugin route permission.
3. `adiwira_csrf_validate()`.
4. A bounded request body, prompt, source content, and output size.
5. `core.posts.create` or `core.pages.create` for add operations.
6. Owner-aware `core.posts.update` or `core.pages.update` for edit operations.
7. Resource type and owner loaded from the database, never trusted from browser context.
8. A bounded provider timeout and response-byte limit.
9. Session release before slow provider I/O when no later session mutation is required.
10. JSON responses through `adiwira_json()`.

The plugin route permission is not a replacement for Core content authorization. Both checks are required.

## Credentials

The plugin supports environment-managed credentials and plugin-owned encrypted dashboard storage. Environment values take priority. Dashboard configuration is Site Owner-only, does not require password reauthentication, and encrypts keys with master key material that remains outside the database.

Separate ciphertext and nonces for OpenAI and Gemini are stored in `jai_provider_settings`. Plaintext keys are rendered only in the Site Owner settings form and otherwise used in server memory to construct provider authorization headers. Keys are never included in general editor configuration, usage records, audit metadata, prompts, or error messages.

Never log authorization headers, full prompts, private content, or raw provider responses by default.

## Provider Client

Provider communication belongs to the plugin. The client must have:

- TLS verification.
- Explicit connect and total timeouts.
- A strict response-size limit.
- A bounded redirect policy without HTTPS downgrade.
- Structured errors with secret redaction.
- Abort handling where supported.
- One bounded retry for an explicit provider `503`; authentication, quota, rate-limit, validation, and other failures are never retried automatically.

Streaming can be added later. A first version can use a normal bounded JSON response.

## Privacy And Safety

- Require a deliberate user action before sending draft content externally.
- Clearly identify which provider receives the content.
- Do not send private content to a provider merely because an editor page opened.
- Treat model output as untrusted HTML.
- Do not execute scripts or provider-returned code.
- Preserve Core save authorization and sanitization.
- Consider a plugin-owned audit record containing actor, resource, operation, provider/model, time, and token counts, but not full content by default.

## Implementation Status

Version `0.4.0` includes:

- Installable manifest with a delegable generation permission and Site Owner-only provider settings.
- Fixed ChatGPT (OpenAI) and Gemini presets with independent encrypted API keys.
- Site Owner-only key editing and provider switching without password confirmation.
- Environment overrides for managed deployments.
- POST/JSON/CSRF/resource-authorized generation endpoint.
- Per-user request limits and redacted usage metadata.
- Selection and complete-document operations.
- Side-by-side source/result review with explicit Apply and Cancel.
- Accessible editor action hover and keyboard-focus states in light and dark themes.
- Core revision-conflict protection and no automatic save.
- Restricted-HTML sanitization of every generated result, including for users who otherwise have unfiltered HTML permission.

Future versions may add provider adapters, streamed responses, richer diffs, and master-key rotation support.

See `examples/editor-assistant.js` for a minimal third-party client integration pattern. The production client is `assets/js/editor-assistant.js`.
