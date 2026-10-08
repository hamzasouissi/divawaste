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
        'measured' => 'Mesuré', 'estimated' => 'Estimé',
        'method' => 'Mesuré : le lot désigne son ordre de fabrication. Estimé (≈) : le lot n\'indique que son étape ; son poids est réparti entre les ordres présents à cette étape, sur ce site, ce jour-là, au prorata des pièces réalisées. Non attribué : le lot n\'indique rien, ou aucun ordre n\'était à son étape. Le taux de chute est la part de la matière consommée devenue déchet.',
        'sheet' => 'Statistiques de production', 'by_order' => 'Par ordre de fabrication', 'by_client' => 'Par client', 'by_article' => 'Par article', 'by_step' => 'Par étape',
        'by_material' => 'Par matière première', 'by_supplier' => 'Par fournisseur', 'unattributed' => 'Non attribué',
        'columns' => ['section' => 'Rubrique', 'label' => 'Libellé', 'detail' => 'Détail', 'lots' => 'Lots', 'measured_kg' => 'Mesuré (kg)', 'estimated_kg' => 'Estimé (kg)',
            'total_kg' => 'Total (kg)', 'pieces' => 'Pièces', 'g_per_piece' => 'g par pièce', 'consumed_kg' => 'Matière consommée (kg)', 'waste_rate_pct' => 'Taux de chute (%)', 'net_amount' => 'Bilan'],
    ],
];
