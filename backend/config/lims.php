<?php

return [
    // First-run admin setup is accepted only from the server computer unless this is enabled.
    'allow_remote_setup' => (bool) env('LIMS_ALLOW_REMOTE_SETUP', false),

    // How long a login token stays valid (days).
    'token_days' => (int) env('LIMS_TOKEN_DAYS', 30),
];
