# ---------------------------------------------------------------------------
# Consultora DH - backup/restore sidecar
#
# The PHP image has pdo_pgsql but no PostgreSQL client executables. This image
# is the same major version as the server (18), so pg_dump/pg_restore can talk
# to it without the "server version newer than pg_dump" refusal.
#
# It owns the schedule (`backup-run`), the backup scripts and the restore
# proof. Laravel never receives these credentials.
# ---------------------------------------------------------------------------
FROM postgres:18.6

RUN apt-get update \
    && apt-get install -y --no-install-recommends gnupg ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY docker/backup/run.sh /usr/local/bin/backup-run
COPY docker/backup/healthcheck.sh /usr/local/bin/backup-healthcheck

RUN chmod 0755 /usr/local/bin/backup-run /usr/local/bin/backup-healthcheck

# The default process is the daily loop. `docker compose run backup
# /scripts/backup.sh` overrides CMD and runs a one-off command instead.
CMD ["/usr/local/bin/backup-run"]
