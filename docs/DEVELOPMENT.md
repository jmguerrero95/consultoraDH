# Desarrollo

Guía de trabajo diario. Todos los comandos se ejecutan **dentro de los
contenedores**, desde el directorio del proyecto (`consultora-dh/`).

## 1. Requisitos

Sólo Docker Desktop con Docker Compose v2. No se instala PHP, Composer, Node,
PostgreSQL ni Redis en el equipo.

> **WSL:** si el CLI `docker` de WSL responde con un error de integración,
> ejecute los comandos de Compose desde el directorio del proyecto y con rutas
> relativas (`docker compose ps`, no `docker -f /mnt/c/... compose ps`).

## 2. Ciclo de vida del entorno

```bash
docker compose up -d              # levantar
docker compose stop               # detener, conservar datos
docker compose down               # detener y eliminar contenedores
docker compose down -v            # además eliminar volúmenes (borra la BD)
docker compose restart node       # reiniciar un servicio
docker compose ps                 # estado y salud
docker compose up -d --build app  # reconstruir una imagen
```

El primer `up` compila la imagen de la aplicación, instala las dependencias de
PHP y de Node y tarda bastante. Las siguientes veces es inmediato.

### 2.1 Servicios

| Servicio | Función | Puerto en el host |
| --- | --- | --- |
| `app` | PHP-FPM + Laravel | — |
| `queue` | Consumidor de trabajos | — |
| `nginx` | Servidor web | `8080` |
| `node` | Vite (desarrollo) | `5173` |
| `postgres` | PostgreSQL 18 | — (interno) |
| `redis` | Redis 8 | — (interno) |

## 3. Composer

```bash
docker compose exec app composer install
docker compose exec app composer require spatie/laravel-dompdf   # añadir paquete
docker compose exec app composer remove paquete
docker compose exec app composer audit                           # avisos de seguridad
docker compose exec app composer dump-autoload -o                 # tras editar composer.json
```

`vendor/` es un volumen con nombre, no el montaje del proyecto. Eso lo hace
mucho más rápido y evita que `composer install` escriba decenas de miles de
archivos pequeños a través del enlace de Docker.

## 4. npm

```bash
docker compose exec node npm install
docker compose exec node npm install --save-dev paquete
docker compose exec node npm run dev          # servidor de desarrollo
docker compose exec node npm run build        # build de producción
```

`node_modules/` también es un volumen con nombre, por el mismo motivo.

### 4.1 Recarga en caliente

Vite vigila `resources/js` y `resources/css`. Si los cambios no se reflejan
(Fuera de WSL, Docker Desktop o unidades de red, donde no llegan los eventos
inotify), active el sondeo en `.env` y reinicie el servicio:

```dotenv
VITE_USE_POLLING=1
```

```bash
docker compose restart node
```

## 5. Roles de PostgreSQL

Hay **dos roles distintos**, y conviene no confundirlos:

| Rol | Variable | ¿Superusuario? | Uso |
| --- | --- | --- | --- |
| `postgres` | `POSTGRES_USER` / `POSTGRES_PASSWORD` | Sí | Sólo el script de inicialización del volumen |
| `consultora_dh_app` | `DB_USERNAME` / `DB_PASSWORD` | **No** | Laravel y las migraciones |

El script `docker/postgres/init/01-create-application-role.sh` crea el rol de la
aplicación sin privilegios elevados y le entrega la propiedad de las bases de
datos. Se ejecuta **una sola vez**, al crearse el volumen, y es idempotente.

Para comprobarlo:

```bash
docker compose exec postgres psql -U postgres -d postgres -c \
  "SELECT rolname, rolsuper, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname LIKE 'consultora_dh%';"
```

Debe mostrar `rolsuper = f`. El panel de Inicio muestra la versión de
PostgreSQL usando esta misma conexión, sin privilegios adicionales.

**Para rehacer la base de datos de desarrollo** con el nuevo arranque (sólo el
volumen de PostgreSQL de Consultora DH; los demás volúmenes no se tocan):

```bash
docker compose down
docker volume rm consultora-dh-postgres-data
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
```

## 6. Migraciones y datos iniciales

```bash
docker compose exec app php artisan migrate --force
docker compose exec app php artisan migrate:status
docker compose exec app php artisan migrate:rollback --step=1
docker compose exec app php artisan db:seed --force
docker compose exec app php artisan migrate:fresh --seed   # ⚠ borra la base de DESARROLLO
```

> `migrate:fresh` borra todas las tablas y reconstruye el esquema. El comando de
> arriba apunta a la base de **desarrollo** y solo es aceptable en una máquina de
> trabajo cuyo estado se pueda reconstruir. No tiene nada que ver con la base de
> pruebas: para esa existe `scripts/reset-test-db.sh` (sección 8.1), que es el
> único mecanismo previsto para destruirla.

`db:seed` crea los roles y la matriz de permisos, y **revoca** cualquier permiso
que no esté en su lista. Es la autoridad: si se añade un permiso al código y no al
seeder, el rol por defecto y el código quedan separados y la ruta responde `403`
sin que nadie sepa por qué.

El catálogo de entidades de seguridad social se deja **vacío a propósito**. Son
datos de referencia reales; A02 no los inventa y sí impide que se inventen por
error. Se registran desde *Configuración → Entidades de seguridad social*.

Las tres migraciones de A02-R1 son correcciones y se aplican sobre el esquema de
A02 sin reescribir la historia de Git:

| Migración | Qué corrige |
| --- | --- |
| `2026_10_01_220000_split_tax_id_and_verification_digit` | Separa el NIT en número y dígito, y pone la unicidad sobre el número base |
| `2026_10_01_220100_relax_contact_email_uniqueness` | El correo de contacto deja de ser único en clientes y empresas |
| `2026_10_01_220200_relax_catalogue_code_uniqueness` | El código del catálogo deja de ser único en todo el catálogo |

La primera **falla con un mensaje claro** si dos empresas acaban compartiendo el
número base del NIT, nombrándolas, en vez de dejar que el índice único reviente con
un error sobre una clave. No se descarta ninguna fila y no se recalcula ningún
dígito: un dígito de verificación dudoso se conserva y se reporta como duda.

A02-R2 añade una más:

| Migración | Qué corrige |
| --- | --- |
| `2026_10_01_230000_allow_unknown_start_to_be_closed` | Permite registrar un fin para una afiliación cuyo inicio se desconoce |

Esa última es una relajación deliberada de una restricción de A02. La anterior sólo
permitía un `started_on` nulo mientras `ended_on` también fuese nulo, así que una
afiliación de la que no se conoce el inicio se podía crear pero no cerrar ni cambiar
de entidad, que es justo para lo que existe la columna nullable. La regla nueva
ordena las dos fechas sólo cuando ambas se conocen. Su `down()` existe, pero
reventará si hay filas con inicio desconocido y fin conocido.

Ninguna de las tres de R1 tiene un `down()` que deshaga la corrección: volver a mezclar
el NIT con su dígito, o volver a poner una unicidad sobre el correo de contacto que
la portfolio ya comparte, no es un estado al que valga la pena volver. Para
retroceder hay que restaurar una copia anterior de la base.

Para comprobar que las migraciones de un módulo se pueden revertir de verdad, lo
más limpio es una base de datos desechable:

```bash
docker compose exec -e DB_DATABASE=a02_check app php artisan migrate --force
docker compose exec -e DB_DATABASE=a02_check app php artisan db:seed --force
docker compose exec -e DB_DATABASE=a02_check app php artisan migrate:rollback --step=6
docker compose exec postgres psql -U postgres -c 'DROP DATABASE a02_check'
```

## 7. Cuentas de administrador

```bash
docker compose exec app php artisan consultora-dh:create-admin
```

Solicita nombre, correo y contraseña. La contraseña se escribe en un campo
**oculto**, y el comando **no tiene la opción `--password`**: una contraseña
pasada como argumento queda en el historial del shell, en `ps` y en el log de
cualquier automatización que lo ejecute.

Las opciones disponibles son sólo `--name`, `--email` y `--role`, ninguna
sensitiva. Para aprovisionar sin interacción, las dos variables de entorno, que
se leen como un par y fallan si no coinciden:

```bash
docker compose exec   -e CONSULTORA_DH_ADMIN_PASSWORD='…'   -e CONSULTORA_DH_ADMIN_PASSWORD_CONFIRMATION='…'   app php artisan consultora-dh:create-admin \
  --name='…' --email='…' --no-interaction
```

Para desactivar una cuenta sin eliminarla hay que cambiar su estado. En A01 eso
se hace directamente sobre la base de datos; A02 lo formalizará como un
módulo de gestión de usuarios.

El efecto es inmediato: `App\Http\Middleware\EnsureUserIsActive` comprueba el
estado en el servidor en cada petición autenticada, de modo que una sesión que
ya estaba abierta se corta en la petición siguiente, sin esperar a que caduque
la cookie. La cuenta tampoco puede volver a iniciar sesión. Reactivarla no
restituye la sesión anterior.

### 7.1 Cabeceras de confianza

`App\Http\Middleware\TrustHosts` está activo **también en desarrollo**, a
diferencia del comportamiento por defecto del framework, para que el control
se pueda probar siempre.

En `local` y `testing` se aceptan `localhost`, `127.0.0.1`, `[::1]`, `nginx` y
`app` sin configurar nada. Los nombres de servicio de Docker están en la lista
porque las pruebas de extremo a extremo llaman a la aplicación desde dentro de la
red.

`TRUSTED_HOSTS` contiene **nombres de dominio literales**, no expresiones
regulares:

```dotenv
TRUSTED_HOSTS=portal.consultoradh.com,www.consultoradh.com
```

Cada entrada se escapa y se ancla, de modo que los puntos son literales y una
entrada como `.*` se descarta por no ser un nombre de dominio. Para probar
contra otro dominio, añádalo ahí; para probar un rechazo, no hace falta
configurar nada.

Cualquier otro entorno, `staging` incluido, se comporta como producción: sin
`TRUSTED_HOSTS` la aplicación responde 503 a todo.

## 8. Pruebas

### 8.1 Backend (Pest)

```bash
docker compose exec app vendor/bin/pest
docker compose exec app vendor/bin/pest --filter=login
docker compose exec app vendor/bin/pest tests/Feature/Auth
docker compose exec app vendor/bin/pest --coverage
```

Equivalente con PHPUnit, que es el ejecutor subyacente:

```bash
docker compose exec app vendor/bin/pest --testsuite=Unit
```

**La base de datos de pruebas es independiente.** `phpunit.xml` selecciona la
conexión `testing`, que apunta a `consultora_dh_test`. `tests/Pest.php` aborta
la ejecución si esa base de datos coincide con la de desarrollo.

> **Ese resguardo protege a Pest, y solo a Pest.** `tests/Pest.php` se ejecuta
> cuando arranca la suite. Un `php artisan` lanzado a mano no lo atraviesa, y
> `--env=testing` **no** selecciona la conexión de pruebas: en este proyecto no
> existe un `.env.testing`, así que Laravel sigue leyendo `.env`, la conexión
> por defecto continúa siendo `pgsql` y el comando opera sobre la base de
> **desarrollo**. Ya ha destruido datos dos veces.
>
> Nunca use `migrate:fresh --env=testing`. No es una forma segura de reiniciar
> la base de pruebas; es una forma de borrar la de desarrollo.

Para reconstruir la base de pruebas desde cero, use el único comando que
comprueba a dónde va a conectarse antes de escribir nada:

```bash
./scripts/reset-test-db.sh           # reconstruye la base de pruebas
./scripts/reset-test-db.sh --seed    # y además carga el seeder
```

El script es *fail-closed*: resuelve la configuración de Laravel, exige que
`database.default` sea `testing`, exige que la base resuelta no sea la de
desarrollo, exige el nombre esperado (`DB_TEST_DATABASE`, por defecto
`consultora_dh_test`) y confirma con `current_database()` de PostgreSQL que la
conexión abierta es esa. Si cualquiera de esas comprobaciones falla, se detiene
**antes** de migrar e informa cuál falló, sin imprimir credenciales.

### 8.2 Frontend (Vitest)

```bash
docker compose exec node npm run test
docker compose exec node npm run test -- --reporter=verbose
docker compose exec node npm run test:watch
docker compose exec node npm run test -- --coverage
```

### 8.3 Interfaz (Playwright)

```bash
./scripts/run-e2e.sh                       # escritorio y móvil
./scripts/run-e2e.sh --project=desktop
./scripts/run-e2e.sh tests/e2e/login.spec.ts
```

El script genera dos contraseñas aleatorias, crea dos cuentas temporales,
instala el navegador si hace falta y ejecuta la suite.
**Ninguna credencial se escribe en disco.**

Las dos cuentas son deliberadas: una con rol Super Admin, que es la que usa la
mayoría de los flujos, y otra con rol Read Only, que es la única forma de
comprobar que una escritura sin permiso se rechaza en el servidor y no sólo que
el botón no aparece. También exporta `E2E_STAMP`, un sufijo por ejecución, para
que los registros creados por un flujo no choquen con los de otro dentro de la
base de pruebas end to end.

> **La suite no toca la base de datos de desarrollo.** Corre contra una
> aplicación y una base propias, definidas en `compose.e2e.yaml`:
>
> | qué                | base de datos    | Redis       |
> |--------------------|------------------|-------------|
> | desarrollo         | `consultora_dh`  | db 0 / caché 1 |
> | pruebas unitarias  | `consultora_dh_test` | (ninguno) |
> | pruebas end to end | `consultora_dh_e2e`  | db 2 / caché 3 |
>
> Antes escribía en la base de desarrollo y luego borraba lo suyo. Para
> reconocerlo usaba una coincidencia de subcadena (`LIKE '%1234567%'`) sobre
> números de documento y razones sociales, y además borraba las filas que ya
> coincidieran **antes** de empezar. Un registro legítimo que contuviera esos
> dígitos podía ser borrado por una ejecución de pruebas, sin que ninguna prueba
> hubiera fallado. A03 añade periodos, pagos y registros financieros: es
> justamente el tipo de dato que no debe adivinarse.
>
> Ahora `run-e2e.sh` levanta `app-e2e` y `nginx-e2e` (que **no** publica ningún
> puerto), reinicia `consultora_dh_e2e` con `scripts/reset-e2e-db.sh`, crea las
> cuentas ahí y mide el estado de desarrollo antes y después para demostrar que
> no cambió. No hay ningún paso de borrado de datos de negocio, porque no hay
> nada que borrar.

Para levantar o detener solo el entorno end to end:

```bash
docker compose -f compose.yaml -f compose.e2e.yaml up -d app-e2e nginx-e2e
docker compose -f compose.yaml -f compose.e2e.yaml down
```

Si la base `consultora_dh_e2e` todavía no existe (por ejemplo, en una
instalación anterior a este cambio), créela una sola vez con:

```bash
./scripts/ensure-e2e-db.sh
```

Ese script **sólo** crea esa base si falta. Nunca borra ni recrea la base de
desarrollo, y nunca ejecuta migraciones.

Si la primera ejecución falla por falta de navegador:

```bash
docker compose exec -T --user root node npx playwright install --with-deps chromium
```

El script limpia los contadores de límite de intentos antes de empezar: cinco
intentos por cada cinco minutos es un límite correcto en producción, pero
bloquearía ejecuciones repetidas de la suite.

## 9. Calidad

```bash
docker compose exec app  vendor/bin/pint --test    # estilo PHP
docker compose exec app  vendor/bin/pint           # corregir estilo PHP
docker compose exec node npm run typecheck          # vue-tsc
docker compose exec node npm run lint               # ESLint
docker compose exec node npm run lint:fix
```

Los cuatro deben pasar antes de dar por terminada una tarea.

## 10. Colas

```bash
docker compose logs -f queue                                    # ver el worker
docker compose exec queue php artisan queue:work --stop-when-empty
docker compose exec app php artisan queue:failed
docker compose exec app php artisan queue:retry <id>
docker compose exec app php artisan queue:flush redis
```

Para despachar un trabajo desde código:

```php
Queue::push(new MiTrabajo());   // un objeto, no el nombre de la clase
```

Pasar el nombre de la clase como cadena en lugar del objeto usa la ruta
heredada de Laravel y falla al ejecutarse. Para una prueba puntual:

```bash
docker compose exec app php artisan tinker --execute="Queue::push(new App\Jobs\MiTrabajo);"
```

## 11. Registros (bitácora)

```bash
docker compose logs -f app                        # salida estándar
docker compose exec app tail -f storage/logs/laravel.log
docker compose exec app grep -c ERROR storage/logs/laravel.log
```

El archivo de bitácora rota cuando alcanza 10 MB. Para vaciarlo durante el
desarrollo:

```bash
docker compose exec app bash -c ': > storage/logs/laravel.log'
```

## 12. Consola interactiva

```bash
docker compose exec app php artisan tinker
```

```php
User::query()->get(['name', 'email', 'status']);
AuditEvent::query()->latest('id')->limit(10)->get(['action', 'ip_address', 'created_at']);
DB::select('select version()');
Redis::connection()->ping();
```

## 13. Estado de salud

```bash
docker compose ps                                    # salud de contenedores
curl -s http://localhost:8080/healthz                # nginx, sin PHP
curl -s http://localhost:8080/api/health | head      # base de datos y Redis
curl -s http://localhost:8080/up                     # comprobación de Laravel
```

La comprobación del contenedor `app` usa el `ping` nativo de PHP-FPM, que
responde sin ejecutar PHP ni tocar la base de datos.

## 14. Problemas frecuentes

| Síntoma | Causa y solución |
| --- | --- |
| El `docker` de WSL falla con un error de integración | Ejecute Compose desde el directorio del proyecto con rutas relativas |
| PostgreSQL se reinicia en bucle | El volumen debe montarse en `/var/lib/postgresql`, no en `/var/lib/postgresql/data` (PostgreSQL 18) |
| Los cambios de CSS/JS no se ven | `VITE_USE_POLLING=1` y `docker compose restart node` |
| El navegador pide los módulos a `0.0.0.0:5173` | Falta `server.origin` en `vite.config.ts`; compruebe `public/hot` |
| `419` en todas las peticiones | Falta la cookie CSRF: no se llama a `/sanctum/csrf-cookie` |
| La suite falla con «Refusing to run…» | `DB_TEST_DATABASE` coincide con `DB_DATABASE`; cámbielo en `.env` |
| Un cambio de código PHP no surte efecto | `docker compose restart app` (OPcache) |
| Puerto ocupado | Cambie `APP_PORT` o `VITE_PORT` en `.env` y ejecute `docker compose up -d` |
| `POSTGRES_PASSWORD must be set in .env` | Falta una contraseña obligatoria en `.env`; el mensaje indica cuál |
| `permission denied to create role` | El rol de la aplicación intentó escalar privilegios: el arranque no aplicó el script de inicialización. Reconstruya el volumen de PostgreSQL |
| `400 Bad Request` en el navegador | La cabecera `Host` no está en la lista de `config/security.php`; añádala a `TRUSTED_HOSTS` |
| `503 trusted_hosts_not_configured` | `APP_ENV` no es `local` ni `testing` y `TRUSTED_HOSTS` está vacía. Falla a propósito: defina los dominios reales |
| `400` sólo en un entorno de pruebas remotas | El nombre de servicio de Docker no está en la lista; añádalo a `TRUSTED_HOSTS` como nombre literal |

## 15. Compartir el código para revisión

El archivo que se envía a quien revisa **se genera desde un commit de Git**, con
`scripts/export-review.sh`:

```bash
scripts/export-review.sh                  # genera el ZIP de HEAD
scripts/export-review.sh --verify-only ./consultora-dh-review-<commit>.zip
```

Produce `consultora-dh-review-<commit>.zip` en la raíz del proyecto.

### Por qué `git archive` y no comprimir la carpeta

`git archive` sólo ve lo que está **versionado**. El directorio de trabajo
contiene cosas que no deben salir nunca de la máquina: `storage/logs/laravel.log`
guarda URL de restablecimiento de contraseña con su token, datos de sesión y
salida de diagnóstico; `.env` contiene credenciales reales; `vendor`,
`node_modules` y `public/build` son generados.

Las alternativas fallan:

- comprimir el directorio envía `.env`, registros, dependencias y compilados;
- borrar esos archivos a mano depende de acordarse de todos, y el siguiente
  persona lo hace mal;
- los ZIP hechos a mano se acumulan y nadie sabe cuál corresponde a qué commit.

### Garantías

El script **falla** en lugar de producir algo dudoso:

| Situación | Resultado |
| --- | --- |
| No hay ningún commit | Error: no hay nada que exportar |
| El árbol de trabajo tiene cambios sin versionar | Error: hay que versionarlos primero |
| Hay archivos prohibidos dentro del ZIP | Error y aviso de no compartirlo |

Nunca versiona el árbol sin confirmar, nunca incluye `.env`, `.git`,
`storage/logs`, estado de `storage/framework` (sesiones, caché, vistas),
`storage/app`, `vendor`, `node_modules`, `public/hot`, `public/build`, volcados
de base de datos, archivos `.zip` ni resultados de Playwright. `.env.example` sí
se incluye, con todos los valores vacíos.

Los registros locales **no se borran** para que el archivo salga limpio: no hace
falto, porque no se/archivean.

## 16. Antes de entregar una tarea

```bash
docker compose exec app  vendor/bin/pint --test && \
docker compose exec app  vendor/bin/pest && \
docker compose exec node npm run test && \
docker compose exec node npm run typecheck && \
docker compose exec node npm run lint && \
docker compose exec node npm run build
```

Después: revise `git status`, busque secretos en el diff, y compruebe que la
documentación refleja lo entregado.
