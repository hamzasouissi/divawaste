<?php

// Import des données de production : nom des informations attendues et messages par ligne.
return [
    'targets' => [
        'clients' => 'Clients', 'suppliers' => 'Fournisseurs', 'materials' => 'Matières premières', 'articles' => 'Articles',
        'orders' => 'Commandes', 'manufacturing_orders' => 'Ordres de fabrication',
    ],
    'fields' => [
        'clients' => ['code' => 'Code', 'name' => 'Nom', 'country_code' => 'Pays (code)', 'contact_name' => 'Contact', 'email' => 'Email', 'phone' => 'Téléphone'],
        'suppliers' => ['code' => 'Code', 'name' => 'Nom', 'country_code' => 'Pays (code)', 'contact_name' => 'Contact', 'email' => 'Email', 'phone' => 'Téléphone'],
        'materials' => [
            'reference' => 'Référence', 'name' => 'Désignation', 'supplier' => 'Fournisseur', 'unit' => 'Unité (m, kg, pièce)', 'color_label' => 'Couleur',
            'grammage_gsm' => 'Grammage (g/m²)', 'batch_number' => 'N° de lot reçu', 'received_on' => 'Date de réception', 'batch_quantity' => 'Quantité reçue',
        ],
        'articles' => [
            'reference' => 'Référence', 'name' => 'Désignation', 'family' => 'Famille', 'client' => 'Client', 'material' => 'Matière première',
            'quantity_per_piece' => 'Consommation par pièce',
        ],
        'orders' => [
            'order_number' => 'N° de commande', 'client' => 'Client', 'article' => 'Article', 'quantity' => 'Quantité',
            'ordered_on' => 'Date de commande', 'due_on' => 'Date de livraison',
        ],
        'manufacturing_orders' => [
            'order_number' => 'N° d\'OF', 'article' => 'Article', 'quantity_planned' => 'Quantité prévue', 'quantity_produced' => 'Quantité produite',
            'customer_order' => 'N° de commande', 'client' => 'Client', 'started_on' => 'Date de début', 'finished_on' => 'Date de fin',
            'step' => 'Étape', 'site' => 'Site', 'pieces_done' => 'Pièces réalisées à l\'étape', 'material' => 'Matière consommée',
            'consumed_quantity' => 'Quantité consommée', 'batch_number' => 'N° de lot de la matière',
        ],
    ],
    'errors' => [
        'unreadable' => 'Ce fichier ne peut pas être lu. Il doit contenir une ligne de titres suivie d\'au moins une ligne (Excel ou CSV).',
        'column_required' => 'Indiquez la colonne qui contient « :field ».',
        'required' => '« :field » est vide.',
        'unknown' => ':what inconnu(e) : « :value ».',
        'unit' => 'Unité inconnue : « :value » (attendu : m, kg ou pièce).',
        'unexpected' => 'Cette ligne n\'a pas pu être enregistrée.',
        'format' => [
            'integer' => '« :value » n\'est pas un nombre entier.',
            'decimal' => '« :value » n\'est pas un nombre.',
            'date' => '« :value » n\'est pas une date reconnue (JJ/MM/AAAA ou AAAA-MM-JJ).',
            'text' => '« :value » n\'est pas valide.',
        ],
    ],
];
