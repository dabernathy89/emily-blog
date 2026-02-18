#!/usr/bin/env bash
#
# Detect whether a push contains only article content changes (incremental)
# or includes template/config changes (full rebuild required).
#
# Usage: detect-changes.sh [before_sha] [after_sha]
#
# Outputs to $GITHUB_OUTPUT:
#   mode=incremental|full
#   urls=<space-separated list of URLs to regenerate> (only when incremental)
#   deleted=<space-separated list of URL paths to remove from snapshot>

set -euo pipefail

# Get the before and after commits from arguments
BEFORE="${1:-}"
AFTER="${2:-HEAD}"

# Get files changed in this push
if [ -n "$BEFORE" ]; then
    CHANGED=$(git diff --name-only "$BEFORE" "$AFTER" 2>/dev/null || echo "")
else
    # Fallback for when no SHAs provided (shouldn't happen in workflow)
    CHANGED=$(git diff --name-only HEAD~1 HEAD 2>/dev/null || echo "")
fi

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
if [ -n "$BEFORE" ]; then
    ADDED_MODIFIED=$(git diff --diff-filter=AM --name-only "$BEFORE" "$AFTER" 2>/dev/null | grep '^content/collections/articles/' || true)
else
    ADDED_MODIFIED=$(git diff --diff-filter=AM --name-only HEAD~1 HEAD 2>/dev/null | grep '^content/collections/articles/' || true)
fi

# Get deleted articles (files to remove from snapshot)
if [ -n "$BEFORE" ]; then
    DELETED=$(git diff --diff-filter=D --name-only "$BEFORE" "$AFTER" 2>/dev/null | grep '^content/collections/articles/' || true)
else
    DELETED=$(git diff --diff-filter=D --name-only HEAD~1 HEAD 2>/dev/null | grep '^content/collections/articles/' || true)
fi

# Build URL list from added/modified articles
# Format: 2013-11-09.my-blogging-challenge.md -> /2013/11/my-blogging-challenge
URLS=""
ARCHIVE_MONTHS=""
TAXONOMY_URLS=""
while IFS= read -r file; do
    [ -z "$file" ] && continue
    basename=$(basename "$file" .md)
    date_part="${basename%%.*}"
    slug="${basename#*.}"
    year="${date_part%%-*}"
    month=$(echo "$date_part" | cut -d- -f2)
    URLS="$URLS /${year}/${month}/${slug}"
    ARCHIVE_MONTHS="$ARCHIVE_MONTHS ${year}/${month}"
    # Add taxonomy archive pages from front matter
    for taxonomy in category tag; do
        terms=$(awk "/^${taxonomy}:/{found=1; next} found && /^  - /{line=\$0; gsub(/^[[:space:]]*- /, \"\", line); print line} found && /^[a-zA-Z]/{found=0}" "$file")
        while IFS= read -r term; do
            [ -z "$term" ] && continue
            TAXONOMY_URLS="$TAXONOMY_URLS /${taxonomy}/${term}"
        done <<< "$terms"
    done
done <<< "$ADDED_MODIFIED"

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
    ARCHIVE_MONTHS="$ARCHIVE_MONTHS ${year}/${month}"
    # Add taxonomy archive pages from front matter (regenerate, not delete)
    # Use git show to read deleted file content from the BEFORE commit
    for taxonomy in category tag; do
        terms=$(git show "${BEFORE}:${file}" 2>/dev/null | awk "/^${taxonomy}:/{found=1; next} found && /^  - /{line=\$0; gsub(/^[[:space:]]*- /, \"\", line); print line} found && /^[a-zA-Z]/{found=0}")
        while IFS= read -r term; do
            [ -z "$term" ] && continue
            TAXONOMY_URLS="$TAXONOMY_URLS /${taxonomy}/${term}"
        done <<< "$terms"
    done
done <<< "$DELETED"

# Regenerate index pages, affected monthly archives, sitemap, and feeds
URLS="$URLS /articles /"
URLS="$URLS /sitemap.xml /feed /feed/atom"
for ym in $(echo "$ARCHIVE_MONTHS" | tr ' ' '\n' | sort -u); do
    [ -z "$ym" ] && continue
    URLS="$URLS /${ym}"
done

# Deduplicate and append taxonomy archive URLs
for tu in $(echo "$TAXONOMY_URLS" | tr ' ' '\n' | sort -u); do
    [ -z "$tu" ] && continue
    URLS="$URLS $tu"
done

echo "mode=incremental" >> "$GITHUB_OUTPUT"
echo "urls=${URLS}" >> "$GITHUB_OUTPUT"
echo "deleted=${DELETED_PATHS}" >> "$GITHUB_OUTPUT"
