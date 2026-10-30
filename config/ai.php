<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Configure API keys for AI providers used by the application.
    | OpenRouter is the sole provider — it handles all AI agents and PDF
    | processing (text extraction and OCR are handled by the model internally).
    |
    */

    'providers' => [

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
            'url' => 'https://openrouter.ai/api/v1',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | AI Model Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which models each AI agent uses. This allows switching models
    | via environment variables without code changes.
    |
    */

    'models' => [

        'parsing' => env('AI_PARSING_MODEL', 'openai/gpt-6-luna'),

        'matching' => env('AI_MATCHING_MODEL', 'google/gemini-3.1-flash-lite'),

    ],

];
