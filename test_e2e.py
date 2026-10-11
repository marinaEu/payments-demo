#!/usr/bin/env python3
"""
End-to-End Test Suite per l'API di Checkout Custom (Laravel).
Resetta automaticamente il database ed esegue test REST completi su tutti gli scenari,
mostrando in console i log dettagliati di Request e Response per ciascuna chiamata.
Zero dipendenze esterne richieste (standard library Python).
"""

import concurrent.futures
import json
import os
import shutil
import subprocess
import time
import urllib.error
import urllib.request
import uuid

BASE_URL = "http://127.0.0.1:8000/api/checkout"


def find_php_binary():
    """Trova l'eseguibile di PHP nel PATH o nel percorso di installazione winget."""
    php_path = shutil.which("php")
    if php_path:
        return php_path

    winget_fallback = os.path.expandvars(
        r"%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
    )
    if os.path.exists(winget_fallback):
        return winget_fallback

    return "php"


def reset_database(php_bin):
    """Resetta il database e popola i dati di test con il seeder."""
    print("🔄 [FASE PRELIMINARE] Reset del Database con 'php artisan migrate:fresh --seed'...")
    try:
        subprocess.run(
            [php_bin, "artisan", "migrate:fresh", "--seed"],
            capture_output=True,
            text=True,
            check=True,
        )
        print("✅ Database SQLite rigenerato e ripopolato con successo!\n")
    except subprocess.CalledProcessError as e:
        print("❌ Errore durante l'esecuzione di migrate:fresh:")
        print(e.stderr)
        exit(1)


def send_checkout_request(payload, headers=None):
    """Esegue la chiamata HTTP POST verso l'endpoint /api/checkout."""
    headers = headers or {}
    headers.setdefault("Content-Type", "application/json")
    headers.setdefault("Accept", "application/json")

    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(BASE_URL, data=data, headers=headers, method="POST")

    try:
        with urllib.request.urlopen(req) as response:
            body = json.loads(response.read().decode("utf-8"))
            return response.status, body, dict(response.headers)
    except urllib.error.HTTPError as e:
        raw_body = e.read().decode("utf-8")
        try:
            body = json.loads(raw_body)
        except Exception:
            body = {"raw": raw_body}
        return e.code, body, dict(e.headers)
    except urllib.error.URLError:
        print(f"\n❌ Impossibile connettersi al server su {BASE_URL}.")
        print("👉 Assicurati che il server Laravel sia attivo eseguendo: php artisan serve\n")
        exit(1)


def log_scenario(scenario_num, title, payload, req_headers, status, resp_body, resp_headers, is_passed):
    """Stampa a video in modo pulito e visibile i dettagli completi di Request e Response."""
    sep = "-" * 70
    print(sep)
    print(f"📌 [SCENARIO {scenario_num}] {title}")
    print(sep)

    # Log della Request
    print("📤 REQUEST HTTP:")
    print(f"   URL:     POST {BASE_URL}")
    print(f"   Headers: {json.dumps(req_headers)}")
    print("   Body JSON:")
    print("   " + json.dumps(payload, indent=4).replace("\n", "\n   "))

    # Log della Response
    print("\n📥 RESPONSE HTTP:")
    relevant_headers = {}
    if "x-cache" in resp_headers:
        relevant_headers["X-Cache"] = resp_headers["x-cache"]
    elif "X-Cache" in resp_headers:
        relevant_headers["X-Cache"] = resp_headers["X-Cache"]
    relevant_headers["Content-Type"] = resp_headers.get("content-type", resp_headers.get("Content-Type", "application/json"))

    print(f"   Status Code: {status}")
    print(f"   Headers:     {json.dumps(relevant_headers)}")
    print("   Body JSON:")
    print("   " + json.dumps(resp_body, indent=4).replace("\n", "\n   "))

    # Esito
    icon = "✅ TEST PASSED" if is_passed else "❌ TEST FAILED"
    print(f"\n🎯 ESITO: {icon}")
    print(sep + "\n")


def main():
    print("\n" + "=" * 70)
    print("🚀 SUITE DI TEST E2E ENDPOINT /api/checkout (CON LOG ESTESI)")
    print("=" * 70 + "\n")

    php_bin = find_php_binary()
    reset_database(php_bin)

    results = []

    # ========================================================
    # SCENARIO 1: Acquisto con successo (Happy Path + Coupon)
    # ========================================================
    token_1 = str(uuid.uuid4())
    headers_1 = {"Idempotency-Key": f"idemp_{token_1}"}
    payload_1 = {
        "checkout_token": token_1,
        "items": [
            {"sku_code": "RUN-42", "quantity": 1}
        ],
        "coupon_code": "DISCOUNT10",
        "customer_email": "mario.rossi@example.com",
        "currency": "EUR",
        "return_url": "https://miosito.it/checkout/success",
        "cancel_url": "https://miosito.it/checkout/cancel",
    }
    status_1, body_1, resp_headers_1 = send_checkout_request(payload_1, headers=headers_1)
    passed_1 = (
        status_1 == 201
        and body_1.get("status") == "pending"
        and str(body_1.get("total_amount")) in ("109.80", "109.8")
        and "PAYPAL_MOCK_" in body_1.get("provider_order_id", "")
        and "redirect_url" in body_1
    )
    log_scenario(
        scenario_num=1,
        title="Acquisto valido con Coupon 10% (Subtotale 100€ - Sconto 10€ + IVA 22% = 109.80€)",
        payload=payload_1,
        req_headers=headers_1,
        status=status_1,
        resp_body=body_1,
        resp_headers=resp_headers_1,
        is_passed=passed_1,
    )
    results.append(passed_1)

    # ========================================================
    # SCENARIO 2: Idempotenza Livello 1 (Cache Middleware su Retry)
    # ========================================================
    status_2, body_2, resp_headers_2 = send_checkout_request(payload_1, headers=headers_1)
    is_cache_hit = resp_headers_2.get("x-cache") == "HIT" or resp_headers_2.get("X-Cache") == "HIT"
    passed_2 = (
        status_2 == 201
        and is_cache_hit
        and body_2.get("order_id") == body_1.get("order_id")
    )
    log_scenario(
        scenario_num=2,
        title="Idempotenza Livello 1: Riconosce il retry di rete identico (Header X-Cache: HIT)",
        payload=payload_1,
        req_headers=headers_1,
        status=status_2,
        resp_body=body_2,
        resp_headers=resp_headers_2,
        is_passed=passed_2,
    )
    results.append(passed_2)

    # ========================================================
    # SCENARIO 3: Idempotenza Livello 2 (Vincolo UNIQUE su Database)
    # ========================================================
    headers_3 = {"Idempotency-Key": str(uuid.uuid4())}
    status_3, body_3, resp_headers_3 = send_checkout_request(payload_1, headers=headers_3)
    passed_3 = (
        status_3 == 409
        and body_3.get("error") == "duplicate_order"
    )
    log_scenario(
        scenario_num=3,
        title="Idempotenza Livello 2: Rifiuta carrello duplicato tramite vincolo DB UNIQUE (409 Conflict)",
        payload=payload_1,
        req_headers=headers_3,
        status=status_3,
        resp_body=body_3,
        resp_headers=resp_headers_3,
        is_passed=passed_3,
    )
    results.append(passed_3)

    # ========================================================
    # SCENARIO 4: Magazzino Insufficiente (OutOfStockException)
    # ========================================================
    token_4 = str(uuid.uuid4())
    headers_4 = {"Idempotency-Key": f"idemp_{token_4}"}
    payload_4 = {
        "checkout_token": token_4,
        "items": [
            {"sku_code": "RUN-43", "quantity": 10}  # A magazzino ce ne sono solo 2
        ],
    }
    status_4, body_4, resp_headers_4 = send_checkout_request(payload_4, headers=headers_4)
    passed_4 = (
        status_4 == 409
        and body_4.get("error") == "out_of_stock"
        and body_4.get("available_stock") == 2
    )
    log_scenario(
        scenario_num=4,
        title="Controllo Stock: Blocca acquisto se quantita richiesta > scorte magazzino (409 Conflict)",
        payload=payload_4,
        req_headers=headers_4,
        status=status_4,
        resp_body=body_4,
        resp_headers=resp_headers_4,
        is_passed=passed_4,
    )
    results.append(passed_4)

    # ========================================================
    # SCENARIO 5: Articolo Inesistente / SKU non a catalogo
    # ========================================================
    token_5 = str(uuid.uuid4())
    headers_5 = {"Idempotency-Key": f"idemp_{token_5}"}
    payload_5 = {
        "checkout_token": token_5,
        "items": [{"sku_code": "SKU_FANTASMA_NON_ESISTE", "quantity": 1}],
    }
    status_5, body_5, resp_headers_5 = send_checkout_request(payload_5, headers=headers_5)
    passed_5 = (
        status_5 == 404
        and body_5.get("error") == "sku_not_found"
    )
    log_scenario(
        scenario_num=5,
        title="Catalogo: Rifiuta articolo con SKU inesistente (404 Not Found)",
        payload=payload_5,
        req_headers=headers_5,
        status=status_5,
        resp_body=body_5,
        resp_headers=resp_headers_5,
        is_passed=passed_5,
    )
    results.append(passed_5)

    # ========================================================
    # SCENARIO 6: Coupon Scaduto (InvalidCouponException)
    # ========================================================
    token_6 = str(uuid.uuid4())
    headers_6 = {"Idempotency-Key": f"idemp_{token_6}"}
    payload_6 = {
        "checkout_token": token_6,
        "items": [{"sku_code": "RUN-42", "quantity": 1}],
        "coupon_code": "SCADUTO20",
    }
    status_6, body_6, resp_headers_6 = send_checkout_request(payload_6, headers=headers_6)
    passed_6 = (
        status_6 == 422
        and body_6.get("error") == "invalid_coupon"
    )
    log_scenario(
        scenario_num=6,
        title="Validazione Coupon: Rifiuta codice coupon scaduto (422 Unprocessable Entity)",
        payload=payload_6,
        req_headers=headers_6,
        status=status_6,
        resp_body=body_6,
        resp_headers=resp_headers_6,
        is_passed=passed_6,
    )
    results.append(passed_6)

    # ========================================================
    # SCENARIO 7: Resilienza Gateway PayPal (Simulazione Guasto 502)
    # ========================================================
    token_7 = str(uuid.uuid4())
    headers_7 = {"Idempotency-Key": f"idemp_{token_7}"}
    payload_7 = {
        "checkout_token": token_7,
        "items": [{"sku_code": "RUN-42", "quantity": 1}],
        "customer_email": "fail_paypal@test.com",
    }
    status_7, body_7, resp_headers_7 = send_checkout_request(payload_7, headers=headers_7)
    passed_7 = (
        status_7 == 502
        and body_7.get("error") == "payment_gateway_error"
    )
    log_scenario(
        scenario_num=7,
        title="Resilienza Gateway: Annulla transazione se PayPal fallisce e non crea ordini orfani (502 Bad Gateway)",
        payload=payload_7,
        req_headers=headers_7,
        status=status_7,
        resp_body=body_7,
        resp_headers=resp_headers_7,
        is_passed=passed_7,
    )
    results.append(passed_7)

    # ========================================================
    # SCENARIO 8: Fire Rate & Concorrenza Parallela (Pessimistic Lock)
    # ========================================================
    sep = "-" * 70
    print(sep)
    print("📌 [SCENARIO 8] Concorrenza Parallela: Fire Rate di 6 thread su 2 pezzi disponibili")
    print(sep)
    print("⚡ Lancio 6 richieste HTTP simultanee su SKU 'RUN-43' (solo 2 pezzi a magazzino)...")

    def concurrent_order(worker_id):
        token_worker = f"tok_firerate_{worker_id}_{uuid.uuid4().hex[:6]}"
        payload_worker = {
            "checkout_token": token_worker,
            "items": [{"sku_code": "RUN-43", "quantity": 1}],
            "customer_email": f"buyer_{worker_id}@test.com",
        }
        headers_worker = {"Idempotency-Key": f"idemp_{token_worker}"}
        status_code, response_body, _ = send_checkout_request(
            payload_worker, headers_worker
        )
        return worker_id, status_code, response_body

    worker_results = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as executor:
        futures = [executor.submit(concurrent_order, i) for i in range(1, 7)]
        for f in concurrent.futures.as_completed(futures):
            worker_results.append(f.result())

    success_orders = [r for r in worker_results if r[1] == 201]
    rejected_orders = [r for r in worker_results if r[1] == 409]

    for w_id, w_status, w_body in sorted(worker_results, key=lambda x: x[0]):
        outcome = "✅ 201 Created (Acquistato)" if w_status == 201 else "🛡️ 409 Conflict (Stock Esaurito)"
        print(f"   [Thread {w_id}] -> HTTP {w_status} | {outcome}")

    passed_8 = (len(success_orders) == 2) and (len(rejected_orders) == 4)
    icon_8 = "✅ TEST PASSED" if passed_8 else "❌ TEST FAILED"
    print(f"\n🎯 ESITO: {icon_8} (Esattamente 2 acquisti riusciti e 4 bloccati per stock out)")
    print(sep + "\n")
    results.append(passed_8)

    # ========================================================
    # RIEPILOGO FINALE
    # ========================================================
    passed_count = sum(1 for r in results if r)
    total_count = len(results)

    print("=" * 70)
    if passed_count == total_count:
        print(f"🎉 SUCCESSO TOTALE: {passed_count}/{total_count} SCENARI E2E SUPERATI CON SUCCESSO!")
    else:
        print(f"⚠️ ATTENZIONE: {passed_count}/{total_count} scenari superati.")
    print("=" * 70 + "\n")


if __name__ == "__main__":
    main()
