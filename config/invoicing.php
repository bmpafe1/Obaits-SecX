<?php

/*
 * Seller details printed on every invoice.
 *
 * These are the issuer's own particulars, which an invoice must carry to be
 * usable by the customer's accountant. Kept in config rather than hardcoded in
 * a template so they can be corrected without a code change -- and because an
 * on-premise or reseller deployment issues invoices in a different name.
 *
 * NOTE tax_number is intentionally blank. Cameroonian invoicing requirements --
 * which fields are mandatory, VAT treatment, required wording -- have NOT been
 * checked with an accountant. The template shows the field only when it is set,
 * so nothing false is printed in the meantime.
 */
return [
    'seller' => [
        'name'        => env('INVOICE_SELLER_NAME', 'Obaits'),
        'legal_name'  => env('INVOICE_SELLER_LEGAL_NAME', 'AICexperts'),
        'address'     => env('INVOICE_SELLER_ADDRESS', ''),
        'city'        => env('INVOICE_SELLER_CITY', 'Douala'),
        'country'     => env('INVOICE_SELLER_COUNTRY', 'Cameroon'),
        'email'       => env('INVOICE_SELLER_EMAIL', 'billing@obaits.com'),
        'website'     => env('INVOICE_SELLER_WEBSITE', 'www.obaits.com'),
        'tax_number'  => env('INVOICE_SELLER_TAX_NUMBER', ''),
        'reg_number'  => env('INVOICE_SELLER_REG_NUMBER', ''),
    ],

    'currency' => 'USD',

    'payment_terms' => env('INVOICE_PAYMENT_TERMS', 'Due on receipt'),
];
