#!/usr/bin/env python3
"""
Static check for WordPress AJAX endpoints in this plugin to ensure the
"form-encoded + nonce" pattern is followed on both server and client.

What it checks:
1) Server (PHP):
   - Finds add_action('wp_ajax_<slug>', 'callback') hooks
   - Locates the callback function and checks it calls check_ajax_referer(..., 'nonce', ...)

2) Client (JS):
   - Searches JS files for requests using that action slug
   - Verifies a 'nonce' is sent in the payload (e.g., object with nonce: ..., or URLSearchParams.set('nonce', ...))
   - Heuristically checks the request is form-encoded (either via jQuery.post or fetch with
     Content-Type: application/x-www-form-urlencoded and a URLSearchParams body)

Exit code:
 - 0 if all discovered endpoints pass both checks
 - 1 if any endpoint fails a check

Usage:
  python3 tools/check_ajax_nonces.py

Notes:
 - This is a static heuristic. It may produce false positives/negatives in complex setups
   (e.g., dynamic wiring, external files). It’s tuned for this plugin’s structure.
"""

from __future__ import annotations
import re
import sys
from pathlib import Path
from typing import Dict, List, Optional, Tuple


PLUGIN_ROOT = Path(__file__).resolve().parents[1]


PHP_HOOK_RE = re.compile(r"add_action\(\s*'wp_ajax_([^']+)'\s*,\s*'([^']+)'\s*\)")
PHP_FUNC_DEF_RE_TMPL = r"function\s+{name}\s*\("
PHP_CHECK_NONCE_RE = re.compile(r"check_ajax_referer\s*\(.*?[,)]", re.IGNORECASE)
PHP_CHECK_NONCE_FIELD_NONCE_RE = re.compile(r"check_ajax_referer\s*\(.*?['\"]nonce['\"]", re.IGNORECASE)

JS_ACTION_PAIR_RE_TMPL = r"action\s*[:=]\s*['\"]{slug}['\"]"
JS_NONCE_PRESENT_RE = re.compile(r"\bnonce\b\s*[:=]|URLSearchParams\s*\(\)|params\.set\(\s*['\"]nonce['\"]", re.IGNORECASE)
JS_FETCH_POST_FORM_URLENCODED_RE = re.compile(
    r"fetch\s*\(.*?\{[\s\S]*?method\s*:\s*['\"]POST['\"][\s\S]*?Content-Type[\s\S]*?application/x-www-form-urlencoded",
    re.IGNORECASE,
)


def read_files_with_suffix(root: Path, suffixes: Tuple[str, ...]) -> List[Path]:
    return [p for p in root.rglob('*') if p.is_file() and p.suffix in suffixes]


def find_ajax_hooks(php_files: List[Path]) -> List[Tuple[str, str, Path, int]]:
    hooks = []
    for f in php_files:
        try:
            text = f.read_text(encoding='utf-8', errors='ignore')
        except Exception:
            continue
        for m in PHP_HOOK_RE.finditer(text):
            slug, cb = m.group(1), m.group(2)
            line = text.count('\n', 0, m.start()) + 1
            hooks.append((slug, cb, f, line))
    return hooks


def locate_function_definition(callback: str, php_files: List[Path]) -> Optional[Tuple[Path, int, str]]:
    pattern = re.compile(PHP_FUNC_DEF_RE_TMPL.format(name=re.escape(callback)))
    for f in php_files:
        try:
            text = f.read_text(encoding='utf-8', errors='ignore')
        except Exception:
            continue
        m = pattern.search(text)
        if m:
            line = text.count('\n', 0, m.start()) + 1
            return f, line, text
    return None


def has_check_ajax_referer(func_text: str, start_index: int) -> Tuple[bool, bool]:
    """Return (has_check, uses_nonce_field_name) scanning from start_index forward."""
    # Scan forward a reasonable window (10k chars) from the function definition
    window = func_text[start_index:start_index + 10000]
    has_check = PHP_CHECK_NONCE_RE.search(window) is not None
    uses_nonce_field = PHP_CHECK_NONCE_FIELD_NONCE_RE.search(window) is not None
    return has_check, uses_nonce_field


def find_js_usages_for_action(slug: str, js_files: List[Path]) -> List[Tuple[Path, int, str]]:
    results = []
    action_re = re.compile(JS_ACTION_PAIR_RE_TMPL.format(slug=re.escape(slug)))
    for f in js_files:
        try:
            text = f.read_text(encoding='utf-8', errors='ignore')
        except Exception:
            continue
        for m in action_re.finditer(text):
            line = text.count('\n', 0, m.start()) + 1
            results.append((f, line, text))
    return results


def js_usage_has_nonce_and_form_encoding(text: str, anchor_line: int) -> Tuple[bool, bool]:
    """Heuristically check within +/- 30 lines of the anchor if nonce is present and form-encoded POST is used."""
    lines = text.splitlines()
    i = max(0, anchor_line - 31)
    j = min(len(lines), anchor_line + 30)
    snippet = "\n".join(lines[i:j])

    has_nonce = JS_NONCE_PRESENT_RE.search(snippet) is not None

    # Consider jQuery.post as form-encoded by convention
    is_jquery_post = '.post(' in snippet or 'jQuery.post(' in snippet
    is_fetch_post_form = JS_FETCH_POST_FORM_URLENCODED_RE.search(snippet) is not None

    has_form_encoded = is_jquery_post or is_fetch_post_form
    return has_nonce, has_form_encoded


def main() -> int:
    php_files = read_files_with_suffix(PLUGIN_ROOT, ('.php',))
    js_files = read_files_with_suffix(PLUGIN_ROOT, ('.js',))

    hooks = find_ajax_hooks(php_files)
    if not hooks:
        print('No wp_ajax_* hooks found. Nothing to check.')
        return 0

    any_fail = False
    print('Discovered AJAX hooks:')
    for slug, cb, f, line in hooks:
        print(f" - {slug} -> {cb} ({f}:{line})")

    print('\nChecking server-side nonce usage...')
    server_results: Dict[str, Tuple[bool, bool]] = {}
    for slug, cb, f, line in hooks:
        loc = locate_function_definition(cb, php_files)
        if not loc:
            print(f"[FAIL] {slug}: callback '{cb}' definition not found")
            server_results[slug] = (False, False)
            any_fail = True
            continue
        func_file, func_line, func_text = loc
        has_check, uses_nonce_field = has_check_ajax_referer(func_text, func_text.find(f'function {cb}('))
        server_results[slug] = (has_check, uses_nonce_field)
        if has_check and uses_nonce_field:
            print(f"[OK]   {slug}: check_ajax_referer present with 'nonce' field ({func_file}:{func_line})")
        elif has_check:
            print(f"[WARN] {slug}: check_ajax_referer present but 'nonce' field name not detected ({func_file}:{func_line})")
        else:
            print(f"[FAIL] {slug}: check_ajax_referer NOT found in callback ({func_file}:{func_line})")
            any_fail = True

    print('\nChecking client-side nonce + form-encoded POST...')
    for slug, _, _, _ in hooks:
        usages = find_js_usages_for_action(slug, js_files)
        if not usages:
            print(f"[WARN] {slug}: No JS usage found for action '{slug}'.")
            # Not strictly a failure — might be server-only
            continue
        # Evaluate all usages; all must pass
        js_ok = True
        for uf, uline, utext in usages:
            has_nonce, has_form = js_usage_has_nonce_and_form_encoding(utext, uline)
            if has_nonce and has_form:
                print(f"[OK]   {slug}: JS includes nonce and form-encoded POST near {uf}:{uline}")
            else:
                js_ok = False
                if not has_nonce and not has_form:
                    print(f"[FAIL] {slug}: JS missing nonce and not form-encoded near {uf}:{uline}")
                elif not has_nonce:
                    print(f"[FAIL] {slug}: JS missing nonce near {uf}:{uline}")
                else:
                    print(f"[FAIL] {slug}: JS not detected as form-encoded POST near {uf}:{uline}")
        if not js_ok:
            any_fail = True

    print('\nSummary:')
    if any_fail:
        print('One or more checks failed. See details above.')
        return 1
    print('All discovered AJAX endpoints follow the nonce + form-encoded pattern.')
    return 0


if __name__ == '__main__':
    sys.exit(main())

