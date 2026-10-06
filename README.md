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

4. **Esegui le migrazioni del Database**:
   ```bash
   php artisan migrate
   ```

5. **Avvia il server di sviluppo**:
   ```bash
   php artisan serve
   ```
   L'applicazione sarà accessibile su `http://localhost:8000`.

---

## 🧪 Esecuzione dei Test e Analisi Statica

- **Esecuzione della suite di test (Pest PHP)**:
  ```bash
  vendor/bin/pest
  ```

- **Esecuzione dell'analisi statica (Larastan)**:
  ```bash
  vendor/bin/phpstan analyse
  ```

---

## 🧮 Logica di Business & Calcolo Carrello (Giorno 2)

La logica finanziaria e di magazzino è completamente disaccoppiata dal layer HTTP tramite Action dedicate:

- **`CheckStockAction`**: Ispeziona in modo puntuale le scorte degli articoli (SKU). Se la quantità richiesta non è disponibile, solleva una `OutOfStockException` (HTTP 409). Supporta il parametro `$lock = true` per attivare il **Pessimistic Locking** (`lockForUpdate`).
- **`ValidateCouponAction`**: Valida lo stato del coupon promozionale (esistenza, stato attivo, scadenza temporale). Calcola la deduzione sia per sconti in percentuale (es. 10%) sia a valore fisso (es. 15€), garantendo che lo sconto non ecceda mai il subtotale. In caso di anomalia solleva una `InvalidCouponException` (HTTP 422).
- **`CalculateCartTotalAction`**: L'orchestratore del calcolo economico. Calcola il subtotale al centesimo, applica il coupon, determina l'imponibile netto, calcola l'aliquota IVA ordinaria al 22% e calcola il totale finale, restituendo un `CartCalculationResultDTO`.

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

