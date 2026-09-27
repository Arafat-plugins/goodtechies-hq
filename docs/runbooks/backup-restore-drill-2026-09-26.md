# Backup restore drill — 26 September 2026

Part E, Phase 12: *"backup restore drill executed and recorded"*. This is the record.

## What was actually run

Not a description of the procedure — the procedure, executed, against a real database:

```
backup:run --only-db
  Dumping database goodtechies_hq..
  Created zip containing 1 files and directories. Size is 34.82 KB
  Successfully copied zip to disk named backups.
  exit=0

hq:verify-backup
  Backup verified: backups:gooderp/2026-09-26-15-00-43.zip (34.8 KB)
  restored into goodtechies_verify,
  roles=5 permissions=25 settings=11 users=5 audit_logs=present
  exit=0
```

Afterwards, checked directly in PostgreSQL:

- `settings.backup_last_verified_at` = `2026-09-26T15:00:55+06:00` — so the Settings screen's
  Backup health card now reads a real verification rather than *"Not verified yet"*.
- `goodtechies_verify` **no longer exists** — the scratch database the restore ran into was
  dropped, which is the part of `hq:verify-backup` that is easy to get wrong and leaves a copy
  of everybody's data lying around if it is.

Every step in between ran for real: `pg_dump` as `hq_migrator`, an AES-256 zip, the download, the
7-Zip decrypt, `psql` restoring into the scratch database, the row counts, the drop.

## What this drill does NOT prove, and it matters

**The backup disk was pointed at a local directory, not at the S3 bucket.** This container has no
`BACKUP_S3_*` credentials and no `BACKUP_ARCHIVE_PASSWORD`, so the drill supplied a throwaway
password and a local path.

So what is proven is **the mechanism**: dump, encrypt, store, list, download, decrypt, restore,
check, drop, record. What is **not** proven is the half that depends on the client's
infrastructure:

| Not yet exercised | Why it matters | Needs |
| --- | --- | --- |
| The real S3 bucket | Credentials, endpoint, bucket policy and network egress from the VPS are all places this can fail, and none of them fail locally | VPS + `BACKUP_S3_*` |
| The real `BACKUP_ARCHIVE_PASSWORD` | A backup nobody can open is not a backup. The copy kept outside the server is the thing being tested here, not the app | The password, from the password manager |
| Cross-provider or cross-region placement | Part B §4 requires the bucket to be in a **different provider account or region**; a bucket in the same account dies with it | GATE A's backup-bucket answer |
| Bucket versioning and replication for uploaded files | Files are **not** in the zip — they live in the file bucket and are protected only by its versioning | GATE A |
| Restoring at production size | 34.8 KB restored instantly. Timing, disk headroom and the restore window are unknown at real volume | A year of real data |

## The drill to run on the VPS, once it exists

```bash
cd /var/www/goodtechies-hq
php artisan backup:list          # confirm the newest backup and which disk it is on
php artisan hq:verify-backup     # the same command, against the real bucket
```

Then confirm on the box: `backup_last_verified_at` moved, and `goodtechies_verify` is gone.
Record the output here, dated, the way this file does. `docs/runbooks/restore-from-backup.md` is
the manual procedure for an actual disaster — this command is the weekly proof that the
procedure would work, and it is scheduled for Sundays at 04:00.

## One thing worth saying plainly

Until the VPS drill is run, the honest statement about this project's backups is: **the code that
makes and verifies them is tested and works; whether the client's bucket is reachable, correctly
placed and openable has never been tested, because the bucket does not exist yet.** That is a
GATE A item, not an engineering gap, and it should not be described as "backups are verified".
