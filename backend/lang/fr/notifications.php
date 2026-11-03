<?php

// Titre et corps de chaque événement métier (M7-01). Les mêmes clés existent côté Angular pour l'affichage in-app.
return [
    'stock' => [
        'threshold_warning' => [
            'title' => 'Limite de stock bientôt atteinte — :site',
            'body' => 'Le stock atteint :quantity pour une limite de :max_quantity (:scope).',
        ],
        'threshold_exceeded' => [
            'title' => 'Limite de stock dépassée — :site',
            'body' => 'Le stock atteint :quantity et dépasse de :excess la limite de :max_quantity (:scope). Planifiez un enlèvement.',
        ],
        'threshold_unverifiable' => [
            'title' => 'Limite de stock non vérifiable — :site',
            'body' => 'Limite de :max_quantity (:scope) : la quantité connue est de :quantity, mais :unweighed_lots lot(s) ne peuvent pas être comptés dans cette unité (:lots) : poids, quantité ou nombre de colis non renseigné. La limite n\'est pas dépassée sur ce qui est connu, et elle ne peut pas être dite respectée : complétez ces lots.',
        ],
    ],
    'capacity' => [
        'space_warning' => ['title' => 'Zone bientôt pleine — :scope', 'body' => 'La place de la zone est occupée à :pct % (:detail). Aucun lot n\'est refusé ; pensez à libérer de la place.'],
        'space_exceeded' => ['title' => 'Place de la zone dépassée — :scope', 'body' => 'La place de la zone est occupée à :pct % (:detail) : sa capacité est dépassée. Les lots continuent d\'être acceptés ; libérez de la place ou revoyez la capacité de la zone.'],
        'space_unverifiable' => ['title' => 'Place de la zone non vérifiable — :scope', 'body' => 'La place connue est occupée à au moins :pct % (:detail), mais :missing lot(s) ne peuvent pas être comptés (:lots) : volume ou conditionnement non renseigné. Ce n\'est pas un dépassement constaté ; l\'occupation ne peut pas être dite dans la capacité.'],
        'load_warning' => ['title' => 'Charge de la zone bientôt atteinte — :scope', 'body' => 'La zone porte :detail (:pct % de sa charge maximale).'],
        'load_exceeded' => ['title' => 'Charge de la zone dépassée — :scope', 'body' => 'La zone porte :detail : sa charge maximale est dépassée de :excess. Les lots continuent d\'être acceptés ; déplacez ou évacuez des lots.'],
        'load_unverifiable' => ['title' => 'Charge de la zone non vérifiable — :scope', 'body' => 'Poids connu : :detail, mais :missing lot(s) ne sont pas pesés (:lots). Ce n\'est pas un dépassement constaté ; la charge ne peut pas être dite respectée : pesez ces lots.'],
        'site_warning' => ['title' => 'Capacité du site bientôt atteinte — :site', 'body' => 'Le site contient :detail (:pct % de son poids maximal), toutes zones confondues.'],
        'site_exceeded' => ['title' => 'Capacité du site dépassée — :site', 'body' => 'Le site contient :detail, toutes zones confondues : son poids maximal est dépassé de :excess. Les lots continuent d\'être acceptés ; planifiez un enlèvement.'],
        'site_unverifiable' => ['title' => 'Capacité du site non vérifiable — :site', 'body' => 'Poids connu sur le site : :detail, mais :missing lot(s) ne sont pas pesés (:lots). Ce n\'est pas un dépassement constaté ; la capacité ne peut pas être dite respectée : pesez ces lots.'],
    ],
    'contract' => [
        'activated' => ['title' => 'Contrat :number en vigueur', 'body' => ':company a mis en vigueur le contrat « :title ».'],
        'ending_soon' => ['title' => 'Contrat :number bientôt échu', 'body' => 'Le contrat « :title » avec :company prend fin le :date.'],
        'pickup_due' => ['title' => 'Enlèvement prévu :when : contrat :number', 'body' => "Le contrat « :title » avec :partner prévoit un enlèvement le :date, et aucun n'est demandé : préparez la demande."],
        'terminated' => ['title' => 'Contrat :number résilié', 'body' => ':company a mis fin au contrat « :title » au :date.'],
    ],
    'marketplace' => [
        'listing_published' => ['title' => 'Nouvelle annonce sur la bourse — :title', 'body' => 'L\'annonce :number (:waste_type) correspond aux déchets que vous acceptez. Consultez-la et faites une offre.'],
        'offer_received' => ['title' => 'Nouvelle offre — annonce :number', 'body' => ':buyer a fait une offre sur l\'annonce « :title ». Acceptez-la, refusez-la ou faites une contre-proposition.'],
        'counter_offer' => ['title' => 'Contre-proposition — annonce :number', 'body' => 'Une nouvelle proposition attend votre réponse sur l\'annonce « :title ».'],
        'offer_accepted' => ['title' => 'Proposition acceptée — annonce :number', 'body' => 'L\'accord sur l\'annonce « :title » est conclu. Un enlèvement en brouillon a été préparé au prix convenu.'],
        'offer_refused' => ['title' => 'Proposition refusée — annonce :number', 'body' => 'La négociation sur l\'annonce « :title » a été refusée.'],
        'offer_withdrawn' => ['title' => 'Offre retirée — annonce :number', 'body' => ':buyer a retiré son offre sur l\'annonce « :title ».'],
        'listing_closed' => ['title' => 'Annonce close — :number', 'body' => 'L\'annonce « :title » est close : :reason. Votre négociation est terminée.'],
        'reasons' => ['sold_to_another' => 'les lots ont été cédés à un autre preneur', 'listing_cancelled' => 'le vendeur a retiré l\'annonce', 'listing_expired' => 'sa date de clôture est passée'],
        'pickup_note' => 'Accord de la bourse, annonce :number : le prix convenu est appliqué à la réception.',
    ],
    'pickup' => [
        'requested' => [
            'title' => 'Nouvelle demande d\'enlèvement :number',
            'body' => ':company demande un enlèvement le :date sur le site :site (:lots lot(s) : :quantity).',
        ],
        'confirmed' => [
            'title' => 'Enlèvement :number confirmé',
            'body' => ':provider a confirmé l\'enlèvement :number pour le :date.',
        ],
        'refused' => [
            'title' => 'Enlèvement :number refusé',
            'body' => ':provider a refusé l\'enlèvement :number. Motif : :reason',
        ],
        'transport_requested' => [
            'title' => 'Transport à confirmer — enlèvement :number',
            'body' => ':provider a confirmé l\'enlèvement :number de :company pour le :date. Vous en êtes le transporteur : confirmez que vous le transportez ce jour-là, ou refusez.',
        ],
        'awaiting_transporter' => [
            'title' => 'Enlèvement :number : en attente du transporteur',
            'body' => ':provider a confirmé l\'enlèvement :number pour le :date. Il sera confirmé quand :transporter aura confirmé le transport.',
        ],
        'transport_confirmed' => [
            'title' => 'Enlèvement :number confirmé',
            'body' => ':transporter a confirmé le transport de l\'enlèvement :number le :date vers :provider. L\'enlèvement est confirmé.',
        ],
        'transport_refused' => [
            'title' => 'Transport de l\'enlèvement :number refusé',
            'body' => ':transporter refuse de transporter l\'enlèvement :number. Motif : :reason. Les lots reviennent en stock ; demandez l\'enlèvement avec un autre transporteur.',
        ],
        'cancelled' => [
            'title' => 'Enlèvement :number annulé',
            'body' => ':company a annulé l\'enlèvement :number. Motif : :reason',
        ],
        'collected' => [
            'title' => 'Enlèvement :number chargé',
            'body' => 'Les lots de l\'enlèvement :number ont quitté le site (:quantity au départ).',
        ],
        'requirements_unmet_at_loading' => [
            'title' => 'Enlèvement :number chargé — exigence non respectée',
            'body' => 'Les lots de l\'enlèvement :number ont quitté le site (:quantity au départ, :left lot(s) restés sur site). Sur ce qui est parti, vos exigences ne sont pas toutes respectées : :requirements. Vous pouvez en parler avec :company avant la réception.',
        ],
        'requirement_exception' => [
            'title' => 'Enlèvement :number parti sous une exigence de :provider',
            'body' => 'Sur ce qui a été chargé (:quantity), une exigence bloquante de :provider n\'est plus respectée : :requirements. Le départ n\'est pas annulé ; indiquez pourquoi sur la page de l\'enlèvement.',
        ],
        'received' => [
            'title' => 'Enlèvement :number réceptionné',
            'body' => ':provider a réceptionné :quantity pour l\'enlèvement :number.',
        ],
        'variance_flagged' => [
            'title' => 'Écart à la réception sur l\'enlèvement :number',
            'body' => ':flagged lot(s) reçu(s) hors tolérance : :received reçu(s) pour :departure au départ.',
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
    'authorization' => [
        'usage_high' => [
            'title' => 'Autorisation :reference bientôt épuisée',
            'body' => 'Une limite de l\'autorisation :reference (:waste_type) est utilisée à :pct %. Prévoyez une nouvelle autorisation : sans elle, les prochains enlèvements seront bloqués.',
        ],
        'expiring_soon' => [
            'title' => 'Autorisation :reference : fin de validité le :date',
            'body' => 'L\'autorisation :reference (:waste_type) prend fin le :date, dans :days jour(s). Sans nouvelle autorisation, les enlèvements de ce déchet seront bloqués.',
        ],
    ],
    'document' => [
        'signature_requested' => [
            'title' => 'Bordereau :number à signer',
            'body' => 'Le bordereau de suivi :number attend votre signature.',
        ],
        'signed_copy_requested' => [
            'title' => 'Bordereau :number : exemplaire signé à joindre',
            'body' => 'Toutes les parties ont signé le bordereau :number. Joignez le scan de l\'exemplaire signé à la main pour le clôturer.',
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
        'activated' => ['title' => 'Votre abonnement DivaWaste est activé', 'body' => 'La formule « :plan » de :company est active depuis le :start. Votre première facture :invoice, d\'un montant de :amount, est disponible dans « Mon abonnement ». Échéance de paiement : :due_date, par virement bancaire.'],
        'trial_ending' => ['title' => 'Votre essai gratuit se termine le :date', 'body' => 'Il reste :days jours d\'essai. Choisissez une formule pour continuer à utiliser DivaWaste.'],
    ],
    'company' => [
        'pending_review' => ['title' => 'Inscription à valider — :company', 'body' => ':company (:type) attend la validation de son inscription.'],
    ],
];
