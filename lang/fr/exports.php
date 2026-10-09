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
        'finance_sheet' => 'Coûts et gains',
        'status' => ['planned' => 'Planifié', 'in_progress' => 'En cours', 'completed' => 'Terminé', 'cancelled' => 'Annulé'],
        'missing' => ['no_order' => 'pas de commande', 'no_price' => 'prix de vente manquant', 'no_production' => 'quantité fabriquée manquante', 'unknown_material_cost' => 'coût matière inconnu',
            'no_consumption' => 'consommation non saisie', 'expense_currency' => 'dépense dans une autre devise', 'order_currency' => 'commande dans une autre devise'],
        'finance_columns' => ['order_number' => 'OF', 'article' => 'Article', 'client' => 'Client', 'customer_order' => 'Commande', 'status' => 'Statut', 'quantity_planned' => 'Quantité prévue',
            'quantity_produced' => 'Quantité fabriquée', 'planned_material_cost' => 'Coût matière prévu (:currency)', 'material_cost_known' => 'Coût matière réel (:currency)',
            'material_cost_estimated' => 'Coût matière estimé (:currency)', 'expenses' => 'Autres dépenses (:currency)', 'total_cost' => 'Coût de production enregistré (:currency)',
            'unit_cost' => 'Coût par pièce (:currency)', 'unit_price' => 'Prix de vente net (:currency)', 'produced_value' => 'Valeur de la production (:currency)',
            'invoiced' => 'Facturé (:currency)', 'gain' => 'Gain enregistré (:currency)', 'margin_pct' => 'Marge (%)', 'missing' => 'Données manquantes'],
        'columns' => ['section' => 'Rubrique', 'label' => 'Libellé', 'detail' => 'Détail', 'lots' => 'Lots', 'measured_kg' => 'Mesuré (kg)', 'estimated_kg' => 'Estimé (kg)',
            'total_kg' => 'Total (kg)', 'pieces' => 'Pièces', 'g_per_piece' => 'g par pièce', 'consumed_kg' => 'Matière consommée (kg)', 'waste_rate_pct' => 'Taux de chute (%)', 'net_amount' => 'Bilan'],
    ],
];
