#!/usr/bin/env bash
# GoodTechies HQ: let this server pull a PRIVATE GitHub repository. Run once, as root,
# before install.sh:
#
#   bash setup-deploy-key.sh
#
# It creates /root/.ssh/hq_github (ed25519, no passphrase) if it is missing, points
# `git@github.com` at that key in /root/.ssh/config, pins GitHub's published host keys in
# /root/.ssh/known_hosts, and prints the public key with where to paste it. Re-running it is
# safe and prints the same key again.
#
# Then clone with:  REPO_URL=git@github.com:Arafat-plugins/goodtechies-hq.git
# A PUBLIC repository needs none of this: REPO_URL=https://github.com/Arafat-plugins/goodtechies-hq.git
set -euo pipefail

REPO="${REPO:-Arafat-plugins/goodtechies-hq}"
KEY_FILE="/root/.ssh/hq_github"
SSH_CONFIG="/root/.ssh/config"
KNOWN_HOSTS="/root/.ssh/known_hosts"

die() {
    echo "ERROR: $*" >&2
    exit 1
}

[ "$(id -u)" -eq 0 ] || die "run setup-deploy-key.sh as root"
command -v ssh-keygen > /dev/null 2>&1 || die "ssh-keygen is missing (apt-get install -y openssh-client)"

install -d -m 700 /root/.ssh

if [ -f "$KEY_FILE" ]; then
    echo "key exists: $KEY_FILE (kept)"
else
    ssh-keygen -t ed25519 -N "" -C "goodtechies-hq@$(hostname -f 2> /dev/null || hostname)" -f "$KEY_FILE" -q
    echo "created $KEY_FILE"
fi
chmod 600 "$KEY_FILE"

# A managed block, replaced as a whole on every run so the file never collects duplicates.
touch "$SSH_CONFIG"
chmod 600 "$SSH_CONFIG"
awk '
    /^# >>> goodtechies-hq github >>>$/ { skip = 1; next }
    /^# <<< goodtechies-hq github <<<$/ { skip = 0; next }
    !skip { print }
' "$SSH_CONFIG" > "$SSH_CONFIG.tmp"
cat >> "$SSH_CONFIG.tmp" <<CONFIG
# >>> goodtechies-hq github >>>
Host github.com
    HostName github.com
    User git
    IdentityFile $KEY_FILE
    IdentitiesOnly yes
# <<< goodtechies-hq github <<<
CONFIG
cat "$SSH_CONFIG.tmp" > "$SSH_CONFIG"
rm -f "$SSH_CONFIG.tmp"
echo "wrote the github.com entry in $SSH_CONFIG"

# GitHub's published SSH host keys (docs.github.com, "GitHub's SSH key fingerprints"):
#   ED25519  SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU
#   ECDSA    SHA256:p2QAMXNIC1TJYWeIOttrVc98/R1BUFWu3/LiyKgUfQM
# Pinned here rather than taken from ssh-keyscan, which would trust whatever answered.
touch "$KNOWN_HOSTS"
chmod 644 "$KNOWN_HOSTS"
while read -r line; do
    grep -qxF "$line" "$KNOWN_HOSTS" || echo "$line" >> "$KNOWN_HOSTS"
done <<'KEYS'
github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl
github.com ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBEmKSENjQEezOmxkZMy7opKgwFB9nkt5YRrYMjNuG5N87uRgg6CLrbo5wAdT/y6v0mKV0U2w0WZ2YB/++Tpockg=
KEYS
echo "pinned github.com's host keys in $KNOWN_HOSTS"

cat <<NEXT

Paste this PUBLIC key into GitHub:
  https://github.com/$REPO/settings/keys/new
  (repository -> Settings -> Deploy keys -> Add deploy key)
  Title: VPS $(hostname)    "Allow write access": leave it OFF (read-only)

$(cat "$KEY_FILE.pub")

Then check that the server can read the repository:
  ssh -T git@github.com          # "Hi $REPO! You've successfully authenticated..."
  git ls-remote git@github.com:$REPO.git main

and install with:
  REPO_URL=git@github.com:$REPO.git
NEXT
