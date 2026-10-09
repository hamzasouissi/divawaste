<?php

// Import of the production data: name of the expected information and messages per line.
return [
    'targets' => [
        'clients' => 'Clients', 'suppliers' => 'Suppliers', 'materials' => 'Raw materials', 'articles' => 'Articles',
        'orders' => 'Orders', 'manufacturing_orders' => 'Manufacturing orders',
        'received_lots' => 'Received lots (materials)', 'consumptions' => 'Materials consumed per order', 'produced_lots' => 'Produced lots',
        'expenses' => 'Production expenses', 'invoices' => 'Customer invoices',
    ],
    'fields' => [
        'clients' => ['code' => 'Code', 'name' => 'Name', 'country_code' => 'Country (code)', 'contact_name' => 'Contact', 'email' => 'Email', 'phone' => 'Phone'],
        'suppliers' => ['code' => 'Code', 'name' => 'Name', 'country_code' => 'Country (code)', 'contact_name' => 'Contact', 'email' => 'Email', 'phone' => 'Phone'],
        'materials' => [
            'reference' => 'Reference', 'name' => 'Description', 'category' => 'Category', 'supplier' => 'Default supplier', 'unit' => 'Unit (m, kg, piece)', 'color_label' => 'Colour',
            'grammage_gsm' => 'Weight (g/m²)', 'width_cm' => 'Width (cm)', 'batch_number' => 'Received batch no.', 'received_on' => 'Reception date', 'batch_quantity' => 'Quantity received',
        ],
        'articles' => [
            'reference' => 'Reference', 'name' => 'Description', 'family' => 'Family', 'client' => 'Client', 'material' => 'Raw material',
            'quantity_per_piece' => 'Consumption per piece',
        ],
        'orders' => [
            'order_number' => 'Order no.', 'client' => 'Client', 'article' => 'Article', 'quantity' => 'Quantity', 'unit_price' => 'Unit selling price excl. tax',
            'ordered_on' => 'Order date', 'due_on' => 'Delivery date',
        ],
        'manufacturing_orders' => [
            'order_number' => 'Manufacturing order no.', 'article' => 'Article', 'quantity_planned' => 'Planned quantity', 'quantity_produced' => 'Produced quantity',
            'customer_order' => 'Order no.', 'client' => 'Client', 'started_on' => 'Start date', 'finished_on' => 'End date',
            'step' => 'Stage', 'site' => 'Site', 'pieces_done' => 'Pieces done at the stage', 'material' => 'Material consumed',
            'consumed_quantity' => 'Quantity consumed', 'batch_number' => 'Batch no. of the material',
        ],
        'received_lots' => [
            'material' => 'Raw material (reference)', 'batch_number' => 'Received lot number', 'supplier' => 'Supplier of the delivery', 'supplier_batch_reference' => 'Supplier\'s lot number',
            'received_on' => 'Received on', 'batch_quantity' => 'Quantity received', 'unit_price' => 'Unit price excl. tax', 'total_amount' => 'Amount excl. tax', 'delivery_cost' => 'Delivery cost',
            'currency' => 'Currency', 'delivery_note' => 'Delivery note', 'invoice_reference' => 'Supplier invoice', 'external_reference' => 'External reference',
        ],
        'consumptions' => [
            'manufacturing_order' => 'Manufacturing order number', 'material' => 'Raw material (reference)', 'consumed_quantity' => 'Quantity consumed', 'batch_number' => 'Received lot number',
            'step' => 'Stage', 'site' => 'Site', 'consumed_on' => 'Date',
        ],
        'produced_lots' => [
            'lot_number' => 'Produced lot number', 'manufacturing_order' => 'Manufacturing order number', 'quantity' => 'Quantity produced', 'produced_on' => 'Produced on', 'status' => 'Status',
            'delivered_on' => 'Delivered on', 'step' => 'Stage', 'site' => 'Site', 'external_reference' => 'External reference',
        ],
        'expenses' => [
            'manufacturing_order' => 'Manufacturing order number', 'reference' => 'Supporting document (number)', 'description' => 'Description', 'amount' => 'Amount excl. tax', 'category' => 'Category',
            'incurred_on' => 'Date', 'supplier' => 'Supplier / payee', 'currency' => 'Currency',
        ],
        'invoices' => [
            'number' => 'Invoice number', 'customer_order' => 'Order number', 'amount' => 'Amount excl. tax', 'kind' => 'Type (invoice, credit note)', 'issued_on' => 'Date',
            'manufacturing_order' => 'Manufacturing order number', 'currency' => 'Currency',
        ],
    ],
    'errors' => [
        'amount_mismatch' => 'The amount does not match the quantity multiplied by the unit price.',
        'unknown_lot' => 'The received lot ":lot" does not exist for the material ":material". Import the received lots first, or leave the column empty (lot not specified).',
        'lot_of_other_order' => 'The produced lot ":lot" already belongs to another manufacturing order.',
        'order_of_other' => 'The manufacturing order ":of" does not belong to the order ":order".',
        'invoice_of_other_order' => 'The invoice ":number" is already recorded on another order.',
        'currency' => 'Unknown currency: ":value" (three-letter code, for example TND or EUR).',
        'unreadable' => 'This file cannot be read. It must contain a heading line followed by at least one line (Excel or CSV).',
        'column_required' => 'Say which column holds ":field".',
        'required' => '":field" is empty.',
        'unknown' => 'Unknown :what: ":value".',
        'unit' => 'Unknown unit: ":value" (expected: m, kg or piece).',
        'unexpected' => 'This line could not be saved.',
        'format' => [
            'integer' => '":value" is not a whole number.',
            'decimal' => '":value" is not a number.',
            'date' => '":value" is not a recognised date (DD/MM/YYYY or YYYY-MM-DD).',
            'text' => '":value" is not valid.',
        ],
    ],
];
