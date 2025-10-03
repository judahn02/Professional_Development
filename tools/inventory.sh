#!/usr/bin/env bash
# inventory.sh — scan the repo for a pattern and emit TSV (file<TAB>line<TAB>context<TAB>text)
# Usage examples:
#   ./tools/inventory.sh -p "presentor|attende" > tofix-inventory.tsv
#   ./tools/inventory.sh -p "nonce" -e "php,js" -I ".git,node_modules,dist,vendor" -r .
#
# Options:
#   -p PATTERN       (required) ERE pattern for grep -E
#   -e EXTENSIONS    Comma-separated list of file extensions to include (default: all)
#   -I DIRS          Comma-separated list of directories to ignore (default: .git,node_modules)
#   -r ROOT          Root directory to search (default: .)
#
# Output columns (TSV):
#   file    line    context(string|code)    matched_line

set -euo pipefail

PATTERN=""
EXTS=""
IGNORE_DIRS=".git,node_modules"
ROOT="."

while getopts ":p:e:I:r:" opt; do
  case "$opt" in
    p) PATTERN="$OPTARG" ;;
    e) EXTS="$OPTARG" ;;
    I) IGNORE_DIRS="$OPTARG" ;;
    r) ROOT="$OPTARG" ;;
    \?) echo "Unknown option: -$OPTARG" >&2; exit 2 ;;
    :)  echo "Missing value for -$OPTARG" >&2; exit 2 ;;
  esac
done

if [[ -z "$PATTERN" ]]; then
  echo "Error: -p PATTERN is required" >&2
  exit 1
fi

mapfile -t IGN_ARR < <(echo "$IGNORE_DIRS" | tr ',' '\n' | sed '/^$/d')
EXCL_DIRS=()
for d in "${IGN_ARR[@]}"; do
  EXCL_DIRS+=(--exclude-dir="$d")
done

EXCL_FILES=(--exclude="*.min.*" --exclude="*.tsv")

TARGETS=("$ROOT")
if [[ -n "$EXTS" ]]; then
  # Build a glob for each extension
  mapfile -t EXT_ARR < <(echo "$EXTS" | tr ',' '\n' | sed 's/^\.*//; s/^/\*./')
  TARGETS=()
  for g in "${EXT_ARR[@]}"; do
    TARGETS+=("$ROOT"/**/"$g")
  done
  shopt -s globstar nullglob
fi

# Grep recursively with context detection (quote presence ≈ "string" heuristic)
grep -RInE "${EXCL_DIRS[@]}" "${EXCL_FILES[@]}" -- "${PATTERN}" "${TARGETS[@]}" 2>/dev/null \
| sed -E 's/^([^:]+):([0-9]+):(.*)$/\1\t\2\t\3/' \
| awk -F'\t' '{
    file=$1; line=$2; text=$3;
    ctx = (text ~ /["'\''`][^"'\''`]*["'\''`]/) ? "string" : "code";
    printf("%s\t%s\t%s\t%s\n", file, line, ctx, text);
  }'
