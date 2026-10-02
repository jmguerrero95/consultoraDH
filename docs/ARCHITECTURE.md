# Arquitectura

## 1. Principio rector: monolito modular

Consultora DH es **un único despliegue** que se ejecuta en un solo servidor. No
hay microservicios, ni cola de mensajes entre procesos de negocio, ni
descubrimiento de servicios.

El motivo es práctico: la aplicación la mantiene y opera un equipo pequeño, y
el coste operativo de varios servicios (red, despliegue, observabilidad,
consistencia distribuida) no compensaría el beneficio a esta escala. Un
monolito bien organizado se lee, se prueba y se despliega más rápido.

"Modular" significa que el código **sí** está separado por dominio, y que esa
separación se respeta:

- Cada módulo de negocio (clientes, afiliaciones, pagos, documentos…) tendrá su
  propia carpeta en `app/Domain/`, con sus modelos, acciones, eventos,
  escuchas, políticas y pruebas.
- Los módulos se comunican por **contratos**: eventos de dominio, servicios de
  aplicación y policies. No se comparten tablas de forma implícita ni se
  escriben modelos de otro módulo.
- Una dependencia entre dominios debe ser deliberada y visible. Si dos módulos
  necesitan lo mismo, eso suele indicar que falta un concepto compartido.

La modularidad se pierde en cuanto el código deja de cumplir estas reglas, de
ahí la insistencia en los límites. Cuando en el futuro haga falta separar un
proceso, un dominio con contrato propio es mucho más fácil de extraer que una
base de código monolítica sin costuras.

## 2. Componentes

```
                      Navegador (Windows)
                              │
                  http://localhost:8080  (host)
                              │
                     ┌────────▼────────┐
                     │      nginx      │  TLS, cabeceras, estáticos
                     └────────┬────────┘
                              │ FastCGI (red interna)
                     ┌────────▼────────┐
                     │  app (PHP-FPM)  │  Laravel 13 · API + shell HTML
                     └───┬────────┬────┘
                         │        │
          ┌──────────────▼──┐  ┌──▼───────────────┐
          │    postgres     │  │      redis        │
          │  PostgreSQL 18  │  │ caché · sesiones  │
          │  Volumen propio │  │ colas · limiters  │
          └─────────────────┘  └──────────────────┘
                              │
                     ┌────────▼────────┐
                     │  node (Vite)    │  solo en desarrollo
                     └─────────────────┘
                              │
                     ┌────────▼────────┐
                     │      queue      │  consumidor de trabajos
                     └─────────────────┘
```

| Servicio | Función | Puerto en el host |
| --- | --- | --- |
| `nginx` | Punto de entrada: estáticos, cabeceras de seguridad, `index.php` | `8080` |
| `app` | Aplicación Laravel (API JSON y shell HTML) | ninguno |
| `queue` | Consumidor de trabajos en cola | ninguno |
| `postgres` | Base de datos | ninguno (solo red interna) |
| `redis` | Caché, sesiones, colas y contadores de límite de intentos | ninguno |
| `node` | Servidor de desarrollo de Vite | `5173` |

Los detalles operativos están en [DEVELOPMENT.md](DEVELOPMENT.md).

## 3. Backend y frontend: mismo repositorio, separación estricta

El backend y el frontend viven en el mismo repositorio pero **no se mezclan**:

- `app/`, `config/`, `routes/`, `database/`, `resources/views/` son PHP.
- `resources/js/` y `resources/css/` son TypeScript y CSS.
- `resources/views/app.blade.php` es el único punto de contacto: un shell HTML
  de 30 líneas que monta la aplicación Vue.

El shell **no contiene datos del usuario**. No hay plantilla Blade por
pantalla, no hay tokens embebidos, no hay estado en el HTML. El frontend
resuelve la sesión llamando a `GET /api/auth/me`, y el router de Vue decide
qué pantalla mostrar.

Consecuencias prácticas:

- Un acceso directo a `/profile` funciona: el servidor devuelve el shell y la
  interfaz resuelve la ruta.
- Una sesión expirada la maneja la interfaz, no un ciclo de redirecciones del
  servidor.
- El shell se puede cachear sin riesgo.

### 3.1 La API se registra con el grupo `web`

Este es un detalle importante y deliberado. En `bootstrap/app.php`, las rutas
de `routes/api.php` se registran con el middleware `web` y el prefijo `/api`:

```php
Route::middleware('web')->prefix('api')->group(base_path('routes/api.php'));
```

El grupo `api` de Laravel es **sin estado**: no inicia sesión y no verifica
CSRF, porque está pensado para clientes que llevan un token. Consultora DH usa
una aplicación de una sola página servida por el mismo origen, con la sesión en
una cookie `HttpOnly`, que es el modelo de la *[stateless SPA][1]* de Sanctum
con sesión. `web` es el grupo que aporta `StartSession` y `VerifyCsrfToken`, que
es exactamente lo que se necesita.

### 3.2 Por qué un cliente `fetch` y no Axios

`resources/js/services/http.ts` implementa el cliente HTTP sobre el `fetch` de
la plataforma. Se eligió así por tres razones:

1. **Un solo lugar** donde se resuelve la cookie CSRF, `credentials` y la
   normalización de errores.
2. Sin dependencias de terceros que actualizar.
3. El paquete resultante es pequeño: el JavaScript de la aplicación ocupa unos
   21 kB comprimidos.

## 4. Capas del backend

```
routes/            Declara rutas y middleware. Sin lógica de negocio.
  │
Http/              Controladores finos: validan el tipo de la petición y
  │                delegan. Form Requests para validar. Resources para serializar.
  │
Domain/            Acciones y eventos. Aquí vive la lógica: no depende de HTTP.
  │                Un evento de dominio no sabe qué es una petición.
  │
Models/            Eloquent. Persistencia y relaciones.
  │
Policies/Gates    Autorización. Se evalúa en el servidor, nunca en el cliente.
```

Reglas que el código respeta:

- **Los controladores no contienen lógica de negocio.** Traducen HTTP a una
  llamada de dominio y la respuesta a JSON.
- **La validación vive en los Form Requests**, con mensajes en español, y no
  en el controlador.
- **La autorización se comprueba en el servidor.** La interfaz oculta un enlace
  que el usuario no puede abrir, pero eso es presentación: la ruta vuelve a
  comprobarlo.
- **No hay una capa de repositorios sobre Eloquent.** Un repositorio que solo
  reescribe `Model::query()` añade indirección sin valor. Cuando un módulo
  necesite una abstracción real (por ejemplo, integraciones con sistemas
  externos), se justifica en ese momento y con pruebas.
- **Los eventos de dominio no conocen la auditoría.** Una acción de dominio
  emite un evento; una única escuchas decide qué eventos se auditan
  (`App\Domain\Audit\Listeners\RecordAuditEvent`). Añadir una acción auditable
  es escribir una clase de evento, nada más.

## 5. A01: estructura de dominios

Durante A01 existen estos dominios, que son los que necesitan los módulos
posteriores:

| Dominio | Responsabilidad |
| --- | --- |
| `Domain/Auth` | Inicio y cierre de sesión, recuperación de contraseña |
| `Domain/Audit` | Registro de auditoría, con saneado de metadatos |
| `Domain/Users` | Estado de la cuenta y creación de administradores |
| `Profile` (en `Domain/Profile`) | Cambio de nombre, correo y contraseña |

`Domain/Auth/Actions/AuthenticateUser` es el ejemplo canónico. Concentra en un
lugar todo lo que hace segura una autenticación: normalización de la dirección,
comparación de contraseña con Constant-time, rechazo de cuentas inactivas,
regeneración del identificador de sesión, registro del último acceso y emisión
de eventos. El controlador solo traduce la petición.

## 6. A02: estructura de dominios

A02 añade cuatro dominios más a los de A01:

| Dominio | Responsabilidad |
| --- | --- |
| `Domain/Clients` | Alta, edición y estado del cliente; documento y contacto |
| `Domain/Companies` | Alta, edición y estado de la empresa; NIT |
| `Domain/Affiliations` | Relaciones históricas con empresas y afiliaciones |
| `Domain/DataQuality` | Comprobaciones del portafolio y sus contadores |
| `Domain/Shared` | `RecordStatus`, que comparten los tres tipos de registro |

La forma que acabó teniendo:

```
app/Domain/Affiliations/
├── ManageClientCompanies.php     vincular, cerrar, transferir
├── ManageAffiliations.php        registrar, cerrar, cambiar de entidad
├── ArlRiskClass.php              nivel de riesgo y sus etiquetas
└── ParallelRelationshipNotAllowed.php

app/Domain/DataQuality/
├── DataQualityCode.php           los nueve códigos y su severidad
├── DataQualityFinding.php
├── DataQualityInspector.php      las comprobaciones
├── DataQualitySeverity.php
└── PortfolioMetrics.php          los contadores del panel
```

Criterios que sí se aplicaron, y por qué:

- **Los modelos viven en `app/Models`, no en el módulo que los posee.** Se
  consideró lo contrario y se descartó: `Client`, `Company`, `User` y
  `AuditEvent` se consultan desde peticiones, recursos y relaciones de todo el
  sistema, así que un modelo encerrado en el módulo obliga a importar el módulo
  entero para obtenerlo. El módulo conserva lo que no es modelo: acciones,
  eventos, excepciones y enums.
- **Una acción por operación de negocio.** `ManageClientCompanies` concentra
  vincular, cerrar y transferir, que comparten bloqueo de fila y reglas de
  fechas; un método de modelo repetiría el bloque tres veces.
- **Cada regla que puede romperse tiene su propia excepción.** Además del
  mensaje para la persona, `ParallelRelationshipNotAllowed` lleva los datos que
  la interfaz necesita para ofrecer sus tres salidas. Un `422` con texto no
  alcanza para eso.
- **La autorización se comprueba por permiso y por rol, con pruebas para cada
  combinación.** Las rutas declaran el permiso; `AuthorizationTest` recorre los
  cinco roles.

### 6.1 Las fechas de un periodo

Los dos campos de fecha significan una cosa y sólo una:

```
[started_on, ended_on)
```

`started_on` es el primer día en que el registro es efectivo. `ended_on` es el
primer día en que **deja** de serlo, y por eso queda fuera del periodo. `NULL`
significa que todavía no ha terminado.

Un traspaso efectivo el 1 de marzo escribe esa misma fecha en las dos filas: el
fin de la anterior y el inicio de la nueva. El 28 defebrero responde la empresa
anterior, el 1 de marzo la nueva, y no hay ningún día que pertenezca a las dos ni a
ninguna. La convención inclusiva, `[inicio, fin]`, obligaría a escribir el cierre
el día anterior y repartir esa resta entre todos los que llaman al dominio; una de
esas cuentas acabaría mal.

La regla vive en `App\Domain\Shared\EffectivePeriod`, la implementa el ámbito
`activeOn()` de las dos tablas, y hay pruebas a ambos lados de la costura. A03 va a
informar sobre periodos y tiene que heredar esto en vez de inventar su propia
aritmética.

### 6.2 Quién bloquea, y qué bloquea

El bloqueo de una operación que cambia relaciones es **la fila del cliente**, dentro
de la transacción, antes de leer nada.

Bloquear las relaciones abiertas no sirve para serializar dos primeras relaciones
simultáneas: cada transacción encuentra cero filas y por tanto bloquea cero filas, y
las dos insertan. La fila maestra siempre existe, así que siempre hay algo con lo
que contenderse. Es la misma razón por la que un "existe? entonces inserto" escrito
en PHP es una carrera y un índice único no lo es.

El orden importa: primero el bloqueo, después la lectura de las relaciones. Al revés,
la lectura que decide se hace fuera del bloqueo y no sirve de nada.

Y vale igual para todo lo que depende del estado del cliente: vincular, cerrar,
trasladar, cambiar de estado y también registrar o cambiar una afiliación, porque
esa última abre un periodo nuevo. Todas toman el cliente y **vuelven a leerlo**; la
copia que tenía el llamador puede haberse quedado vieja mientras esperaba, y decidir
con ella es decidir con información caducada.

El orden completo es siempre **cliente, después historial**. Dos transacciones que
tomaran los bloqueos en orden contrario pueden interbloquearse.

### 6.3 Cuándo el dominio no elige

Dos casos en que el servidor se niega a decidir y devuelve un conflicto con lo que
necesita para que la persona elija:

- **Un vínculo repetido.** El origen de datos tiene personas en más de una empresa a
  la vez y no hay forma de saber si es real o un error, así que quien llama dice qué
  hacer: `transfer`, `parallel` (con motivo) o cancelar.
- **Un traspaso con más de una relación abierta.** Con dos o más, elegir una es una
  decisión de negocio y no le corresponde a este sistema. Responde
  `transfer_source_required` con las relaciones abiertas; la transferencia se hace
  después sobre el endpoint que nombra el origen en su ruta.

Un `close_others` existe en el dominio para desactivar un cliente, y no se acepta
por el endpoint de vínculo: un valor capaz de cerrar todas las relaciones abiertas
no pertenece en una petición cuyo trabajo es añadir una.

### 6.4 El modelo histórico

Las relaciones con empresas y las afiliaciones no se actualizan: se cierran. Un
cierre marca la fila anterior con su fecha de fin y abre una fila nueva, y un
cambio de entidad hace las dos cosas. De ahí salen tres detalles que conviene
tener presentes:

- Las restricciones de unicidad de esas dos tablas son **parciales** (`WHERE
  ended_on IS NULL`): sólo hay una fila abierta por cliente y empresa, o por
  cliente y tipo. Las filas cerradas pueden repetirse sin límite.
- Las claves foráneas de las filas históricas **no se borran en cascada**: un
  borrado en el maestro destruiría el periodo registrado.
- `audit_events` recibe `subject_type` y `subject_id` para podereboquear las
  acciones de cliente, de vínculo y de afiliación en la misma ficha sin un
  registro de cambios escrito a mano.

## 7. Base de datos

- **PostgreSQL 18** para todo. No hay SQLite en desarrollo ni en pruebas: se
  quiere el mismo motor, las mismas restricciones y los mismos tipos en todas
  partes.
- Las migraciones son la definición del esquema. `users.status` tiene una
  restricción `CHECK` además del enum de PHP, para que ninguna vía de
  escritura pueda introducir un estado inválido.
- Los correos se guardan siempre en minúsculas, mediante un mutador en el
  modelo, y con índice único. Así es imposible que existan dos cuentas que
  difieran sólo en mayúsculas.
- **No hay borrado lógico** en A01. Se añade sólo cuando exista una razón
  concreta; un `deleted_at` global aplicado sin criterio es una decisión
  difícil de revertir.

### 7.1 Base de datos de pruebas

`phpunit.xml` apunta a la conexión `testing`, que usa `DB_TEST_DATABASE`
(`consultora_dh_test`). Además, `tests/Pest.php` **aborta la suite** si esa
base de datos coincide con la de desarrollo: la suite es destructiva y perder
datos locales por una ejecución de tests sería inaceptable.

## 8. Redis

Redis se usa para cuatro cosas, todas con una justificación:

| Uso | Motivo |
| --- | --- |
| Sesiones | Compartidas entre peticiones y entre los workers de cola |
| Caché | Respuestas y datos de lectura; invalidable desde la aplicación |
| Colas | Trabajos en segundo plano, con reintentos y colas fallidas |
| Límite de intentos | Contadores de autenticación, con caducidad automática |

La política `maxmemory-policy` es `noeviction`: si Redis se llena, falla la
escritura en lugar de descartar silenciosamente una sesión o un trabajo. Para
una plataforma administrativa, perder una sesión por presión de memoria sería
peor que un error visible.

`appendonly` está activado para que un reinicio no invalide todas las sesiones
ni pierda trabajos en cola.

## 9. Colas

`queue` es un servicio propio que ejecuta `php artisan queue:work`. Existe
porque A02 en adelante necesita trabajo asíncrono (importaciones, PDF,
notificaciones) y la infraestructura debe estar probada antes de que exista el
primer trabajo real.

En A01 se verificó el circuito completo: un trabajo colocado en Redis fue
recogido y ejecutado por el worker. No se incluye ninguna clase de trabajo en
el repositorio, porque todavía no hay una operación de negocio que lo justifique.

## 10. Decisiones de diseño y sus motivos

| Decisión | Motivo | Alternativa descartada |
| --- | --- | --- |
| Monolito modular | Un servidor, un despliegue, costes bajos | Microservicios |
| Sesión con cookie, sin token en el navegador | `HttpOnly` limita el alcance de un XSS | Token en `localStorage` |
| `Sanctum` para CSRF y futura autenticación de máquina | Infraestructura estándar, sin homebrew | CSRF propio |
| Spatie para roles y permisos | Maduro, con soporte de Laravel 13 | Implementación propia |
| `fetch` en lugar de Axios | Sin dependencias, control total | Axios |
| Bootstrap 5 | Componentes fiables y accesibles, sin coste | Tailwind, kits de pago |
| Tokens de diseño en CSS | La identidad se cambia en un archivo | Colores en cada componente |
| `spatie/laravel-permission` en vez de policies a medida | Los roles son de la organización, no de cada modelo | Policies puras |
| Enums de PHP + `CHECK` en base de datos | Dominio tipado y base de datos que no puede corromperse | Sólo el enum |

## 11. Rendimiento

A01 no optimiza de forma prematura, pero tampoco introduce deuda:

- La página de inicio ejecuta **dos** consultas ligeras (`select 1` en
  PostgreSQL y `PING` en Redis). No hay diagnóstico costoso ni sondeos en el
  frontend: el panel carga una vez y sólo se recarga si el usuario lo pide.
- Las consultas del panel usan Eloquent directamente, sin N+1: las relaciones
  de rol y permisos se resuelven una vez por usuario.
- El paquete de producción ronda los 21 kB comprimidos de JavaScript de
  aplicación (más el runtime de Vue), sin bibliotecas pesadas.
- Las comprobaciones de salud de Docker son baratas: el pool de PHP-FPM
  responde a un `ping` propio sin ejecutar PHP, y nginx tiene un endpoint que
  no toca PHP.
- OPcache está activo y el contenedor incluye un `Dockerfile` con verificación
  de extensiones para que una imagen sin el driver de PostgreSQL o de Redis
  falle al construirse y no al arrancar en producción.

## 12. Notas operativas

- **WSL y rutas de Docker.** Si el CLI de `docker` de WSL responde con un error
  de integración, ejecute los comandos de Compose desde el directorio del
  proyecto con rutas relativas. Es la forma fiable cuando la integración WSL de
  Docker Desktop no está activa.
- **`vendor/` y `node_modules/` en volúmenes con nombre.** Están dentro del
  sistema de archivos de Docker y no en el montaje de enlace: leer decenas de
  miles de archivos pequeños a través del enlace es mucho más lento, y afecta tanto al arranque como a cada compilación.
- **PostgreSQL 18** almacena sus datos en un subdirectorio versionado de
  `/var/lib/postgresql`. El volumen se monta en el directorio padre; montarlo
  en `/var/lib/postgresql/data` hace que la imagen rechace arrancar.

[1]: https://laravel.com/docs/sanctum#spa-authentication
