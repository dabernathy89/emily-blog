#!/usr/bin/env bash
#
# Detect whether a push contains only article content changes (incremental)
# or includes template/config changes (full rebuild required).
#
# Outputs to $GITHUB_OUTPUT:
#   mode=incremental|full
#   urls=<space-separated list of URLs to regenerate> (only when incremental)
#   deleted=<space-separated list of URL paths to remove from snapshot>

set -euo pipefail

# Get files changed in this push
CHANGED=$(git diff --name-only ORIG_HEAD HEAD 2>/dev/null || echo "")

# If we can't diff (e.g. first commit), fall back to full
if [ -z "$CHANGED" ]; then
    echo "mode=full" >> "$GITHUB_OUTPUT"
    exit 0
fi

# Check if any changed files are outside article content
NON_CONTENT=$(echo "$CHANGED" | grep -v '^content/collections/articles/' || true)

if [ -n "$NON_CONTENT" ]; then
    echo "mode=full" >> "$GITHUB_OUTPUT"
    exit 0
fi

# Get added/modified articles (files to regenerate)
ADDED_MODIFIED=$(git diff --diff-filter=AM --name-only ORIG_HEAD HEAD 2>/dev/null | grep '^content/collections/articles/' || true)

# Get deleted articles (files to remove from snapshot)
DELETED=$(git diff --diff-filter=D --name-only ORIG_HEAD HEAD 2>/dev/null | grep '^content/collections/articles/' || true)

# Build URL list from added/modified articles
# Format: 2013-11-09.my-blogging-challenge.md -> /2013/11/my-blogging-challenge
URLS=""
while IFS= read -r file; do
    [ -z "$file" ] && continue
    basename=$(basename "$file" .md)
    date_part="${basename%%.*}"
    slug="${basename#*.}"
    year="${date_part%%-*}"
    month=$(echo "$date_part" | cut -d- -f2)
    URLS="$URLS /${year}/${month}/${slug}"
done <<< "$ADDED_MODIFIED"

# Also regenerate index pages that list articles
URLS="$URLS /articles /"

# Build list of paths to delete from snapshot
DELETED_PATHS=""
while IFS= read -r file; do
    [ -z "$file" ] && continue
    basename=$(basename "$file" .md)
    date_part="${basename%%.*}"
    slug="${basename#*.}"
    year="${date_part%%-*}"
    month=$(echo "$date_part" | cut -d- -f2)
    DELETED_PATHS="$DELETED_PATHS /${year}/${month}/${slug}"
done <<< "$DELETED"

echo "mode=incremental" >> "$GITHUB_OUTPUT"
echo "urls=${URLS}" >> "$GITHUB_OUTPUT"
echo "deleted=${DELETED_PATHS}" >> "$GITHUB_OUTPUT"
