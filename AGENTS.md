# AGENTS.md — reglas permanentes de Consultora DH

Estas reglas aplican a cualquier persona o agente que modifique, pruebe,
certifique o publique este repositorio. No son sugerencias: una entrega que
incumpla una de ellas no puede declararse terminada.

## Las 12 reglas

1. **Sin evidencia LIVE no hay éxito.** Una capacidad solo se declara
   `PASS`/`VERIFIED_LIVE` si una petición real al proveedor real, con
   credenciales reales y datos sintéticos, produjo el resultado observable que
   se afirma. Los mocks, las pruebas herméticas y la configuración presente
   prueban el código, no la disponibilidad externa.

2. **Tres proveedores independientes como mínimo.** La aceptación exige
   `CERTIFIED_INDEPENDENT_PROVIDERS >= 3`, `PRIVATE_TOOL_CAPABLE_PROVIDERS >= 3`
   y `FAILOVER_LIVE = PASS`. El objetivo operativo es 4–5. No se cuentan dos
   modelos del mismo proveedor como dos proveedores.

3. **Solo niveles gratuitos.** No se activan tarjetas, créditos, facturación,
   suscripciones ni pruebas de pago. Si un proveedor exige pago para continuar,
   se documenta como bloqueo externo; no se simula su disponibilidad.

4. **La política de datos se declara, no se supone.** Cada proveedor conserva su
   clasificación real (`standard` o `training`). `AI_ALLOW_TRAINING_PROVIDERS`
   es una decisión explícita del operador; el valor por defecto del código sigue
   siendo `false` (fail-closed). Está prohibido reclasificar un proveedor para
   hacerlo pasar el filtro de datos privados.

5. **Datos sintéticos solamente.** Las pruebas LIVE no usan nombres, documentos,
   correos, teléfonos, saldos ni identificadores de clientes reales. Se usan
   cuentas y archivos QA desechables, y se eliminan al terminar.

6. **Seguridad y RBAC intactos.** Ninguna corrección puede debilitar permisos,
   aislamiento entre usuarios, propiedad de recursos, auditoría, validación de
   argumentos, límites de herramientas, SSRF, cuotas ni confirmación de
   escrituras. Un fallo de permisos se corrige en el permiso, no saltándolo.

7. **Las escrituras se confirman.** El asistente puede proponer, pero no ejecutar
   una escritura administrativa sin confirmación humana. La confirmación es
   idempotente: una propuesta se ejecuta exactamente una vez y una segunda
   confirmación se rechaza.

8. **El failover ocurre en el mismo turno.** Si el primer proveedor falla por
   credencial, transporte, cuota o capacidad, el mismo turno continúa con otro
   proveedor independiente, sin pedir al usuario que repita la pregunta y sin
   reiniciar la conversación.

9. **Nunca se inventa un archivo ni un enlace.** Si se pidió generar un archivo y
   no existe, se intenta una corrección acotada; si aun así no se produce, el
   turno falla de forma explícita. No se entrega un enlace falso, un archivo
   vacío ni una promesa de descarga.

10. **Los residuales se informan.** El informe final debe declarar proveedores
    certificados, modelos, latencias, llamadas realizadas, pruebas ejecutadas,
    bloqueos externos y riesgos pendientes. Un residual no es un fallo si se
    nombra con precisión; ocultarlo sí lo es.

11. **Operaciones destructivas prohibidas.** No se usa `git reset --hard`,
    `git clean -fd`, `git push --force`, `docker compose down -v`,
    `migrate:fresh` sobre bases reales ni `FLUSHALL`. No se borran cambios,
    informes ni datos de otras sesiones. Las pruebas usan su propia base.

12. **Publicación solo por fast-forward y solo con aceptación.** Se publica en
    `main` únicamente si se cumplen todos los gates anteriores; el push es
    fast-forward y sin `--force`. Si falta un gate, el trabajo permanece en una
    rama y el informe explica el bloqueo con honestidad.

## Flujo mínimo de una entrega

1. Leer el estado real (`git status`, contenedores, configuración efectiva).
2. Reproducir el fallo antes de corregirlo.
3. Hacer el cambio más pequeño que resuelve la causa.
4. Ejecutar pruebas dirigidas y luego la suite del área afectada.
5. Ejecutar Pint sobre el PHP modificado; typecheck/build si cambió el contrato
   del frontend.
6. Ejecutar la certificación LIVE con datos sintéticos y guardar la evidencia.
7. Limpiar los datos y archivos sintéticos.
8. Escribir el informe con resultados exactos y residuales.
9. Publicar por fast-forward si y solo si todos los gates pasan.

## Evidencia mínima por proveedor

| Campo | Contenido |
|---|---|
| Proveedor | Nombre independiente |
| Modelo | Identificador exacto usado |
| Clasificación | `standard` o `training` |
| Herramientas | Sí/No y herramienta observada |
| Datos privados | Sí/No, con la política vigente |
| Resultado | Respuesta o artefacto verificable |
| Latencia | Milisegundos reales |
| Failover | Proveedor de respaldo y resultado en el mismo turno |

La ausencia de cualquiera de estos campos convierte el proveedor en
`NO_CERTIFICADO`, aunque su clave exista y sus pruebas unitarias pasen.
