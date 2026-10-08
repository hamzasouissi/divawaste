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
    'production' => [
        'sheet' => 'Production statistics', 'by_order' => 'By manufacturing order', 'by_client' => 'By client', 'by_article' => 'By article', 'by_step' => 'By stage',
        'by_material' => 'By raw material', 'by_supplier' => 'By supplier', 'unattributed' => 'Not attributed',
        'columns' => ['section' => 'Section', 'label' => 'Label', 'detail' => 'Detail', 'lots' => 'Lots', 'measured_kg' => 'Measured (kg)', 'estimated_kg' => 'Estimated (kg)',
            'total_kg' => 'Total (kg)', 'pieces' => 'Pieces', 'g_per_piece' => 'g per piece', 'consumed_kg' => 'Material consumed (kg)', 'waste_rate_pct' => 'Waste rate (%)', 'net_amount' => 'Balance'],
    ],
];
