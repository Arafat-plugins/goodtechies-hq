#!/usr/bin/env bash
# GoodTechies HQ: the SSH key GitHub Actions deploys with. Run once, as root, after install.sh:
#
#   bash /var/www/goodtechies-hq/deploy/setup-actions-key.sh
#
# It generates a new ed25519 key pair, installs the PUBLIC half in /root/.ssh/authorized_keys
# restricted to running deploy/deploy.sh and nothing else (no shell, no forwarding, no pty),
# prints the PRIVATE half once for the VPS_SSH_KEY secret and deletes it from this server, and
# prints the VPS_HOST and VPS_KNOWN_HOSTS values. Re-running it rotates the key: the old line
# is replaced, so the old private key stops working and the secret must be pasted again.
#
# Environment:
#   APP_DIR   application directory (default: /var/www/goodtechies-hq)
#   VPS_HOST  the address GitHub connects to (default: this server's public IPv4)
#   SSH_PORT  the port GitHub connects to (default: the port sshd listens on, else 22)
#   REPO      owner/name on GitHub, for the printed link (default: Arafat-plugins/goodtechies-hq)
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/goodtechies-hq}"
REPO="${REPO:-Arafat-plugins/goodtechies-hq}"
AUTHORIZED_KEYS="/root/.ssh/authorized_keys"
KEY_TAG="goodtechies-hq-actions-deploy"

die() {
    echo "ERROR: $*" >&2
    exit 1
}

[ "$(id -u)" -eq 0 ] || die "run setup-actions-key.sh as root"
[ -f "$APP_DIR/deploy/deploy.sh" ] || die "no $APP_DIR/deploy/deploy.sh (run install.sh first)"
command -v ssh-keygen > /dev/null 2>&1 || die "ssh-keygen is missing"

VPS_HOST="${VPS_HOST:-$(ip -4 route get 1.1.1.1 2> /dev/null | awk '{ for (i = 1; i < NF; i++) if ($i == "src") { print $(i + 1); exit } }')}"
[ -n "$VPS_HOST" ] || die "could not work out this server's public IPv4; pass VPS_HOST=<ip>"
SSH_PORT="${SSH_PORT:-$(sshd -T 2> /dev/null | awk '$1 == "port" { print $2; exit }')}"
SSH_PORT="${SSH_PORT:-22}"

# Root logins with a key must be allowed, or GitHub can never connect.
root_login="$(sshd -T 2> /dev/null | awk '$1 == "permitrootlogin" { print $2 }')"
case "$root_login" in
    no) die "sshd has PermitRootLogin no; set it to prohibit-password (key only) in /etc/ssh/sshd_config and restart ssh" ;;
    "") echo "WARNING: could not read sshd's PermitRootLogin; it must not be 'no'" >&2 ;;
esac

work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT
ssh-keygen -t ed25519 -N "" -C "$KEY_TAG" -f "$work_dir/key" -q

# The restriction. `restrict` turns off port, agent and X11 forwarding and the pty; `command=`
# runs deploy.sh whatever the client asked for. What it asked for arrives as
# SSH_ORIGINAL_COMMAND, and deploy.sh accepts only `deploy <commit id>` from it. Through
# /bin/bash because the repository is committed from Windows, so deploy.sh has no execute bit
# (and chmod-ing it here would make the checkout "dirty" and stop every release).
install -d -m 700 /root/.ssh
touch "$AUTHORIZED_KEYS"
chmod 600 "$AUTHORIZED_KEYS"
grep -v " $KEY_TAG\$" "$AUTHORIZED_KEYS" > "$AUTHORIZED_KEYS.tmp" || true
echo "restrict,command=\"/bin/bash $APP_DIR/deploy/deploy.sh\" $(cat "$work_dir/key.pub")" >> "$AUTHORIZED_KEYS.tmp"
cat "$AUTHORIZED_KEYS.tmp" > "$AUTHORIZED_KEYS"
rm -f "$AUTHORIZED_KEYS.tmp"
echo "installed the restricted deploy key in $AUTHORIZED_KEYS (any previous one replaced)"

# known_hosts, from this server's own host keys rather than a network scan.
if [ "$SSH_PORT" = "22" ]; then host_spec="$VPS_HOST"; else host_spec="[$VPS_HOST]:$SSH_PORT"; fi
known_hosts=""
for pub in /etc/ssh/ssh_host_ed25519_key.pub /etc/ssh/ssh_host_ecdsa_key.pub /etc/ssh/ssh_host_rsa_key.pub; do
    [ -f "$pub" ] || continue
    known_hosts+="$host_spec $(awk '{ print $1, $2 }' "$pub")"$'\n'
done
[ -n "$known_hosts" ] || die "no host keys found in /etc/ssh"

cat <<SECRETS

Add these in GitHub: https://github.com/$REPO/settings/secrets/actions
(repository -> Settings -> Secrets and variables -> Actions -> New repository secret)

---- VPS_HOST ----
$VPS_HOST
SECRETS
if [ "$SSH_PORT" != "22" ]; then
    printf '\n---- VPS_PORT ----\n%s\n' "$SSH_PORT"
fi
cat <<SECRETS

---- VPS_KNOWN_HOSTS ----
${known_hosts%$'\n'}

---- VPS_SSH_KEY (shown once; copy every line, BEGIN and END included) ----
$(cat "$work_dir/key")
----

The private key is NOT kept on this server. Lost it? Run this script again and paste the new one.
Test it from GitHub: Actions -> Deploy -> Run workflow.
SECRETS
