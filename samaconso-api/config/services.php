<?php

return [
    // URL du frontend Angular (liens présents dans les emails)
    'frontend' => ['url' => env('FRONTEND_URL', 'http://localhost:4200')],

    // Microservice Python (FastAPI + Scikit-learn / Random Forest Regressor)
    'ml' => [
        'url' => env('ML_SERVICE_URL', 'http://127.0.0.1:8001'),
        'token' => env('ML_SERVICE_TOKEN'),
        'timeout' => (int) env('ML_SERVICE_TIMEOUT', 20),
    ],

    // Passerelle de paiement : "simulated" (développement) ou implémentation réelle.
    'paiement' => [
        'driver' => env('PAYMENT_DRIVER', 'simulated'),
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),
    ],

    // Notifications : "log" (développement) ou "http" (fournisseur SMS générique)
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'url' => env('SMS_HTTP_URL'),
        'token' => env('SMS_HTTP_TOKEN'),
        'sender' => env('SMS_SENDER', 'SamaConso'),
    ],
    
];
