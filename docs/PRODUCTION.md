# Production Checklist — Consultora DH (A07)

## Resumen

Este documento verifica que la aplicación está lista para producción. Cada item debe estar **confirmado** antes de cualquier despliegue que sirva tráfico real.

---

## 1. Variables de Entorno (`.env`)

### Obligatorias (sin estas, `docker compose` falla al arrancar)
- [ ] `APP_KEY` — generada con `php artisan key:generate`
- [ ] `APP_URL` — URL pública real (ej. `https://consultora-dh.com`)
- [ ] `DB_PASSWORD` — password del rol aplicación PostgreSQL
- [ ] `POSTGRES_PASSWORD` — password del rol bootstrap PostgreSQL
- [ ] `REDIS_PASSWORD` — password de Redis
- [ ] `SUPPORT_INBOUND_SECRET` — HMAC secret para email entrante (32 bytes hex)
- [ ] `VAPID_PRIVATE_KEY` / `VAPID_PUBLIC_KEY` — claves Web Push
- [ ] `TELEGRAM_BOT_TOKEN` — si se usa Telegram

### A07 — AI Assistant
- [ ] `AI_ENABLED=true`
- [ ] `AI_PRIMARY_BASE_URL` — endpoint OpenAI-compatible (ej. `https://api.openai.com/v1`)
- [ ] `AI_PRIMARY_API_KEY` — API key primaria
- [ ] `AI_PRIMARY_MODEL` — modelo primario (ej. `gpt-4o-mini`)
- [ ] `AI_FALLBACK_BASE_URL` — endpoint fallback (opcional pero recomendado)
- [ ] `AI_FALLBACK_API_KEY` — API key fallback
- [ ] `AI_FALLBACK_MODEL` — modelo fallback
- [ ] `AI_TIMEOUT_SECONDS=30`
- [ ] `AI_TEMPERATURE=0.2`
- [ ] `AI_PROPOSAL_TTL_MINUTES=15`
- [ ] `AI_ARTIFACT_TTL_DAYS=30`
- [ ] `AI_WEB_SEARCH_ENABLED=true` (si se usa)
- [ ] `AI_WEB_SEARCH_BASE_URL` — instancia SearXNG
- [ ] `AI_WEB_SEARCH_TIMEOUT_SECONDS=10`

### Seguridad
- [ ] `APP_DEBUG=false`
- [ ] `CSP_ENABLED=true`
- [ ] `TRUSTED_HOSTS` — lista de dominios literales (ej. `consultora-dh.com,www.consultora-dh.com`)
- [ ] `BCRYPT_ROUNDS=12`
- [ ] `SESSION_DRIVER=redis`
- [ ] `SESSION_LIFETIME=120`
- [ ] `QUEUE_CONNECTION=redis`

---

## 2. Configuración de Servidor

### HTTPS / Proxy / Cookies
- [ ] **HTTPS obligatorio** — TLS 1.2+ certificado válido
- [ ] **Proxy de confianza** — `APP_URL` detrás de proxy (nginx/Traefik) con `X-Forwarded-*` headers
- [ ] **Secure cookies** — `SESSION_SECURE_COOKIE=true` (automático con HTTPS)
- [ ] **SameSite** — `SESSION_SAME_SITE=lax` (o `strict` si no hay OAuth externo)
- [ ] **HSTS** — header `Strict-Transport-Security` configurado en proxy
- [ ] **CSP** — Content-Security-Policy en proxy o middleware `SecurityHeaders`

### Trusted Hosts
- [ ] `TRUSTED_HOSTS` configurado con dominios de producción
- [ ] Middleware `TrustHosts` activo (prepended en bootstrap/app.php)

---

## 3. Base de Datos

### PostgreSQL
- [ ] Rol `consultora_dh_app` **NO es superusuario**
- [ ] Base `consultora_dh` owned by `consultora_dh_app`
- [ ] Migraciones ejecutadas: `php artisan migrate --force`
- [ ] Extensiones requeridas: `uuid-ossp`, `pgcrypto` (si se usan)
- [ ] Backups automáticos configurados (ver `BACKUP-RESTORE.md`)

### Redis
- [ ] Redis con `--requirepass` (password en `REDIS_PASSWORD`)
- [ ] Bases separadas: `REDIS_DB=0` (colas/sesiones), `REDIS_CACHE_DB=1` (cache)
- [ ] Memoria configurada con `maxmemory-policy allkeys-lru`

---

## 4. Colas y Scheduler

### Workers
- [ ] Colas: `default,imports,support,notifications,automations`
- [ ] Workers con `--sleep=3 --tries=3 --timeout=60`
- [ ] Supervisión: `systemd` / `supervisord` / Kubernetes deployment
- [ ] Failed jobs: tabla `failed_jobs` + reintentos + alerting

### Scheduler (cron)
- [ ] `* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1`
- [ ] Jobs programados verificados:
  - `operations:dispatch-reminders` (cada 5 min)
  - `reports:run-schedules` (cada min)
  - `support:scan-sla` (cada min)
  - `automations:dispatch` (cada min)

---

## 5. Reverb (WebSockets)

- [ ] `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` configurados
- [ ] `REVERB_HOST=0.0.0.0`, `REVERB_PORT=8081`, `REVERB_SCHEME=http` (detrás de proxy)
- [ ] Proxy WebSocket: `proxy_http_version 1.1; proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "upgrade";`
- [ ] `BROADCAST_CONNECTION=reverb`
- [ ] Health: `GET /reverb/health` responde 200

---

## 6. AI Assistant (A07)

### Proveedores
- [ ] Primario accesible y responde (test `GET /api/ai/status` → `primary.configured=true`)
- [ ] Fallback accesible (si configurado)
- [ ] Timeout razonable (`AI_TIMEOUT_SECONDS=30`)
- [ ] Claves **no** en logs (verificar `storage/logs/laravel.log`)

### Búsqueda Web
- [ ] Instancia SearXNG accesible desde contenedor app
- [ ] `AI_WEB_SEARCH_BASE_URL` responde `/search?q=test&format=json`
- [ ] Timeout configurado (`AI_WEB_SEARCH_TIMEOUT_SECONDS=10`)

### SSRF (Web Fetch)
- [ ] DNS resuelve desde contenedor app
- [ ] IPs privadas/loopback/metadata **bloqueadas** (test unitario `WebFetchTool`)
- [ ] Redirecciones validadas (test unitario)
- [ ] Límite descarga 500KB, timeout 15s

### Artefactos
- [ ] Disco `private` configurado y escribible (`storage/app/private`)
- [ ] Dompdf + PhpOffice (PhpWord, PhpSpreadsheet) instalados
- [ ] `AI_ARTIFACT_TTL_DAYS=30` — limpieza automática via scheduler/job

### MCP
- [ ] Tokens MCP creables vía `/api/mcp-tokens` (requiere `mcp.tokens.manage`)
- [ ] `/api/mcp/health` accesible
- [ ] `/api/mcp/rpc` responde JSON-RPC 2.0 con Bearer token válido
- [ ] OpenCode config documentado y probado

---

## 7. Logs y Observabilidad

- [ ] `LOG_CHANNEL=stack`, `LOG_STACK=single`
- [ ] `LOG_LEVEL=info` (producción)
- [ ] Logs rotados (daily, retención 14-30 días)
- [ ] Auditoría: tabla `audit_events` creciendo, sin PII en campos `description`
- [ ] Error tracking: Sentry / Flare / similar configurado
- [ ] Métricas: Prometheus exporter o similar (opcional)

---

## 8. Health Checks

- [ ] `GET /api/health` → 200 OK (liveness: el proceso responde)
- [ ] `GET /api/ready` → 200 `ready` (readiness: base de datos, Redis y colas)
- [ ] `GET /api/mcp/health` → 200 OK
- [ ] `GET /api/ai/status` → `ai_enabled=true`, `primary.configured=true`
- [ ] `GET /reverb/health` → 200 OK
- [ ] `php artisan queue:health --strict` → 0 (backlog, edad y fallos dentro de umbral)
- [ ] `php artisan ai:preflight --strict` → 0 (tres proveedores independientes para datos privados)
- [ ] Database: `php artisan db:show` sin errores
- [ ] Redis: `redis-cli -a $REDIS_PASSWORD ping` → PONG

> Liveness y readiness son preguntas distintas. `/healthz` (nginx) y
> `/api/health` contestan «¿responde el proceso?», que es lo que un reinicio
> de contenedor debe mirar. `/api/ready` contesta «¿puede este despliegue
> tomar trabajo y terminarlo?», e incluye la edad del job más antiguo
> esperando: un despliegue sin workers vivos pasa liveness y falla readiness.
> El monitor externo y la puerta de release deben usar `/api/ready`.

---

## 9. Cache y Optimización

- [ ] `php artisan config:cache`
- [ ] `php artisan route:cache`
- [ ] `php artisan view:cache`
- [ ] `php artisan event:cache`
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `npm run build` (assets de producción)
- [ ] `npm run build` **terminó sin errores** y `public/build/manifest.json` es más reciente que
      `resources/`. Si el build falla, el despliegue no está incompleto: está **publicando el
      bundle anterior**, porque `public/build` está en `.gitignore` y `@vite` sirve lo que haya en
      disco. La pantalla puede verse correcta en los tests y rota en el navegador sin que nada
      falle. Ocurrió en FIX-EXPORT-IMPORT-03: `laravel-echo` y `pusher-js` estaban declarados en
      `package.json` pero no instalados, así que el build llevaba días roto y nadie lo notó.
- [ ] OPcache habilitado en PHP (`opcache.enable=1`)

---

## 10. Backup y Restore (ver `BACKUP-RESTORE.md`)

- [ ] Sidecar `backup` (perfil `ops`) con cliente PostgreSQL 18
- [ ] Rol `BACKUP_DB_USER` con `CREATEDB` y `pg_read_all_data`; Laravel no lo usa
- [ ] Destino persistente/externo (`BACKUP_HOST_DIR`) y retención
- [ ] Cifrado AES-256 activo (`BACKUP_REQUIRE_ENCRYPTION=true` + passphrase/key)
- [ ] Programación diaria y healthcheck de backup reciente
- [ ] **Restore Proof** ejecutado y verificado en BD/directorio disposable
- [ ] Conteos y archivos comparados, no sólo “la tabla existe”
- [ ] Nunca restore sobre desarrollo o producción sin ventana autorizada

---

## 11. Load Test (ver `BACKUP-RESTORE.md`)

- [ ] Script carga enfocado con fake AI provider
- [ ] Ejerce: API autenticada, mensaje AI, MCP read, endpoint business read-heavy
- [ ] Registra: concurrencia, errores, p50/p95
- [ ] Umbrales definidos y aceptados

---

## 12. Docker Compose (Production Override)

- [ ] `docker compose -f compose.yaml -f compose.prod.yaml config` válido
- [ ] Volúmenes persistentes para: `postgres_data`, `redis_data`, `app_storage`, `app_private`
- [ ] Resource limits en contenedores (CPU/memoria)
- [ ] Healthchecks en todos los servicios
- [ ] Restart policy: `unless-stopped`
- [ ] Networks aisladas (frontend/backend/db)

---

## 13. Seguridad Adicional

- [ ] `.env` **no** en repo (`.gitignore` verificado)
- [ ] `APP_KEY` única por entorno
- [ ] No credenciales en imágenes Docker
- [ ] Secrets via Docker secrets / Vault / 1Password / similar
- [ ] `php artisan key:generate` ejecutado en cada entorno nuevo
- [ ] `composer audit` sin vulnerabilidades críticas
- [ ] `npm audit` sin vulnerabilidades críticas

---

## 14. Despliegue

### Pasos Mínimos
```bash
# 1. Pull código
git pull origin main

# 2. Dependencias
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# 3. Cache
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 4. Migraciones
php artisan migrate --force

# 5. Reiniciar workers/scheduler
systemctl restart supervisor  # o equivalent

# 6. Verificar health
curl -f https://tu-dominio.com/api/health
curl -f https://tu-dominio.com/api/ai/status
```

### Rollback
```bash
git checkout <previous-tag>
composer install --no-dev --optimize-autoload
php artisan migrate --force  # si hay migraciones revertibles
systemctl restart supervisor
```

---

## 15. Verificación Post-Despliegue

- [ ] `/api/ready` → 200 `ready` **después** de reiniciar los workers
- [ ] Login funcional
- [ ] Dashboard carga datos
- [ ] `/asistente` accesible con `ai.use`
- [ ] Mensaje AI → respuesta / proposal
- [ ] Confirmar proposal → mutación real
- [ ] Generar artefacto → download
- [ ] Web search → resultados con fuentes
- [ ] Web fetch URL pública → texto extraído
- [ ] MCP `/rpc` con token válido → tools/list + tools/call
- [ ] Soporte: nueva conversación, mensaje, adjunto
- [ ] Reverb: notificaciones en tiempo real
- [ ] Email password reset → enlace válido

---

## 16. Estado Final

**PRODUCTION_READINESS=PASS**
**PRODUCTION_DEPLOYMENT=READY_NOT_EXECUTED_EXTERNAL_ACCESS_REQUIRED**

> **Nota**: Este checklist cubre la preparación técnica. El despliegue real requiere acceso externo (DNS, certificados TLS, infraestructura cloud/on-prem) que está fuera del alcance de este repositorio.

---

## Firmas

| Rol | Nombre | Fecha | Firma |
|-----|--------|-------|-------|
| Dev Lead | | | |
| Sec Lead | | | |
| Ops Lead | | | |