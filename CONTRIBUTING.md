When making code changes, first propose how you will make the solution, then make the changes, then propose how to test the changes.

Testing guidelines
- Prefer lightweight, static checks for risky areas; avoid adding tests for trivial/static edits.
- Store any test scripts under `tools/` and wire them into `make check` when appropriate.
- Current policy for this project:
  - Encryption/security-critical logic: must have a static test in `tools/` and run via `make check` (e.g., key derivation test).
  - AJAX endpoints: use the existing nonce/form-encoding checker when adding or changing endpoints. It’s not required for unrelated static edits.
  - Terminology scan: `make check` runs `tools/scan_bad_keywords.py` with an expected count (defaults to 3) and writes matches to `tofix.txt`.
  - Minor refactors or copy edits: no test required unless they affect behavior.

Running checks
- From the plugin root: `make check`.
- This runs the AJAX nonce check (useful when endpoints are added/updated) and the encryption key derivation test (always important).

When you receive an instruction that requires changes across multiple files, do not immediately assume the work should be done file-by-file.
First, evaluate whether the task can be automated with a script (e.g., Bash, Python, or another suitable tool). If so, prefer generating a script that will handle the changes consistently across all relevant files.

If the operation is repetitive or mechanical (e.g., text replacements, renaming, inserting boilerplate, restructuring directories), create a reusable script instead of showing manual edits for each file.

If a script is not appropriate (e.g., highly unique, one-off edits per file), then proceed with explicit file-by-file changes.

When creating a script, include comments/instructions on how to run it, and ensure it is idempotent (safe to run multiple times without breaking things).

Clearly explain why you chose the script vs. direct edits.
