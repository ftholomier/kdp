<?php
/**
 * Tirage — KDP Studio : configuration de l'application.
 *
 * TOUTES les variables de l'application sont externalisées ici (aucun .env).
 * Pour surcharger localement sans toucher à ce fichier versionné, créez
 * config/config.local.php qui retourne un tableau partiel : il sera fusionné
 * par-dessus celui-ci (voir config.local.sample.php).
 */
return [

    // ── Application ────────────────────────────────────────────────────────
    'app' => [
        'name'      => 'Tirage',
        // URL publique de l'application, sans slash final (vide = détection auto).
        // Ex. : 'https://studio.mondomaine.fr'
        'base_url'  => '',
        'env'       => 'production',      // production | development
        'debug'     => false,             // true = messages d'erreur détaillés
        'timezone'  => 'Europe/Paris',
        'locale'    => 'fr_FR',
    ],

    // ── Base de données MySQL (phpMyAdmin) ─────────────────────────────────
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'kdp_studio',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // ── Sécurité / connexion ───────────────────────────────────────────────
    'security' => [
        'session_name'     => 'tirage_session',
        'session_lifetime' => 604800,     // 7 jours, en secondes
        'password_algo'    => PASSWORD_DEFAULT,
        'login_throttle'   => 5,          // tentatives max avant pause
        'login_pause'      => 300,        // pause en secondes après échecs
    ],

    // ── IA Google Gemini ───────────────────────────────────────────────────
    'gemini' => [
        // Clé API : https://aistudio.google.com/apikey
        'api_key'    => '',
        'endpoint'   => 'https://generativelanguage.googleapis.com/v1beta',
        // Modèle rapide/économique : analyse de niche, concepts, sommaire,
        // textes de couverture, métadonnées KDP.
        'model_fast' => 'gemini-2.5-flash',
        // Modèle qualité maximale : rédaction des chapitres, retouches.
        'model_pro'  => 'gemini-2.5-pro',
        'timeout'         => 180,         // secondes par appel
        'connect_timeout' => 15,
        'max_retries'     => 2,           // relances automatiques par appel
        'temperature_ideas'   => 0.9,     // créativité pour thèmes/concepts
        'temperature_writing' => 0.8,     // créativité pour la rédaction
        'max_output_tokens'   => 8192,
        // Ancrage Google Search pour l'analyse marché (tendances récentes).
        'use_google_search'   => true,
    ],

    // ── Canopy API : vraies données Amazon (https://www.canopyapi.co) ──────
    // Optionnel. Avec une clé (offre gratuite disponible), l'analyse de niche
    // (étape 1) et les concepts (étape 2) s'appuient sur les résultats réels
    // d'Amazon : titres du top, prix, notes, volume d'avis. Sans clé ou en cas
    // d'erreur/quota, l'application retombe automatiquement sur Gemini seul.
    'canopy' => [
        // Clé API : https://www.canopyapi.co (Dashboard après inscription)
        'api_key'        => '',
        'endpoint'       => 'https://graphql.canopyapi.co/',
        'domain'         => 'FR',        // place de marché Amazon : FR, US, DE, ES, IT, UK…
        'timeout'        => 30,          // secondes par appel
        // Économie de crédits (offre gratuite ≈ 100 requêtes/mois) :
        'cache_ttl'      => 604800,      // une même recherche = 1 crédit / 7 jours
        'monthly_budget' => 95,          // garde-fou : au-delà, mise en veille du connecteur
        'searches_per_analysis' => 2,    // recherches Amazon max par analyse d'idée
    ],

    // ── Moteur de rédaction (étape 5) ──────────────────────────────────────
    'writing' => [
        'words_per_page'       => 285,    // mots par page (comme la maquette)
        'sections_per_chapter' => 3,
        'pages_per_chapter'    => 20,     // pour déduire le nombre de chapitres
        'min_chapters'         => 6,
        'max_chapters'         => 14,
        'seconds_per_section'  => 40,     // estimation du temps restant
        'context_tail_chars'   => 700,    // fin du texte précédent passé en contexte
    ],

    // ── Amazon KDP : marché, impression, marges ────────────────────────────
    'kdp' => [
        'marketplace'    => 'Amazon.fr',
        'language'       => 'Français',
        'royalty_rate'   => 0.60,         // taux de redevance broché
        // Coût d'impression estimé, noir & blanc (EUR) :
        'print_fixed'    => 0.85,
        'print_per_page' => 0.012,
        // Épaisseur du dos par page (mm) : papier blanc 0.0572, crème 0.0635
        'spine_per_page' => 0.0572,
        'bleed_mm'       => 3.175,        // fond perdu couverture
        // Zone code-barres réservée par KDP au dos (mm)
        'barcode_w_mm'   => 50.8,
        'barcode_h_mm'   => 30.5,
    ],

    // ── Formats d'impression proposés ──────────────────────────────────────
    'trims' => [
        '6x9'  => ['label' => '15,24 × 22,86 cm (6×9) — standard non-fiction', 'w_mm' => 152.4, 'h_mm' => 228.6],
        '5x8'  => ['label' => '12,7 × 20,32 cm (5×8) — poche',                 'w_mm' => 127.0, 'h_mm' => 203.2],
        '7x10' => ['label' => '17,78 × 25,4 cm (7×10) — illustré',             'w_mm' => 177.8, 'h_mm' => 254.0],
    ],

    // ── Chemins (hors racine web) ──────────────────────────────────────────
    'paths' => [
        'storage'         => dirname(__DIR__) . '/storage',
        'uploads'         => dirname(__DIR__) . '/storage/uploads',
        'exports'         => dirname(__DIR__) . '/storage/exports',
        'logs'            => dirname(__DIR__) . '/storage/logs',
        'cache'           => dirname(__DIR__) . '/storage/cache',
        // Gabarits de couverture flat design : déposez ici vos propres
        // modèles (un dossier par gabarit : front.svg, back.svg, meta.json).
        'cover_templates' => dirname(__DIR__) . '/templates/covers',
    ],
];
