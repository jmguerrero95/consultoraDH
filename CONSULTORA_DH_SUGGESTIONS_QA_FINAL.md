# CONSULTORA DH — SUGGESTIONS-QA-FINAL

## Alcance

Cierre de los dos fallos conocidos de sugerencias, sin cambiar código productivo:

1. `tests/e2e/a07-suggestions.spec.ts` — T1.
2. `tests/frontend/suggestion.spec.ts` — expectativa de `useSuggestion`.

## Reproducción previa

- **E2E T1**: la llamada `GET /api/ai/suggestion?module=receivables&operation=client_account`
  devolvía `{"suggestion": null}`. El motor exige `client_id` para el contexto
  `receivables/client_account`; sin él, `forEntity()` no tiene entidad y responde silencio.
  El fallo se reprodujo en `T1` con `Received: null` en la aserción de la línea 92.
- **Frontend**: `useSuggestion.refresh('imports', 'issues')` invoca
  `ai.suggestion(module, operation, clientId)` con `clientId === undefined`. La prueba
  esperaba una llamada de dos argumentos, pero el contrato real siempre reenvía el tercero.
  Resultado previo: 14 pasaron, 1 falló.

## Corrección aplicada

Sólo pruebas. No se modificó ningún contrato productivo.

- T1 envía el `clientId` sintético que ya devuelve `seedOverdueObligation()`, el mismo que
  usa el resto de la suite. La protección de aislamiento entre clientes queda intacta: el
  motor sigue rechazando una cuenta sin `client_id` y sigue filtrando por entidad.
- La expectativa frontend pasa a
  `toHaveBeenCalledWith('imports', 'issues', undefined)`, conservando la comprobación de
  módulo y operación y añadiendo la del contexto opcional.
- El timeout de visibilidad de T1 sube de 20 s a 30 s, en línea con T4/T5 y con el coste
  medido de compilar el grafo de módulos en el Vite de E2E (≈8 s por módulo en frío). No se
  relajó ninguna aserción: una pantalla que no renderiza sigue fallando.

## Evidencia

| Comprobación | Resultado |
| --- | --- |
| `tests/frontend/suggestion.spec.ts` | 15 passed |
| Vecindario frontend (`suggestion`, `suggestion-context`, `contextual-suggestion`) | 38 passed |
| `tests/Feature/A07/Suggestion*` (4 archivos) | 60 passed / 184 assertions |
| `tests/e2e/a07-suggestions.spec.ts` completo (T1–T7) | 7 passed |
| ESLint sobre los dos archivos modificados | limpio |

El E2E corrió contra el stack aislado (`consultora_dh_e2e`, Redis E2E, proveedor AI simulado).
No se repitieron los 436 tests A07. El script verificó que la base de desarrollo conservó
exactamente sus registros antes y después, y limpió únicamente la base E2E.

## Cierre

```text
BASE_SHA=cfef7dd07797b71f8299cbcf08d0b862515a6cc8
FINAL_CODE_SHA=e717be0d78dc39ebd88e4a5a7e1f8cd816c9458b
ORIGIN_MAIN_TIP=verificado tras el push fast-forward (ver entrega)
SUGGESTIONS_E2E_T1=PASS
SUGGESTIONS_FRONTEND=PASS
RELATED_TESTS=38 frontend + 60 backend + 7 E2E
PRODUCT_CODE_CHANGED=NO
EXTERNAL_LLM_REQUESTS=0
REAL_DATA_PRESERVED=YES
FINAL_RESULT=PASS
```
