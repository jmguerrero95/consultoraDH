# Consultora DH

Plataforma administrativa para la gestión de clientes, empresas, afiliaciones,
periodos, pagos y documentos.

**Consultora DH** es una aplicación web de uso interno. Todo el trabajo de un
administrador —clientes, afiliaciones, cortes, cartera, planillas, novedades,
documentos y reportes— se concentra en un solo lugar, con trazabilidad.

> **Estado actual: A01 — base técnica.**
> La autenticación, la seguridad, los permisos, la auditoría y las pantallas
> administrativas ya funcionan. Los módulos de negocio todavía **no** existen;
> el panel inicial lo indica de forma explícita en lugar de mostrar datos
> inventados. Consulte [docs/ROADMAP.md](docs/ROADMAP.md) para el plan.

---

## 1. ¿Qué es Consultora DH?

Un sistema de gestión interna con tres grupos de requisitos:

- **Administración operativa**: clientes, empresas, afiliaciones al sistema de
  seguridad social (EPS, AFP, ARL y Cajas de Compensación Familiar), periodos y
  fechas de corte, obligaciones, pagos, cartera, planillas, novedades
  operativas, tareas y recordatorios, y documentos.
- **Servicio al cliente**: portal de clientes, centro de soporte, correo
  bidireccional y avisos al administrador.
- **Analítica e integración**: importaciones y exportaciones Excel, reportes
  en PDF, reportería dinámica, automatizaciones, asistente de IA e integración
  por MCP / OpenCode.

A01 no implementa ninguno de esos módulos. Construye la base técnica que los
sostendrá correctamente.

## 2. Arquitectura en una frase

Un **monolito modular**: una única aplicación Laravel que sirve la API y una
aplicación Vue 3 compilada por Vite, en el mismo repositorio, sobre
PostgreSQL y Redis, en contenedores Docker.

El detalle está en [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## 3. Tecnologías

| Capa | Tecnología |
| --- | --- |
| Backend | Laravel 13, PHP 8.4, Laravel Sanctum, `spatie/laravel-permission` |
| Frontend | Vue 3 (Composition API), TypeScript, Vite, Vue Router, Pinia |
| Interfaz | Bootstrap 5, Bootstrap Icons, tipografía Inter autoalojada |
| Base de datos | PostgreSQL 18 |
| Caché, sesiones, colas | Redis 8 |
| Servidor web | Nginx |
| Pruebas backend | Pest (sobre PHPUnit) |
| Pruebas frontend | Vitest, `@vue/test-utils` |
| Pruebas de interfaz | Playwright |
| Calidad | Laravel Pint, ESLint, `vue-tsc` |
| Infraestructura | Docker, Docker Compose |

Todo el software es libre o de coste cero, y no hay dependencias de pago ni
recursos externos por CDN: Bootstrap, los iconos y la tipografía se sirven
desde el propio servidor.

## 4. Requisitos

- **Docker Desktop** con Docker Compose v2.
- Nada más. No hace falta instalar PHP, Composer, Node, PostgreSQL ni Redis en
  el equipo: todo se ejecuta dentro de contenedores.

Puertos que ocupa el entorno de desarrollo (elegidos tras revisar los que ya
estaban en uso en la máquina):

| Puerto | Servicio | Nota |
| --- | --- | --- |
| `8080` | Nginx (aplicación) | `APP_PORT` en `.env` |
| `5173` | Servidor de desarrollo de Vite | `VITE_PORT` en `.env` |

PostgreSQL y Redis **no** se publican: solo son accesibles desde la red
interna de Docker. Los dos puertos de la tabla se enlazan a `127.0.0.1`, de modo
que tampoco quedan expuestos a la red local.

## 5. Puesta en marcha

```bash
cd consultora-dh

# 1. Variables de entorno. .env nunca se versiona, así que las credenciales son
#    sólo suyas.
cp .env.example .env
```

Abra `.env` y defina **tres contraseñas obligatorias**. Para generar cada una:

```bash
openssl rand -base64 24
```

| Variable | Para qué sirve |
| --- | --- |
| `DB_PASSWORD` | El rol de la aplicación en PostgreSQL (`consultora_dh_app`). **No** es superusuario. |
| `POSTGRES_PASSWORD` | El rol bootstrap de PostgreSQL (`postgres`), usado sólo al inicializar el volumen. |
| `REDIS_PASSWORD` | Contraseña de Redis (caché, sesiones, colas). **Obligatoria también en desarrollo.** |

`docker compose` se niega a arrancar mientras falte alguna, diciendo cuál.
`APP_KEY` se genera sola en el primer arranque.

```bash
# 2. Levantar el entorno. La primera vez compila la imagen de la aplicación y
#    descarga las dependencias de PHP y de Node.
docker compose up -d

# 3. Crear el esquema y los roles de la aplicación.
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force

# 4. Crear la primera cuenta de administrador (ver más abajo).
docker compose exec app php artisan consultora-dh:create-admin
```

La aplicación queda disponible en **<http://localhost:8080>**.

En desarrollo no hay que configurar nada más: los hosts de confianza ya cubren
`localhost`, `127.0.0.1` y los nombres de servicio de Docker. **En producción
hay que definir `TRUSTED_HOSTS`** con los dominios reales, separados por comas y
como nombres literales:

```dotenv
TRUSTED_HOSTS=portal.consultoradh.com,www.consultoradh.com
```

Sin esa variable la aplicación responde 503 a todo: falla cerrada en lugar de
admitir `localhost`. Los dominios con comodín no valen aquí.

### 5.1 Crear el primer administrador

```bash
docker compose exec app php artisan consultora-dh:create-admin
```

El comando solicita de forma interactiva el nombre, el correo electrónico y
la contraseña. **La contraseña se pide con entrada oculta y nunca se vuelve a
mostrar.** Valida la política de contraseñas, normaliza el correo a minúsculas,
rechaza direcciones duplicadas, crea la cuenta activa y le asigna el rol
**Super Admin**.

**El comando no tiene la opción `--password`.** No está desactivada: no existe,
así que Symfony rechaza el argumento antes de ejecutarlo. Una contraseña pasada
por línea de comandos queda en el historial del shell, aparece en `ps` para
cualquier usuario de la máquina y en el log de la herramienta que lo ejecute.

Para automatización (por ejemplo en un script de provisión) se usan las dos
variables de entorno, que se leen como un par y fallan si no coinciden:

```bash
docker compose exec \
  -e CONSULTORA_DH_ADMIN_PASSWORD='...' \
  -e CONSULTORA_DH_ADMIN_PASSWORD_CONFIRMATION='...' \
  app php artisan consultora-dh:create-admin \
    --name='Nombre Apellido' --email='admin@consultora-dh.test' --no-interaction
```

Las únicas opciones disponibles son `--name`, `--email` y `--role`, ninguna
sensible.

**Nunca se siembre una contraseña de administrador predecible.** Las cuentas
de prueba de los tests se crean con factories dentro de la base de datos de
pruebas, nunca en la de desarrollo.

## 6. Migraciones y datos iniciales

```bash
docker compose exec app php artisan migrate --force      # aplicar
docker compose exec app php artisan migrate:status        # estado
docker compose exec app php artisan migrate:rollback      # revertir
docker compose exec app php artisan db:seed --force       # roles y permisos
```

La base de datos de pruebas (`consultora_dh_test`) se crea sola la primera vez
que se inicializa el volumen de PostgreSQL.

Las migraciones de A02 crean el dominio de negocio: `clients`, `companies`,
`social_security_entities`, `client_company_assignments` y `client_affiliations`,
y añaden `subject_type` y `subject_id` a `audit_events`.

Las relaciones con empresas y las afiliaciones se guardan como periodos
históricos: cerrar o cambiar una relación **marca la fila anterior con su fecha de
fin y crea una fila nueva**. Por eso no hay borrados en esas dos tablas, y por eso
las restricciones de unicidad son parciales: sólo una fila abierta por cliente y
tipo. `db:seed` deja el catálogo de entidades de seguridad social **vacío a
propósito**: son datos de referencia reales y esta tarea no los inventa. Regístralos
desde *Configuración → Entidades de seguridad social* antes de registrar
afiliaciones.

## 7. Flujo de trabajo del frontend

El servicio `node` mantiene el servidor de desarrollo de Vite con recarga en
caliente. El navegador carga los módulos desde `http://localhost:5173` y las
peticiones de la API desde `http://localhost:8080`: mismo sitio, distinto
puerto, por lo que no hace falta CORS para la API.

```bash
docker compose logs -f node            # ver el servidor de Vite
docker compose restart node            # reiniciarlo
```

Si el proyecto está en un sistema de archivos que no entrega eventos inotify
(WSL, Docker Desktop, unidades de red), active el sondeo en `.env`:

```dotenv
VITE_USE_POLLING=1
```

Para un build de producción:

```bash
docker compose exec node npm run build
```

En producción no se usa el servidor de Vite: nginx sirve `public/build`, y el
servicio `node` se detiene.

## 8. Pruebas y calidad

Todo se ejecuta dentro de los contenedores.

```bash
# Backend (Pest sobre PHPUnit). Aísla su propia base de datos.
docker compose exec app vendor/bin/pest

# Frontend
docker compose exec node npm run test        # Vitest
docker compose exec node npm run typecheck   # vue-tsc
docker compose exec node npm run lint        # ESLint
docker compose exec node npm run build       # build de producción

# Estilo del backend
docker compose exec app vendor/bin/pint --test

# Interfaz de extremo a extremo (crea dos cuentas temporales y las elimina)
./scripts/run-e2e.sh
```

La guía completa está en [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md).

## 9. Directorios importantes

```
consultora-dh/
├── app/
│   ├── Domain/           # Lógica de negocio: auditoría, autenticación, usuarios,
│   │                     # clientes, empresas, afiliaciones, calidad de datos
│   ├── Http/             # Controladores, Form Requests, middleware, recursos
│   ├── Models/           # User, AuditEvent, Client, Company, ClientCompanyAssignment,
│   │                     # ClientAffiliation, SocialSecurityEntity
│   └── Providers/        # Proveedores de servicios
├── bootstrap/            # Arranque: rutas, middleware, manejo de errores
├── config/               # Configuración (incluye security.php)
├── database/
│   ├── factories/        # Generadores para las pruebas
│   ├── migrations/       # Esquema
│   └── seeders/          # Roles y permisos
├── docker/               # Dockerfile, nginx, PostgreSQL
├── docs/                 # Documentación del proyecto
├── lang/es/              # Mensajes en español
├── resources/
│   ├── css/              # tokens.css + app.css
│   ├── js/               # Aplicación Vue
│   └── views/            # Plantilla Blade del shell
├── routes/               # web.php (documento) y api.php (JSON)
├── scripts/              # Utilidades (run-e2e.sh, export-review.sh)
└── tests/
    ├── e2e/              # Playwright: flujos de extremo a extremo
    ├── Feature/A02/      # Pest: clientes, empresas, afiliaciones, permisos
    └── frontend/         # Vitest: composables, campos de formulario, páginas
```

### 9.1 Los cuatro flujos de A02 en las pruebas

`scripts/run-e2e.sh` crea **dos** cuentas temporales con contraseñas aleatorias y
las elimina al terminar:

| Flujo | Qué demuestra |
| --- | --- |
| Alta completa | Empresa, cliente, vínculo, afiliación y las entradas de historial |
| Transferencia | La empresa anterior queda histórica y la nueva queda vigente |
| Vínculo duplicado | El aviso ofrece transferir, mantener en paralelo o cancelar; al cancelar no cambia nada |
| Rol de sólo lectura | Ve el portafolio y el servidor responde `403` a cualquier escritura |

El cuarto flujo necesita dos cuentas a propósito: un administrador no puede
demostrar que una petición sin permiso es rechazada.
```

## 10. Detener el entorno

```bash
docker compose stop        # detener, conservar los datos
docker compose down        # detener y eliminar los contenedores
```

`docker compose down` **no** borra los volúmenes, de modo que la base de datos
y las dependencias sobreviven. Para empezar de cero:

```bash
docker compose down -v
```

> Esto elimina el volumen de PostgreSQL, incluida la base de datos de
> desarrollo.

## 11. Seguridad y producción

El entorno local está pensado para desarrollar, no para exponerse. Antes de
publicar la aplicación:

- `APP_ENV=production` y **`APP_DEBUG=false`**. Con la depuración activa se
  muestran excepciones con trazas.
- `APP_URL` con el dominio real, y `SESSION_SECURE_COOKIE=true`.
- Terminación TLS y HSTS, y `CSP_ENABLED=true` una vez compilados los
  recursos.
- Credenciales nuevas y distintas para base de datos y Redis.
- Un correo SMTP real en lugar del remitente en bitácora.
- Copias de seguridad de PostgreSQL y rotación de la bitácora.

Los detalles y la lista completa están en [docs/SECURITY.md](docs/SECURITY.md).

## 12. Documentación

| Documento | Contenido |
| --- | --- |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Monolito modular, capas, dominios futuros |
| [docs/SECURITY.md](docs/SECURITY.md) | Autenticación, sesión, CSRF, permisos, auditoría |
| [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) | Comandos, pruebas, depuración |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Plan de A02 a A15 |
| [docs/TASKS/A01.md](docs/TASKS/A01.md) | Qué se entregó en A01 |
| [docs/TASKS/A02.md](docs/TASKS/A02.md) | Clientes, empresas, afiliaciones e historial |

## 13. Licencia

**Software propietario.** `consultora-dh/platform` declara `"license":
"proprietary"` en `composer.json`, y el paquete `consultora-dh` declara
`"license": "UNLICENSED"` y `"private": true` en `package.json`. No se concede
ningún derecho de uso, copia o redistribución sobre este código.

Los componentes de terceros mantienen sus propias licencias, y no se han
modificado: el código de terceros es de uso separado y no queda cubierto por la
licencia de Consultora DH.
