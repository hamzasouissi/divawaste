<?php

return [
    'origin' => ['created' => 'Production', 'split' => 'Split', 'grouping' => 'Grouping'],
    'lot_status' => [
        'created' => 'Created', 'stored' => 'Stored', 'awaiting_pickup' => 'Awaiting pickup',
        'collected' => 'Collected', 'treated' => 'Treated', 'closed' => 'Closed',
    ],
    'report' => [
        'waste_register' => 'Chronological waste register',
        'rse_summary' => 'CSR report — waste management',
        'economic_balance' => 'Waste economic balance',
    ],
    'channel' => [
        'reuse' => 'Reuse', 'recycling' => 'Recycling', 'energy_recovery' => 'Energy recovery',
        'incineration' => 'Incineration', 'other_treatment' => 'Other treatment', 'landfill' => 'Landfill',
    ],
    'signer' => ['producer' => 'Producer', 'transporter' => 'Transporter', 'receiver' => 'Receiver'],
];
