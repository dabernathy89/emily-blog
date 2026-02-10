#!/usr/bin/env bash
#
# Detect whether a push contains only article content changes (incremental)
# or includes template/config changes (full rebuild required).
#
# Outputs to $GITHUB_OUTPUT:
#   mode=incremental|full
#   urls=<space-separated list of URLs to regenerate> (only when incremental)

set -euo pipefail

# Get files changed in this push
CHANGED=$(git diff --name-only HEAD~1 HEAD 2>/dev/null || echo "")

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

# Content-only changes: extract URLs from article filenames
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
done <<< "$(echo "$CHANGED" | grep '^content/collections/articles/')"

# Also regenerate index pages that list articles
URLS="$URLS /articles /"

echo "mode=incremental" >> "$GITHUB_OUTPUT"
echo "urls=${URLS}" >> "$GITHUB_OUTPUT"
