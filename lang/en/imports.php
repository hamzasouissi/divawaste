<?php

// Import of the production data: name of the expected information and messages per line.
return [
    'targets' => [
        'clients' => 'Clients', 'suppliers' => 'Suppliers', 'materials' => 'Raw materials', 'articles' => 'Articles',
        'orders' => 'Orders', 'manufacturing_orders' => 'Manufacturing orders',
    ],
    'fields' => [
        'clients' => ['code' => 'Code', 'name' => 'Name', 'country_code' => 'Country (code)', 'contact_name' => 'Contact', 'email' => 'Email', 'phone' => 'Phone'],
        'suppliers' => ['code' => 'Code', 'name' => 'Name', 'country_code' => 'Country (code)', 'contact_name' => 'Contact', 'email' => 'Email', 'phone' => 'Phone'],
        'materials' => [
            'reference' => 'Reference', 'name' => 'Description', 'supplier' => 'Supplier', 'unit' => 'Unit (m, kg, piece)', 'color_label' => 'Colour',
            'grammage_gsm' => 'Weight (g/m²)', 'batch_number' => 'Received batch no.', 'received_on' => 'Reception date', 'batch_quantity' => 'Quantity received',
        ],
        'articles' => [
            'reference' => 'Reference', 'name' => 'Description', 'family' => 'Family', 'client' => 'Client', 'material' => 'Raw material',
            'quantity_per_piece' => 'Consumption per piece',
        ],
        'orders' => [
            'order_number' => 'Order no.', 'client' => 'Client', 'article' => 'Article', 'quantity' => 'Quantity',
            'ordered_on' => 'Order date', 'due_on' => 'Delivery date',
        ],
        'manufacturing_orders' => [
            'order_number' => 'Manufacturing order no.', 'article' => 'Article', 'quantity_planned' => 'Planned quantity', 'quantity_produced' => 'Produced quantity',
            'customer_order' => 'Order no.', 'client' => 'Client', 'started_on' => 'Start date', 'finished_on' => 'End date',
            'step' => 'Stage', 'site' => 'Site', 'pieces_done' => 'Pieces done at the stage', 'material' => 'Material consumed',
            'consumed_quantity' => 'Quantity consumed', 'batch_number' => 'Batch no. of the material',
        ],
    ],
    'errors' => [
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
