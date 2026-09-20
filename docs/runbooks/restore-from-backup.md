# Runbook: restore from backup

## Where backups live

- **Database:** `backup:run --only-db` runs every day at 02:00 (app timezone) and writes an AES-256 encrypted zip to the `backups` disk. That disk is an S3 bucket in a **different provider account or region** (`BACKUP_S3_*` in `.env`).
  - Each zip is stored as `goodtechies-hq/<YYYY-MM-DD-HH-mm-ss>.zip` and holds `db-dumps/postgresql-<db>.sql.gz`, dumped as `hq_migrator`.
- **Retention:** `backup:clean` runs at 01:30. It keeps everything for 7 days, then 14 daily, 8 weekly, 12 monthly and 2 yearly backups, with a 5000 MB cap.
- **Monitoring:** `backup:monitor` runs at 03:00 and flags a newest backup older than 1 day.
- **Weekly restore test:** `hq:verify-backup` runs Sundays at 04:00.
  - It restores the newest zip into the scratch database `goodtechies_verify`, checks it, then drops it.
  - Admin → Settings shows the result as `backup_last_verified_at`.
- **Uploaded files** are not in the zip. They live in the S3 file bucket, which has versioning and replication.
- **`BACKUP_ARCHIVE_PASSWORD`** is needed to open any backup. A copy is kept outside the server (password manager). If the server is lost, take it from there.

## 1. Pick and download a backup

```bash
cd /var/www/goodtechies-hq
php artisan backup:list                  # newest backup, count and used storage per disk
```

Download the zip from the bucket console or with any S3 client using the `BACKUP_S3_*` keys, e.g. `aws s3 cp s3://<bucket>/goodtechies-hq/<file>.zip . --endpoint-url <endpoint>`.

The dump holds personal data, so work in a root-only directory: `install -d -m 700 /root/restore && cd /root/restore`.

## 2. Decrypt and unpack

The zip uses AES-256. Info-ZIP `unzip -P` only reads legacy ZipCrypto and cannot open it, so use 7-Zip (`apt install 7zip`):

```bash
7z x -p"$BACKUP_ARCHIVE_PASSWORD" <file>.zip        # → db-dumps/postgresql-goodtechies_hq.sql.gz
gunzip db-dumps/postgresql-goodtechies_hq.sql.gz    # → db-dumps/postgresql-goodtechies_hq.sql
```

## 3. Restore into a fresh database (never over the live one)

1. Create the new database:
   ```bash
   sudo -u postgres createdb -T template0 goodtechies_hq_restored
   ```
2. Run `deploy/sql/roles.sql` against it. The script makes `hq_migrator` the owner and creates any missing roles (on a new server, all of them). Pass the passwords from `.env`, because every run sets them:
   ```bash
   sudo -u postgres psql -X -q -d postgres -v db=goodtechies_hq_restored \
     -v migrator_password='…' -v app_password='…' -v ro_password='…' -f deploy/sql/roles.sql
   ```
3. Restore as `hq_migrator`. The restore runs in one transaction and stops at the first error:
   ```bash
   PGPASSWORD='<DB_MIGRATOR_PASSWORD>' psql -X -h 127.0.0.1 -U hq_migrator -d goodtechies_hq_restored \
     --single-transaction -v ON_ERROR_STOP=1 -f db-dumps/postgresql-goodtechies_hq.sql
   ```
4. Run `roles.sql` again with the same arguments. It is idempotent, and this run re-applies the grants to the restored tables, including the append-only revoke on `audit_logs`.

## 4. Switch the application to the restored database

```bash
php artisan down
supervisorctl stop all                   # queue worker (and Reverb from Phase 6)
```

- **Option A: point `.env` at the restored database.**
  - Set `DB_DATABASE=goodtechies_hq_restored`.
  - Release with `SKIP_PULL=1 deploy/deploy.sh` (the config is cached, so a release is required).
- **Option B: swap the database names.** `.env` stays unchanged, but nothing may be connected to either database:
  ```bash
  systemctl stop php8.3-fpm
  sudo -u postgres psql -X -c 'ALTER DATABASE goodtechies_hq RENAME TO goodtechies_hq_broken_<date>'
  sudo -u postgres psql -X -c 'ALTER DATABASE goodtechies_hq_restored RENAME TO goodtechies_hq'
  systemctl start php8.3-fpm
  ```

Apply any migrations newer than the backup (migrations are forward-only), then bring the app back:

```bash
php artisan migrate --database=pgsql_migrator --force
supervisorctl start all
php artisan up
```

## 5. Verify

1. Log in as an admin. Check that recent clients, projects and payroll runs are there, and that the audit log continues from the backup time.
2. Run `php artisan hq:verify-backup`. It must print `Backup verified: …` and exit 0. Admin → Settings then shows the new `backup_last_verified_at`.
3. Clean up:
   - Run `rm -rf /root/restore`.
   - Keep `goodtechies_hq_broken_<date>` until the restore is accepted, then drop it.

## File storage recovery

Uploaded files are recovered from the file bucket, not from the zip.

- **Deleted or overwritten objects:** bucket versioning keeps the old versions.
  - In the console: show versions, then restore the previous version or delete the delete marker.
  - With the CLI: run `aws s3api list-object-versions --bucket <bucket> --prefix <path>`, then copy the wanted `VersionId` back over the key.
- **Bucket or region lost:** the replica bucket holds a full copy.
  - Either point `AWS_BUCKET` (plus `AWS_ENDPOINT` / `AWS_DEFAULT_REGION`) at the replica, or copy it back with `aws s3 sync s3://<replica> s3://<new-primary>`.
  - Then release with `SKIP_PULL=1 deploy/deploy.sh`.

## Afterwards

Record the restore or drill in `PROGRESS.md` → **Deployment log**: date, backup file used, time taken, result and follow-ups.
