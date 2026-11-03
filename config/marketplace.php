<?php

/*
 * The bourse (M4-06): lots a company produced, offered to the eligible providers of the platform.
 */
return [
    // The platform's commission on an accepted deal, in percent of its amount. 0 today. The rate is kept on each deal
    // when it is accepted, so a later change never rewrites the deals already made.
    'commission_pct' => (string) env('MARKETPLACE_COMMISSION_PCT', '0'),

    // How long a proposal stands when its author gives no date: after that it can no longer be accepted, only answered
    // with another one.
    'proposal_days' => (int) env('MARKETPLACE_PROPOSAL_DAYS', 7),
];
