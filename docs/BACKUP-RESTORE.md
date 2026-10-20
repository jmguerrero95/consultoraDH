# Backup & Restore — Consultora DH (A01/DR-01)

## Resumen

El backup corre en un **sidecar dedicado** (`docker/backup/Dockerfile`,
`postgres:18.6`) y no en la imagen PHP. La imagen de la aplicación tiene
`pdo_pgsql`, pero no `pg_dump`, `pg_restore`, `createdb`, `dropdb` ni `psql`; y
un cliente PostgreSQL 15 no puede volcar un servidor 18.

| Componente | Qué incluye | Cuándo |
| --- | --- | --- |
| PostgreSQL | Esquema + datos, formato custom comprimido | Diario, 02:00 UTC |
| Almacenamiento privado | `storage/app/private/` completo | Junto al dump |
| Conteos | `COUNTS.tsv` de tablas críticas en el instante del dump | Junto al dump |
| Manifest | SHA-256 de los bytes almacenados | Junto al dump |

El backup es **cifrado AES-256** por defecto (`BACKUP_REQUIRE_ENCRYPTION=true`).
Se guarda en `${BACKUP_HOST_DIR:-./backups}`, un bind persistente que en
producción debe apuntar a un disco externo. `BACKUP_OFFSITE_DIR` permite una
segunda copia en otro montaje.

---

## 1. Requisitos y credenciales

El rol de aplicación (`consultora_dh_app`) es **NOCREATEDB** y no se eleva.
El backup usa un rol separado:

| Rol | Atributos | Uso |
| --- | --- | --- |
| `POSTGRES_USER` | superusuario | Sólo bootstrap de PostgreSQL |
| `DB_USERNAME` | `NOSUPERUSER NOCREATEDB NOCREATEROLE` | Laravel |
| `BACKUP_DB_USER` | `LOGIN CREATEDB NOSUPERUSER`, miembro de `pg_read_all_data` | Backup y restore proof |

En un volumen nuevo, `docker/postgres/init/02-create-backup-role.sh` crea el rol
si `BACKUP_DB_PASSWORD` está definido. En un volumen existente, ejecute una vez:

```bash
set -a; . ./.env; set +a
docker compose exec -e BACKUP_DB_PASSWORD="$BACKUP_DB_PASSWORD" \
  postgres bash /docker-entrypoint-initdb.d/02-create-backup-role.sh
```

Variables mínimas en `.env`:

```env
BACKUP_DB_USER=consultora_dh_backup
BACKUP_DB_PASSWORD=<secreto>
BACKUP_REQUIRE_ENCRYPTION=true
BACKUP_ENCRYPTION_PASSPHRASE=<secreto distinto>
# o bien un fichero de clave montado en el contenedor:
# BACKUP_ENCRYPTION_KEY_FILE=/run/secrets/backup_key
BACKUP_HOST_DIR=/mnt/disco-externo/consultora-dh-backups
BACKUP_RETENTION_DAYS=30
```

No reutilice `DB_PASSWORD` ni `POSTGRES_PASSWORD` como passphrase de backup.
Laravel nunca recibe las credenciales de backup.

---

## 2. Arranque y programación

El servicio está detrás del perfil `ops` para que un `docker compose up` normal
no lo arranque sin credenciales:

```bash
docker compose --profile ops up -d backup
docker compose --profile ops ps backup
docker compose --profile ops logs -f backup
```

`docker/backup/run.sh` hace un backup inmediato al arrancar y después cada día a
`BACKUP_HOUR_UTC` (02:00 por defecto). Si falla, reintenta cada
`BACKUP_RETRY_SECONDS` (300 s). El healthcheck no comprueba que el proceso viva:
comprueba que existe `/backups/.last-success` y que tiene menos de
`BACKUP_MAX_AGE_HOURS` (36 h). Un contenedor `unhealthy` es la señal de alerta.

```bash
docker inspect --format '{{.State.Health.Status}}' consultora-dh-backup
```

El backup manual es:

```bash
docker compose --profile ops exec backup /scripts/backup.sh
```

---

## 3. Qué produce un backup

```
/backups/consultora_dh_YYYYMMDD_HHMMSS/
├── postgres.dump.gpg          # custom format, cifrado
├── private_storage.tar.gz.gpg # contenido de storage/app/private
├── COUNTS.tsv.gpg             # conteos de tablas en el instante del dump
└── MANIFEST.sha256            # SHA-256 de los ficheros cifrados + metadatos
```

Sin cifrado (sólo si `BACKUP_REQUIRE_ENCRYPTION=false`) los nombres no llevan
`.gpg` y el script emite un aviso. En producción no se acepta ese modo.

El manifest incluye `StorageFiles` y `Encrypted`; el restore proof los usa para
comparar el número de archivos y saber si debe descifrar.

Retención: se eliminan directorios `consultora_dh_*` con más de
`RETENTION_DAYS` días, tanto en `BACKUP_DIR` como en `BACKUP_OFFSITE_DIR`.

---

## 4. Restore proof (obligatorio)

El proof **nunca** restaura sobre `PGDATABASE`. Crea
`consultora_dh_restore_<UTC>`, restaura con `--single-transaction`, compara cada
tabla de `COUNTS.tsv`, verifica el número de archivos privados y elimina la base
y el directorio disposable en un `trap` incluso si falla.

```bash
docker compose --profile ops run --rm backup \
  /scripts/restore_proof.sh /backups/consultora_dh_20261011_020000
```

Comprueba, en este orden:

1. `sha256sum -c MANIFEST.sha256` sobre los bytes almacenados.
2. Descifrado a un directorio temporal (si aplica).
3. `createdb` con el rol de backup y `pg_restore --single-transaction`.
4. `to_regclass` de cada tabla y comparación exacta con `COUNTS.tsv`.
5. Extracción del storage y comparación con `StorageFiles`.
6. Existencia de `ai-artifacts`, `clients` e `imports` (configurable con
   `RESTORE_REQUIRED_DIRS`).

Un resultado correcto termina con `RESTORE PROOF EXITOSO`. Cualquier diferencia
de conteo o de archivos es un fallo, no un aviso.

---

## 5. Restauración real (sólo con autorización)

> **Nunca** ejecute un restore sobre la base operativa sin una ventana de
> mantenimiento aprobada y un backup del estado actual. El procedimiento de
> abajo es el único autorizado; no use `docker compose down -v`, `dropdb` sobre
> la base operativa ni `migrate:fresh`.

```bash
# 1. Detener escrituras de la aplicación
docker compose stop queue queue-imports queue-ai queue-automations scheduler
docker compose exec app php artisan down --message="Restauración en curso"

# 2. Backup del estado actual antes de tocar nada
docker compose --profile ops exec backup /scripts/backup.sh

# 3. Descifrar el backup elegido a un directorio temporal dentro del sidecar
#    (siga el mismo procedimiento del restore proof; no restaure el .gpg directo)

# 4. Restaurar en la base operativa SOLO con autorización expresa:
#    pg_restore --clean --if-exists --no-owner --no-privileges \
#      --single-transaction -d consultora_dh /ruta/postgres.dump

# 5. Restaurar storage/app/private con el tar extraído
# 6. php artisan migrate --force (sólo si el backup es anterior a una migración)
# 7. Verificar /api/health, login y un documento antes de abrir tráfico
docker compose exec app php artisan up
```

La restauración real no está automatizada a propósito: un comando que borre la
base operativa con un argumento equivocado es más peligroso que un runbook.

---

## 6. Evidencia mínima de DR

| Evidencia | Cómo se obtiene |
| --- | --- |
| Backup real | `docker compose --profile ops exec backup /scripts/backup.sh` |
| Checksums | `sha256sum -c MANIFEST.sha256` |
| Conteos | Comparación de `COUNTS.tsv` contra la base restaurada |
| Archivos | `StorageFiles` contra el tar extraído |
| Cifrado | `Encrypted: yes` y descifrado correcto en el proof |
| Aislamiento | La base disposable se crea y se elimina; `PGDATABASE` no se toca |
| Retención | Directorios antiguos eliminados según `RETENTION_DAYS` |
| Alerta | Healthcheck en `unhealthy` si no hay éxito reciente |

La evidencia de una ejecución con datos sintéticos se guarda en el informe de
entrega; no se usa la base de desarrollo ni datos de clientes para certificar.
