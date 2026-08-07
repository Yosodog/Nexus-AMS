<?php

return [
    'account_statements' => [
        'sync_row_limit' => (int) env('ACCOUNT_STATEMENT_SYNC_ROW_LIMIT', 1000),
        'print_row_limit' => (int) env('ACCOUNT_STATEMENT_PRINT_ROW_LIMIT', 2000),
        'availability_hours' => (int) env('ACCOUNT_STATEMENT_AVAILABILITY_HOURS', 24),
        'stale_processing_hours' => (int) env('ACCOUNT_STATEMENT_STALE_PROCESSING_HOURS', 6),
        'history_retention_days' => (int) env('ACCOUNT_STATEMENT_HISTORY_RETENTION_DAYS', 30),
    ],

    'categories' => [
        'tax' => [
            'label' => 'Taxes',
            'direction' => 'income',
            'color' => 'success',
            'group' => 'taxes',
            'icon' => 'fa-solid fa-coins',
        ],
        'loan_interest' => [
            'label' => 'Loan Interest',
            'direction' => 'income',
            'color' => 'primary',
            'group' => 'misc',
            'icon' => 'fa-solid fa-piggy-bank',
        ],
        'mmr_income' => [
            'label' => 'MMR Contributions',
            'direction' => 'income',
            'color' => 'info',
            'group' => 'mmr',
            'icon' => 'fa-solid fa-sack-dollar',
        ],
        'mmr_expense' => [
            'label' => 'MMR Purchases',
            'direction' => 'expense',
            'color' => 'warning',
            'group' => 'mmr',
            'icon' => 'fa-solid fa-cart-shopping',
        ],
        'grant' => [
            'label' => 'Member Grants',
            'direction' => 'expense',
            'color' => 'danger',
            'group' => 'grants',
            'icon' => 'fa-solid fa-gift',
        ],
        'city_grant' => [
            'label' => 'City Grants',
            'direction' => 'expense',
            'color' => 'secondary',
            'group' => 'grants',
            'icon' => 'fa-solid fa-city',
        ],
        'war_aid' => [
            'label' => 'War Aid',
            'direction' => 'expense',
            'color' => 'dark',
            'group' => 'war',
            'icon' => 'fa-solid fa-helmet-safety',
        ],
        'counter_reimbursement' => [
            'label' => 'Counter Reimbursements',
            'direction' => 'expense',
            'color' => 'danger',
            'group' => 'war',
            'icon' => 'fa-solid fa-hand-holding-dollar',
        ],
        'rebuilding' => [
            'label' => 'Rebuilding',
            'direction' => 'expense',
            'color' => 'secondary',
            'group' => 'war',
            'icon' => 'fa-solid fa-hammer',
        ],
        'other' => [
            'label' => 'Miscellaneous',
            'direction' => 'expense',
            'color' => 'light',
            'group' => 'misc',
            'icon' => 'fa-solid fa-ellipsis',
        ],
    ],
];
