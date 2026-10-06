<?php

// Invoice issuer identity, snapshotted on every issued invoice (M7-04).
return [
    'seller' => [
        'legal_name' => env('BILLING_SELLER_LEGAL_NAME', 'Diva Software'),
        'tax_id' => env('BILLING_SELLER_TAX_ID'),
        'vat_number' => env('BILLING_SELLER_VAT_NUMBER'),
        'address' => env('BILLING_SELLER_ADDRESS'),
        'country' => env('BILLING_SELLER_COUNTRY', 'TN'),
    ],
    'invoice_prefix' => env('BILLING_INVOICE_PREFIX', 'DW'),
];
