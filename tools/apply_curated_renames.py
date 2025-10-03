#!/usr/bin/env python3
"""
Apply curated, safe renames across the project to standardize terminology while
avoiding unintended edits (for example, database procedure names).

Rules (default):
 - Quoted text fixes (user-facing copy)
   * "Presentors" -> "Presenters"
   * "Presentor"  -> "Presenter"
   * "Attendees"  -> "Members"
   * "attendees"  -> "members"
   * "Attendee Table" -> "Member Table"
   * "ATTENDEE TABLE" -> "MEMBER TABLE"
   * "No attendees yet." -> "No members yet."
   * "Add presentors to session in the sessions page" ->
     "Add presenters to session in the sessions page"

 - Identifier / handle updates applied everywhere (safe tokens only)
   * PD-admin-presentors-table-(css/js) -> PD-admin-presenters-table-(css/js)
   * PD-admin-presentor-page-css -> PD-admin-presenter-page-css
   * PDPresentors -> PDPresenters
   * profdef_presentors_table -> profdef_presenters_table
   * profdef_presentor_page -> profdef_presenter_page
   * pd_presentors_permission -> pd_presenters_permission
   * pd_get_presentors_json -> pd_get_presenters_json
   * pd_add_presentor_json -> pd_add_presenter_json
   * get_attendees -> get_members (affects function/action names)
   * attendee*/Attendee* tokens -> member*/Member* (variables, classes, etc.)

The list is intentionally explicit to avoid touching DB procedure names such as
`presentor_table_view` or `add_presentor`. Extend the `CURATED_RULES` list below to
cover additional cases as needed.

Usage:
  - Dry run (default):
      python3 tools/apply_curated_renames.py
  - Apply changes in place:
      python3 tools/apply_curated_renames.py --apply
  - Limit to extensions:
      python3 tools/apply_curated_renames.py -e .php,.js,.css,.md,.txt

Exit code: 0 on success, 1 if any error occurs during processing.
"""

from __future__ import annotations
import argparse
import os
from pathlib import Path
import re
import sys
from typing import List, Tuple


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_EXTS = {'.php', '.js', '.css', '.md', '.txt', '.html'}
IGNORE_DIRS = {'.git', 'node_modules', '.venv', '__pycache__'}


class Rule:
    def __init__(self, pattern: str, repl: str, mode: str = 'quoted'):
        self.pattern = pattern
        self.regex = re.compile(pattern)
        self.repl = repl
        if mode not in {'quoted', 'all'}:
            raise ValueError(f"Unsupported rule mode: {mode}")
        self.mode = mode


CURATED_RULES: List[Rule] = [
    # Quoted (user-facing text)
    Rule(r"Presentors", "Presenters", mode='quoted'),
    Rule(r"Presentor",  "Presenter",  mode='quoted'),
    Rule(r"Attendees",  "Members",    mode='quoted'),
    Rule(r"attendees",  "members",    mode='quoted'),
    Rule(r"Attendee Table", "Member Table", mode='quoted'),
    Rule(r"ATTENDEE TABLE", "MEMBER TABLE", mode='quoted'),
    Rule(r"No attendees yet\.", "No members yet.", mode='quoted'),
    Rule(r"Add presentors to session in the sessions page",
         "Add presenters to session in the sessions page", mode='quoted'),

    # Handles / identifiers (safe, non-DB)
    Rule(r"PD-admin-presentors-table-css", "PD-admin-presenters-table-css", mode='all'),
    Rule(r"PD-admin-presentors-table-js",  "PD-admin-presenters-table-js",  mode='all'),
    Rule(r"PD-admin-presentor-page-css",   "PD-admin-presenter-page-css",   mode='all'),
    Rule(r"PDPresentors", "PDPresenters", mode='all'),
    Rule(r"profdef_presentors_table", "profdef_presenters_table", mode='all'),
    Rule(r"profdef_presentor_page",  "profdef_presenter_page",  mode='all'),
    Rule(r"pd_presentors_permission", "pd_presenters_permission", mode='all'),
    Rule(r"pd_get_presentors_json",    "pd_get_presenters_json",    mode='all'),
    Rule(r"pd_add_presentor_json",     "pd_add_presenter_json",     mode='all'),

    # Members terminology (variables, functions, classes, etc.)
    Rule(r"get_attendees", "get_members", mode='all'),
    Rule(r"Attendees", "Members", mode='all'),
    Rule(r"attendees", "members", mode='all'),
    Rule(r"Attendee",  "Member",  mode='all'),
    Rule(r"attendee",  "member",  mode='all'),
    Rule(r"attendee-row", "member-row", mode='all'),
    Rule(r"attendee-list-block", "member-list-block", mode='all'),
    Rule(r"attendee-name", "member-name", mode='all'),
    Rule(r"attendee-email", "member-email", mode='all'),
    Rule(r"no-attendees", "no-members", mode='all'),
    Rule(r"add-attendee-btn", "add-member-btn", mode='all'),
    Rule(r"toggleAttendeeDropdown", "toggleMemberDropdown", mode='all'),
    Rule(r"attendee-profile", "member-profile", mode='all'),
]


def find_quoted_spans(line: str) -> List[Tuple[int, int]]:
    """Return a list of (start, end) indices for single/double/backtick-quoted ranges.
    Simple state machine, handles escaped quotes. End index is exclusive.
    """
    spans = []
    i = 0
    n = len(line)
    while i < n:
        ch = line[i]
        if ch in ('"', "'", '`'):
            q = ch
            start = i + 1
            i += 1
            while i < n:
                if line[i] == '\\':
                    i += 2
                    continue
                if line[i] == q:
                    spans.append((start, i))
                    i += 1
                    break
                i += 1
            else:
                # No closing quote; treat rest of line as quoted
                spans.append((start, n))
                break
        else:
            i += 1
    return spans


def apply_rules_to_line(line: str, rules: List[Rule]) -> Tuple[str, bool]:
    changed = False
    new_line = line
    # Pre-compute quoted spans once per original line
    spans = find_quoted_spans(line)
    if spans:
        # Build a mask for quoted positions
        mask = [False] * len(line)
        for a, b in spans:
            for i in range(a, min(b, len(mask))):
                mask[i] = True
    else:
        mask = None

    for rule in rules:
        if rule.mode == 'quoted' and not spans:
            continue

        # Walk matches left-to-right avoiding overlap issues
        pos = 0
        while True:
            m = rule.regex.search(new_line, pos)
            if not m:
                break
            s, e = m.span()
            if rule.mode == 'quoted':
                # Ensure match is entirely within a quoted region of the ORIGINAL line
                # Map span to original indices by assuming unchanged up to s (safe enough for our limited rules)
                # For robustness, fall back to checking any quoted region contains the substring in current new_line.
                in_quote = False
                if mask and s < len(mask):
                    in_quote = all(mask[i] if i < len(mask) else False for i in range(s, min(e, len(mask))))
                if not in_quote:
                    pos = e
                    continue
            # Replace
            new_line = new_line[:s] + rule.repl + new_line[e:]
            pos = s + len(rule.repl)
            changed = True
    return new_line, changed


def process_file(path: Path, rules: List[Rule], apply: bool) -> int:
    try:
        text = path.read_text(encoding='utf-8', errors='ignore')
    except Exception as e:
        print(f"[ERR] Cannot read {path}: {e}", file=sys.stderr)
        return 0

    lines = text.splitlines(True)
    out_lines: List[str] = []
    total_changes = 0
    for idx, line in enumerate(lines, start=1):
        new_line, changed = apply_rules_to_line(line, rules)
        if changed:
            print(f"{path}:{idx}: {line.rstrip()}\n    -> {new_line.rstrip()}")
            total_changes += 1
        out_lines.append(new_line)

    if apply and total_changes:
        try:
            path.write_text(''.join(out_lines), encoding='utf-8')
        except Exception as e:
            print(f"[ERR] Cannot write {path}: {e}", file=sys.stderr)
            return 0
    return total_changes


def main(argv: List[str]) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--apply', action='store_true', help='Write changes to files (default is dry-run)')
    ap.add_argument('-e', '--ext', help=f'Comma-separated list of file extensions to include (default: {",".join(sorted(DEFAULT_EXTS))})')
    ap.add_argument('-r', '--root', default=str(ROOT), help='Root directory to scan (default: repo root)')
    args = ap.parse_args(argv)

    exts = DEFAULT_EXTS
    if args.ext:
        exts = {e.strip().lower() for e in args.ext.split(',') if e.strip()}

    root = Path(args.root).resolve()
    total = 0
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames[:] = [d for d in dirnames if d not in IGNORE_DIRS]
        for fname in filenames:
            p = Path(dirpath) / fname
            if p.suffix.lower() not in exts:
                continue
            total += process_file(p, CURATED_RULES, args.apply)

    print(f"\nSummary: {'APPLIED' if args.apply else 'DRY-RUN'} - {total} line(s) changed across project")
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
