# Contributing Guidelines

This project enforces an **automation-first** policy.

- If a task spans multiple files, **do not** edit file-by-file.
- Instead, **write or use a script** (e.g., Bash/Python in `tools/`) to perform the change safely and consistently.
- **Codex must not commit to Git.** Codex should output a **commit message** the maintainer can copy/paste.

---

## Codex Execution Protocol (you must follow this order)

1) **Propose a solution**
   - Describe the approach succinctly (what to change, which files, why automation is appropriate).
   - If a tool already exists in `tools/`, reference it. If not, propose a new one.

2) **Make the changes**
   - Prefer adding or updating a script in `tools/` to automate the change.
   - Scripts must be **idempotent** and support a **dry-run** mode by default.
   - Do not run `git` commands; do not stage or commit.

3) **Run/check inside Codex**
   - Explain which checks would run (linters, static scans, `make check`, unit tests) and what you expect to see.
   - If additional validation is useful (e.g., scanning for regressions), propose it here.

4) **Propose how the user can test & verify**
   - Provide precise steps the user can run locally to confirm correctness (commands, pages to visit, expected outputs).
   - Include any rollback instructions if relevant.

5) **Output a commit message (text only)**
   - Provide a single, conventional-commit style message the maintainer can paste into:
     ```
     git commit -m "<header>" -m "<body>"
     ```

---

## Automation Rules

- **Use or write scripts** for multi-file work.
- **Location:** place scripts in `tools/`. Keep them small, dependency-light, and documented via `--help` or header comments.
- **Dry-run first:** scripts should default to dry-run, with an explicit `--apply` (or equivalent) to write changes.
- **Idempotence:** running the same script again should not corrupt or duplicate edits.
- **Separation of concerns:** where possible, separate scanning from editing.

---

## Available Tooling

- `tools/inventory.sh` — repository scanner
  - Finds matches with a simple context heuristic and emits **TSV**:
    ```
    file<TAB>line<TAB>context(string|code)<TAB>matched_line
    ```
  - Examples:
    ```bash
    ./tools/inventory.sh -p "presentor|attende" > tofix-inventory.tsv
    ./tools/inventory.sh -p "nonce" -e "php,js" -I ".git,node_modules,dist" -r .
    ```

> If `inventory.sh` is insufficient for the change, **Codex should propose creating a new tool** under `tools/` (with dry-run and apply modes), and then use it.

---

## Makefile (recommended targets)

Maintain simple `make` targets to standardize execution:
- `make scan` — run inventory scans (and write to TSV).
- `make check` — run existing project checks (nonce / key-derivation / terminology scans / etc.).
- `make <custom>` — targets for any new script Codex adds later.

> Codex may propose updating the Makefile, but **must not** run `git` commands.

---

## Testing Guidelines

- **Security-sensitive code** (encryption/keys): must have a static test under `tools/` and run via `make check`.
- **AJAX endpoints**: reuse the nonce/form-encoding checker when adding or changing endpoints.
- **Terminology / style**: rely on scanners to flag regressions before commit.

---

## Pull Request Checklist

- [ ] Multi-file work done via a script in `tools/`, not manual edits.
- [ ] Script supports dry-run and is idempotent.
- [ ] Any Makefile updates added for discoverability.
- [ ] `make check` (and other relevant checks) pass locally.
- [ ] Commit message provided (Conventional Commits), but **no commits made by Codex**.

---

## Commit Message (Codex output format)

Codex should end with a ready-to-paste message like:



refactor: standardize terminology and update admin labels

    Replace legacy UI strings in member-related views

    Add scanner usage notes in docs

    No runtime behavior changes


The maintainer copies this into:
```bash
git add -A
git commit -m "refactor: standardize terminology and update admin labels" \
  -m "- Replace legacy UI strings in member-related views
- Add scanner usage notes in docs
- No runtime behavior changes"
```

