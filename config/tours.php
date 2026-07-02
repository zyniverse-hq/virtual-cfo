<?php

return [
    'dashboard' => [
        [
            'title' => 'Your Dashboard',
            'description' => 'This is your home base. The workflow is: Upload statements → Review transactions → Map to account heads → Export to Tally.',
            'element' => null,
        ],
        [
            'title' => 'Import & Mapping Stats',
            'description' => 'These cards show files processed, transactions parsed, and how many are mapped. Keep an eye on the "Unmapped" count — that is your to-do list.',
            'element' => '.fi-wi-stats-overview',
        ],
        [
            'title' => 'Recent Imports',
            'description' => 'Your last 5 uploads with status, row count, and mapped percentage. Click any row to view details or re-process a failed import.',
            'element' => '.fi-wi-table',
        ],
        [
            'title' => 'Mapping Progress',
            'description' => 'The doughnut chart shows how your transactions are mapped: Auto (by rules), Manual, AI-suggested, or still Unmapped. Aim to shrink the Unmapped slice.',
            'element' => '.fi-wi-chart:first-of-type',
        ],
    ],

    'imported-files' => [
        [
            'title' => 'Upload & Track Statements',
            'description' => 'Upload bank or credit card statements here (PDF, CSV, XLSX). The system parses them with AI and extracts transactions automatically.',
            'element' => null,
        ],
        [
            'title' => 'Upload a Statement',
            'description' => 'Click "Upload Statement" to add a new file. Select the statement type and bank account, then upload. Processing starts immediately.',
            'element' => '.fi-header-actions',
        ],
        [
            'title' => 'Track Processing Status',
            'description' => 'The Status column shows: Pending → Processing → Completed (or Failed). If a PDF is password-protected, you will see "Needs Password" — click the row actions to set it.',
            'element' => '.fi-ta-header-cell-status',
        ],
        [
            'title' => 'Row Actions',
            'description' => 'Each row has actions: Download the original file, Re-process if parsing failed, or Set Password for locked PDFs. Look for the "..." menu on each row.',
            'element' => '.fi-ta-actions:first-of-type',
        ],
        [
            'title' => 'Filter by Status',
            'description' => 'Use the filters to narrow by status (e.g., only failed imports), statement type, or source. Helpful when you have many uploads.',
            'element' => '.fi-ta-header-toolbar',
        ],
    ],

    'transactions' => [
        [
            'title' => 'Your Transactions',
            'description' => 'Parsed transactions from all your imports land here. This is where you map them to Tally account heads and export.',
            'element' => null,
        ],
        [
            'title' => 'Mapping Stats',
            'description' => 'Total transactions, how many are unmapped (your to-do), and your mapped percentage. Green means 80%+ mapped — you are ready to export.',
            'element' => '.fi-wi-stats-overview',
        ],
        [
            'title' => 'AI Matching',
            'description' => 'Click "Run AI Matching" to auto-map unmapped transactions using AI. It suggests account heads with confidence scores. High-confidence matches are assigned automatically.',
            'element' => '.fi-header-actions',
        ],
        [
            'title' => 'Assign Heads & Create Rules',
            'description' => 'Click any row\'s "Assign Head" to manually map it. Use "Create Rule" to make a reusable pattern — next time a similar transaction appears, it maps automatically.',
            'element' => '.fi-ta-header-cell-accountHead-name',
        ],
        [
            'title' => 'Bulk Actions',
            'description' => 'Select multiple transactions with checkboxes, then use "Assign Account Head" to map them all at once. Great for batches from the same vendor.',
            'element' => '.fi-ta-header-toolbar',
        ],
        [
            'title' => 'Export to Tally',
            'description' => 'When mapping is complete, use the Export menu (Tally XML, CSV, or Excel). Set a date range to export a specific period. The Tally XML is ready to import directly.',
            'element' => '.fi-header-actions',
        ],
        [
            'title' => 'Filters',
            'description' => 'Filter by imported file, mapping type, account head, date range, or unmapped-only. The "Unmapped Only" filter is your fastest path to clearing the backlog.',
            'element' => '.fi-ta-header-toolbar',
        ],
    ],

    'account-heads' => [
        [
            'title' => 'Your Chart of Accounts',
            'description' => 'These are the Tally account heads that transactions get mapped to. You need these set up before mapping or exporting.',
            'element' => null,
        ],
        [
            'title' => 'Import from Tally XML',
            'description' => 'The fastest way to set up: click "Import from Tally XML" and upload your Tally master file. It creates all heads, groups, and hierarchy automatically. It also detects bank accounts.',
            'element' => '.fi-header-actions',
        ],
        [
            'title' => 'Usage Counts',
            'description' => 'The "Transactions" column shows how many transactions are mapped to each head. "Rules" shows how many auto-mapping rules target it. Heads with zero usage may be unused.',
            'element' => '.fi-ta-header-cell-transactions-count',
        ],
        [
            'title' => 'Active Toggle',
            'description' => 'Inactive heads are hidden from mapping suggestions, keeping your dropdowns clean. Deactivate heads you do not use instead of deleting them.',
            'element' => '.fi-ta-header-cell-is-active',
        ],
    ],

    'head-mappings' => [
        [
            'title' => 'Auto-Mapping Rules',
            'description' => 'Rules automatically assign account heads to transactions based on their description. Once created, a rule applies to ALL future imports — no manual work needed.',
            'element' => null,
        ],
        [
            'title' => 'Create Rules',
            'description' => 'Click "New" to create a rule manually, or go to Transactions → click a row → "Create Rule" to pre-fill from an existing transaction. The second approach is faster.',
            'element' => '.fi-header-actions',
        ],
        [
            'title' => 'Test Before Applying',
            'description' => 'Each rule has a "Test Rule" action in the row menu. It shows how many existing transactions would match — use it to verify your pattern before relying on it.',
            'element' => '.fi-ta-actions:first-of-type',
        ],
        [
            'title' => 'Match Types',
            'description' => 'Contains: matches if description includes the text. Exact: must match fully. Regex: for advanced patterns (e.g., "NEFT.*SALARY"). Contains covers 90% of cases.',
            'element' => '.fi-ta-header-cell-match-type',
        ],
        [
            'title' => 'Priority & Usage',
            'description' => 'Rules with lower priority numbers run first. The "Uses" column shows how often a rule matched — high-use rules are your most valuable automation.',
            'element' => '.fi-ta-header-cell-priority',
        ],
    ],

    'reconciliation' => [
        [
            'title' => 'Reconciliation',
            'description' => 'Match bank transactions against invoices to add GST breakdowns and vendor details to your Tally exports. Upload both a bank statement and invoices first.',
            'element' => null,
        ],
        [
            'title' => 'Reconciliation Stats',
            'description' => 'Track progress: Unreconciled (not yet matched), Matched (confirmed), Flagged (needs review), and Pending Suggestions (system found possible matches for you).',
            'element' => '.fi-wi-stats-overview',
        ],
        [
            'title' => 'Run Reconciliation',
            'description' => 'Click "Run Reconciliation" and select a bank statement + invoice file. The system matches transactions by amount and date, then suggests possible matches.',
            'element' => '.fi-header-actions',
        ],
        [
            'title' => 'Review Matches',
            'description' => 'Green "Confirm" buttons appear on rows with suggestions. Click to accept. Use "Match Invoice" to manually link a transaction. "Reject All" clears bad suggestions.',
            'element' => '.fi-ta-actions:first-of-type',
        ],
    ],
];
