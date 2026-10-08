<?php

// Libellés des documents générés (PDF, Excel).
return [
    'origin' => ['created' => 'Production', 'split' => 'Fractionnement', 'grouping' => 'Regroupement'],
    'lot_status' => [
        'created' => 'Créé', 'stored' => 'Stocké', 'awaiting_pickup' => 'En attente d\'enlèvement',
        'collected' => 'Enlevé', 'treated' => 'Traité', 'closed' => 'Clôturé',
    ],
    'report' => [
        'waste_register' => 'Registre chronologique des déchets',
        'rse_summary' => 'Rapport RSE — gestion des déchets',
        'economic_balance' => 'Bilan économique des déchets',
    ],
    'channel' => [
        'reuse' => 'Réutilisation', 'recycling' => 'Recyclage', 'energy_recovery' => 'Valorisation énergétique',
        'incineration' => 'Incinération sans valorisation', 'other_treatment' => 'Autre traitement', 'landfill' => 'Enfouissement',
    ],
    'signer' => ['producer' => 'Producteur', 'transporter' => 'Transporteur', 'receiver' => 'Destinataire'],
    'production' => [
        'sheet' => 'Statistiques de production', 'by_order' => 'Par ordre de fabrication', 'by_client' => 'Par client', 'by_article' => 'Par article', 'by_step' => 'Par étape',
        'by_material' => 'Par matière première', 'by_supplier' => 'Par fournisseur', 'unattributed' => 'Non attribué',
        'columns' => ['section' => 'Rubrique', 'label' => 'Libellé', 'detail' => 'Détail', 'lots' => 'Lots', 'measured_kg' => 'Mesuré (kg)', 'estimated_kg' => 'Estimé (kg)',
            'total_kg' => 'Total (kg)', 'pieces' => 'Pièces', 'g_per_piece' => 'g par pièce', 'consumed_kg' => 'Matière consommée (kg)', 'waste_rate_pct' => 'Taux de chute (%)', 'net_amount' => 'Bilan'],
    ],
];
