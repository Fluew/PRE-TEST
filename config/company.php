<?php

/*
| Company details printed on customer documents (quotations, Sales Orders, receipts, invoices)
| and in customer emails. Set the real values in .env.
|
| bir_registered: set to true only when these system-generated invoices are BIR-registered
| (accredited CAS / authority to print). While false, every invoice and receipt says it is a
| system-generated document that is not a BIR-registered invoice or official receipt.
*/

return [
    'name' => env('COMPANY_NAME', 'MR-PRIDE Electrical Supplies'),
    'tagline' => env('COMPANY_TAGLINE', 'Industrial & Commercial Electrical Solutions'),
    'address' => env('COMPANY_ADDRESS', ''),
    'phone' => env('COMPANY_PHONE', '+63 (2) 8123-4567'),
    'email' => env('COMPANY_EMAIL', 'info@mrpelectrodesk.ph'),
    'tin' => env('COMPANY_TIN', ''),
    'bir_registered' => (bool) env('COMPANY_BIR_REGISTERED', false),

    // Shown in the "Sales Order created" email. Leave empty until the real account details are set.
    'payment_instructions' => [
        'Bank Transfer' => env('COMPANY_BANK_DETAILS', ''),
        'GCash' => env('COMPANY_GCASH_DETAILS', ''),
    ],

    // Amounts are stored and printed in this currency (see config/currency.php).
    'currency' => 'USD',
];
