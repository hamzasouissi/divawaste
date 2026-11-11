<?php

// Import des données de production : nom des informations attendues et messages par ligne.
return [
    'targets' => [
        'clients' => 'Clients', 'suppliers' => 'Fournisseurs', 'materials' => 'Matières premières', 'articles' => 'Articles',
        'orders' => 'Commandes', 'manufacturing_orders' => 'Ordres de fabrication',
        'received_lots' => 'Lots reçus (matières)', 'consumptions' => 'Matières consommées par OF', 'produced_lots' => 'Lots fabriqués',
        'expenses' => 'Dépenses de production', 'invoices' => 'Factures clients', 'waste_lots' => 'Lots de déchets',
    ],
    'fields' => [
        'clients' => ['code' => 'Code', 'name' => 'Nom', 'country_code' => 'Pays (code)', 'contact_name' => 'Contact', 'email' => 'Email', 'phone' => 'Téléphone'],
        'suppliers' => ['code' => 'Code', 'name' => 'Nom', 'country_code' => 'Pays (code)', 'contact_name' => 'Contact', 'email' => 'Email', 'phone' => 'Téléphone'],
        'materials' => [
            'reference' => 'Référence', 'name' => 'Désignation', 'category' => 'Catégorie', 'supplier' => 'Fournisseur par défaut', 'unit' => 'Unité (m, kg, pièce)', 'color_label' => 'Couleur', 'waste_type' => 'Type de déchet produit',
            'grammage_gsm' => 'Grammage (g/m²)', 'width_cm' => 'Laize (cm)', 'nature' => 'Nature (tissu, fil, fourniture, emballage, chimique, autre)', 'unit_weight_kg' => 'Poids d\'une pièce (kg)', 'batch_number' => 'N° de lot reçu', 'received_on' => 'Date de réception', 'batch_quantity' => 'Quantité reçue',
        ],
        'articles' => [
            'reference' => 'Référence', 'name' => 'Désignation', 'family' => 'Famille', 'client' => 'Client', 'material' => 'Matière première',
            'quantity_per_piece' => 'Consommation par pièce',
            'efficiency_pct' => 'Efficience du placement (%)',
        ],
        'orders' => [
            'order_number' => 'N° de commande', 'client' => 'Client', 'article' => 'Article', 'quantity' => 'Quantité', 'unit_price' => 'Prix de vente unitaire HT',
            'ordered_on' => 'Date de commande', 'due_on' => 'Date de livraison',
        ],
        'manufacturing_orders' => [
            'order_number' => 'N° d\'OF', 'article' => 'Article', 'quantity_planned' => 'Quantité prévue', 'quantity_produced' => 'Quantité produite',
            'customer_order' => 'N° de commande', 'client' => 'Client', 'started_on' => 'Date de début', 'finished_on' => 'Date de fin',
            'step' => 'Étape', 'site' => 'Site', 'pieces_done' => 'Pièces réalisées à l\'étape', 'material' => 'Matière consommée',
            'consumed_quantity' => 'Quantité consommée', 'batch_number' => 'N° de lot de la matière',
        ],
        'received_lots' => [
            'material' => 'Matière première (référence)', 'batch_number' => 'N° de lot reçu', 'supplier' => 'Fournisseur de la livraison', 'supplier_batch_reference' => 'N° de lot du fournisseur',
            'received_on' => 'Date de réception', 'batch_quantity' => 'Quantité reçue', 'unit_price' => 'Prix unitaire HT', 'total_amount' => 'Montant HT', 'delivery_cost' => 'Frais de livraison',
            'currency' => 'Devise', 'delivery_note' => 'Bon de livraison', 'invoice_reference' => 'Facture fournisseur', 'external_reference' => 'Référence externe',
        ],
        'consumptions' => [
            'manufacturing_order' => 'N° d\'OF', 'material' => 'Matière première (référence)', 'consumed_quantity' => 'Quantité consommée', 'batch_number' => 'N° de lot reçu',
            'step' => 'Étape', 'site' => 'Site', 'consumed_on' => 'Date',
        ],
        'produced_lots' => [
            'lot_number' => 'N° de lot fabriqué', 'manufacturing_order' => 'N° d\'OF', 'quantity' => 'Quantité fabriquée', 'produced_on' => 'Date de fabrication', 'status' => 'Statut',
            'delivered_on' => 'Date de livraison', 'step' => 'Étape', 'site' => 'Site', 'external_reference' => 'Référence externe',
        ],
        'expenses' => [
            'manufacturing_order' => 'N° d\'OF', 'reference' => 'Pièce justificative (n°)', 'description' => 'Libellé', 'amount' => 'Montant HT', 'category' => 'Catégorie',
            'incurred_on' => 'Date', 'supplier' => 'Fournisseur / bénéficiaire', 'currency' => 'Devise',
        ],
        'invoices' => [
            'number' => 'N° de facture', 'customer_order' => 'N° de commande', 'amount' => 'Montant HT', 'kind' => 'Type (facture, avoir)', 'issued_on' => 'Date',
            'manufacturing_order' => 'N° d\'OF', 'currency' => 'Devise',
        ],
        'waste_lots' => [
            'site' => 'Site', 'zone' => 'Zone', 'waste_type' => 'Type de déchet', 'net_weight_kg' => 'Poids (kg)', 'weight_estimated' => 'Poids estimé (oui / non)',
            'quantity' => 'Quantité', 'unit' => 'Unité (kg, t, l, m³, pièce)', 'packaging' => 'Emballage', 'package_count' => 'Nombre d\'emballages', 'generated_at' => 'Date du lot',
            'manufacturing_order' => 'N° d\'OF', 'step' => 'Étape de production', 'notes' => 'Remarques', 'external_reference' => 'Votre référence du lot',
        ],
    ],
    'errors' => [
        'amount_mismatch' => 'Le montant ne correspond pas à la quantité multipliée par le prix unitaire.',
        'unknown_lot' => 'Le lot reçu « :lot » n\'existe pas pour la matière « :material ». Importez d\'abord les lots reçus, ou laissez la colonne vide (lot non précisé).',
        'lot_of_other_order' => 'Le lot fabriqué « :lot » appartient déjà à un autre OF.',
        'order_of_other' => 'L\'OF « :of » n\'appartient pas à la commande « :order ».',
        'invoice_of_other_order' => 'La facture « :number » est déjà enregistrée sur une autre commande.',
        'currency' => 'Devise inconnue : « :value » (code à trois lettres, par exemple TND ou EUR).',
        'unreadable' => 'Ce fichier ne peut pas être lu. Il doit contenir une ligne de titres suivie d\'au moins une ligne (Excel ou CSV).',
        'column_required' => 'Indiquez la colonne qui contient « :field ».',
        'required' => '« :field » est vide.',
        'unknown' => ':what inconnu(e) : « :value ».',
        'unit' => 'Unité inconnue : « :value » (attendu : m, kg ou pièce).',
        'nature' => 'Nature inconnue : « :value » (attendu : tissu, fil, fourniture, emballage, chimique ou autre).',
        'efficiency' => 'Efficience invalide : « :value » (attendu : un pourcentage entre 0 et 100).',
        'unexpected' => 'Cette ligne n\'a pas pu être enregistrée.',
        'site_rights' => 'Vous ne pouvez pas créer de lots sur le site « :site ».',
        'zone_needed' => 'Le site « :site » a plusieurs zones et aucune aire de stockage : indiquez la zone.',
        'lot_unit' => 'Unité inconnue : « :value » (attendu : kg, t, l, m³ ou pièce).',
        'yes_no' => '« :field » : « :value » n\'est ni oui ni non.',
        'format' => [
            'integer' => '« :value » n\'est pas un nombre entier.',
            'decimal' => '« :value » n\'est pas un nombre.',
            'date' => '« :value » n\'est pas une date reconnue (JJ/MM/AAAA ou AAAA-MM-JJ).',
            'text' => '« :value » n\'est pas valide.',
        ],
    ],
    'feeds' => [
        'errors' => [
            'address' => 'L\'adresse doit commencer par https:// et mener à un serveur accessible depuis internet.',
            'download' => 'L\'adresse n\'a pas pu être lue (serveur introuvable ou réponse en erreur).',
        ],
    ],
];
