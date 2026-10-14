# FIX-LOCAL-01 — Importación del libro original y defectos operacionales

**Estado:** cerrado técnicamente · **aceptación manual en navegador pendiente**
**Ámbito:** corrección de defectos encontrados en pruebas manuales. Sin funcionalidades nuevas.

---

## 1. Libro original (Excel de la consultora)

El archivo se carga hoy por la interfaz. Antes producía
`El libro referencia contenido externo y esta importación no lo resuelve`.

| Comprobación | Resultado |
|---|---|
| Subida por `POST /api/imports` | **PASS** — 201, almacenamiento privado |
| Fingerprint del archivo real | **PASS** — las 8 métricas del verificador coinciden |
| Parser y staging | **PASS** — hojas, bloques, filas e identidades reconstruidos |
| Aplicación en base aislada | **PARTIAL** — ver §2 |

### Causas corregidas

1. **`WorkbookGuard` rechazaba toda relación externa.** El archivo real contiene **401**
   relaciones `TargetMode="External"`, **todas** hipervínculos `mailto:` de contacto. El guard
   ahora clasifica cada relación por tipo, destino y modo:
   - **siguen rechazadas** `externalLink` / `externalReference`, destinos `file:`, rutas UNC,
     `oleObject` / `activeX` / `frame`, y cualquier otro destino externo;
   - **se aceptan** `mailto:` y `tel:`, que no nombran recurso y **nunca se resuelven**.
2. **Columnas `phone` de 40 caracteres.** El archivo trae celdas enmascaradas de 43. La
   migración `2026_10_13_100000_widen_source_phone_columns` amplía a 180
   `legacy_import_rows`, `clients` y `companies`. No transforma datos y su `down()` **se niega**
   a truncar.
3. **Reconstrucción de desapariciones.** `findDisappearances()` preguntaba si existía *nota de
   retiro* en vez de si el intervalo estaba *cerrado*. Con la política `manual_only` la nota no
   produce fecha, así que los episodios quedaban abiertos **y sin preguntar**: **441 de 1 709**
   acciones fallaban al aplicar. Ahora la pregunta se hace: **547**.
4. **Falsos solapamientos.** `genuineOverlap()` llamaba a `overlaps()`, que responde `true`
   cuando falta una fecha: evidencia ausente tratada como prueba. Corregido.
5. **Agrupación §8.1.** Una fila sin fecha de inicio caía en otro grupo (`…|unknown`) y partía
   **un solo empleo en dos relaciones**. Una fila sin fecha es ahora la repetición de
   observación y se une al episodio vigente. Episodios: 766 → 765, exactamente el sobrante.
6. **Excepción en Apply.** `applyActions()` capturaba `RuntimeException` pero no
   `DomainException`; un caso legítimo devolvía **500** en lugar del rechazo de §12.3.

---

## 2. Una ambigüedad histórica queda pendiente de decisión humana

**No se ha aplicado nada automáticamente.** El bloqueo es correcto y está documentado.

Un cliente del archivo figura en **un mismo empleador** a lo largo de cuatro meses
consecutivos con **tres fechas de inicio mutuamente excluyentes**. El plan lleva dos relaciones
abiertas con un mismo empleador, que A02 no puede representar, así que la segunda se rechaza y
§12.3 revierte el lote **completo**.

No es decidible desde el archivo y no se ha inventado información:

- §8.4 no pregunta, porque ninguno de esos episodios deja de aparecer antes del final.
- §8.5 omite pares del **mismo** empleador por diseño.
- Las decisiones de §8.5 (`recognize_transfer`, `authorize_parallel`) son para empleadores
  **distintos**, y `transferIsExecutable()` rechaza pares con fechas iguales.

### Alternativas para decisión humana

1. **Corregir la fecha en el Excel y volver a importar.** Es la vía más directa.
2. **Añadir un hallazgo** de «fechas de inicio contradictorias para un mismo empleador». Es
   una ampliación del modelo de dominio, no una corrección, y queda fuera de este hito.
3. **Resolver la relación manualmente** en la aplicación tras un proceso que no lo contenga.

> Un segundo caso, dos empleadores simultáneos con fechas de inicio distintas, **sí quedó
> gestionable**: se expone como `overlapping_company_history` con el vocabulario de §8.5 y el
> revisor lo resuelve con `recognize_transfer`.

---

## 3. Resto de correcciones implementadas

| Módulo | Corrección |
|---|---|
| **Soporte** | `broadcastAs()` ausente: los eventos se emitían con el nombre de clase y ningún listener los recibía. Suscripción al canal de personal, que no tenía llamador, para notas internas. `microphone=(self)` en nginx y middleware, idénticos; `camera=()` sigue denegado. Endpoint de adjuntos del personal (antes 404), reutilizando la lógica del portal. Endpoint de agentes, que antes no existía y devolvía HTML. Ordenación de bandeja (`NULLS LAST`) y «Sin mensajes» en lugar de `31/12/1969`. |
| **Planillas** | El enum `SocialSecurityEntityType` no convertible a cadena producía **HTTP 500** en la vista previa. Serialización explícita con `->value`, conservando el formato determinista del digest. |
| **Clientes** | `Number(id)` sobre un `ComputedRef` pedía `/api/clients/NaN/account`. Fechas civiles con la trampa de medianoche UTC. Panel de solicitudes dentro de su pestaña. Historial con entidad, fecha y responsable. |
| **Operación** | Selectores de cliente por nombre y de responsable por nombre. Límite `min` en el recordatorio. |
| **Documentos** | `prompt()` de ids eliminado, slug derivado del nombre, estado vacío explicativo, ruta duplicada retirada. |
| **PWA** | `respondWith()` recibía `undefined` (`Failed to convert value to 'Response'`). Se conserva la prohibición de cachear la API autenticada. |

---

## 4. Pruebas dirigidas ejecutadas

| Prueba | Resultado |
|---|---|
| Verificador del archivo real | PASS — sin modificar expectativas |
| Flujo real en base aislada (subida → cola → parse → plan → revisión → apply) | OK — 1 364 aserciones |
| A04: guard + API de importación | 27 OK |
| A04: remediación R1, R2 y cierre de contrato | 88 OK |
| Soporte: adjuntos de personal | 9 OK |
| A05: documentos, tareas, operaciones, planillas | 28 OK |
| A02: clientes | 31 OK |
| TypeScript / ESLint / build | Limpios — **0 errores de lint netos nuevos** |

---

## 5. Pendiente

- **Aceptación manual en navegador.** Ninguna corrección de interfaz se ha ejercitado en un
  navegador real; se verificó a nivel de código, configuración y HTTP. **No se declara PASS.**
- **El caso histórico de §2** requiere decisión de la consultora.
- La base de desarrollo **no** recibió el libro original: la importación se aplicó solo en la base
  E2E, y los datos de prueba manuales permanecen intactos.

## Reproducir la verificación del libro

El archivo real se conserva en `.local-fixtures/`, fuera del control de versiones.

```bash
docker exec -e REAL_WORKBOOK_PATH=/var/www/html/.local-fixtures/'EMPRESA BLINDEN AÑO 2026.xlsx' \
    consultora-dh-app-e2e vendor/bin/phpunit -c phpunit.real-workbook.xml
```

Si el archivo no está presente, la prueba se **omite**; nunca pasa en verde por ausencia.