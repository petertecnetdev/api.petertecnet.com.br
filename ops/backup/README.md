# Peter Tecnet database backups

Production database protection for the central Peter Tecnet API.

## Guarantees

- Daily encrypted backup from the VPS.
- Mandatory encrypted backup immediately before Laravel migrations.
- Restore validation into an isolated temporary database before a backup is accepted.
- Local retention: 14 daily, 14 days of pre-deploy snapshots, ~10 weeks of weekly snapshots and ~13 months of monthly snapshots.
- Off-site copy through GitHub Actions artifacts (encrypted, 90-day retention).
- Optional second off-site destination through `rclone` (S3, Backblaze B2, DigitalOcean Spaces, Google Drive, etc.).
- Dumps are never committed to the application repository.

## Encryption

Backups use `age`. The deploy workflow derives the public key from the existing `VPS_SSH_KEY` GitHub secret and installs only that public key on the VPS. The private SSH key is not written into the backup subsystem.

A dedicated age key is preferable long-term. If one is provisioned later, replace `/etc/petertecnet-backup/age-recipient.txt` with the dedicated public recipient and configure the matching identity in the off-site restore process.

## VPS locations

- Executable: `/usr/local/sbin/petertecnet-db-backup`
- Configuration: `/etc/petertecnet-backup/backup.env`
- Encryption recipient: `/etc/petertecnet-backup/age-recipient.txt`
- Backups: `/var/backups/petertecnet/database`
- Timer: `petertecnet-db-backup.timer`

## Operations

```bash
sudo systemctl status petertecnet-db-backup.timer
sudo systemctl list-timers petertecnet-db-backup.timer
sudo /usr/local/sbin/petertecnet-db-backup manual
sudo /usr/local/sbin/petertecnet-db-backup pre-deploy
sudo /usr/local/sbin/petertecnet-db-backup latest daily
sudo journalctl -u petertecnet-db-backup.service
```

## Optional second off-site provider

Configure an `rclone` remote on the VPS, then set the remote destination in the root-only config:

```bash
sudo rclone config
sudoedit /etc/petertecnet-backup/backup.env
```

Example:

```dotenv
OFFSITE_RCLONE_REMOTE="petertecnet-offsite:petertecnet/database"
```

Every accepted backup and its manifest will then also be copied to that provider.

## Restore validation

For local MySQL/MariaDB, the service first tries root socket authentication to create an isolated temporary database. If the database is remote or socket authentication is unavailable, configure dedicated restore-test admin credentials in `/etc/petertecnet-backup/backup.env`.

The validation must succeed before the encrypted backup is considered complete. It verifies that the dump restores, contains tables, and contains the critical `users` and `establishments` tables.
