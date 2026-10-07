# Custom Headless Checkout API

API RESTful headless in Laravel per la gestione sicura del processo di checkout e-commerce con integrazione PayPal in modalità Redirect (Mock strutturato).

## 🚀 Panoramica del Progetto

Il progetto implementa un flusso di checkout robusto e scalabile seguendo rigorosamente i principi di **Clean Architecture** e **Domain-Driven Design (DDD)**:

- **Architettura a Livelli**: Flusso disaccoppiato `Controller -> Action -> Service` con uso di **DTO (Data Transfer Objects)** per eliminare l'uso di array generici o istanze `$request` nel dominio.
- **Integrità Transazionale & Stock**: Controllo della disponibilità a magazzino tramite **Pessimistic Locking** (`lockForUpdate()`) a livello di database all'interno di transazioni atomiche.
- **Protezione Ordini Doppi**: Strategia ibrida di **Idempotenza** basata su Middleware API (con header `Idempotency-Key` e cache) e constraint `UNIQUE` sul database.
- **Integrazione Gateway Pagamenti**: Astrazione tramite interfaccia (`PaymentGatewayInterface`) e implementazione di un mock strutturato di PayPal (modalità Redirect con link `approve`).
- **Documentazione Interattiva**: Specifica OpenAPI / Swagger generata automaticamente da **Dedoc Scramble** disponibile su `/docs/api`.
- **Analisi Statica & Test**: Tipizzazione stretta certificata da **Larastan (PHPStan livello 5)** e suite di test completa con **Pest PHP**.

---

## 🛠️ Stack Tecnologico

- **Framework**: Laravel 12.x / PHP 8.3+
- **Database**: SQLite (sviluppo/test) / compatibile con MySQL e PostgreSQL
- **Testing**: Pest PHP
- **Analisi Statica**: Larastan (PHPStan Livello 5)
- **Documentazione API**: Dedoc Scramble (OpenAPI 3.1)

---

## 📦 Installazione e Setup

1. **Clona il repository ed entra nella cartella**:
   ```bash
   cd payments
   ```

2. **Installa le dipendenze Composer**:
   ```bash
   composer install
   ```

3. **Configura le variabili d'ambiente**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Esegui le migrazioni e popola i dati di test (Seeder)**:
   ```bash
   php artisan migrate --seed
   ```
   *(Crea automaticamente prodotti di catalogo, varianti SKU con scorte reali ed esaurite, e coupon promozionali).*

5. **Avvia il server di sviluppo**:
   ```bash
   php artisan serve
   ```
   L'applicazione sarà accessibile su `http://localhost:8000`.

### 🐳 Avvio Alternativo con Podman / Docker (Opzionale)

Se preferisci avviare l'intera applicazione e il server Redis containerizzati con Podman (senza dover installare PHP o estensioni sulla macchina host):

```bash
# Avvia i container dell'applicazione e di Redis in background
podman-compose up -d --build
```
L'API sarà immediatamente disponibile e funzionante su `http://localhost:8000`. Per arrestare i container:
```bash
podman-compose down
```


---

## 🧪 Esecuzione dei Test e Analisi Statica

- **Esecuzione della suite di test automatizzati (Pest PHP)**:
  ```bash
  vendor/bin/pest
  ```

- **Esecuzione dell'analisi statica dei tipi (Larastan / PHPStan Livello 5)**:
  ```bash
  vendor/bin/phpstan analyse
  ```

- **Suite di Test End-to-End REST (Script Python autonomo)**:
  *(Resetta automaticamente il DB ed esegue 7 scenari reali di checkout via HTTP)*:
  ```bash
  python test_e2e.py
  ```

---

## 🧮 Logica di Business & Calcolo Carrello (Giorno 2)

La logica finanziaria e di magazzino è completamente disaccoppiata dal layer HTTP tramite Action dedicate:

- **`CheckStockAction`**: Ispeziona in modo puntuale le scorte degli articoli (SKU). Se la quantità richiesta non è disponibile, solleva una `OutOfStockException` (HTTP 409). Supporta il parametro `$lock = true` per attivare il **Pessimistic Locking** (`lockForUpdate`).
- **`ValidateCouponAction`**: Valida lo stato del coupon promozionale (esistenza, stato attivo, scadenza temporale). Calcola la deduzione sia per sconti in percentuale (es. 10%) sia a valore fisso (es. 15€), garantendo che lo sconto non ecceda mai il subtotale. In caso di anomalia solleva una `InvalidCouponException` (HTTP 422).
- **`CalculateCartTotalAction`**: L'orchestratore del calcolo economico. Calcola il subtotale al centesimo, applica il coupon, determina l'imponibile netto, calcola l'aliquota IVA ordinaria al 22% e calcola il totale finale, restituendo un `CartCalculationResultDTO`.

---

## 💳 Gateway di Pagamento & PayPal Mock (Giorno 3)

L'integrazione dei pagamenti è disaccoppiata dall'infrastruttura tramite **Inversion of Control (IoC)**:

- **`PaymentGatewayInterface` (`app/Contracts`)**: Definisce il contratto per qualsiasi provider in modalità Redirect (`createOrder(Order $order, ...): PaymentRedirectResponseDTO`).
- **`PayPalRedirectMockService` (`app/Services`)**: Implementazione mock conforme alla specifica PayPal REST v2 Orders API. Simula la creazione dell'ordine generando un identificatore alfanumerico (es. `PAYPAL_MOCK_5O190127TN364715T`) e l'URL di approvazione (`https://www.sandbox.paypal.com/checkoutnow?token=...`).
- **Inversion of Control**: Il binding è registrato in `AppServiceProvider`, consentendo di sostituire il Mock con un provider reale PayPal o Stripe senza modificare l'orchestratore di checkout.
- **Resilienza & Error Simulation**: Se l'ordine appartiene all'indirizzo email `fail_paypal@test.com`, il servizio simula un'eccezione di timeout/disservizio (`PaymentGatewayException` HTTP 502) per testare le difese transazionali.

---

## 📖 Documentazione e Approfondimenti Tecnici

Il progetto include documentazione architetturale dettagliata conforme ai requisiti di consegna:
- [`ARCHITECTURE.md`](./ARCHITECTURE.md): Diagramma di sequenza del checkout, responsabilità delle classi, schema ER del database e progettazione del Webhook asincrono PayPal.
- [`DECISIONS.md`](./DECISIONS.md): Motivazioni delle scelte tecnologiche, trade-off sul Pessimistic Locking, strategia anti-duplicazione ibrida, gestione degli errori e piano di containerizzazione con Podman.
- [`myDocs/laravel-per-sviluppatori-java.md`](./myDocs/laravel-per-sviluppatori-java.md): Guida concettuale per comprendere l'ORM Eloquent, le Migrations e i DTO partendo dall'ecosistema Java (Spring/Hibernate).

---

## 📖 Endpoint Disponibili

- `GET /` $\rightarrow$ Endpoint di stato dell'applicazione (Healthcheck) e link alla documentazione.
- `GET /docs/api` $\rightarrow$ Interfaccia interattiva OpenAPI / Swagger (Dedoc Scramble).
- `GET /api/increment/{number}` $\rightarrow$ Endpoint di test architetturale (incrementa il numero in input di 2).
- `POST /api/checkout` $\rightarrow$ **Endpoint Principale di Checkout** (Giorno 4). Esegue la validazione del carrello, il controllo stock con blocco pessimistico, l'applicazione del coupon, la creazione dell'ordine in stato `pending`, la chiamata a PayPal Mock e la restituzione della `redirect_url`.

### Esempio di Chiamata `POST /api/checkout`

**Headers raccomandati**:
```http
Content-Type: application/json
Idempotency-Key: 9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d
```

**Payload JSON di richiesta**:
```json
{
  "checkout_token": "chk_unique_session_9988",
  "items": [
    {
      "sku_code": "RUN-42",
      "quantity": 1
    }
  ],
  "coupon_code": "DISCOUNT10",
  "customer_email": "mario.rossi@example.com",
  "currency": "EUR",
  "return_url": "https://miosito.it/checkout/success",
  "cancel_url": "https://miosito.it/checkout/cancel"
}
```

**Risposta HTTP `201 Created`**:
```json
{
  "order_id": 1,
  "status": "pending",
  "provider_order_id": "PAYPAL_MOCK_5O190127TN364715T",
  "redirect_url": "https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL_MOCK_5O190127TN364715T",
  "total_amount": 109.80,
  "currency": "EUR",
  "items_count": 1
}
```

**Comportamento Errori & Resilienza**:
- `409 Conflict`: Restituito se la quantità richiesta supera lo stock a magazzino (`error: out_of_stock`) oppure se viene rilevato un ordine duplicato a livello di vincolo DB.
- `422 Unprocessable Entity`: Restituito in caso di coupon inesistente/scaduto o errori di validazione schema.
- `502 Bad Gateway`: Restituito se il gateway di pagamento fallisce o simula un timeout (la transazione DB locale viene annullata automaticamente, prevenendo ordini orfani).

### Comando cURL per Test Rapido da Terminale
```bash
curl -X POST http://localhost:8000/api/checkout \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 11112222-3333-4444-5555-666677778888" \
  -d '{
    "checkout_token": "chk_demo_curl_001",
    "items": [
      {"sku_code": "RUN-42", "quantity": 1}
    ],
    "coupon_code": "DISCOUNT10",
    "customer_email": "mario.rossi@example.com",
    "currency": "EUR"
  }'
```



