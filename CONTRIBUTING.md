When making code changes, first propose how you will make the solution, then make the changes, then propose how to test the changes.

Testing guidelines
- Prefer lightweight, static checks for risky areas; avoid adding tests for trivial/static edits.
- Store any test scripts under `tools/` and wire them into `make check` when appropriate.
- Current policy for this project:
  - Encryption/security-critical logic: must have a static test in `tools/` and run via `make check` (e.g., key derivation test).
  - AJAX endpoints: use the existing nonce/form-encoding checker when adding or changing endpoints. It’s not required for unrelated static edits.
  - Minor refactors or copy edits: no test required unless they affect behavior.

Running checks
- From the plugin root: `make check`.
- This runs the AJAX nonce check (useful when endpoints are added/updated) and the encryption key derivation test (always important).

