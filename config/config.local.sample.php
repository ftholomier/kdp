<?php
/**
 * Surcharge locale de configuration (NON versionnée).
 * Copiez ce fichier en config.local.php et n'y mettez que ce qui change :
 * il est fusionné récursivement par-dessus config.php.
 */
return [
    'app' => [
        'debug'    => true,
        'env'      => 'development',
        'base_url' => 'http://localhost/kdp',
    ],
    'db' => [
        'name' => 'kdp_studio',
        'user' => 'root',
        'pass' => '',
    ],
    'gemini' => [
        'api_key' => 'VOTRE_CLE_API_GEMINI',
    ],
];
