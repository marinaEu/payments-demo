# DECISIONS.md - Registro delle Decisioni Architetturali (ADR) & Strategie di Dominio

Questo documento formalizza l'insieme completo delle decisioni architetturali, delle scelte di modellazione dati, delle strategie transazionali e dei trade-off ingegneristici adottati per l'intero ciclo di vita del sistema di checkout custom headless.

---

## 1. Dominio Carrello & Catalogo (Punto 1 della Consegna)

### 1.1 Separazione di Responsabilità: `Product` vs `Sku` (Varianti Minime)
- **Decisione**: Separare l'entità concettuale di catalogo (`Product`, es. *"Scarpe da Corsa Pro"*) dall'unità fisica vendibile di magazzino (`Sku`, es. *"RUN-42 - Taglia 42"*).
- **Motivazione**: Un utente non acquista mai un "prodotto generico", ma una specifica variante fisica con attributi minimi (taglia/colore). Tracciare lo stock o il prezzo sul `Product` causerebbe gravi anomalie logistiche (es. vendere una taglia S perché esistono a magazzino 10 taglie XL). Lo `Sku` rappresenta la singola riga di inventario con codice a barre e disponibilità fisica indipendente.

### 1.2 Disaccoppiamento tramite DTO Fortemente Tipizzati (`CartDTO`, `CartItemDTO`)
- **Decisione**: Vietare il transito dell'oggetto HTTP `$request` o di array associativi non tipizzati verso la business logic, incapsulando il carrello in classi DTO immutabili con proprietà `readonly` e stringhe decimali esatte.
- **Motivazione**: Garantisce il rispetto del **Domain-Driven Design (DDD)** e del **Single Responsibility Principle (SRP)**. I servizi e le Action lavorano esclusivamente con contratti di tipo espliciti e garantiti da PHP 8.2+ e Larastan (Livello 5), rendendo il dominio riutilizzabile da qualsiasi contesto (API REST, CLI Artisan, Webhook o code asincrone).

### 1.3 Purezza delle Action Economiche (Separazione di Responsabilità SRP)
- **Decisione**: `CalculateCartTotalAction` non esegue query al database, non impone row-lock e non dipende da `CheckStockAction`. Riceve la mappa degli SKU già verificati e si occupa unicamente della computazione matematica ed economica.
- **Motivazione**: Garantisce il rispetto rigoroso del Single Responsibility Principle (SRP). L'azione può essere riutilizzata per visualizzare anteprime di sconti o carrelli laterali senza attivare blocchi pessimistici né sporcare lo stato del database.

---

## 2. Verifica Disponibilità & Strategia di Locking dello Stock (Punto 2 della Consegna)

### 2.1 Pessimistic Locking (`lockForUpdate`) vs Optimistic Locking
- **Decisione Adottata**: Utilizzare il **Pessimistic Locking** a livello di database (`SELECT ... FOR UPDATE` via Eloquent `lockForUpdate()`) durante la verifica di disponibilità e prenotazione delle scorte nel checkout.
- **Trade-off e Motivazione**:
  - *Perché scartato l'Optimistic Locking (versioning)*: L'approccio ottimistico verifica che un campo `version` non sia cambiato al momento dell'update. Nelle vendite e-commerce ad alta concorrenza (*Flash Sales* con articoli ambiti a scorte limitate), se 50 utenti premono "Paga" nello stesso secondo, 49 fallirebbero con eccezione di versione. Questo costringerebbe a complessi e onerosi loop di *retry applicativo*, saturando la CPU del server e degradando pesantemente la User Experience.
  - *Perché scelto il Pessimistic Locking*: Serializza le richieste a livello di riga transazionale ACID. La seconda richiesta concorrente attende il rilascio del lock della prima, leggendo lo stock effettivo aggiornato ed **eliminando matematicamente qualsiasi rischio di over-selling**.

### 2.2 Prevenzione Deadlock Transazionali via Lock Deterministico (`ORDER BY id ASC`)
- **Problema**: Interrogare e bloccare gli SKU nell'ordine sparso inviato dal carrello client espone al rischio critico di **Deadlock circolare (SQLSTATE 40001)**: se l'Utente 1 ordina [SKU-A, SKU-B] e l'Utente 2 ordina contemporaneamente [SKU-B, SKU-A], i due thread bloccano le risorse in ordine incrociato, generando uno stallo che costringe il DB a terminare forzatamente una delle due transazioni.
- **Soluzione Implementata in `CheckStockAction`**: Tutti gli SKU richiesti vengono estratti in batch e **bloccati sempre secondo un ordinamento deterministico fisso per chiave primaria crescente (`ORDER BY id ASC`)**:
  ```php
  Sku::whereIn('code', $uniqueSkuCodes)
     ->where('is_active', true)
     ->orderBy('id', 'asc')
     ->lockForUpdate()
     ->get();
  ```
  Poiché ogni transazione nel sistema acquisisce i row-lock nell'identica sequenza, i Deadlock circolari diventano **matematicamente impossibili**.

### 2.3 Eliminazione del Problema N+1 Queries
- La verifica dello stock non esegue query singole all'interno di un ciclo foreach, ma aggrega le quantità richieste per codice ed esegue un'unica query in batch (`WHERE code IN (...)`), abbattendo il traffico di rete tra PHP e Database da $O(N)$ a $O(1)$.

### 2.4 Il Ciclo di Vita dello Stock (Prenotazione Provvisoria)
- **Al Checkout (Fase Iniziale)**: Lo stock viene decrementato atomicamente sotto Pessimistic Lock al momento della creazione dell'ordine in stato `pending`. Questo previene immediatamente l'over-selling verso altri acquirenti concorrenti.
- **Al Webhook / Capture (Fase di Conferma)**: Lo stock **non viene toccato** . L'accredito finanziario sposta l'ordine a `paid`.
- **Al Cron Job Sweeper (Fase di Rollback)**: Gli ordini che rimangono in `pending` per oltre 30 minuti (acquirente che abbandona PayPal) vengono annullati e lo stock viene formalmente ripristinato a magazzino (`$sku->increment('stock', $qty)`).

---

## 3. Gestione Coupon 

### 3.1 Regola di Business: Un Solo Coupon per Ordine
- **Decisione**: Il carrello accetta al massimo una stringa `coupon_code` (proprietà singola nel `CartDTO`).
- **Motivazione**: Impedisce l'accumulo non autorizzato di più codici promozionali sullo stesso acquisto, garantendo marginalità economica certa al merchant.

### 3.2 Supporto Bivalente: Sconti Percentuali e a Valore Fisso
- **Decisione**: Gestire nativamente entrambe le tipologie tramite l'Enum `CouponType` (`PERCENTAGE`, `FIXED`).
- **Regole di Calcolo**:
  - *Percentuale*: Calcolo del rateo sull'imponibile lordo (es. 10% di 100€ = 10€).
  - *Valore Fisso*: Detrazione diretta dell'importo (es. 15€).
  - *Capping di Sicurezza*: Lo sconto a valore fisso viene limitato superiormente al subtotale del carrello (`min($discount, $subtotal)`), impedendo totali negativi o anomalie contabili se il valore del coupon supera il costo del carrello.

### 3.3 Validazione Temporale e Stato
- L'Action `ValidateCouponAction` verifica congiuntamente che il coupon esista, sia marcato come attivo (`is_active = true`) e che la data di scadenza (`expires_at`) non sia passata, sollevando in caso contrario una `InvalidCouponException` (HTTP 422).

### 3.4 Concorrenza sui Coupon, Prevenzione del Double-Spending e Quote di Utilizzo
- **Modello Attuale - Global Promotional Codes**: L'architettura attuale adotta il pattern dei codici promozionali pubblici e condivisi (es. `DISCOUNT10` per campagne marketing o Black Friday). Non prevedendo un tetto massimo di utilizzi o quote individuali, la lettura del coupon in `ValidateCouponAction` avviene intenzionalmente in sola lettura senza blocchi pessimistici (`SELECT` senza `FOR UPDATE`), massimizzando il throughput e azzerando la contesa di scrittura (*Lock Contention*) sul database.
- **Rischio di Race Condition nei Coupon a Quota Limitata (Double-Spending Vulnerability)**:
  - Se venisse introdotto un controllo di utilizzo a quota (`max_uses` vs `used_count`) tramite una sequenza ingenua *Read-Then-Write* (`if ($coupon->used_count >= $coupon->max_uses)` seguito da `$coupon->increment()`), durante campagne ad alto traffico (es. *"I primi 100 acquirenti ottengono il 50%"*) si verificherebbe una grave **Race Condition**. Centinaia di richieste simultanee leggerebbero contemporaneamente la medesima quota residua superando il controllo e provocando lo sforamento massivo del budget promozionale (*Coupon Over-Redemption*).
- **Pattern di Risoluzione Enterprise (Roadmap di Produzione)**:
  1. *Atomic Conditional Update*: La prenotazione della quota promozionale non deve avvenire tramite lock pessimistici di riga (che serializzerebbero l'intero traffico del checkout bloccando le letture), bensì tramite un update condizionale atomico a livello di motore SQL:
     ```sql
     UPDATE coupons 
     SET used_count = used_count + 1, updated_at = NOW() 
     WHERE code = ? 
       AND is_active = 1 
       AND (expires_at IS NULL OR expires_at > NOW())
       AND used_count < max_uses;
     ```
     Se la query restituisce $0$ righe modificate, la quota è esaurita e il sistema rigetta l'applicazione con HTTP 422 `coupon_exhausted`. In caso di successivo fallimento del gateway, una transazione compensativa rapida scala all'indietro il contatore (`used_count = used_count - 1`).
  2. *Tabella di Redenzione per Utente (`coupon_redemptions`)*: Per prevenire il riutilizzo multiplo da parte dello stesso cliente, si introduce una tabella di audit dedicata `coupon_redemptions (coupon_id, customer_email, order_id)` presidiata da un vincolo **`UNIQUE (coupon_id, customer_email)`**, delegando al motore relazionale l'impossibilità matematica di abusi o riscatti fraudolenti multipli.

---

## 4. Integrazione Gateway PayPal - Modalità Redirect 

### 4.1 PayPal REST v2 (Orders API) vs Piattaforme Terze
- **Decisione**: Adottare il modello ufficiale **PayPal REST v2 Orders API** in modalità Redirect, evitando framework white-label complessi (Braintree).
- **Motivazione**: Rispetta fedelmente la traccia di consegna: l'endpoint `/checkout` orchestra il carrello, verifica le scorte, applica il coupon e restituisce al client la `redirect_url` PayPal per l'approvazione del pagamento.

### 4.2 Modalità Redirect: Minimo Scope PCI-DSS (SAQ-A)
- **Decisione**: Nessun dato bancario o numero di carta transita sui server dell'applicazione; l'acquirente si autentica e approva l'addebito direttamente sulle pagine protette di PayPal.
- **Motivazione**: Esenta l'infrastruttura dal 99% degli obblighi di conformità PCI-DSS (rientrando nel livello base SAQ-A), azzerando la superficie d'attacco per il furto di dati di pagamento.

### 4.3 Mock Strutturato per Valutazione Deterministica
- **Decisione**: Implementare `PayPalRedirectMockService` per simulare fedelmente le risposte di PayPal Orders v2 (generazione ID `PAYPAL_MOCK_...` e link di approvazione `https://www.sandbox.paypal.com/checkoutnow?token=...`).
- **Motivazione**: Rende il progetto completamente autonomo e testabile "out-of-the-box" da revisori e pipeline CI/CD, eliminando la necessità di registrare account Sandbox, generare Client ID/Secret o dipendere dalla connettività internet durante i test.
- **Simulazione Disservizi**: Utilizzando l'email `fail_paypal@test.com`, il mock solleva una `PaymentGatewayException` (HTTP 502) per testare attivamente le difese transazionali e il rollback dello stock.

### 4.4 Inversion of Control (IoC) tramite `PaymentGatewayInterface`
- **Decisione**: Legare il servizio a un'interfaccia nel Service Container (`AppServiceProvider`).
- **Motivazione**: Permette di sostituire il Mock con un client HTTP reale di PayPal o con Stripe modificando una sola riga di configurazione nel provider, lasciando intatta la logica di checkout.

### 4.5 Audit Trail Outbound con Mascheramento PII (GDPR)
- **Decisione**: Tutte le interazioni in uscita e in entrata con PayPal vengono registrate su un canale di log dedicato (`storage/logs/paypal_audit.log`).
- **Motivazione**: Fornisce un tracciamento storico (*Audit Trail*) essenziale per difendersi dalle contestazioni di pagamento, mascherando preventivamente i dati personali PII (es. email `m**********@example.com`) per garantire la conformità al GDPR.

---

## 5. Gestione Ordini Doppi & Idempotenza Ibrida a Tre Barriere 

Nel dominio dei pagamenti, i doppi ordini scaturiscono da due eventi distinti:
1. **Network Retries**: La connessione dell'utente cade dopo l'invio dell'ordine prima di ricevere la `redirect_url`; il client ritenta l'invio della richiesta.
2. **Double Click Frenetico**: L'utente clicca ripetutamente sul pulsante "Paga" inviando più richieste quasi simultanee.

L'architettura implementata neutralizza entrambi i problemi tramite **tre barriere complementari**:

1. **Barriera 1 - Cache Response (X-Cache: HIT, per Retry di Rete)**:
   - Se una richiesta con una determinata `Idempotency-Key` è già stata completata con successo nelle ultime 24 ore, il middleware restituisce istantaneamente la medesima risposta salvata in cache, senza toccare controller né database.
2. **Barriera 2 - Atomic Distributed Lock (`Cache::lock`) con Ordinamento Deterministico & Payload Hash**:
   - Se due o più richieste arrivano simultaneamente (scenario di doppio clic rapido), entrambe troverebbero la cache vuota (*race condition in-flight*).
   - *Ancoraggio Duale & Hashing*: Il middleware calcola l'impronta crittografica SHA-256 del body (`hash('sha256', $request->getContent())`). Se la chiave è già in cache ma il payload differisce, rigetta con HTTP 422 `idempotency_payload_mismatch` (conforme a RFC Draft IETF).
   - *Prevenzione Deadlock Distribuito*: Per le richieste che specificano sia l'header `Idempotency-Key` sia il `checkout_token` nel body, l'array delle chiavi di lock viene **ordinato deterministicamente in modo alfabetico (`sort($lockKeys)`)**, impedendo a thread concorrenti di acquisire i lock in ordine inverso e scongiurando matematicamente i deadlock circolari a livello di cluster Redis.
   - La prima richiesta acquisisce il lock atomico per 15s; le richieste concorrenti successive non ottengono il lock e vengono **respinte all'istante con HTTP 409 `request_in_flight`**, prima ancora di allocare connessioni al DB o consumare cicli di CPU.
3. **Barriera 3 - Database Unique Constraint (Fonte di Verità Assoluta)**:
   - La colonna `checkout_token` nella tabella `orders` è marcata come `UNIQUE`.
   - Anche nell'eventualità estrema di cache eviction, riavvio di Redis o cluster distribuito asincrono, il motore relazionale impedisce la scrittura fisica di due record con lo stesso token. L'eccezione viene intercettata dal global handler e restituita come HTTP 409 `duplicate_order`.

---

## 6. Gestione delle Performance e Ottimizzazioni 

In un'applicazione di checkout ad alto volume, le performance non dipendono solo dalla velocità della CPU, ma soprattutto dalla **gestione efficiente delle risorse di I/O, dei lock di database e delle connessioni di rete**. 

Le ottimizzazioni adottate e pianificate si articolano su cinque livelli:

### 6.1 Two-Phase Decoupled Transaction (Eliminazione della Connection Starvation)
- **Problema Risolto**: Se la chiamata HTTP a PayPal viene eseguita dentro la `DB::transaction`, il thread trattiene il lock pessimistico sulle righe degli SKU e la connessione del database per tutta la durata del round-trip di rete. Con latenze esterne di 3-10 secondi, il connection pool del database si satura in pochi istanti (*Cascading Failure* dell'intero sito).
- **Ottimizzazione Implementata**:
  - *Fase 1 (Transazione Locale Atomica < 2ms)*: Verifica stock, creazione ordine `pending`, decremento scorte e **commit immediato**. Tutti i lock e le connessioni DB vengono rilasciati all'istante.
  - *Fase 2 (Chiamata HTTP Esterna)*: Eseguita **completamente fuori da qualsiasi transazione database**.
  - *Fase 3A (Transazione Compensativa su errore)*: Se PayPal non risponde o solleva un errore 502/timeout, si apre una micro-transazione locale che **ripristina lo stock decrementato (`$sku->increment`)** e cancella l'ordine pendente. Zero ordini orfani, zero scorte bloccate e zero connection starvation.

### 6.2 Eliminazione delle Query N+1 tramite Batching
- In `CheckStockAction`, tutti i codici SKU del carrello vengono aggregati ed estratti in un'unica query batch (`WHERE code IN (...)`). Questo riduce il traffico di rete tra il server PHP e il Database da $O(N)$ a $O(1)$.

### 6.3 Prevenzione dei Deadlock Transazionali via Lock Deterministico
- La query di blocco esegue un ordinamento deterministico fisso (`ORDER BY id ASC`). Ogni thread del sistema acquisisce i lock nell'identico ordine, eliminando alla radice la contesa circolare e azzerando i crash di deadlock.

### 6.4 Abbattimento del Traffico Ripetuto tramite Caching dell'Idempotenza
- `IdempotencyMiddleware` sfrutta la memoria RAM (Cache/Redis) per intercettare i duplicati in $O(1)$ restituendo la risposta salvata (`X-Cache: HIT`), senza generare alcuna query verso il database relazionale né chiamate a PayPal.

### 6.5 Roadmap per Volumi Estremi (Flash Sales da 10.000 req/s)
- **Redis Atomic Inventory (`DECRBY`)**: Per eventi promozionali estremi, la prenotazione dello stock viene spostata interamente su chiavi Redis in memoria. L'istruzione nativa `DECRBY` scala lo stock in microsecondi senza lock relazionali.
- **Laravel Octane (FrankenPHP / Swoole)**: Mantiene l'applicazione avviata in memoria RAM persistente tra una richiesta e l'altra, eliminando il costo del bootstrap del framework e moltiplicando il throughput per 5x.

---

## 7. Precisione Finanziaria: Perché Brick\Math\BigDecimal a 12 Decimali supera i Float e il BCMath procedurale


### 7.1 Soluzione Adottata: Brick\Math\BigDecimal (Scala Interna a 12 Decimali & Half-Up Rounding)
Per superare sia le imprecisioni dei float sia la verbosità procedurale delle funzioni native string-based di BCMath (`bcdiv`, `bcmul`), in `CalculateCartTotalAction` e `ValidateCouponAction` abbiamo adottato la libreria a oggetti immutabili **`Brick\Math\BigDecimal`** (già integrata nel framework Laravel):
1. **Oggetti Immutabili strongly-typed**: Ogni operazione (`plus`, `minus`, `multipliedBy`, `dividedBy`) restituisce una nuova istanza immutabile di `BigDecimal`, eliminando side-effects e conversioni di tipo accidentali.
2. **Scala Interna Elevata (12 Cifre Decimali)**: Tutte le operazioni intermedie (moltiplicazione quantità per prezzo unitario, calcolo del rateo di sconto percentuale, scorporo e moltiplicazione dell'aliquota IVA) vengono eseguite con una scala interna di **12 decimali** (`INTERNAL_SCALE = 12`), azzerando qualsiasi deriva da arrotondamento.
3. **Modalità di Arrotondamento Esplicita (`RoundingMode::HalfUp`)**: La divisione e l'arrotondamento finale impiegano la modalità commerciale/bancaria standard *Half-Up* (arrotondamento per eccesso dal 5 in su).
4. **Arrotondamento Finale a 2 Decimali (Centesimi di Euro)**: Il metodo `toScale(2, RoundingMode::HalfUp)` viene invocato **esclusivamente al confine finale del calcolo** prima della persistenza nel database sulla colonna `DECIMAL(10, 2)` e della restituzione del payload JSON.


---

## 8. Gestione degli Errori & Architettura Software

### 8.1 Pattern Thin Controller & Self-Rendering Exceptions nel Dominio
- Le eccezioni di business (`OutOfStockException`, `InvalidCouponException`, `SkuNotFoundException`, `PaymentGatewayException`) implementano ciascuna il metodo nativo `render(Request $request): JsonResponse`.
- Il Controller non necessita di blocchi `try-catch` monolitici: gestisce unicamente l'Happy Path delegando alla Action, mentre il framework invoca automaticamente il metodo `render` dell'eccezione lanciata nel dominio, garantendo una perfetta Separation of Concerns:
  - `OutOfStockException` $\rightarrow$ **HTTP 409 Conflict** (con dettaglio di SKU richiesto e scorte residue).
  - `InvalidCouponException` $\rightarrow$ **HTTP 422 Unprocessable Entity** (con spiegazione se inesistente, scaduto o disattivato).
  - `SkuNotFoundException` $\rightarrow$ **HTTP 404 Not Found**.
  - `PaymentGatewayException` $\rightarrow$ **HTTP 502 Bad Gateway**.

### 8.2 Layer di Presentazione HTTP con `CheckoutResource` (`JsonResource`)
- L'output verso il client è incapsulato in `CheckoutResource`, evitando di esporre la struttura interna del DTO e consentendo il versionamento controllato dell'API.

### 8.3 Neutralizzazione della Race Condition (Return URL vs Webhook Asincrono)
- **Problema di Concorrenza**: L'acquirente completa il pagamento su PayPal e il browser viene reindirizzato alla `return_url`; contemporaneamente PayPal recapita il Webhook `CHECKOUT.ORDER.APPROVED`. Due worker paralleli tentano di processare l'accredito dello stesso ordine nello stesso istante.
- **Soluzione (Optimistic State Locking)**: La transizione di stato avviene esclusivamente tramite query condizionale atomica:
  ```sql
  UPDATE orders SET status = 'paid', updated_at = NOW() WHERE id = ? AND status = 'pending';
  ```
  Il primo thread che esegue la query aggiorna la riga e procede con la cattura dei fondi. Il secondo thread concorrente ottiene 0 righe modificate, terminando l'esecuzione senza sollevare errori né duplicare l'incasso.

---

## 9. Cosa faresti se avessi più tempo? (Evoluzione Enterprise a Lungo Termine)

Se il progetto venisse esteso verso un ambiente enterprise su scala globale:

1. **Containerizzazione & Podman Rootless**:
   - Creazione di un `Containerfile` multi-stage ottimizzato per PHP 8.3-FPM (Alpine/Debian-slim) e Caddy/Nginx.
   - Orchestrazione con `compose.yaml` per gestire l'applicazione in modalità **rootless** (per standard elevati di security isolation), affiancata da container dedicati per PostgreSQL e Redis.
2. **Transizione a PayPal Reale (Orders v2 API & Webhook)**:
   - Implementazione del provider reale `PayPalRestService` configurando le credenziali Sandbox/Live.
   - Implementazione dell'endpoint di Webhook per l'evento `CHECKOUT.ORDER.APPROVED` con verifica crittografica della firma tramite certificato pubblico PayPal (`PAYPAL-CERT-URL`).
3. **Architettura Distribuita per High-Concurrency (Redis & Apache Kafka)**:
   - Spostamento del blocco stock preliminare su **Redis Atomic Decrement (`DECRBY`)** con TTL (es. 15 minuti di carrello riservato), per eliminare il lock contention sul database relazionale durante promozioni con migliaia di acquisti al secondo.
   - Utilizzo di un event streaming broker (**Apache Kafka / RabbitMQ**) per disaccoppiare in modo asincrono le notifiche di pagamento, emissione fatture e aggiornamento inventario (Event Sourcing).
4. **Sonda di Monitoraggio PayPal & Pattern Circuit Breaker**:
   - Creazione di una sonda periodica (healthcheck / ping verso le API di autenticazione PayPal o scraping dello status ufficiale di PayPal).
   - Implementazione di un **Circuit Breaker** (es. tramite pacchetti come `ackintosh/ganesha` o custom cache key su Redis): se il tasso di fallimento o timeout verso PayPal supera una certa soglia, il circuito scatta su "Open", impedendo agli utenti di avviare checkout destinati a fallire.
5. **Crittografia & Gestione Avanzata delle Credenziali (Hashing Password & Encryption at Rest)**:
   - Implementazione della crittografia per dati sensinsibili sulle property.
   - Integrazione con un gestore di segreti esterno (es. HashiCorp Vault o AWS Secrets Manager / KMS) per la rotazione programmata delle chiavi.
6. **Motore Fiscale Dinamico & Aliquote IVA Flessibili**:
   - Storicizzazione delle aliquote sul DB tramite categorie fiscali (`tax_categories` sui prodotti o SKU, es. 4% editoria, 10% beni primari, 22% ordinaria).
   - Calcolo dell'IVA riga per riga (*line-item tax calculation*) anziché cumulativo, con risoluzione dinamica dell'aliquota in base al Paese di fatturazione del cliente (regime europeo IVA OSS / One Stop Shop).
7. **Job Programmato di Riconciliazione & Sweeper Ordini Orfani (Eventual Consistency)**:
   - Implementazione di un comando schedulato (es. ogni 10-15 minuti via Laravel Scheduler `schedule:run`) che seleziona gli ordini rimasti in stato `pending` da oltre 30 minuti.
   - Interroga le API PayPal (`GET /v2/checkout/orders/{id}`) per verificare lo stato effettivo della transazione.
   - Se l'ordine risulta `APPROVED`, esegue il Capture e aggiorna lo stato a `paid`. Se l'ordine è stato abbandonato o è scaduto (`CREATED` o `VOIDED`), transiziona l'ordine a `cancelled` e sblocca lo stock a magazzino (*stock release*).
8. **Audit Trail Finanziario, Tracciamento Antifrode & Log Sanitization**:
   - Tabella immutabile di audit (`order_audit_logs`) per tracciare ogni cambio stato, fonte scatenante, IP e User-Agent del cliente (fondamentale per la difesa da chargeback fraudolenti).
   - Middleware di **Sanitizzazione Automatica dei Log**: filtro strutturato per eliminare o mascherare nei log applicativi (`storage/logs/laravel.log`) qualsiasi dato sensibile PII o token di accesso PayPal, garantendo la conformità alle direttive GDPR e PCI-DSS SAQ-A.
9. **Gestione Automatizzata delle Contestazioni (Disputes) e Chargeback**:
   - Gestione degli eventi Webhook dedicati di PayPal (`CUSTOMER.DISPUTE.CREATED`, `CUSTOMER.DISPUTE.RESOLVED`, `PAYMENT.CAPTURE.DENIED`).
   - Blocco istantaneo del fulfillment e congelamento delle spedizioni magazzino se la contestazione interviene prima dell'evasione merce.
   - Generazione automatica del fascicolo probatorio antifrode da inviare programmaticamente alle API PayPal Disputes (`provide-evidence`).
10. **Logging Strutturato Enterprise & Observability (Container/Podman-Ready)**:
   - Formattazione JSON dei log applicativi tramite Monolog per ingestione diretta da parte di stack SIEM/APM (ELK, Datadog, Grafana Loki).
   - Iniezione del Request Correlation ID (UUID) per il tracciamento distribuito delle transazioni e reindirizzamento dei flussi di log su `php://stdout` e `php://stderr` per la gestione nativa via Podman.
11. **Sicurezza: Rate Limiting Dedicato sull'Endpoint di Checkout (TODO di Produzione)**:
   - Configurazione in `AppServiceProvider` di un Named Rate Limiter dedicato (`checkout`) a 15 richieste/minuto con chiave ibrida (IP + email cliente) e header `Retry-After`.
   - Posizionamento del middleware `throttle:checkout` a monte di `IdempotencyMiddleware` in `routes/api.php`, per respingere tentativi di Lock Storming o DoS prima ancora di interrogare la cache o allocare connessioni al database.
12. **Performance: Aggregazione Atomica Decremento/Ripristino Scorte (TODO di Produzione)**:
   - Raggruppamento preliminare delle quantità per SKU univoco prima di invocare il decremento atomico nella transazione ACID locale e nel ripristino compensativo di `ProcessCheckoutAction`.
   - Riduzione del numero di query `UPDATE` da $O(N)$ (linee del carrello) a $O(M)$ (SKU unici), eliminando scritture ridondanti per ordini con righe multiple del medesimo articolo.
