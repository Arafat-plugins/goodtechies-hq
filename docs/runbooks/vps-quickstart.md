# Runbook: first install on the VPS, and deploying on every push

For the VPSDime server: Ubuntu 24.04, 1 vCPU, 1 GB RAM, 10 GB disk, IP `63.142.251.206`.
There is no domain yet, so the app runs on **plain http at the IP**. A domain with https is
one command later (the last section). Every command below is copy-paste; run them in order.

What never goes to the server: your local `.env`, `vendor/`, `node_modules/`, `public/build/`
and your local database. All are git-ignored, so they are never pushed to GitHub, and the server
only ever gets code from GitHub. It builds its own `vendor/` and assets, keeps its own `.env`,
and gets its own database, which changes only through migrations.

## 1. Let the server read the private repository (once)

On your Windows machine (PowerShell or cmd), copy the key script to the server:

```bat
scp D:\goodtechies-hq\deploy\setup-deploy-key.sh root@63.142.251.206:/root/
```

Then log in to the server and run it:

```bash
ssh root@63.142.251.206
apt-get update && apt-get install -y git
bash /root/setup-deploy-key.sh
```

It prints a public key. In GitHub open **Arafat-plugins/goodtechies-hq → Settings → Deploy keys
→ Add deploy key**, paste it, and leave **Allow write access off**. Then check:

```bash
git ls-remote git@github.com:Arafat-plugins/goodtechies-hq.git main   # prints one line
```

If the repository is public you can skip this section and clone over https instead:
`https://github.com/Arafat-plugins/goodtechies-hq.git`.

## 2. Install (once, about 15 minutes)

```bash
git clone --branch main git@github.com:Arafat-plugins/goodtechies-hq.git /var/www/goodtechies-hq
cd /var/www/goodtechies-hq
DOMAIN=63.142.251.206 bash deploy/install.sh 2>&1 | tee /root/hq-install.log
```

To seed the team with real addresses, put them in front of the same command, for example
`SEED_SHAHADAT_EMAIL=shahadat@goodtechies.com SEED_FARUK_EMAIL=… DOMAIN=63.142.251.206 bash deploy/install.sh`.

What it does that matters on this box:

- **Checks first, changes nothing until it is sure.** The `preflight` step lists other Nginx
  sites, anything else on ports 80/443 (Apache…), hosting panels (cPanel, Plesk, Hestia,
  aaPanel, CyberPanel, CloudPanel…), MySQL and PostgreSQL. It **stops** if a panel or a
  non-Nginx web server is there, or if another site already uses the name. Read the `STOP:`
  lines; `FORCE=1` overrides them once you are sure.
- **Only adds its own things:** the Nginx site `goodtechies-hq`, the PHP-FPM pool
  `goodtechies-hq`, the Supervisor programs `hq-queue` and `hq-reverb`, and the cron file
  `/etc/cron.d/goodtechies-hq` (the scheduler, every minute). It removes Nginx's
  `sites-enabled/default` only when it is the untouched stock file. Other sites keep working:
  our site answers `http://63.142.251.206` because Nginx matches an exact `server_name` before
  any other site's `default_server`.
- **Firewall:** opens ssh, 80 and 443. If ufw is off and other services listen publicly, it
  adds the rules but does **not** switch ufw on, and tells you why.
- **1 GB RAM:** creates a 2 GB `/swapfile` (with `vm.swappiness=10`), runs PHP on demand
  (at most 5 workers), gives PostgreSQL 128 MB of buffers and Redis a 96 MB ceiling, and caps
  Node's heap during the asset build.
- **Seeds once:** roles, permissions, settings, the five team members, the six leave types
  with their opening balances, the Bangladesh holidays and the finance category lists.
  **No demo** clients, projects, tasks, meetings, finance rows, payroll or attendance
  (`SEED_DEMO=0`).

At the end it prints the **seed password once**. Write it down.

## 3. First sign-in

Open **http://63.142.251.206**. Each Admin signs in with their email and the seed password,
**sets up 2FA** (scan the QR code with an authenticator app, or type the secret shown under
it), saves the recovery codes (use **Download**), and changes the password in Profile. When
everyone has, delete the `SEED_PASSWORD=` line from `/var/www/goodtechies-hq/.env`.

Over plain http the browser disables a few things until there is a domain with https: the
**Copy** buttons (type or download instead), **voice notes**, and the encryption of the
one-time password page in the browser history. Everything else works.

## 4. Turn on automatic deploys (once)

On the server:

```bash
bash /var/www/goodtechies-hq/deploy/setup-actions-key.sh
```

It prints three values. In GitHub open **Settings → Secrets and variables → Actions → New
repository secret** and add each one, copying the whole block:

| Secret | Value |
| --- | --- |
| `VPS_HOST` | `63.142.251.206` |
| `VPS_KNOWN_HOSTS` | the lines under `---- VPS_KNOWN_HOSTS ----` |
| `VPS_SSH_KEY` | everything from `-----BEGIN OPENSSH PRIVATE KEY-----` to `-----END …-----` |

(`VPS_PORT` too, only if it prints one.) The private key is shown once and not kept on the
server; if you lose it, run the script again and replace the secret. On the server this key
can do exactly one thing — run `deploy/deploy.sh` — and nothing else.

Test it: GitHub → **Actions → Deploy → Run workflow**. A green tick means it works.

## 5. Every day

1. Change code on Windows in `D:\goodtechies-hq`.
2. Run `push-to-github.bat`.
3. GitHub Actions runs **Deploy** by itself. In about 3–6 minutes the change is live. During
   the release the site shows a short "be right back" page.

**Watch a deploy:** GitHub → **Actions** → the newest **Deploy** run → **Release** step. Every
`==>` line is one step of `deploy/deploy.sh`. On the server: `tail -f
/var/www/goodtechies-hq/storage/logs/laravel-*.log` and `supervisorctl status`.

**A red deploy:** the site is brought back up and the run turns red. Open the **Release** step
and read the last `==>` and `ERROR:` lines. Two you may meet on purpose:

- *"the server checkout has local changes"* — somebody edited a file on the server. Releases
  ship only what is on GitHub: make the change on Windows and push it, then on the server
  `git -C /var/www/goodtechies-hq stash` (or `git checkout -- <file>`), and re-run the workflow.
- *"HEAD is detached … (a rollback?)"* — see the end of the next section.

Deploys never run two at a time, and `.env` and `storage/` (uploads, logs) are never touched by
a release.

## 6. Roll back

```bash
cd /var/www/goodtechies-hq
git log --oneline -5                 # pick the last good commit
git checkout <sha>
SKIP_PULL=1 bash deploy/deploy.sh
```

**Migrations only go forward.** If the bad release added a migration, rolling the code back does
not undo it; restore the database from the backup taken before the release instead
(`restore-from-backup.md`). Backups need the `BACKUP_*` keys in `.env`, which this install does
not have yet — set them up before you rely on this.

While the server sits on an old commit, automatic deploys stop with a red *"HEAD is detached"*
on purpose. Fix the problem on Windows, push, then resume:

```bash
cd /var/www/goodtechies-hq && git checkout main && bash deploy/deploy.sh
```

## 7. Later: switch to a domain with https

1. Point the domain's **A record** at `63.142.251.206` and wait until `ping hq.example.com`
   shows that IP.
2. On the server, one re-run with the domain:

   ```bash
   cd /var/www/goodtechies-hq
   DOMAIN=hq.example.com CERTBOT_EMAIL=you@example.com bash deploy/install.sh
   ```

   It changes `APP_URL` to `https://…`, turns the secure session cookie back on, points the
   realtime socket at `wss://` on 443, rebuilds the assets, gets the Let's Encrypt certificate
   and adds the http→https redirect and HSTS. Passwords, `APP_KEY`, the database and the team
   are kept. Everyone signs in again once (the cookie changed).

3. Nothing changes in GitHub: `VPS_HOST` stays the IP, because that is where ssh goes.

After the switch, `http://63.142.251.206` no longer serves the app. Certbot renews the
certificate by itself.
