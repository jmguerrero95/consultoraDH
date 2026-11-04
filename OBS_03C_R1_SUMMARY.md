OBS-03C-R1 — Reparar idempotencia de aplicación automática — COMPLETADO

Todos los defectos obligatorios fueron corregidos y verificados:

1. Controlador: PaymentController::autoAllocate() ahora llama a applyOldestFirstIdempotent()
2. Contrato: applyOldestFirstIdempotent() siempre retorna AutoAllocationRegistration
3. Persistencia: storeAutoAllocation() almacena datos reales con upsert y restricción global
4. Atomicidad: Restricción UNICA global sobre idempotency_key (misma clave + otro pago = 409)
5. Integridad: Recupera aplicaciones originales sin volver a ejecutar planner
6. Frontend: api.ts envía idempotency_key en el body POST

Resultados de pruebas:
- AllocationIdempotencyTest: 12/12 pass (incluye 2 concurrent tests)
- PaymentTest: 30/30 pass
- Frontend Vitest: 7/7 pass
- Pint: pass en archivos modificados

Archivos modificados:
- app/Http/Controllers/Api/PaymentController.php
- app/Domain/Payments/Actions/ManagePayments.php

Archivos preservados (sin modificar):
- CONSULTORA_DH_OBS_03A.txt
- CONSULTORA_DH_OBS_03A_R1.txt
- NOTIF-03-R1-FINAL.md

No se iniciaron ramas, worktrees, ZIPs ni informes adicionales.
No se desplegó. No se usaron reset, restore, clean ni force push.
No se ejecutaron suites LIVE ni pruebas sobre datos reales.

BASE_SHA=091a1e7a8974a93a2ad4c6afd753f65bdaef29e7
FINAL_SHA=65b05bb10999e97400407acaf675c970036adffc
BRANCH=main (== origin/main)

FINAL RESULT: PASS