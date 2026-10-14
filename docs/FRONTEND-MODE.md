# El modo de frontend activo, y por qué el navegador veía la interfaz anterior

Este documento responde a una pregunta que salió mal: **¿qué JavaScript y CSS está viendo
realmente el navegador?**

---

## 1. La respuesta corta

**El navegador no estaba viendo el bundle. Estaba viendo el servidor de desarrollo de Vite.**

`public/hot` existe, y `@vite` lo respeta:

```php
// resources/views/app.blade.php
@vite(['resources/css/app.css', 'resources/js/app.ts'])
```

Cuando hay un `public/hot`, Laravel **ignora por completo** `public/build/manifest.json` y escribe
las etiquetas apuntando al servidor de Vite. Comprobado sobre el HTML que Nginx entrega de verdad:

```
$ curl -s http://localhost:8080/ | grep -oE '(src|href)="http://localhost:5173[^"]*"'
src="http://localhost:5173/@vite/client"
href="http://localhost:5173/resources/css/app.css"
src="http://localhost:5173/resources/js/app.ts"
```

Así que `npm run build` **no tenía ninguna efecto sobre lo que el navegador carga**. El bundle
compilado estaba al día —y lo sigue estando— pero nadie lo estaba sirviendo.

> En FIX-EXPORT-IMPORT-03 se concluyó «el bundle está obsoleto, hay que reconstruirlo». La
> conclusión era correcta como síntoma y **equivocada como causa**: el bundle nunca se consultó.
> Las pruebas de frontend pasaban porque compilan desde el fuente, que es justo lo que oculta el
> problema: la ruta de test y la ruta del navegador leen fuentes distintas.

---

## 2. La segunda mitad: por qué Vite servía código viejo

Arreglar lo anterior no bastaba, porque el servidor de desarrollo tampoco entregaba el código
actual.

| Comprobación | Resultado |
|---|---|
| Contenedor `node` | vivo, `healthy` |
| `GET /@vite/client` | HTTP 200 |
| `GET /resources/js/app.ts` | HTTP 200 |
| Archivo que ve el contenedor | 80 303 bytes, 14-oct 21:51 — **el correcto** |
| Lo que servía Vite | 181 371 bytes, **sin** el código nuevo |

El disco estaba bien y el servidor respondía. Lo que no estaba bien es la **caché**: el grafo de
módulos de Vite vive en memoria y sólo lo invalida el vigilante de archivos. `VITE_USE_POLLING`
estaba en `0`, y el montaje de `compose.yaml` es exactamente el caso que eso no cubre:

```
volumes:
  - .:/var/www/html        # directorio de Windows servido por WSL via Docker Desktop
```

Ese tipo de montaje **no entrega eventos inotify**. El vigilante nunca dispara, el grafo nunca se
invalida, y Vite sigue sirviendo la transformación del arranque anterior. El síntoma no tiene
ninguna señal: la página carga, el health check está en verde, los tests pasan, y sólo el
navegador está atrasado.

Prueba: reiniciar **sólo** el servicio `node` —sin tocar código— cambió lo servido de 181 371 a
250 950 bytes, y el código nuevo apareció. Eso aísla la caché como causa.

---

## 3. El arreglo

### 3.1 Sondeo por archivos (`VITE_USE_POLLING`)

`.env` y `.env.example` pasan de `0` a `1`, y `compose.yaml` declara la variable en el entorno del
servicio `node`:

```yaml
environment:
  PLAYWRIGHT_BROWSERS_PATH: /ms-playwright
  VITE_USE_POLLING: ${VITE_USE_POLLING:-1}
```

El valor por defecto es `1` porque este servicio **sólo** puede correr con el proyecto montado, y
sobre el stack documentado ese montaje no entrega inotify. Declararlo en `environment:` además de
en el archivo hace que el proceso y el `.env` digan lo mismo, que es lo que permite a
`docker/node/start.sh` informar de lo que está en vigor.

**Verificado que el sondeo funciona**, editando un módulo y sin reiniciar nada:

```
$ (dentro del contenedor) VITE_USE_POLLING=[1]
$ docker logs consultora-dh-node | head -1
[consultora-dh] Vite watch: polling (bind mount without inotify)

marca insertada dentro de <script setup>  ->  servida en 4 s
marca eliminada                           ->  servida en 6 s
```

> Una primera versión de esa prueba **falló** y la conclusion fue errónea: la marca se había
> añadido al final del archivo, fuera de `<template>`, y el compilador de SFC descarta ese
> contenido. No era el sondeo. La marca va dentro de `<script setup>`.

### 3.2 `public/hot` se conserva

No se borra. Vite está funcionando, y borrarlo sólo publicaría el bundle sin arreglar el
servidor: el síntoma desaparecería hasta la siguiente edición. La coherencia entre
modo desarrollo y modo compilado queda así:

| Modo | Estado | Cómo se activa |
|---|---|---|
| Desarrollo (activo) | correcto | `public/hot` presente + `node` con sondeo |
| Compilado | correcto | `public/build/manifest.json` al día en `4caf8ed` |

Cambiar de uno a otro es borrar o crear `public/hot`; los dos sirven el mismo código.

### 3.3 `docker/node/start.sh` comprueba las dependencias de verdad

La comprobación anterior era:

```bash
if [ ! -d node_modules ] || [ ! -d node_modules/.bin ]; then
```

Eso responde «¿existe este directorio?» y no «¿esta instalación sirve?». Una instalación a medias
—interrumpida, o hecha desde un `package.json` que después ganó una dependencia— la pasa, y Vite
sirve una página con un import sin resolver mientras **parece** sano: el health check sólo pregunta
si `/@vite/client` contesta, y contesta.

Ahora cada nombre de `dependencies` tiene que estar presente **con manifiesto legible**:

```bash
if [ ! -f "node_modules/${package}/package.json" ]; then
```

El manifiesto se comprueba en disco y no con `require.resolve()` porque un paquete cuyo mapa
`exports` no publica `./package.json` fallaría la resolución estando perfectamente instalado — y una
comprobación que da falsas alarmas acaba apagándose, que es el mismo silencio que viene a quitar.

**Y un error propio, encontrado al probar la comprobación:** la primera versión usaba
`node -e "expr"` sin `console.log`, que evalúa y **descarta**. La función no imprimía nada, el
bucle no corría nunca y la comprobación pasaba siempre: exactamente el fallo que venía a
detectar, reproducido dentro de la propia comprobación.

Verificado con las dos behaviours:

```
instalación completa   ->  []
pinia oculto           ->  [ pinia]
pinia + pusher ocultos ->  [ pinia pusher-js]
restaurado             ->  []
```

Si tras instalar siguen sin resolverse, el script **sale con código 1** y lo dice, en vez de
arrancar un servidor que va a servir una página rota.

---

## 4. Lo que queda fuera de este arreglo

**El Service Worker no era la causa.** `public/sw.js` se registra en
`resources/js/app.ts`, pero su manejador de `fetch` devuelve de inmediato cuando
`url.origin !== self.location.origin`:

```js
// public/sw.js
if (url.origin !== self.location.origin) {
  return;
}
```

`localhost:5173` es un origen distinto de `localhost:8080`, así que el SW **nunca intercepta** los
módulos del servidor de desarrollo. Sólo cachea primero `/build/`, `/icons/` y `/manifest.json`, y
en modo desarrollo el documento no los referencia. Las navegaciones prefieren la red y las
respuestas de API nunca se cachean.

(En la comprobación con Chromium el SW ni siquiera pudo registrarse: `http://nginx` no es contexto
seguro, así que `navigator.serviceWorker` es `undefined`. Cuestión distinta: con `localhost` sí lo
sería, y la regla de origen lo dejaría fuera igualmente.)

---

## 5. Cómo comprobar, en cualquier momento, que el navegador ve el código actual

Sin caché, sin build y sin suposiciones:

```bash
# 1. ¿En qué modo está?
cat public/hot 2>/dev/null || echo "modo compilado"

# 2. ¿Qué referencia el documento de verdad?
curl -s http://localhost:8080/ | grep -oE '(src|href)="[^"]*"' | grep -E "5173|build"

# 3. ¿Lo que ese origen entrega contiene el código?
curl -s http://localhost:5173/resources/js/pages/imports/ImportDetailPage.vue \
  | grep -c "Cancelar esta importación"
```

Si (1) dice desarrollo, (2) tiene que mostrar `5173` y (3) tiene que dar un número. Un `0` en (3)
con un `1` sano en Vite significa que hay que reiniciar `node` — es la caché del grafo de módulos.

---

## 6. Una nota sobre por que fallan las pruebas de frontend

`npm run typecheck` y `npx vitest` compilan **desde el fuente**. No pasan por Vite, no dependen de
`public/hot` y no dependen del montaje. Es decir: pueden estar verdes mientras el navegador va
tres semanas atrás, y esa es exactamente la separación que hizo posible este fallo.

La comprobación que faltaba no es otra prueba unitaria, sino la de arriba: leer por HTTP lo que el
servidor entrega de verdad.