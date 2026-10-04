# Seguridad

Este documento describe los controles implementados en Consultora DH y, al
final, lo que falta hacer antes de publicar la aplicación.

La postura se resume en una frase: **el servidor es la única autoridad.** La
interfaz oculta lo que un usuario no puede abrir, pero cada petición se vuelve
a comprobar.

---

## 1. Autenticación

El modelo es **sesión con cookie**, no tokens en el navegador.

```
Navegador --> GET /sanctum/csrf-cookie --> cookie XSRF-TOKEN
           --> POST /api/auth/login
                cabeceras: X-XSRF-TOKEN
                cuerpo:    { email, password, remember }
           <-- 200 { user: {...} }  +  cookie de sesión (HttpOnly)
```

### 1.1 Por qué no hay tokens en `localStorage`

Un token en `localStorage` es legible por cualquier JavaScript que se ejecute
en la página. Una sola dependencia comprometida, un error de XSS o un fragmento
de terceros filtrado, y el token queda expuesto. La cookie de sesión es
`HttpOnly`: el JavaScript de la página no puede leerla.

| Propiedad | Valor | Motivo |
| --- | --- | --- |
| `HttpOnly` | `true` | El JavaScript no puede leer la cookie |
| `SameSite` | `lax` | No se envía en peticiones iniciadas por terceros |
| `Secure` | `true` en producción | Sólo por HTTPS |
| Regeneración | En el inicio de sesión | Evita el secuestro de sesión |

### 1.2 Propiedades del inicio de sesión

`App\Domain\Auth\Actions\AuthenticateUser` implementa:

- **Normalización de la dirección** a minúsculas antes de buscar y antes de
  guardar, con índice único. `Admin@Ejemplo.com` y `admin@ejemplo.com` no
  pueden ser dos cuentas.
- **Comparación de contraseña** con el hasher del framework (bcrypt, coste 12
  por defecto). No se implementa criptografía propia.
- **Cuentas inactivas rechazadas.** `users.status` es un enum de PHP respaldado
  por una restricción `CHECK` en PostgreSQL.
- **Regeneración del identificador de sesión** tras autenticar, que invalida
  cualquier identificador previo y evita el secuestro de sesión.
- **Registro de `last_login_at`.**
- **Emisión de eventos de dominio**, que alimentan la auditoría.

### 1.3 Resistencia a la enumeración de cuentas

El inicio de sesión devuelve un cuerpo **idéntico** en los tres casos de
rechazo:

| Situación | Respuesta |
| --- | --- |
| Contraseña incorrecta | `422` `Las credenciales proporcionadas no son válidas.` |
| Cuenta inactiva | `422` `Las credenciales proporcionadas no son válidas.` |
| Dirección desconocida | `422` `Las credenciales proporcionadas no son válidas.` |

Además:

- Cuando la dirección no existe se verifica igualmente un hash señuelo, de
  coste equivalente, para que el **tiempo de respuesta** tampoco distinga los
  casos. Sin esto, un atacante podría enumerar cuentas midiendo tiempos.
- La recuperación de contraseña responde igual haya o no una cuenta
  registrada, y registra internamente si existía.

Hay pruebas que verifican que las tres respuestas son idénticas
(`tests/Feature/Auth/LoginTest.php`), porque es una propiedad fácil de romper sin
querer.

### 1.4 Limitación de intentos

Los tres endpoints de autenticación y recuperación **no comparten ningún
contador**. Pedir un enlace de recuperación no es un intento de inicio de sesión,
y no debe poder gastar el presupuesto de inicio de sesión: si lo compartieran,
bastaría con pedir restablecer la contraseña en bucle para impedir que un
administrador se inicie sesión, sin adivinar una sola contraseña.

| Política | Se aplica a | Clave | Valores | Qué detiene |
| --- | --- | --- | --- | --- |
| `login-attempt` | `POST /api/auth/login` | dirección IP | 20 / 5 min | Fuerza bruta desde una sola máquina |
| `login-account` | `POST /api/auth/login` | dirección normalizada | 30 / 15 min | Ataque distribuido contra una cuenta conocida |
| `recovery-attempt` | `POST /api/auth/forgot-password` | dirección IP | 10 / 15 min | Uso del endpoint como amplificador de correo |
| `recovery-account` | `POST /api/auth/forgot-password` | dirección normalizada | 5 / 15 min | Repetición contra una sola dirección |
| `reset-attempt` | `POST /api/auth/reset-password` | dirección IP | 10 / 15 min | Prueba de tokens desde una máquina |
| `reset-account` | `POST /api/auth/reset-password` | dirección normalizada | 5 / 15 min | Prueba de tokens contra una cuenta |

Las dos dimensiones hacen falta porque una sola no sirve:

- Sólo por cuenta: un atacante con una botnet puede probar muchas cuentas sin
  tocar ningún límite.
- Sólo por IP: un atacante puede dispersar una misma cuenta por miles de
  direcciones y nunca agota ningún contador.

**Por qué 30 y no 10 por cuenta.** Con 10, tres reintentos y un compañero
compartiendo la dirección de la oficina bastaban para agotar el presupuesto de
un administrador durante un cuarto de hora: el límite se convertía en un arma
contra el usuario legítimo sin ganancia alguna, porque un atacante que quiere
bloquear una dirección conocida tiene que superar también el límite por IP, y
eso exige varias máquinas. Con 30, un ataque en línea sigue obteniendo 30
contraseñas por cuarto de hora, que no es una vía viable contra una política de
12 caracteres con mayúsculas, minúsculas y números, y el error humano
corriente ya no consume el presupuesto.

El límite por IP se mantiene en 20 por 5 minutos porque es el que un atacante
no puede subir sin moverse de máquina, y por tanto el que trabaja de verdad
contra la fuerza bruta en línea.

La recuperación puede ser **más estricta** que el inicio de sesión, no al revés:
cada petición aceptada cuesta un correo saliente y, en el restablecimiento, un
token. Estos límites se suman al del propio *password broker* de Laravel, que
permite un enlace por cuenta y minuto, de modo que el límite más estrecho sobre
correos repetidos a una misma dirección sigue siendo el del broker.

El límite general de la API (`api`, 120 peticiones por minuto y usuario) se
aplica además a todas las rutas autenticadas, para que una sesión válida no
permita martillear la aplicación. Está declarado en el grupo de middleware de
`routes/api.php`, no sólo definido.

La respuesta `429` incluye la cabecera `Retry-After` y el campo `retry_after` en
el cuerpo, y la interfaz indica cuántos segundos faltan.

## 2. Sesión

- La API se registra con el grupo de middleware `web`, que aporta
  `StartSession` y la verificación CSRF (ver
  [ARCHITECTURE.md](ARCHITECTURE.md#31-la-api-se-registra-con-el-grupo-web)).
- El cierre de sesión **invalida** la sesión y genera un token CSRF nuevo; no
  se limita a vaciarla.
- El middleware `auth.session` (`AuthenticateSession`) compara el hash de
  contraseña guardado en cada sesión con el actual, de modo que un cambio de
  contraseña invalida el resto de sesiones de la cuenta.

## 3. CSRF

- Todas las rutas de escritura (`POST`, `PUT`, `PATCH`, `DELETE`) pasan por la
  verificación de CSRF.
- El cliente pide la cookie `XSRF-TOKEN` a `/sanctum/csrf-cookie` y la reenvía
  en la cabecera `X-XSRF-TOKEN`. Es el mecanismo que Laravel espera y el que
  Sanctum documenta para aplicaciones de una sola página.
- Una petición sin el token recibe `419`, con un mensaje en español y el código
  `session_expired`, sin traza.

## 4. Autorización

- **Roles** (`spatie/laravel-permission`): Super Admin, Administrator,
  Operations, Collections, Support, Read Only.
- **Permisos**: además de `settings.view`, A02 introduce **catorce** permisos de
  negocio en cinco familias:

  | Familia | Permisos |
  | --- | --- |
  | `clients.*` | `view`, `create`, `update`, `change_status` (4) |
  | `companies.*` | `view`, `create`, `update`, `change_status` (4) |
  | `relationships.*` | `view`, `manage` (2) |
  | `affiliations.*` | `view`, `manage` (2) |
  | `social_security_entities.*` | `view`, `manage` (2) |

  No se crean permisos de negocio por adelantado: llegan con su módulo.
- **Los permisos de lectura se aplican por sección, no por pantalla.**
  `clients.view` autoriza leer el cliente: su documento, su nombre, su contacto.
  No autoriza a leer con quién trabaja; para eso está `relationships.view`, que
  gobierna las relaciones y su historial, y `affiliations.view`, que gobierna las
  afiliaciones, su historial y los hallazgos de calidad derivados de ellas, que
  nombran la entidad afectada. Una sección que el rol no puede leer se responde
  como `{"visible": false}` y no como una lista vacía, porque "no puede verlo" y
  "no hay nada" son respuestas distintas y sólo una es cierta. El panel omite las
  cifras de las secciones que el rol no puede leer, en vez de mostrarlas en cero.
  Las pruebas de `ReadPermissionsTest` construyen roles que no existen en el
  sembrador, porque con los seis roles reales ninguna permiso se puede probar de
  forma independiente: todos los que tienen uno tienen también el otro.
- **Un rol sin permiso recibe `403`, no una respuesta vacía.** Los cinco roles se
  prueban endpoint por endpoint en `AuthorizationTest`. Donde la ausencia de
  permiso tiene una forma más honesta que una lista vacía, la API lo dice:
  `GET /api/clients/{id}` devuelve `{"affiliations": {"visible": false}}` para un
  rol sin `affiliations.view`, y el historial de auditoría de la ficha omite los
  eventos de afiliación, que dicen la entidad a la que la persona está afiliada.
- **`db:seed` es la autoridad de la matriz de permisos.** Revoca cualquier
  permiso que no esté en su lista, de modo que el rol por defecto y el código no
  pueden separarse en silencio entre despliegues.
- **La comprobación es del servidor.** `GET /api/settings` exige
  `settings.view`; un rol sin ese permiso recibe `403`, aunque llegue
  directamente a la URL.
- La respuesta de `GET /api/auth/me` incluye los nombres de los permisos del
  usuario para que la interfaz pueda ocultar enlaces. Es información de
  presentación, no autorización.

### 4.1 Convención de nombres

`<dominio>.<acción>`, en minúsculas y separado por un punto:

```
settings.view
clients.view      clients.create      clients.update
payments.register payments.view
periods.close
documents.review
reports.generate
```

Al añadir un módulo, sus permisos se registran en `DatabaseSeeder` y la ruta se
protege con el middleware `can:`.

## 5. Contraseñas

Política implementada con `Illuminate\Validation\Rules\Password`:

- Mínimo **12 caracteres**.
- Combinación de mayúsculas, minúsculas y números.
- Confirmación obligatoria en el alta y en el cambio.
- El cambio de contraseña **no puede reutilizar** la actual.
- El hash lo produce el framework; el código de la aplicación nunca compara ni
  manipula contraseñas.

La política es deliberadamente razonable. Reglas arbitrarias del tipo «una
mayúscula, un símbolo y ninguna palabra de diccionario» empujan a la gente a
anotar la contraseña en un papel, que es peor que una contraseña larga y
predecible.

### 5.1 Cambios sensibles

Cambiar el **correo electrónico** o la **contraseña** exige la contraseña
actual (`current_password`), aunque la sesión ya esté autenticada. Una sesión
robada por sí sola no permite tomar el control de la cuenta.

### 5.2 Invalidación de «recordar esta sesión»

Consultora DH admite inicio de sesión persistente, así que existe un
`remember_token` y puede haber una copia de él en un navegador.

Ese token **se rota** (valor aleatorio nuevo) en los dos casos en que cambia
la contraseña:

- cambio de contraseña desde el perfil;
- restablecimiento de contraseña.

Si el token sobreviviera, quien conserve esa cookie seguiría dentro, que es
justo lo que se pretende impedir al cambiar una contraseña. Rotarlo invalida
todas las copias emitidas sin necesidad de saber dónde están, y usa el
mecanismo que el propio framework proporciona, sin criptografía propia.

### 5.3 Una cuenta inactiva pierde el acceso que ya tenía

Rechazar una cuenta inactiva **al iniciar sesión** no basta. Una sesión abierta
mientras la cuenta estaba activa sigue siendo válida después: suspender a un
administrador no surte efecto hasta que caduca la cookie, que es justo lo
contrario de lo que espera quien lo suspende.

`App\Http\Middleware\EnsureUserIsActive` comprueba el estado **en el servidor**,
en cada petición autenticada, y se aplica como `user.active` en el grupo de
`routes/api.php`. Cuando la cuenta ya no está activa:

- cierra la sesión (`logout`);
- la invalida y renueva el token CSRF;
- responde **401** con el cuerpo habitual de "no autenticado", sin explicar por
  qué, para no confirmar que la cuenta existe pero está suspendida;
- registra **una** fila de auditoría, `auth.session.rejected_inactive`.

Una sola fila por transición: al invalidar la sesión desaparece el usuario, así
que las peticiones siguientes ya no tienen a nadie que registrar. El valor
`user.status` que la interfaz tiene en memoria es sólo presentación y nunca se
consulta para decidir.

Reactivar la cuenta **no** restituye la sesión anterior: el acceso se cortó, no
se pausó, y hace falta un inicio de sesión nuevo.

### 5.4 Verificación del correo al cambiar la dirección

`users.email_verified_at` se pone a `null` cuando la cuenta cambia a una
dirección **distinta**, y se conserva cuando la dirección no cambia de verdad.

A01 no exige verificación de correo, así que esto no cambia nada visible hoy.
Lo que evita es que la columna afirme haber verificado una dirección que nadie
comprobó, por si la verificación se activa más adelante. La comparación se hace
sobre el valor ya normalizado, porque `User::email()` pasa a minúsculas y recorta
al asignar: volver a guardar la misma dirección con otra capitalización no es un
cambio de dirección.

## 6. Auditoría

### 6.1 Qué se registra

| Acción | Cuándo |
| --- | --- |
| `auth.login.succeeded` | Inicio de sesión correcto |
| `auth.login.failed` | Credenciales rechazadas (dirección conocida o no) |
| `auth.login.rejected_inactive` | Credenciales correctas en una cuenta inactiva |
| `auth.session.rejected_inactive` | Petición de una sesión abierta antes de que la cuenta se volviera inactiva |
| `auth.logout` | Cierre de sesión |
| `auth.password.reset_requested` | Recuperación solicitada |
| `auth.password.reset_completed` | Contraseña restablecida |
| `profile.updated` | Cambio del nombre visible |
| `profile.email_changed` | Cambio del correo (direcciones anterior y nueva) |
| `profile.password_changed` | Cambio de contraseña |
| `admin.created` | Alta de administrador |
| `client.created` · `client.updated` | Alta y edición del cliente |
| `client.activated` · `client.deactivated` | Cambio de estado del cliente |
| `company.created` · `company.updated` | Alta y edición de la empresa |
| `company.activated` · `company.deactivated` | Cambio de estado de la empresa |
| `relationship.created` · `relationship.closed` | Alta y cierre de un vínculo |
| `relationship.transferred` | Transferencia a otra empresa |
| `relationship.parallel_authorized` | Segunda relación abierta, con justificación |
| `affiliation.created` · `affiliation.closed` | Alta y cierre de una afiliación |
| `affiliation.changed` | Cambio de entidad, con la entidad anterior y la nueva |
| `social_security_entity.created` · `.updated` · `.deactivated` | Catálogo |

### 6.2 Campos

`audit_events` guarda `user_id` (nulo cuando no hay cuenta atribuible),
`action`, `ip_address`, `user_agent`, `metadata` (JSONB), `created_at` y, desde
A02, `subject_type` y `subject_id`. Los dos últimos son lo que permite resolver
el historial de una ficha con una consulta, en lugar de con un registro de
cambios escrito a mano y mantenido a mano.

La tabla es de sólo inserciones: el modelo rechaza actualizaciones y borrados.

**Los metadatos guardan identificadores, no copias del registro.** El alta de un
cliente guarda su documento, que es la identidad que después habrá que
reconocer, y no sus nombres: el registro ya está enlazado por `subject_id`, y una
segunda copia de un nombre en una tabla de sólo inserciones son datos personales
sin propósito operativo que además se desincroniza del original. Una prueba lo
verifica. La razón social de una empresa sí se guarda, porque una empresa no es
una persona.

### 6.3 Qué no se registra nunca

Contraseñas, hashes de contraseña, cookies, tokens CSRF, identificadores de
sesión y secretos de API. `App\Domain\Audit\MetadataScrubber` sustituye por
`[redactado]` cualquier clave que contenga `password`, `secret`, `token`,
`cookie`, `authorization`, `api_key`, `session_id` y similares, en cualquier
profundidad y con un límite de anidamiento.

Es una **segunda** barrera: los eventos de dominio ya son cuidadosos y el saneo
protege frente a un olvido puntual. Una prueba lo verifica insertando
deliberadamente una clave `current_password` y comprobando que queda redactada.

Si la auditoría falla, el error se registra y la petición continúa: la
auditoría nunca debe ser el motivo de que alguien no pueda trabajar.

## 7. Cabeceras de seguridad

Se aplican en dos capas: `docker/nginx/default.conf` para los recursos
estáticos y `App\Http\Middleware\SecurityHeaders` para las respuestas dinámicas
(HTML y JSON).

| Cabecera | Valor | Motivo |
| --- | --- | --- |
| `X-Content-Type-Options` | `nosniff` | Evita que el navegador interprete un archivo como otro tipo |
| `X-Frame-Options` | `DENY` | Impide el *clickjacking* |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | No filtra rutas internas a terceros |
| `Permissions-Policy` | cámara, micrófono, geolocalización y similares desactivados | Reduce la superficie de la API del navegador |
| `Cross-Origin-Opener-Policy` | `same-origin` | Aísla el contexto de navegación |

### 7.1 HSTS

**No se activa en desarrollo.** Una vez enviado, HSTS obliga al navegador a no
intentar HTTP para ese origen durante el `max-age` indicado, y esa decisión no
se puede revocar desde el servidor. Activarlo sin HTTPS.prepare un corte que dura `max-age`.

Como activar:

```
Strict-Transport-Security: max-age=31536000; includeSubDomains
```

Sólo cuando HTTPS esté terminado y probado. Empiece con un `max-age` corto
(por ejemplo `300`) y aumentarlo cuando confirme que todo funciona.

### 7.2 CSP

`Content-Security-Policy` está **desactivada por defecto**
(`CSP_ENABLED=false`) y se define en `config/security.php`.

No se activa en desarrollo porque el servidor de Vite necesita `eval` para la
recarga en caliente, sirve los módulos desde otro origen e inyecta scripts en
línea. Una CSP restrictiva rompería el desarrollo; una CSP permisiva no aporta
seguridad.

En producción se activa con una política que **no necesita `unsafe-inline` ni
`unsafe-eval`**, porque todos los recursos están autoalojados:

```
default-src 'self'; base-uri 'self'; form-action 'self';
frame-ancestors 'none'; object-src 'none';
script-src 'self'; style-src 'self';
img-src 'self' data:; font-src 'self' data:; connect-src 'self'
```

### 7.3 Proxy inverso

En producción, si hay un proxy o balanceador delante, hay que:

- Reenviar `X-Forwarded-For`, `X-Forwarded-Proto` y `X-Forwarded-Host`.
- Configurar `TrustProxies` con los **rangos de IP reales** del proxy. Usar
  `*` permitiría a un cliente falseear su dirección y con ella la auditoría y
  el límite de intentos.
- Pasar `HTTPS=on` para que Laravel emita cookies seguras.

### 7.4 Endpoint público de salud

`GET /api/health` es público, porque lo usan los monitorizadores, de modo que
sólo expone lo imprescindible:

```json
{ "status": "ok", "checks": { "database": true, "redis": true } }
```

No informa versiones de software, ni nombres de host o de contenedor, ni
cadenas de conexión. Las versiones exactas de PostgreSQL y Redis siguen
disponibles para un administrador autenticado en el panel de Inicio, donde la
audiencia ya ha sido autorizada. Hay una prueba que falla si aparece cualquier
otro dato en la respuesta.

## 8. Errores

- Con `APP_DEBUG=false`, una excepción no revela traza, archivo ni línea. La
  interfaz recibe «Se ha producido un error inesperado» y el detalle va a la
  bitácora.
- Todos los mensajes de la API están escritos para un usuario en español. El
  diagnóstico técnico pertenece a los registros.
- Las respuestas JSON llevan un `code` estable (`unauthenticated`,
  `forbidden`, `not_found`, `too_many_requests`, `session_expired`,
  `server_error`) para que la interfaz decida sin analizar textos.

## 9. Secretos

- `APP_KEY`, las contraseñas de PostgreSQL y de Redis **nunca** se imprimen, ni
  en el log, ni en la consola, ni en la respuesta de la API. Hay pruebas que
  fallan si alguno aparece.
- `.env` está en `.gitignore` y no se versiona. `.env.example` contiene los
  valores **vacíos**: `composer validate` y una prueba comprueban que no se ha
  colado un secreto real.
- `REDIS_PASSWORD` es **obligatoria en desarrollo y en producción**. Docker
  levanta Redis con `--requirepass` y `compose.yaml` se niega a arrancar si la
  variable está vacía. Una redacción anterior de `.env.example` sugería que
  podía quedar vacía en desarrollo, lo que contradecía al comportamiento real;
  ahora la documentación y la ejecución dicen lo mismo.

### 8.1 Datos que no se tratan como secretos, y datos que sí

Un correo de contacto **no** es una identidad y por eso no tiene índice único:
varias personas pueden compartir una dirección, y lo mismo varias empresas con un
mismo correo de contabilidad. Lo que sí es una identidad, y por lo tanto único,
son el documento del cliente y el número del NIT.

El NIT se guarda en dos columnas, `tax_id` (el número, `900123456`) y
`verification_digit` (el dígito, `3`), porque la DIAN los trata como valores
distintos. La unicidad recae sobre el número base, y el dígito **nunca se
calcula**: un valor histórico dudoso se conserva tal como llegó y se reporta como
duda. Un cálculo sin vectores de prueba de una fuente oficial no es ayuda, es una
confianza falsa.

Las cinco clases de riesgo de ARL son ordinales y la quinta es la **máxima**, no una
ausencia de clasificación: I mínimo, II bajo, III medio, IV alto, V máximo. Lo que
no se conoce es `NULL`. Confundir las dos cosas hacía que un trabajador en riesgo
máximo apareciera como si nadie supiera en qué clase está, y por eso las etiquetas
las envía el enum del dominio en lugar de una lista que la interfaz mantenía aparte.

### 9.1 El comando `create-admin` no acepta contraseñas

`consultora-dh:create-admin` **no tiene la opción `--password`**. No está
desactivada: no existe, y Symfony rechaza el argumento antes de que el comando
se ejecute.

Una contraseña pasada como argumento queda en el historial del shell, aparece en
`ps` para cualquier usuario de la máquina y en el log de la herramienta que
ejecute el comando. Los dos mecanismos admitidos no tienen ese problema:

| Situación | Cómo |
| --- | --- |
| Interactivo | `php artisan consultora-dh:create-admin` y el prompt oculto `secret()` |
| Automatizado | `CONSULTORA_DH_ADMIN_PASSWORD` y `CONSULTORA_DH_ADMIN_PASSWORD_CONFIRMATION` |

Las dos variables de entorno se leen como un par: si no coinciden, el comando
falla. `scripts/run-e2e.sh` las usa para aprovisionar su cuenta temporal.

Las opciones no sensibles que siguen existiendo son `--name`, `--email` y
`--role`. La contraseña se cifra de inmediato y no vuelve a mostrarse.

## 10. Base de datos, Redis y red

### 10.1 Dos roles de PostgreSQL, con propósitos separados

| Rol | Origen | ¿Superusuario? | ¿Quién lo usa? |
| --- | --- | --- | --- |
| `postgres` | `POSTGRES_USER` | **Sí** | Únicamente `docker/postgres/init/`, al crear las bases de datos y conceder privilegios. **Nunca Laravel.** |
| `consultora_dh_app` | `DB_USERNAME` / `DB_PASSWORD` | **No** | Laravel, incluidas las migraciones. |

El rol de la aplicación se crea en el script de inicialización con
`NOSUPERUSER NOCREATEDB NOCREATEROLE` y es el **propietario** de las bases de
datos `consultora_dh` y `consultora_dh_test`. Ser propietario es exactamente lo
que necesitan las migraciones (crear tablas, alterarlas, crear índices, insertar
semillas) y nada más.

Comprobación:

```sql
SELECT rolname, rolsuper, rolcreatedb, rolcreaterole FROM pg_roles
 WHERE rolname IN ('postgres', 'consultora_dh_app');

SELECT datname, pg_get_userbyid(datdba) AS owner FROM pg_database
 WHERE datname LIKE 'consultora_dh%';
```

Una aplicación que corre como superusuario puede leer cualquier base de datos
del servidor, saltarse restricciones y crear roles. Con esta separación, un
inyección de SQL que llegase a ejecutarse sólo alcanzaría los datos de
Consultora DH.

El script es idempotente: puede ejecutarse de nuevo sin duplicar nada.

### 10.2 Redis y red

- **No se publican al host.** Los servicios `postgres` y `redis` no declaran
  `ports:`: son accesibles sólo desde la red interna de Docker.
- Redis exige contraseña (`REDIS_PASSWORD`). Sin ella, cualquiera que alcance el
  puerto leería las sesiones de todos los usuarios.
- `security.limit_extensions = .php` en PHP-FPM: sólo se ejecutan scripts PHP.
- nginx responde `deny all` a los archivos ocultos, de modo que `.env` y `.git`
  nunca se sirven aunque estuvieran dentro de `public/`.
- El pool de PHP-FPM se ejecuta como `www-data`, nunca como `root`.
- Los puertos publicados de la aplicación y de Vite se enlazan a `127.0.0.1`, de
  modo que no quedan expuestos a la red local.

### 10.3 Cabeceras de confianza (`Host`)

El flujo de recuperación de contraseña construye URL absolutas a partir de la
petición. Una cabecera `Host` falsificada haría que ese enlace apuntara a un
servidor controlado por el atacante, que es la forma habitual de robar un
enlace de restablecimiento.

`App\Http\Middleware\TrustHosts` (una subclase del `TrustHosts` del framework)
rechaza con **400** cualquier host que no esté en la lista de
`config/security.php`, y con **503** si la lista está vacía.

#### Entornos local y testing

Se aceptan únicamente los hosts que el desarrollo local necesita de verdad:

| Host | Motivo |
| --- | --- |
| `localhost` | Navegador y `php artisan serve` |
| `127.0.0.1` | Navegador |
| `[::1]` | Loopback IPv6 |
| `nginx` | Nombre de servicio, usado por las pruebas de extremo a extremo |
| `app` | Nombre de servicio, usado por las pruebas de extremo a extremo |

El puerto no forma parte del patrón: Symfony lo elimina de la cabecera antes de
comparar, de modo que `localhost:8080` se compara como `localhost`.

#### Producción y cualquier otro entorno

`staging`, `ci` o un entorno vacío se comportan **igual que producción**: sin
`TRUSTED_HOSTS` no se atiende ninguna petición.

```dotenv
TRUSTED_HOSTS=portal.consultoradh.com,www.consultoradh.com
```

`TRUSTED_HOSTS` contiene **nombres de dominio literales**, no expresiones
regulares. Cada entrada se escapa con `preg_quote()` y se ancla, de modo que el
ejemplo anterior produce exactamente:

```
^portal\.consultoradh\.com$
^www\.consultoradh\.com$
```

Los puntos son literales: `portal.consultoradh.com` no coincide con
`portalXconsultoradhXcom`.

Esto no es sólo una convención. Symfony reconoce los patrones con la forma
`^escapado$` y los resuelve **con una tabla hash, sin pasar por el motor de
expresiones regulares**. Una configuración que produce esos patrones no llega
al motor, y por eso una entrada como `.*`, `^`, `a|b` o `[a-z]+.io` nunca se
convierte en patrón: no es un nombre de dominio, así que se descarta y se
informa en el log. **Un valor del entorno no puede inyectar sintaxis de
expresión regular.**

Los subdominios con comodín **no** se admiten en esta variable. Si algún día
hacen falta, deben llegar por una opción separada y explícita, nunca
debilitando esta.

#### Fallo cerrado

Si `APP_ENV` no es `local` ni `testing` y `TRUSTED_HOSTS` no produce ningún host
válido, `App\Http\Middleware\TrustHosts` lanza
`TrustedHostsNotConfigured` y la aplicación responde **503** a todo, con el
código `trusted_hosts_not_configured`. El motivo exacto queda en el log; al
cliente sólo se le dice que la aplicación no puede atender solicitudes.

La alternativa, seguir aceptando los hosts de desarrollo en producción, es
exactamente el fallo que este control existe para evitar.

El middleware se activa **también en desarrollo**, a diferencia del
comportamiento por defecto del framework (que lo desactiva cuando el entorno es
`local`). Un control de seguridad que sólo existe en producción es un control
que nadie prueba hasta que ya no se puede cambiar.

## 11. Validación de entrada

- Toda entrada pasa por un **Form Request** con reglas explícitas.
- Todas las consultas se construyen con el constructor de Eloquent o
  parametrizadas por PDO. No hay SQL escrito a mano ni valores concatenados.
- La salida se escapa con la interpolación de Blade y de Vue. Vue escapa el
  contenido por defecto y no se usa `v-html` en ninguna parte.
- Los archivos ocultos y las rutas de descarga se validan contra una
  expresión regular antes de tocarse.

## 12. Dependencias

Fijadas a versiones exactas en `composer.lock` y `package-lock.json`, y
revisadas durante A01: `composer` no reporta avisos de seguridad. El proyecto
no usa servicios de pago ni recursos de terceros por CDN.

### 12.1 Almacenamiento de archivos

El disco `local` tiene `'serve' => false`. Con el valor por defecto de Laravel
(`true`), el framework registra una ruta `storage/{path}` **con un manejador
PUT**: una vía de escritura ajena al flujo de sesión y, por tanto, fuera de la
protección CSRF que sí cubren las siete rutas de escritura de la aplicación.

A01 no almacena archivos subidos por el usuario, de modo que la ruta no se usa.
La entrega de documentos en A07 será una acción de controlador explícita, con
autorización y registro en la bitácora.

## 13. Datos financieros

A03 introduce las primeras cantidades de dinero del sistema, y con ellas dos
preguntas que antes no había que responder.

### Qué se registra y qué no

Un pago tiene una **referencia**, que puede ser el número de un comprobante
bancario. Esa referencia sí se guarda —es el dato que permite conciliar— pero no
se escribe en la bitácora: la auditoría de pagos registra el identificador, el
importe y el estado, nunca la referencia ni las notas.

Los metadatos de auditoría de los eventos financieros llevan **identificadores y
cantidades**: `client_id`, `company_id`, `period_id`, `delta_cop`,
`amount_cop`. No llevan nombres, documentos, correos ni direcciones. La pregunta
que resuelve ese límite es «responder quién y cuánto», y un nombre no hace falta
para contestarla; quien lee la auditoría es alguien que no debería estar
leyendo el padrón de clientes.

### El límite de permiso por separado de la lectura

El total por cobrar es el número más sensible del sistema, así que `receivables.view`
no se comparte con ninguna otra capacidad y `obligations.generate` es un permiso
distinto de `obligations.adjust`. Collections puede registrar y aplicar un pago y
no puede decidir cuánto se factura; Operations puede decidir y no puede recibir
dinero. Esa separación se prueba, no se documenta solamente: el flujo H registra un
pago con Collections y verifica que la generación, la modificación de un valor,
una fecha de corte, el cierre y la reapertura responden `403`.

Las cifras de la cartera tampoco se filtran a través de conteos. El panel muestra
la sección financiera únicamente cuando el rol puede leer el portafolio, y el
vocabulario de la cartera responde `403` sin él.

Y lo que sí se corrigió en A03-R1 es que **impartir** tampoco arrastra un permiso
que no hace falta. Las deudas que un pago puede aplicar se leen por un endpoint
propio de pagos —`GET /api/payments/clients/{client}/allocatable`, bajo
`payments.allocate`— en vez de por la cuenta del cliente, que exige
`receivables.view`. Un rol de cobranzas sin `receivables.view` podía así usar el
botón que el propio sistema le concedía, y uno con `receivables.view` sin
`payments.allocate` no puede mover dinero. Lo mismo ocurre al revés en la pantalla
de configuración: `/settings/billing` contiene dos dominios permisos por separado
y cada mitad se carga, se dibuja y se ofrece solo a quien puede leerla, de modo que
un rol con `rates.view` recibe un `403` por las fechas de corte al abrir la página
y además ve «Sin fechas de corte» sobre reglas que existen.

La interfaz no es el control, y las pruebas de sonda lo dicen explícitamente: cada
caso de §55 comprueba tres cosas en el cliente —ninguna llamada de fondo a un
endpoint que el permiso no cubre, ninguna cifra financiera que el servidor retuvo
y ningún `RouterLink` a una ruta que el guard negaría— y la misma matriz se
comprueba contra la capa HTTP real en `tests/Feature/A03/PermissionBoundaryTest.php`,
con los mismos usuarios mínimos y la interfaz por completo.

### El límite general por peticiones

Toda ruta autenticada de la API pasa por el limitador `api`, **120 peticiones por
minuto y por cuenta**. Ese número es el valor por defecto en el código y el que
documenta `.env.example`.

El entorno de pruebas de extremo a extremo lo sube a 2000 **sólo en
`compose.e2e.yaml`**, porque los flujos de A03 recorren un mes financiero completo
y eso son cientos de peticiones en un par de minutos. El valor por defecto de
producción y el del desarrollo siguen siendo 120: ninguno de los dos fija la
variable. La suite fija esa cifra en una prueba, para que no pueda convertirse en
el valor normal por accidente.

## 14. Registro (bitácora)

- La bitácora vive en `storage/logs/laravel.log` y en la salida estándar de los
  contenedores.
- **No se registran contraseñas, tokens ni cuerpos sensibles.** Laravel no
  registra el cuerpo de las peticiones, y el saneo de la auditoría cubre los
  metadatos que sí se persisten.
- En producción conviene `LOG_LEVEL=info` y rotar el archivo, además de enviar
  los errores a un colector central.
- El `user_agent` se trunca a 512 caracteres: lo controla el cliente y una
  cadena sin límite sería una vía para inflar la base de datos.
- **La única credencial que sí llega a la bitácora es el correo de restablecimiento
  de contraseña, y sólo porque el entorno local usa `MAIL_MAILER=log`.** Laravel
  escribe el mensaje completo en `laravel.log` en lugar de enviarlo, y ese mensaje
  lleva el enlace con el token. En producción el valor es un SMTP real, así que no
  ocurre; mientras tanto, quien tenga acceso al archivo de bitácora de esta máquina
  puede restablecer la contraseña de esa cuenta local. Por eso `storage/logs/` está
  fuera del repositorio, y por eso hay que borrarlo —o al menos `truncarlo`— antes de
  compartir el archivo de una sesión de desarrollo.

## 15. Antes de publicar: lista de verificación

- [ ] `APP_ENV=production`
- [ ] **`APP_DEBUG=false`** — con la depuración activa se muestran trazas
- [ ] `APP_URL` con el dominio real y `SESSION_SECURE_COOKIE=true`
- [ ] **`TRUSTED_HOSTS` con los dominios reales, separados por comas**
      (cabecera `Host` falsificada). Sin esta variable la aplicación
      responde **503 a todo**: falla cerrada en lugar de admitir
      `localhost`. Los dominios con comodín no valen aquí.
- [ ] HTTPS terminado en el proxy inverso
- [ ] HSTS activo, empezando con un `max-age` corto
- [ ] `CSP_ENABLED=true` una vez compilados los recursos
- [ ] `TrustProxies` con los rangos reales del proxy
- [ ] `DB_PASSWORD` y `POSTGRES_PASSWORD` distintas, y **`REDIS_PASSWORD`
      definida** (obligatoria también en desarrollo)
- [ ] `MAIL_MAILER` con un SMTP real (el remitente en bitácora no envía nada)
- [ ] `php artisan config:cache` y `php artisan route:cache` tras desplegar
- [ ] Copias de seguridad automáticas de PostgreSQL, y prueba de restauración
- [ ] `npm run build` ejecutado: nginx sirve `public/build`, no el servidor de
      Vite
- [ ] Revisión de `composer audit` y `npm audit`
