# Jyavani AI Plugin

This repository is the authoritative source for the standalone Jyavani AI plugin.

## Boundaries

- Keep provider credentials out of the browser. Environment values may override dashboard settings; dashboard-managed API keys must use authenticated encryption with master key material outside the database.
- Never persist prompts, source content, selected text, generated content, plaintext API keys, authorization headers, or raw provider responses.
- Use the `jai_` prefix for PHP functions, constants, tables, and browser classes.
- Use only the public `window.JyavaniEditor` contract. Do not depend on Quill, CodeMirror, or editor DOM internals.
- Generated content only mutates the editor draft. Never bypass Core Article/Page save authorization and sanitization.
- Every provider request must be POST/CSRF protected, resource-authorized, rate-limited, size-bounded, timeout-bounded, and TLS verified.
- Keep static destinations below `static/plugins/jyavani-ai/`.
- Keep release ZIPs flat with `plugin.json` at the archive root.

## Verification

- Run PHP syntax checks for every PHP file.
- Run JavaScript syntax checks for every JavaScript file.
- Run every PHP contract under `tests/`.
- Verify the manifest against canonical Jyavani Core.
- Build and reopen the release ZIP before testing installation.
- Test selection and whole-document operations in Article/Page add/edit with both Quill and CodeMirror.
