<?php

return [
    'greeting' => 'Bonjour :name,',

    'account_locked' => [
        'subject' => 'Votre compte DivaWaste est temporairement verrouillé',
        'line' => 'Après 5 tentatives de connexion infructueuses, votre compte est verrouillé pendant :minutes minutes.',
        'advice' => 'Si vous n\'êtes pas à l\'origine de ces tentatives, réinitialisez votre mot de passe dès la fin du verrouillage.',
    ],

    'reset_password' => [
        'subject' => 'Réinitialisation de votre mot de passe',
        'line' => 'Vous recevez ce message car une réinitialisation de mot de passe a été demandée pour votre compte.',
        'action' => 'Choisir un nouveau mot de passe',
        'expiry' => 'Ce lien expire dans :minutes minutes. Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message.',
    ],

    'verify_email' => [
        'subject' => 'Confirmez votre adresse email',
        'line' => 'Merci de confirmer votre adresse email pour finaliser votre inscription sur DivaWaste.',
        'action' => 'Confirmer mon adresse',
        'expiry' => 'Ce lien est valable :hours heures.',
    ],

    'invitation' => [
        'subject' => ':company vous invite sur DivaWaste',
        'line' => ':inviter vous invite à rejoindre :company avec le rôle « :role ».',
        'action' => 'Accepter l\'invitation',
        'expiry' => 'Cette invitation expire dans :days jours.',
    ],

    'company_approved' => [
        'subject' => 'Votre compte DivaWaste est validé',
        'line' => 'L\'inscription de :company a été validée. Vous pouvez maintenant vous connecter et configurer votre espace.',
        'action' => 'Accéder à mon espace',
    ],

    'company_rejected' => [
        'subject' => 'Votre inscription DivaWaste n\'a pas été retenue',
        'line' => 'L\'inscription de :company n\'a pas pu être validée.',
        'reason' => 'Motif : :reason',
    ],

    'company_suspended' => [
        'subject' => 'Votre compte DivaWaste est suspendu',
        'line' => 'L\'accès de :company à la plateforme est suspendu.',
        'reason' => 'Motif : :reason',
    ],

    'company_reactivated' => [
        'subject' => 'Votre compte DivaWaste est réactivé',
        'line' => 'L\'accès de :company à la plateforme est rétabli.',
        'action' => 'Accéder à mon espace',
    ],

    'event' => [
        'action' => 'Ouvrir dans DivaWaste',
    ],
];
