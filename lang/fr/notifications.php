<?php

// Titre et corps de chaque événement métier (M7-01). Les mêmes clés existent côté Angular pour l'affichage in-app.
return [
    'stock' => [
        'threshold_warning' => [
            'title' => 'Seuil de stock bientôt atteint — :site',
            'body' => 'Le stock de déchets atteint :quantity_kg kg pour un seuil de :max_quantity_kg kg (:site).',
        ],
        'threshold_exceeded' => [
            'title' => 'Seuil de stock dépassé — :site',
            'body' => 'Le stock de déchets atteint :quantity_kg kg et dépasse le seuil de :max_quantity_kg kg (:site). Planifiez un enlèvement.',
        ],
    ],
    'pickup' => [
        'requested' => [
            'title' => 'Nouvelle demande d\'enlèvement :number',
            'body' => ':company demande un enlèvement le :date sur le site :site (:lots lots, :weight_kg kg).',
        ],
        'confirmed' => [
            'title' => 'Enlèvement :number confirmé',
            'body' => ':provider a confirmé l\'enlèvement :number pour le :date.',
        ],
        'refused' => [
            'title' => 'Enlèvement :number refusé',
            'body' => ':provider a refusé l\'enlèvement :number. Motif : :reason',
        ],
        'cancelled' => [
            'title' => 'Enlèvement :number annulé',
            'body' => ':company a annulé l\'enlèvement :number. Motif : :reason',
        ],
        'collected' => [
            'title' => 'Enlèvement :number chargé',
            'body' => 'Les lots de l\'enlèvement :number ont quitté le site (:weight_kg kg au départ).',
        ],
        'received' => [
            'title' => 'Enlèvement :number réceptionné',
            'body' => ':provider a réceptionné :weight_kg kg pour l\'enlèvement :number.',
        ],
        'variance_flagged' => [
            'title' => 'Écart de poids sur l\'enlèvement :number',
            'body' => 'Poids reçu :received_kg kg pour :departure_kg kg au départ, soit un écart de :variance_pct %.',
        ],
        'completed' => [
            'title' => 'Enlèvement :number clôturé',
            'body' => 'L\'enlèvement :number est clôturé.',
        ],
    ],
    'accreditation' => [
        'expiring_soon' => [
            'title' => 'Agrément bientôt expiré — :provider',
            'body' => 'L\'agrément :reference de :provider expire le :date (dans :days jours).',
        ],
        'expired' => [
            'title' => 'Agrément expiré — :provider',
            'body' => 'L\'agrément :reference de :provider a expiré le :date. Ce prestataire ne peut plus être choisi tant qu\'il n\'est pas renouvelé.',
        ],
        'reviewed' => [
            'title' => 'Agrément :reference : :status',
            'body' => 'Votre agrément :reference a été examiné par la plateforme. Décision : :status.',
        ],
    ],
    'document' => [
        'signature_requested' => [
            'title' => 'Bordereau :number à signer',
            'body' => 'Le bordereau de suivi :number attend votre signature.',
        ],
        'certificate_uploaded' => [
            'title' => 'Certificat déposé pour l\'enlèvement :number',
            'body' => ':provider a déposé un certificat pour l\'enlèvement :number.',
        ],
    ],
    'report' => [
        'ready' => ['title' => 'Votre rapport est prêt', 'body' => 'Le rapport « :name » est disponible au téléchargement.'],
        'failed' => ['title' => 'Échec de génération du rapport', 'body' => 'Le rapport « :name » n\'a pas pu être généré.'],
    ],
    'payment' => [
        'declared' => ['title' => 'Virement déclaré — :company', 'body' => ':company déclare un virement de :amount pour la facture :invoice.'],
        'reviewed' => ['title' => 'Virement :status — facture :invoice', 'body' => 'Votre virement de :amount pour la facture :invoice a été :status.'],
    ],
    'invoice' => [
        'issued' => ['title' => 'Nouvelle facture :invoice', 'body' => 'La facture :invoice d\'un montant de :amount est disponible. Échéance : :due_date.'],
    ],
    'subscription' => [
        'trial_ending' => ['title' => 'Votre essai gratuit se termine le :date', 'body' => 'Il reste :days jours d\'essai. Choisissez une formule pour continuer à utiliser DivaWaste.'],
    ],
    'company' => [
        'pending_review' => ['title' => 'Inscription à valider — :company', 'body' => ':company (:type) attend la validation de son inscription.'],
    ],
];
