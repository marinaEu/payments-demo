<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validazione dei dati del carrello e parametri per il checkout.
 */
class CheckoutRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /**
             * Token univoco del carrello / sessione per prevenire ordini duplicati (Idempotenza).
             * @example "chk_unique_abc_123"
             */
            'checkout_token' => ['required', 'string', 'max:128'],

            /**
             * Elenco degli articoli da acquistare con relativo SKU e quantità.
             */
            'items' => ['required', 'array', 'min:1'],

            /**
             * Codice univoco della variante articolo (SKU).
             * @example "RUN-42"
             */
            'items.*.sku_code' => ['required', 'string'],

            /**
             * Quantità richiesta per il rispettivo SKU (almeno 1).
             * @example 2
             */
            'items.*.quantity' => ['required', 'integer', 'min:1'],

            /**
             * Codice promozionale facoltativo (percentuale o fisso).
             * @example "DISCOUNT10"
             */
            'coupon_code' => ['nullable', 'string', 'max:64'],

            /**
             * Indirizzo email dell'acquirente.
             * @example "mario.rossi@example.com"
             */
            'customer_email' => ['nullable', 'email', 'max:255'],

            /**
             * Codice valuta ISO a 3 caratteri (default EUR).
             * @example "EUR"
             */
            'currency' => ['nullable', 'string', 'size:3'],

            /**
             * URL a cui PayPal reindirizza il browser in caso di pagamento approvato.
             * @example "https://miosito.it/checkout/success"
             */
            'return_url' => ['nullable', 'url'],

            /**
             * URL a cui PayPal reindirizza il browser in caso di annullamento.
             * @example "https://miosito.it/checkout/cancel"
             */
            'cancel_url' => ['nullable', 'url'],
        ];
    }

    /**
     * Custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'checkout_token.required' => 'Il token di checkout univoco è obbligatorio per garantire l\'idempotenza.',
            'items.required' => 'Il carrello deve contenere almeno un articolo.',
            'items.min' => 'Il carrello deve contenere almeno un articolo.',
            'items.*.sku_code.required' => 'Il codice SKU dell\'articolo è obbligatorio.',
            'items.*.quantity.required' => 'La quantità dell\'articolo è obbligatoria.',
            'items.*.quantity.min' => 'La quantità di ogni articolo deve essere pari ad almeno 1.',
            'customer_email.email' => 'L\'indirizzo email del cliente non è valido.',
        ];
    }
}
