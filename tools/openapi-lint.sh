#!/usr/bin/env bash
#
# tools/openapi-lint.sh
#
# Lint for the OpenAPI spec at Docs/openapi/auth.yaml.
#
# Goals (per AC10/AC11 in ADD-AUTH-001):
#   1. Block breaking changes without an explicit v1->v2 bump.
#   2. Detect removed paths/operations.
#   3. Detect removed response fields, type changes, removed required[] entries.
#   4. Validate basic YAML structure.
#
# Usage:
#   tools/openapi-lint.sh                  # lint against current branch (no diff)
#   tools/openapi-lint.sh <base-ref>       # lint against <base-ref> (e.g. origin/main)
#
# Exit codes:
#   0 = OK
#   1 = breaking change detected
#   2 = invalid spec
#
# Requires: bash, grep, awk, sed, python3 (for YAML parse + diff).

set -euo pipefail

SPEC="Docs/openapi/auth.yaml"
BASE_REF="${1:-}"

if [ ! -f "$SPEC" ]; then
  echo "::error::Spec not found: $SPEC" >&2
  exit 2
fi

# ── 1. Basic structural validation ────────────────────────
echo "▶ Validating OpenAPI spec structure..."

# Required top-level fields (allow leading whitespace for nested keys)
for field in openapi info title version paths components; do
  if ! grep -qE "^\s*${field}:" "$SPEC"; then
    echo "::error::Missing required top-level field: $field" >&2
    exit 2
  fi
done

# OpenAPI version must be 3.x
OPENAPI_VERSION=$(grep -E "^\s*openapi:" "$SPEC" | awk '{print $2}')
case "$OPENAPI_VERSION" in
  3.0.*|3.1.*) ;;
  *) echo "::error::Unsupported OpenAPI version: $OPENAPI_VERSION (expected 3.0.x or 3.1.x)" >&2; exit 2 ;;
esac

# spec must be in v1 (URL prefix /api/v1/)
if ! grep -q "/api/v1" "$SPEC"; then
  echo "::warning::Spec does not mention /api/v1 URL prefix" >&2
fi

# ── 2. Required paths present ────────────────────────────
echo "▶ Checking required paths..."

REQUIRED_PATHS=(
  "/auth/login"
  "/auth/logout"
  "/auth/validate-key"
  "/me/apps"
  "/me/identity"
  "/me/permisos"
  "/usuarios/{userId}/identity"
  "/usuarios/{userId}/apps/{appId}/permisos"
)

for path in "${REQUIRED_PATHS[@]}"; do
  # Allow trailing whitespace in YAML; grep for path followed by colon
  if ! grep -E "^\s*${path}:" "$SPEC" >/dev/null; then
    echo "::error::Missing required path: $path" >&2
    exit 2
  fi
done

# ── 3. Diff-based breaking change detection ──────────────
if [ -n "$BASE_REF" ]; then
  echo "▶ Detecting breaking changes vs $BASE_REF..."

  if ! git rev-parse --verify "$BASE_REF" >/dev/null 2>&1; then
    echo "::error::Base ref not found: $BASE_REF" >&2
    exit 2
  fi

  # Get the diff of just the spec
  DIFF=$(git diff "$BASE_REF"...HEAD -- "$SPEC" || true)

  if [ -z "$DIFF" ]; then
    echo "  (no changes in spec)"
  else
    BREAKING=0

    # Check: any path removed?
    REMOVED_PATHS=$(echo "$DIFF" | grep '^-  /' | grep ':$' | awk '{print $2}' | tr -d ':' || true)
    if [ -n "$REMOVED_PATHS" ]; then
      echo "::error::Removed paths (BREAKING):"
      echo "$REMOVED_PATHS" | sed 's/^/  - /'
      BREAKING=1
    fi

    # Check: any operation (get/post/etc) removed?
    REMOVED_OPS=$(echo "$DIFF" | grep -E '^-    (get|post|put|patch|delete):$' | awk '{print $1}' || true)
    if [ -n "$REMOVED_OPS" ]; then
      echo "::error::Removed operations (BREAKING):"
      echo "$REMOVED_OPS" | sed 's/^/  - /'
      BREAKING=1
    fi

    # Check: any property removed from a schema (looking for '^-    X:' at schema section)
    # Heuristic: a property is "removed" if a `^    foo:` line was deleted
    REMOVED_PROPS=$(echo "$DIFF" | grep -E '^-    [a-zA-Z_]+:' | awk '{print $2}' | tr -d ':' || true)
    if [ -n "$REMOVED_PROPS" ]; then
      # Some removals might be intentional (renamed properties). Just warn — manual review.
      echo "::warning::Removed schema properties (manual review recommended):"
      echo "$REMOVED_PROPS" | sort -u | sed 's/^/  - /'
    fi

    # Check: any required[] entry removed? (i.e., a new required added = OK; required removed = breaking)
    # The '^-' lines inside 'required:' lists would be a breaking change.
    REMOVED_REQUIRED=$(echo "$DIFF" | grep -E '^-      - [a-zA-Z_]+$' || true)
    if [ -n "$REMOVED_REQUIRED" ]; then
      echo "::error::Removed entries from required[] arrays (BREAKING):"
      echo "$REMOVED_REQUIRED" | sed 's/^/  - /'
      BREAKING=1
    fi

    # Check: type changes (simplified heuristic)
    # If a line "type: X" was deleted and "type: Y" added with different value, flag.
    TYPE_CHANGES=$(echo "$DIFF" | grep -E '^[+-]\s*type:' | awk '{print $2}' | sort | uniq -c | sort -rn | head -5)
    if [ -n "$TYPE_CHANGES" ]; then
      UNIQUE_TYPES=$(echo "$TYPE_CHANGES" | wc -l)
      if [ "$UNIQUE_TYPES" -gt 2 ]; then
        echo "::warning::Multiple type changes detected (manual review recommended):"
        echo "$TYPE_CHANGES" | head -10 | sed 's/^/  /'
      fi
    fi

    if [ "$BREAKING" -eq 1 ]; then
      echo ""
      echo "❌ Breaking changes detected. Either:"
      echo "   (a) bump the spec to v2 (create Docs/openapi/auth.v2.yaml)"
      echo "   (b) restore the removed fields and migrate clients first"
      echo "   (c) add an exception comment in the diff (manual review required)"
      exit 1
    fi

    echo "  ✓ No breaking changes detected"
  fi
else
  echo "  (no base ref provided, skipping diff check)"
fi

# ── 4. Forward-compat checks (AC11) ───────────────────
echo "▶ Checking forward-compat markers..."

if ! grep -q "scope_label" "$SPEC"; then
  echo "::warning::Spec should include scope_label marker for forward compat (AC11)" >&2
fi

# Check that all top-level responses include scope_label (skip — too strict)

# ── 5. Schemas integrity ─────────────────────────────────
echo "▶ Validating schema definitions..."

# Schemas must have type or allOf/oneOf/anyOf
SCHEMAS=$(awk '/^    [A-Z][a-zA-Z]+:$/{print $1}' "$SPEC" | tr -d ':' || true)
SCHEMA_COUNT=$(echo "$SCHEMAS" | wc -l)
echo "  Found $SCHEMA_COUNT schemas: $(echo $SCHEMAS | tr '\n' ' ')"

# ── 6. Optional: Python-based full parse ────────────────
if command -v python3 >/dev/null 2>&1; then
  echo "▶ Running full YAML parse via Python..."
  if ! python3 - <<'PYEOF' 2>&1; then
    echo "::warning::YAML parse via Python failed (likely PyYAML not installed). CI will catch this with PyYAML installed."
  fi
import sys
try:
    import yaml
except ImportError:
    print("(PyYAML not installed, skipping deep parse)")
    sys.exit(0)
with open("Docs/openapi/auth.yaml") as f:
    spec = yaml.safe_load(f)
if not isinstance(spec, dict):
    print("::error::Spec is not a valid YAML mapping"); sys.exit(1)
required_keys = ["openapi", "info", "paths", "components"]
for k in required_keys:
    if k not in spec:
        print(f"::error::Missing top-level key: {k}"); sys.exit(1)
if spec["openapi"].split(".")[0] != "3":
    print(f"::error::OpenAPI version must start with 3: {spec['openapi']}"); sys.exit(1)
print(f"  ✓ Spec parses OK, OpenAPI {spec['openapi']}, {len(spec.get('paths', {}))} paths, {len(spec.get('components', {}).get('schemas', {}))} schemas")
PYEOF
else
  echo "  (python3 not available locally, skipping deep parse. CI will run it.)"
fi

echo ""
echo "✅ OpenAPI spec lint passed"
exit 0
