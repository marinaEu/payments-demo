# ARCHITECTURE.md - Flusso di Checkout & Architettura Software

Questo documento descrive l'architettura tecnica del sistema di checkout headless, la suddivisione delle responsabilità tra i layer, i pattern di resilienza transazionale e la progettazione del flusso asincrono dei webhook.

---

## 1. Panoramica del Flusso di Checkout (Two-Phase Decoupled Pattern)

L'applicazione segue una rigorosa separazione delle responsabilità strutturata nel pattern **Controller $\rightarrow$ Action $\rightarrow$ Service**, interfacciato tramite **DTO** e **JsonResource**.

Per prevenire la saturazione del pool di connessioni (*Connection Starvation*) e lock prolungati sul database, **la chiamata di rete esterna a PayPal è rigorosamente disaccoppiata dalla transazione ACID del database**.

```text
  [ Client HTTP ]
        │  POST /api/checkout (Payload JSON + Header Idempotency-Key)
        ▼
┌────────────────────────────────────────────────────────────────────────┐
│  IdempotencyMiddleware (Cache + Atomic Distributed Lock)               │
│  1. Se la richiesta è già completata e in cache: restituisce X-Cache:HIT│
│  2. Acquisisce Atomic Lock (Cache::lock per 15s)                       │
│     - Se già acquisito: rifiuta subito con 409 'request_in_flight'     │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│  CheckoutController (Thin Controller)                                  │
│  - Valida lo schema tramite CheckoutRequest                            │
│  - Mappa la request nel CartDTO                                        │
│  - Invoca ProcessCheckoutAction ed esegue il wrap in CheckoutResource  │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│  ProcessCheckoutAction (Orchestratore a Due Fasi)                      │
│                                                                        │
│  [ FASE 1: Transazione Atomica DB Locale (< 2ms) ]                     │
│  1. DB::beginTransaction()                                             │
│  2. CheckStockAction: Batch query + Lock deterministico (ORDER BY id)  │
│  3. CalculateCartTotalAction: Calcolo monetario BCMath (subtotale/IVA) │
│  4. Creazione record Ordine ('pending') [DB UNIQUE checkout_token]     │
│  5. Decremento atomico dello stock a magazzino (Prenotazione)          │
│  6. DB::commit() -> RILASCIO IMMEDIATO DI TUTTI I LOCK E CONNESSIONI   │
│                                                                        │
│  [ FASE 2: Chiamata di Rete Esterna (FUORI dalla Transazione DB) ]     │
│  7. PaymentGatewayInterface->createOrder($order)                       │
│     - Nessuna risorsa DB bloccata durante il round-trip HTTP           │
│                                                                        │
│  [ FASE 3A: Se il Gateway fallisce -> Transazione Compensativa ]       │
│  8a. DB::transaction: Ripristina stock ($sku->increment) e annulla     │
│                                                                        │
│  [ FASE 3B: Se il Gateway ha successo -> Finalizzazione rapida ]       │
│  8b. Aggiorna provider_order_id e restituisce CheckoutResultDTO        │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Responsabilità dei Componenti

### 2.1 Layer di Trasporto & Presentazione (HTTP)
- **`CheckoutRequest` (`app/Http/Requests`)**: Valida che il carrello contenga articoli validi, quantitativi positivi ($\ge 1$) e token di idempotenza conforme. Integra annotazioni Swagger/OpenAPI con esempi per ogni campo.
- **`CheckoutController` (`app/Http/Controllers`)**: Thin Controller invocabile di sole 15 righe, privo di blocchi try/catch. Riceve l'input, invoca `CartDTO::fromArray()`, delega alla Action e restituisce `CheckoutResource`.
- **`CheckoutResource` (`app/Http/Resources`)**: Layer di presentazione (`JsonResource`) che trasforma e versiona il DTO interno nel payload JSON consumato dal client, disaccoppiando il dominio dal trasporto.
- **`IdempotencyMiddleware` (`app/Http/Middleware`)**: Protezione a due livelli:
  1. *Cache Response*: memorizzazione della risposta HTTP 201 per 24 ore.
  2. *Atomic Distributed Lock*: blocco mutex (`Cache::lock`) per neutralizzare le richieste concorrenti in-flight durante i double click rapidi.

### 2.2 Layer di Dominio & DTO
- **`CartDTO` e `CartItemDTO` (`app/DTOs`)**: Strutture dati immutabili strongly-typed che trasportano gli articoli e l'intento d'acquisto senza far trapelare la `$request` HTTP.
- **`CheckoutResultDTO` e `CartCalculationResultDTO`**: DTO di ritorno contenenti i risultati delle elaborazioni finanziarie.
- **`OrderStatus` e `CouponType` (`app/Enums`)**: Enum nativi PHP 8.1+ che garantiscono type-safety assoluta sullo stato dell'ordine (`pending`, `paid`, `failed`, `cancelled`) e sulla natura dello sconto (`percentage`, `fixed`).

### 2.3 Layer di Business Logic (Actions & Domain Exceptions)
- **`ProcessCheckoutAction`**: Orchestratore del caso d'uso. Separa la transazione locale ACID dalla chiamata esterna e gestisce la transazione compensativa in caso di errore del provider.
- **`CheckStockAction`**: Ispeziona la disponibilità a magazzino degli SKU eliminando le query N+1 tramite batch query. Applica il **Pessimistic Locking deterministico (`ORDER BY id ASC`)** per scongiurare matematicamente il rischio di Deadlock circolari (SQLSTATE 40001).
- **`CalculateCartTotalAction` & `ValidateCouponAction`**: Eseguono la totalizzazione e il calcolo delle imposte IVA al 22% impiegando l'estensione **BCMath** a precisione arbitraria, azzerando le imprecisioni binarie dei numeri float IEEE-754.
- **Self-Rendering Exceptions (`app/Exceptions`)**: `OutOfStockException` (409), `InvalidCouponException` (422), `SkuNotFoundException` (404) e `PaymentGatewayException` (502) implementano il metodo `render(Request $request): JsonResponse`, autogestendo la risposta HTTP in modo trasparente.

### 2.4 Layer di Integrazione & Infrastruttura (Services)
- **`PaymentGatewayInterface` (`app/Contracts`)**: Contratto IoC (Inversion of Control) che definisce il metodo `createOrder(Order $order, ...): PaymentRedirectResponseDTO`.
- **`PayPalRedirectMockService` (`app/Services`)**: Implementazione mock strutturata conforme all'API Orders v2 di PayPal. Integra l'**Audit Trail dedicato** su canale isolato (`storage/logs/paypal_audit.log`) con sanitizzazione automatica dei dati sensibili PII (email mascherata per conformità GDPR).

---

## 3. Modello Dati e Schema ER

1. **`products`**: Informazioni generali di catalogo (titolo, descrizione, stato attivo).
2. **`skus`**: Varianti fisiche vendibili (Taglia/Colore). Memorizza `stock` e `price_amount`. Relazione $1 \rightarrow N$ con `Product`.
3. **`coupons`**: Codici promozionali percentuali o a valore fisso con data di scadenza.
4. **`orders`**: Record di testata transazione. Contiene i totali monetari, lo stato, il riferimento al gateway (`provider_order_id`) e il vincolo **`UNIQUE` su `checkout_token`**.
5. **`order_items`**: Righe d'ordine storicizzate con prezzo unitario e snapshot dello SKU al momento dell'acquisto.

---

## 4. Gestione Architetturale del Webhook PayPal Reale

Sebbene per la consegna il Webhook reale sia simulato/omesso a livello di codice come da specifiche, ecco il design architetturale basato su **Event-Driven Architecture** per la gestione in produzione.

### 4.1 Il Ciclo di Vita dello Stock (Prenotazione Provvisoria)
È fondamentale chiarire la separazione delle responsabilità tra il Checkout e il Webhook sul ciclo di vita dell'inventario:
- **Al Checkout (Fase Iniziale)**: Lo stock viene decrementato atomicamente sotto Pessimistic Lock al momento della creazione dell'ordine in stato `pending`. Questo previene immediatamente l'over-selling verso altri acquirenti concorrenti.
- **Al Webhook (Fase di Conferma)**: Il Webhook **non decrementa lo stock** (sarebbe un grave paradosso di doppio decremento). Si occupa esclusivamente della cattura finanziaria (Capture API) e del passaggio dell'ordine da `pending` a `paid`.
- **Al Cron Job Sweeper (Fase di Rollback)**: Gli ordini che rimangono in `pending` (perché l'utente ha chiuso il browser senza autorizzare il pagamento su PayPal) vengono intercettati da un job schedulato in background che, dopo 30 minuti di inattività, li annulla e **ripristina lo stock** (`$sku->increment('stock', $qty)`) restituendo la disponibilità a magazzino.

### 4.2 Gestione della Concorrenza (Race Condition tra Return URL e Webhook)
Se l'acquirente completa il pagamento su PayPal e viene reindirizzato alla `return_url`, e contemporaneamente PayPal invia la notifica Webhook, si genera una potenziale **Race Condition** tra i due worker di capture.
Questa concorrenza viene neutralizzata tramite una **transizione di stato condizionale atomica (Optimistic State Locking)**:
```sql
UPDATE orders 
SET status = 'paid', updated_at = NOW() 
WHERE id = ? AND status = 'pending';
```
- Il primo worker (sincrono o webhook) che esegue l'aggiornamento trova lo stato `pending`, lo transiziona a `paid` e procede con la Capture.
- Il secondo worker concorrente trova $0$ righe affette (poiché lo stato è già transitato a `paid`), ignorando l'operazione in modo del tutto idempotente e scongiurando doppi addebiti.

### 4.3 Diagramma Event-Driven del Webhook

```text
[ PayPal Server ] ─── POST /api/webhooks/paypal ───> [ WebhookController ]
                                                             │
                                                             ▼
                                                    [ Valida Firma SSL / HMAC ]
                                                             │
                                                             ▼
                                                    [ Dispatch Event ]
                                                    OrderCheckoutApproved
                                                             │
                                                             ▼ (Queue Async)
                                                    [ CapturePaymentListener ]
                                                    - Ascolta: CHECKOUT.ORDER.APPROVED
                                                    - Verifica stato atomico ('pending')
                                                    - Esegue POST /v2/orders/{id}/capture
                                                    - Aggiorna stato Ordine a 'paid'
                                                    - Notifica cliente via email
```

1. **Endpoint Dedicato (`POST /api/webhooks/paypal`)**:
   - Escluso dalla protezione CSRF e protetto dalla verifica crittografica della firma PayPal (`PAYPAL-TRANSMISSION-SIG`, `PAYPAL-CERT-URL`).
2. **Risposta Immediata HTTP 200/202**:
   - Risponde entro pochi secondi per evitare retry automatici da PayPal, delegando l'elaborazione a una **Coda asincrona (`ShouldQueue`)**.
3. **Capture Asincrona su Autorizzazione Approvata**:
   - Il worker elabora l'evento **`CHECKOUT.ORDER.APPROVED`** (l'acquirente ha approvato l'addebito ma i fondi non sono stati ancora spostati).
   - Esegue la chiamata di Capture finale verso l'endpoint PayPal API.
   - *Nota Tecnica*: L'evento `PAYMENT.CAPTURE.COMPLETED` viene gestito come notifica informativa passiva di incasso avvenuto e **non deve invocare la Capture API**, altrimenti PayPal restituirebbe l'errore HTTP 422 `ORDER_ALREADY_CAPTURED`.
