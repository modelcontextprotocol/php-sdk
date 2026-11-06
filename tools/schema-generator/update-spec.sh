#!/usr/bin/env bash
# Refreshes the vendored MCP specification schemas from modelcontextprotocol/modelcontextprotocol.
#
# Usage: tools/schema-generator/update-spec.sh <commit-or-tag>
set -euo pipefail

REF="${1:?usage: $0 <commit-or-tag>}"
REVISIONS=(2024-11-05 2025-03-26 2025-06-18 2025-11-25 2026-07-28)
LATEST="${REVISIONS[${#REVISIONS[@]}-1]}"

SPEC_DIR="$(cd "$(dirname "$0")" && pwd)/spec"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

git -C "$WORK_DIR" init -q
git -C "$WORK_DIR" fetch -q --depth 1 https://github.com/modelcontextprotocol/modelcontextprotocol.git "$REF"
git -C "$WORK_DIR" checkout -q FETCH_HEAD
COMMIT="$(git -C "$WORK_DIR" rev-parse HEAD)"

rm -rf "$SPEC_DIR"
for revision in "${REVISIONS[@]}"; do
    mkdir -p "$SPEC_DIR/$revision"
    cp "$WORK_DIR/schema/$revision/schema.json" "$SPEC_DIR/$revision/"
done
cp "$WORK_DIR/schema/$LATEST/schema.ts" "$SPEC_DIR/$LATEST/"
cp -r "$WORK_DIR/schema/$LATEST/examples" "$SPEC_DIR/$LATEST/"

cat > "$SPEC_DIR/README.md" <<EOF
# Vendored MCP specification schemas

Copied from https://github.com/modelcontextprotocol/modelcontextprotocol at commit \`$COMMIT\`
by \`tools/schema-generator/update-spec.sh\`. Do not edit by hand.

- \`<revision>/schema.json\` for every protocol revision the SDK supports
- \`$LATEST/schema.ts\` for the deprecation notices that \`schema.json\` does not carry
- \`$LATEST/examples/\` for the round-trip tests in \`tests/Unit/Schema/Generated/\`

The specification is licensed under Apache-2.0 (older contributions under MIT), see
https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/LICENSE.
EOF

echo "Vendored $COMMIT into $SPEC_DIR"
