#!/usr/bin/env bash
# Build a WP Engine–ready plugin zip: css-timeclock-addon/css-timeclock-addon.php
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PARENT="$(cd "${ROOT}/.." && pwd)"
# This monorepo commits the upload zip at the repository root. A standalone
# plugin checkout (no parent archive) still writes css-timeclock-addon/dist.
# Monorepo keeps the upload zip next to docs/. A standalone plugin
# checkout (no parent whitepaper) still writes css-timeclock-addon/dist.
if [ -f "${PARENT}/docs/CSS-Timeclock-Whitepaper.md" ] || [ -f "${PARENT}/dist/css-timeclock-addon.zip" ]; then
  DIST="${PARENT}/dist"
else
  DIST="${ROOT}/dist"
fi
STAGE="$(mktemp -d)"
NAME="css-timeclock-addon"

mkdir -p "${DIST}"
mkdir -p "${STAGE}/${NAME}"

# Copy plugin files without rsync (not always available on hosts / CI).
(
  cd "${ROOT}"
  find . \
    -mindepth 1 \
    \( \
      -name '.git' -o \
      -name '.gitignore' -o \
      -name 'dist' -o \
      -name 'preview' -o \
      -name 'tmp-wp' -o \
      -name 'agent-tools' -o \
      -name 'node_modules' -o \
      -name 'bin' -o \
      -name '*.zip' -o \
      -name '.DS_Store' \
    \) -prune -o \
    -print
) | while IFS= read -r rel; do
  rel="${rel#./}"
  [ -n "${rel}" ] || continue
  src="${ROOT}/${rel}"
  dest="${STAGE}/${NAME}/${rel}"
  if [ -d "${src}" ]; then
    mkdir -p "${dest}"
  else
    mkdir -p "$(dirname "${dest}")"
    cp "${src}" "${dest}"
  fi
done

rm -f "${DIST}/${NAME}.zip"
(
  cd "${STAGE}"
  zip -qr "${DIST}/${NAME}.zip" "${NAME}"
)

rm -rf "${STAGE}"
echo "Wrote ${DIST}/${NAME}.zip"
