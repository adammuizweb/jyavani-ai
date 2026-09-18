# Changelog

## 0.4.0

- Adopt the Jyavani 2.3.139 declarative plugin-owned sidebar icon contract.
- Remove the route-specific sidebar CSS replacement now owned by Core.

## 0.3.3

- Replace the provider-like sparkle artwork with a provider-neutral Jyavani AI circuit monogram.
- Add a dedicated adaptive sidebar icon with a supported Lucide fallback.

## 0.3.2

- Ensure the inactive provider API-key field is visually hidden despite the field grid layout.

## 0.3.1

- Keep the settings primary button readable across light and dark hover states.
- Show only the active provider's API key field and preserve the inactive provider key.
- Clarify that timeout, input, output, and per-user rate limits are shared by both providers.

## 0.3.0

- Add fixed ChatGPT (OpenAI) and Gemini provider presets with separate encrypted API keys.
- Let the Site Owner switch providers without re-entering either key.
- Show saved keys to the Site Owner and remove password confirmation from settings saves.
- Keep AI generation available to delegated CMS users through the existing generation permission.

## 0.2.1

- Keep the Jyavani AI editor action label readable on hover and keyboard focus in light and dark themes.

## 0.2.0

- Add Site Owner-only provider configuration in the dashboard.
- Encrypt dashboard-managed API keys with XChaCha20-Poly1305 and external master key material.
- Require current-password confirmation for every provider configuration change.
- Keep deployment environment values as explicit higher-priority overrides.
- Add a plugin-owned provider settings migration without changing the original migration history.
- Retry one transient provider 503 and return actionable secret-safe provider errors.

## 0.1.0

- Add JyavaniEditor-based AI assistant for Article and Page add/edit screens.
- Add selection and whole-document generation with explicit preview and apply.
- Add OpenAI-compatible environment configuration and bounded provider transport.
- Add resource authorization, CSRF protection, per-user rate limiting, and redacted usage metadata.
- Sanitize all generated HTML before returning it to the editor.
