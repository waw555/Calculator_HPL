#!/usr/bin/env bash
set -euo pipefail

while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null
done < <(find . -path './.git' -prune -o -name '*.php' -print0)

echo "All PHP files passed syntax validation."
