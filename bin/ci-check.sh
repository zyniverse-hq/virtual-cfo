#!/usr/bin/env bash
#
# Runs the same gates as .github/workflows/tests.yml, locally, before you commit.
#
#   bash bin/ci-check.sh                     # all gates
#   bash bin/ci-check.sh --filter=UserTest   # scope the test gates to matching tests
#   bash bin/ci-check.sh --skip-tests        # static gates only (fast)
#   bash bin/ci-check.sh --coverage          # also enforce CI's coverage minimums
#
# Every gate runs even if an earlier one fails, so you see all problems at once.
# Exits non-zero if any gate failed.

set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

FILTER=""
SKIP_TESTS=false
COVERAGE=false

for arg in "$@"; do
  case "$arg" in
    --filter=*)   FILTER="${arg#*=}" ;;
    --skip-tests) SKIP_TESTS=true ;;
    --coverage)   COVERAGE=true ;;
    -h|--help)    sed -n '2,12p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)            echo "Unknown option: $arg (try --help)" >&2; exit 2 ;;
  esac
done

FAILED=()

# Run a gate, remember whether it failed, but keep going.
gate() {
  local name="$1"; shift
  printf '\n\033[1m── %s\033[0m\n' "$name"
  # Leading newline: some tools (Pint) end their output without one.
  if "$@"; then
    printf '\n\033[32m✓ %s\033[0m\n' "$name"
  else
    printf '\n\033[31m✗ %s\033[0m\n' "$name"
    FAILED+=("$name")
  fi
}

php_syntax() {
  find app config database routes tests -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null
}

gate "PHP Syntax"      php_syntax
gate "Code Style"      vendor/bin/pint --test
gate "Static Analysis" vendor/bin/phpstan analyse --memory-limit=1G
gate "Security Audit"  composer audit

if [ "$SKIP_TESTS" = false ]; then
  # A filtered run is a subset, so coverage minimums would fail by definition.
  if [ -n "$FILTER" ]; then
    gate "Tests (--filter=$FILTER)" php artisan test --compact --filter="$FILTER"
  else
    [ -n "$(find tests/Unit -name '*Test.php' 2>/dev/null)" ] &&
      gate "Unit Tests" php artisan test --compact tests/Unit
    gate "Architecture Tests" php artisan test --compact tests/Architecture

    if [ "$COVERAGE" = true ]; then
      gate "Feature Tests (coverage)" vendor/bin/pest --coverage --min=50 tests/Feature
      gate "Type Coverage"            vendor/bin/pest --type-coverage --min=95
    else
      gate "Feature Tests" php artisan test --compact tests/Feature
    fi
  fi
fi

printf '\n'
if [ ${#FAILED[@]} -eq 0 ]; then
  printf '\033[32mAll gates passed.\033[0m\n'
  [ "$COVERAGE" = false ] && [ "$SKIP_TESTS" = false ] && [ -z "$FILTER" ] &&
    printf 'Note: coverage minimums not checked. CI enforces them — run with --coverage.\n'
  exit 0
fi

printf '\033[31mFailed: %s\033[0m\n' "${FAILED[*]}"
exit 1
