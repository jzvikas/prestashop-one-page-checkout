#!/usr/bin/env bash
set -euo pipefail

if [[ "${JZOPC_RUNTIME_ACTIVE_FIXTURE:-}" != "1" ]]; then
  echo "Refusing to build active checkout fixture without JZOPC_RUNTIME_ACTIVE_FIXTURE=1." >&2
  exit 2
fi

if [[ $# -ne 2 ]]; then
  echo "Usage: build-active-checkout-fixture.sh <repository-root> <target-dir>" >&2
  exit 2
fi

source_root="$(cd "$1" && pwd)"
target_root="$2"

case "$target_root" in
  /tmp/jzopc-active-fixture|/tmp/jzopc-active-fixture-*) ;;
  *)
    echo "Active checkout fixture target must be an explicit /tmp/jzopc-active-fixture path." >&2
    exit 2
    ;;
esac

source_module="$source_root/jzonepagecheckout.php"
if [[ ! -f "$source_module" ]]; then
  echo "Source module file is missing." >&2
  exit 2
fi

closed='private const INTEGRATION_SHELL_READY = false;'
opened='private const INTEGRATION_SHELL_READY = true;'
if [[ "$(grep -Fxc "    $closed" "$source_module")" -ne 1 ]]; then
  echo "Source readiness gate is not the expected single closed production constant." >&2
  exit 2
fi
if grep -Fq "$opened" "$source_module"; then
  echo "Source repository unexpectedly contains an open readiness gate." >&2
  exit 2
fi

rm -rf "$target_root"
mkdir -p "$target_root"
tar -C "$source_root" --exclude='.git' -cf - . | tar -C "$target_root" -xf -

target_module="$target_root/jzonepagecheckout.php"
php -r '
$path = $argv[1];
$closed = "private const INTEGRATION_SHELL_READY = false;";
$opened = "private const INTEGRATION_SHELL_READY = true;";
$source = file_get_contents($path);
if (!is_string($source) || substr_count($source, $closed) !== 1 || str_contains($source, $opened)) {
    fwrite(STDERR, "Temporary module readiness source is unexpected.\n");
    exit(3);
}
$updated = str_replace($closed, $opened, $source, $count);
if ($count !== 1 || file_put_contents($path, $updated) === false) {
    fwrite(STDERR, "Unable to create temporary active readiness fixture.\n");
    exit(3);
}
' "$target_module"

if ! grep -Fq "$opened" "$target_module"; then
  echo "Temporary fixture readiness gate was not opened." >&2
  exit 3
fi
if ! grep -Fq "$closed" "$source_module" || grep -Fq "$opened" "$source_module"; then
  echo "Source readiness gate changed while creating temporary fixture." >&2
  exit 3
fi

printf 'Active checkout runtime fixture created at %s; source readiness remains closed.\n' "$target_root"
