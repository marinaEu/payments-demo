## Regole sulla Documentazione (Clean & Concise Code)
- **Self-Documenting Code**: Usa nomi di variabili, metodi e classi estremamente espressivi. Se serve un commento per capire cosa fa una riga, rinomina il metodo o la variabile.
- **PHPDoc Essenziale**: Inserisci un blocco PHPDoc solo sopra classi e metodi pubblici, limitandoti a una spiegazione sintetica.
- **Commenti Inline Esclusivamente per il "Perché"**: Inserisci commenti inline SOLO se c'è un motivo di business non ovvio, un'eccezione alla regola, o un'ottimizzazione di performance.
- **Nessuna Ridondanza**: MAI inserire commenti che ripetono ciò che il codice già dice chiaramente (es. VIETATO: `// Salva l'utente` sopra `$user->save()`).

## Best Practice Laravel da Rispettare
- **PHP 8.2+ e Type Hinting**: Tipi definiti per argomenti, return type e proprietà. `declare(strict_types=1);` ove opportuno.
- **Thin Controllers**: I Controller si occupano solo di ricevere la Request e restituire la Response.
- **Form Requests**: Mai validare nei Controller. Usa sempre classi `FormRequest`.
- **Action Pattern / Service Layer**: La Business Logic va in Action classes (es. `CreateOrderAction`) o Service classes.
- **Eloquent Idiomatico**: Sfrutta le relazioni, gli Scopes e i Cast dei Modelli.
- **Database Migrations & Seeders**: Ogni modifica al DB deve avvenire tramite Migration documentata.
- **Testing con Pest**: Genera sempre test unitari o di integrazione (Pest PHP) per ogni funzionalità sviluppata.

## Architettura e Disaccoppiamento (Obbligatori)

1. **Single Responsibility Principle (SRP)**: Ogni classe deve avere una sola ragione per cambiare. Evita God-Classes o Service monolitici.
2. **DTO (Data Transfer Objects)**: Vietato passare la `$request` HTTP o array generici ai servizi di dominio. Usa DTO fortemente tipizzati per trasferire i dati tra i layer.
3. **Inversion of Control & Contracts**: Tutte le dipendenze esterne (API di terze parti, payment gateway, invio SMS) devono essere astratte tramite Interfacce/Contracts PHP. Inietta sempre l'interfaccia nel Service Container, mai la classe concreta.
4. **Event-Driven Architecture**: Gli effetti collaterali (invio email, notifiche, log, webhook) NON devono stare nel flusso principale. Usa Eventi, Listener e Queue in background (`ShouldQueue`).
5. **Struttura Organizzata per Domain/Feature**: Organizza il codice per Dominio o per Feature logica quando il progetto cresce, evitando di ammassare decine di file generici dentro `Http/Controllers` o `Services`.