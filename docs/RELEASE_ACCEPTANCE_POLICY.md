# Política de aceptación y publicación — Consultora DH

**Estado:** vigente desde 2026-10-19
**Ámbito:** cambios de producto, infraestructura, IA y documentación
**Norma superior:** `AGENTS.md` (12 reglas permanentes)

---

## 1. Qué significa "aceptado"

Un cambio está aceptado cuando cumple **todos** los gates de esta política. No
hay aceptación parcial, condicional ni por analogía. Un gate que no se pudo
ejecutar se reporta como `NO_EJECUTADO` o `BLOQUEADO_EXTERNO`; nunca como
`PASS`.

```text
GATE_1  Reproducción del fallo o contrato a cumplir
GATE_2  Corrección mínima y revisada
GATE_3  Pruebas dirigidas en verde
GATE_4  Suite del área en verde
GATE_5  Estilo, tipos y build cuando aplique
GATE_6  Certificación LIVE con datos sintéticos
GATE_7  Failover LIVE en el mismo turno
GATE_8  Seguridad, RBAC, aislamiento y auditoría preservados
GATE_9  Limpieza de datos y archivos sintéticos
GATE_10 Informe con evidencia exacta y residuales
GATE_11 Publicación fast-forward (solo si 1–10 pasan)
```

---

## 2. Disponibilidad de IA

### 2.1 Umbrales obligatorios

| Métrica | Umbral | Definición |
|---|---:|---|
| `CERTIFIED_INDEPENDENT_PROVIDERS` | >= 3 | Proveedores distintos con una generación LIVE exitosa |
| `PRIVATE_TOOL_CAPABLE_PROVIDERS` | >= 3 | Proveedores distintos que aceptaron datos privados sintéticos y ejecutaron una herramienta |
| `FAILOVER_LIVE` | `PASS` | Un turno real continuó en otro proveedor tras un fallo real o inducido |
| Proveedores objetivo | 4–5 | Objetivo operativo, no un mínimo |
| Coste | 0 | Solo niveles gratuitos; sin tarjeta, crédito ni facturación |

### 2.2 Qué cuenta como proveedor independiente

Cuenta un proveedor con endpoint, credencial y cuota propias. No cuentan:

- dos modelos del mismo proveedor;
- un router que reenvía al mismo proveedor;
- un mock, un servidor local o un `Http::fake()`;
- una clave presente sin generación observada;
- una respuesta de chat sin la herramienta que la operación requiere.

### 2.3 Evidencia LIVE exigida

Cada proveedor certificado debe aportar:

1. nombre y modelo exactos;
2. clasificación de datos (`standard`/`training`);
3. petición real con datos sintéticos;
4. resultado verificable (texto, total correcto o archivo descargable);
5. latencia observada;
6. herramienta ejecutada, cuando la operación sea con herramientas;
7. identificador de conversación/artefacto o log equivalente;
8. intentos y resultado del failover, si participó.

La evidencia se guarda fuera del repositorio si contiene datos operativos. En el
informe se publican identificadores y resultados, nunca credenciales.

---

## 3. Política de datos y proveedores que entrenan

`AI_ALLOW_TRAINING_PROVIDERS` decide si un proveedor clasificado `training`
puede recibir datos privados. Es una decisión explícita del operador.

- Valor por defecto en código: `false` (fail-closed).
- Valor de esta aceptación: `true`, autorizado por el responsable del producto.
- La clasificación de cada proveedor no se altera para habilitar el gate.
- Los proveedores `standard` siguen siendo preferidos cuando el pool lo permite.
- Un proveedor de entrenamiento nunca se declara `standard` por conveniencia.

Cambiar esta variable exige reiniciar los procesos que cachean configuración y
volver a ejecutar la certificación LIVE.

---

## 4. Seguridad que no se negocia

Ninguna corrección de disponibilidad puede:

- omitir `authorize()` ni `authorizeProposal()`;
- permitir que un usuario confirme una propuesta ajena;
- ejecutar una escritura sin confirmación;
- duplicar una escritura por doble confirmación o reintento;
- ampliar el alcance de un usuario a recursos de otro;
- saltarse la validación de argumentos de la herramienta;
- desactivar el filtro SSRF, las cuotas, el presupuesto o el límite de rondas;
- enviar datos privados a un proveedor no autorizado;
- borrar auditoría para ocultar un fallo.

Los permisos se corrigen en la matriz de roles o en el permiso afectado, no
saltando la comprobación. Las pruebas de seguridad deben ejecutarse antes de
publicar.

---

## 5. Escrituras y artefactos

### 5.1 Propuestas

- Una herramienta de escritura produce una propuesta `pending`; no ejecuta.
- Los argumentos se normalizan y validan antes de guardarse.
- La confirmación usa la propuesta almacenada, no los argumentos del navegador.
- La transición `pending -> confirmed` es condicional y atómica.
- Una segunda confirmación se rechaza.
- Una propuesta vencida no se puede confirmar.
- La autorización se vuelve a comprobar al confirmar.

### 5.2 Archivos

- Un archivo solicitado debe existir y ser legible antes de anunciar su enlace.
- Un texto que dice "archivo generado" sin fila de artefacto no es evidencia.
- Se permite **una** corrección acotada si el archivo no se produjo.
- Si la corrección falla, el turno falla con `missing_artifact`.
- Nunca se inventa un enlace, un nombre o un contenido.
- Los XLSX/CSV se abren y se verifican sus totales, no solo su tamaño.

---

## 6. Failover

El failover es del turno, no de la conversación:

1. el resolver elige el primer candidato sano;
2. si falla por credencial, transporte, cuota o capacidad, registra el intento;
3. el mismo turno pasa al siguiente proveedor independiente;
4. la pregunta del usuario no se repite;
5. el turno conserva herramientas, presupuesto y política de datos;
6. el resultado final declara proveedor y modelo que respondieron.

Evidencia mínima: intento fallido + proveedor de respaldo + resultado
verificable + latencia, todo en una sola llamada a `ask()`.

---

## 7. Pruebas

### 7.1 Backend

- Pruebas dirigidas a los archivos modificados.
- Suite del área (`tests/Feature/A07`, `tests/Unit/A07` o la correspondiente).
- Suite completa solo si el cambio es transversal o lo exige la entrega.
- Ninguna prueba puede depender del `.env` del operador para su resultado; la
  política de privacidad se fija explícitamente en la prueba.

### 7.2 Frontend

- Typecheck y build si cambió un contrato, un tipo o un componente.
- Pruebas Vitest del área afectada.
- Playwright dirigido para flujos críticos.
- Los errores preexistentes se reportan como tales; no se ocultan ni se
  atribuyen al cambio.

### 7.3 Estilo

Pint sobre todo el PHP modificado. Un fallo de estilo bloquea la publicación.

---

## 8. Limpieza

Antes de publicar:

- eliminar usuarios, conversaciones, propuestas, artefactos y adjuntos QA;
- vaciar la outbox de borrado o confirmar que quedó drenada;
- eliminar scripts temporales y cookies;
- conservar solo la evidencia agregada necesaria para el informe;
- comprobar que no quedaron archivos huérfanos en `storage`;
- no tocar datos ni informes de otras sesiones.

---

## 9. Informe final

El informe debe contener, como mínimo:

1. SHA base y SHA funcional;
2. bloque de contrato con los valores exactos;
3. tabla LIVE por proveedor (modelo, política, herramienta, resultado, latencia);
4. evidencia de failover por escenario;
5. tabla de pruebas ejecutadas y resultados;
6. lista de cambios de código;
7. residuales y bloqueos externos;
8. decisión de publicación.

Los valores desconocidos se escriben `NO_EJECUTADO`, `NO_OBSERVADO` o
`BLOQUEADO_EXTERNO`. No se rellenan con estimaciones presentadas como hechos.

---

## 10. Publicación

- `main` solo recibe commits por fast-forward.
- Prohibido `git push --force` y `git push --force-with-lease` sobre `main`.
- Si un gate falla, el trabajo queda en una rama y se informa el bloqueo.
- El commit funcional va antes que el informe; el informe no puede contener su
  propio SHA.
- Después del push se verifica que `origin/main` apunta al commit esperado.
