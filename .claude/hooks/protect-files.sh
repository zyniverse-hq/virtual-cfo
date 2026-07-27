#!/bin/bash
# Blocks Edit/Write to sensitive files
# Exit code 2 = block with message shown to Claude

input=$(cat)
# Windows paths have backslashes that break jq — escape them first
sanitized=$(echo "$input" | sed 's/\\/\\\\/g')
file_path=$(echo "$sanitized" | jq -r '.tool_input.file_path // empty')

if [[ -z "$file_path" ]]; then
  exit 0
fi

# Protect composer.lock
if [[ "$(basename "$file_path")" == "composer.lock" ]]; then
  echo "BLOCKED: composer.lock is auto-generated. Run 'composer update' instead." >&2
  exit 2
fi

# Protect phpunit.xml (CI uses DB_PASSWORD=postgres; local may differ — edit manually and revert before committing)
if [[ "$(basename "$file_path")" == "phpunit.xml" ]]; then
  echo "BLOCKED: phpunit.xml contains CI-specific values (DB_PASSWORD). Edit manually and revert before committing." >&2
  exit 2
fi

# Protect source-of-truth SQL schemas
if [[ "$file_path" == */docs/schema/*.sql ]]; then
  echo "BLOCKED: docs/schema/*.sql are source-of-truth references. Edit migrations instead." >&2
  exit 2
fi

exit 0
