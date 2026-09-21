#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# device-sync — stage everything the user's machine is missing, in one step.
#
# The user tests in a browser on their own machine, so work that is committed
# here but not copied there does not exist as far as they are concerned. This
# has already gone wrong once: a whole phase was reported as done while their
# copy sat a phase behind, because the sync depended on someone remembering.
# It no longer does.
#
#   tools/device-sync.sh            list what is out of date, stage it, print
#                                   the device_commit_files batches
#   tools/device-sync.sh --mark     record HEAD as synced (run AFTER every
#                                   batch has been written and verified)
#
# The marker is tracked in git, so a session that loses its context can still
# work out what the machine is missing.
# ---------------------------------------------------------------------------
set -euo pipefail

cd "$(dirname "$0")/.."

MARKER=".device-synced"
STAGE="/mnt/user-data/outputs/devicesync"
DEVICE_ROOT='D:\goodtechies-hq'
BATCH=45   # device_commit_files takes 50; leave headroom

if [[ "${1:-}" == "--mark" ]]; then
    git rev-parse HEAD > "$MARKER"
    echo "marked $(cat "$MARKER") as synced to the device"
    echo "commit $MARKER so the next session knows."
    exit 0
fi

if [[ ! -f "$MARKER" ]]; then
    echo "No $MARKER yet. Either this is the first sync (copy everything"
    echo "tracked), or the marker was lost. Write a SHA into it by hand, or"
    echo "run with --mark after a full copy."
    exit 1
fi

LAST="$(cat "$MARKER")"
HEAD_SHA="$(git rev-parse HEAD)"

if [[ "$LAST" == "$HEAD_SHA" ]]; then
    echo "up to date — the device has $HEAD_SHA"
    exit 0
fi

# Added, copied, modified, renamed. Deletions are listed separately because
# device_commit_files cannot remove a file; those need telling the user.
mapfile -t CHANGED < <(git diff --name-only --diff-filter=ACMR "$LAST..HEAD" | sort -u)
mapfile -t DELETED < <(git diff --name-only --diff-filter=D "$LAST..HEAD" | sort -u)

echo "device is at : $LAST"
echo "repo is at   : $HEAD_SHA"
echo "to copy      : ${#CHANGED[@]} file(s)"
echo "to delete    : ${#DELETED[@]} file(s)  (the bridge cannot delete — tell the user)"
echo

if ((${#DELETED[@]})); then
    printf 'DELETE BY HAND: %s\n' "${DELETED[@]}"
    echo
fi

((${#CHANGED[@]})) || exit 0

rm -rf "$STAGE"; mkdir -p "$STAGE"
for f in "${CHANGED[@]}"; do
    [[ -f "$f" ]] || continue
    mkdir -p "$STAGE/$(dirname "$f")"
    cp "$f" "$STAGE/$f"
done

# Emit ready-to-use device_commit_files payloads, batched.
python3 - "$STAGE" "$DEVICE_ROOT" "$BATCH" <<'PY'
import json, os, sys
stage, root, batch = sys.argv[1], sys.argv[2], int(sys.argv[3])
files = sorted(
    os.path.relpath(os.path.join(dp, fn), stage)
    for dp, _, fns in os.walk(stage) for fn in fns
)
for i in range(0, len(files), batch):
    chunk = files[i:i + batch]
    payload = [
        {"stagedPath": f"{stage}/{f}",
         "devicePath": root + "\\" + f.replace("/", "\\")}
        for f in chunk
    ]
    out = f"/tmp/devicesync-batch{i // batch}.json"
    with open(out, "w") as fh:
        json.dump(payload, fh)
    print(f"batch {i // batch}: {len(chunk)} file(s) -> {out}")
PY

echo
echo "Now call device_commit_files once per batch, then:"
echo "  - if the phase added migrations, the user must restart start-hq.bat"
echo "    (it runs them), or the new screen 500s on a missing table"
echo "  - verify with device_list_dir before believing 'written'"
echo "  - tools/device-sync.sh --mark  and commit $MARKER"
