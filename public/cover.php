<?php
declare(strict_types=1);

/**
 * Couverture broché complète : ce point d'entrée redirige vers l'export PDF
 * serveur (export/cover-pdf), le SEUL circuit fiable — un vrai fichier PDF
 * aux cotes exactes KDP (4ème + tranche + 1ère, fond perdu, code-barres),
 * sans passer par une imprimante PDF du navigateur qui casse la mise en page.
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;

if (!Auth::user()) {
    http_response_code(401);
    exit('Authentification requise — connectez-vous puis rechargez.');
}
header('Location: api.php?r=export/cover-pdf&id=' . (int) ($_GET['id'] ?? 0));
exit;
