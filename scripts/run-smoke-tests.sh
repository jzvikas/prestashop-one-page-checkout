#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

shopt -s nullglob
smoke_tests=(tests/Smoke/*Test.php)
if ((${#smoke_tests[@]} == 0)); then
  echo "No smoke tests found under tests/Smoke." >&2
  exit 1
fi

for test in "${smoke_tests[@]}"; do
  printf '==> %s\n' "$test"
  php -d zend.assertions=1 -d assert.exception=1 "$test"
done

printf 'Smoke suite completed: %d test files.\n' "${#smoke_tests[@]}"
