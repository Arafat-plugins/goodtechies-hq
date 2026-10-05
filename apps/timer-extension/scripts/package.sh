#!/usr/bin/env bash
# package.sh — build the unpacked folder and the zip in dist/.
# Run from apps/timer-extension/ (npm run package does that).
set -euo pipefail

cd "$(dirname "$0")/.."

rm -rf dist && mkdir -p dist
cp -r src dist/goodtechies-timer-0.1.0
(cd dist && zip -qr goodtechies-timer-0.1.0.zip goodtechies-timer-0.1.0)
unzip -l dist/goodtechies-timer-0.1.0.zip | tail -1
