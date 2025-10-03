#!/usr/bin/env python3
"""
Scan the project for problematic keywords and print file:line with the match.

Defaults target common naming issues in this plugin:
 - presentors, presentor
 - attende, attendee

Usage:
  python3 tools/scan_bad_keywords.py

Options:
  --patterns PAT, -p PAT     Add/override patterns (comma-separated or repeated)
  --case-sensitive, -c       Make matching case sensitive (default: insensitive)
  --root DIR, -r DIR         Root directory to scan (default: repo root)
  --ignore DIR, -i DIR       Extra directories to ignore (repeatable)
  --only-ext EXT, -e EXT     Only scan files with these extensions (comma-separated).
                              Overrides default Python exclusion.
  --expected N               Expect N matches; fails if the count differs.
  --output PATH              Write matches to PATH (default: tofix.txt)

Exit code:
  - With --expected: 0 if found == expected, else 1
  - Without --expected: 0 if no matches, 1 if any matches were found
"""

from __future__ import annotations
import argparse
import os
from pathlib import Path
import sys
import re


DEFAULT_PATTERNS = [
    'presentors',    # wrong plural
    'presentor',     # wrong singular
    'attende',       # misspelling
    'attendee',      # preferred to be renamed to member in this project
]

DEFAULT_IGNORES = {'.git', 'node_modules', '.venv', '__pycache__'}
DEFAULT_SKIP_EXTS = {'.py'}  # skip Python files unless explicitly requested


def iter_files(root: Path, only_ext: set[str] | None, ignores: set[str]):
    for dirpath, dirnames, filenames in os.walk(root):
        # prune ignored directories
        dirnames[:] = [d for d in dirnames if d not in ignores]
        for fname in filenames:
            p = Path(dirpath) / fname
            suffix = p.suffix.lower()
            if only_ext:
                if suffix not in only_ext:
                    continue
            else:
                if suffix in DEFAULT_SKIP_EXTS:
                    continue
            yield p


def compile_patterns(patterns: list[str], case_sensitive: bool) -> list[re.Pattern]:
    flags = 0 if case_sensitive else re.IGNORECASE
    return [re.compile(re.escape(p), flags) for p in patterns if p]


def scan_file(path: Path, regexes: list[re.Pattern]) -> list[tuple[int, str, str]]:
    results: list[tuple[int, str, str]] = []
    try:
        text = path.read_text(encoding='utf-8', errors='ignore')
    except Exception:
        return results
    for i, line in enumerate(text.splitlines(), start=1):
        for rgx in regexes:
            m = rgx.search(line)
            if m:
                results.append((i, rgx.pattern, line.rstrip('\n')))
                break  # one report per line is enough
    return results


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('-p', '--patterns', action='append', help='Comma-separated list of patterns to search')
    ap.add_argument('-c', '--case-sensitive', action='store_true', help='Case sensitive matching')
    ap.add_argument('-r', '--root', default=str(Path(__file__).resolve().parents[1]), help='Root directory to scan')
    ap.add_argument('-i', '--ignore', action='append', default=[], help='Directory to ignore (repeatable)')
    ap.add_argument('-e', '--only-ext', help='Comma-separated list of file extensions to include (e.g., .php,.js,.css). Overrides default Python exclusion.')
    ap.add_argument('--expected', type=int, help='Fail if the number of matches does not equal EXPECTED')
    ap.add_argument('--output', default='tofix.txt', help='Write matches to this file (default: tofix.txt)')

    args = ap.parse_args(argv)

    patterns: list[str] = []
    if args.patterns:
        for chunk in args.patterns:
            patterns.extend([p.strip() for p in chunk.split(',') if p.strip()])
    if not patterns:
        patterns = DEFAULT_PATTERNS[:]

    only_ext: set[str] | None = None
    if args.only_ext:
        only_ext = set(e.strip().lower() for e in args.only_ext.split(',') if e.strip())

    ignores = DEFAULT_IGNORES | set(args.ignore or [])
    root = Path(args.root).resolve()
    regexes = compile_patterns(patterns, args.case_sensitive)

    matches: list[str] = []
    for p in iter_files(root, only_ext, ignores):
        hits = scan_file(p, regexes)
        for line_no, pat, line in hits:
            matches.append(f"{p}:{line_no}: {line}")

    found = len(matches)

    output_path = Path(args.output).resolve()
    try:
        output_path.write_text('\n'.join(matches) + ('\n' if matches else ''), encoding='utf-8')
    except Exception as exc:
        print(f"[ERR] Could not write output file {output_path}: {exc}", file=sys.stderr)
        return 1

    if args.expected is not None:
        status = 'PASS' if found == args.expected else 'FAIL'
        print(f"Terminology scan: found {found}, expected {args.expected} ({status}). Results saved to {output_path}")
        return 0 if found == args.expected else 1

    if found:
        print(f"Terminology scan: found {found} matches. Results saved to {output_path}")
        return 1

    print(f"Terminology scan: no matches found. Results saved to {output_path}")
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
