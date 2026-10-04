<?php

return [
    'greeting' => 'Hello :name,',

    'account_locked' => [
        'subject' => 'Your DivaWaste account is temporarily locked',
        'line' => 'After 5 failed sign-in attempts, your account is locked for :minutes minutes.',
        'advice' => 'If these attempts were not yours, reset your password as soon as the lock ends.',
    ],

    'reset_password' => [
        'subject' => 'Reset your password',
        'line' => 'You are receiving this message because a password reset was requested for your account.',
        'action' => 'Choose a new password',
        'expiry' => 'This link expires in :minutes minutes. If you did not request it, ignore this message.',
    ],

    'verify_email' => [
        'subject' => 'Confirm your email address',
        'line' => 'Please confirm your email address to complete your DivaWaste registration.',
        'action' => 'Confirm my address',
        'expiry' => 'This link is valid for :hours hours.',
    ],

    'invitation' => [
        'subject' => ':company invites you to DivaWaste',
        'line' => ':inviter invites you to join :company with the role ":role".',
        'action' => 'Accept the invitation',
        'expiry' => 'This invitation expires in :days days.',
    ],

    'company_approved' => [
        'subject' => 'Your DivaWaste account is approved',
        'line' => 'The registration of :company has been approved. You can now sign in and set up your workspace.',
        'action' => 'Open my workspace',
    ],

    'company_rejected' => [
        'subject' => 'Your DivaWaste registration was not accepted',
        'line' => 'The registration of :company could not be approved.',
        'reason' => 'Reason: :reason',
    ],

    'company_suspended' => [
        'subject' => 'Your DivaWaste account is suspended',
        'line' => 'Access of :company to the platform is suspended.',
        'reason' => 'Reason: :reason',
    ],

    'company_reactivated' => [
        'subject' => 'Your DivaWaste account is reactivated',
        'line' => 'Access of :company to the platform is restored.',
        'action' => 'Open my workspace',
    ],

    'event' => [
        'action' => 'Open in DivaWaste',
    ],
];
