# Tirage — Studio de livres Amazon KDP

Solution complète en **PHP natif** (aucun framework, aucune dépendance Composer) pour écrire,
mettre en page et publier des livres sur **Amazon KDP**, avec l'IA **Google Gemini**.
Interface fidèle à la maquette « KDP Studio » (palette crème/marine/orange, Instrument Serif/Sans,
IBM Plex Mono).

## Le parcours en 7 étapes

| # | Étape | Ce qui se passe |
|---|-------|-----------------|
| 01 | **Niche** | Décrivez votre idée → Gemini (+ ancrage Google Search) la confronte au marché Amazon.fr et propose 6 thématiques scorées (demande, concurrence, prix médian). Ou partez directement des catégories les plus consultées. |
| 02 | **Concept** | 8 livres à écrire (titre, accroche, description, badge marché, prix conseillé, pagination estimée), chacun comblant un angle mort des meilleures ventes. Régénérables à volonté. |
| 03 | **Sommaire** | Nombre de pages (curseur 60–400), photos/illustrations (activables, visuels par chapitre, style), ton d'écriture, format d'impression. Sommaire généré, éditable (titres cliquables, réordonnancement). |
| 04 | **Couverture** | **Studio de couvertures** façon générateur de logo : 8 versions flat design proposées (mises en page × palettes × motifs vectoriels), régénérables, cliquables. **Illustration IA optionnelle** (Gemini image « nano banana ») avec prompt libre et image d'inspiration, style flat imposé. Rendu **haute résolution côté serveur** (GD + polices embarquées) : aperçu fidèle, export JPG eBook 1600×2560 fiable, textes de 4ème rédigés par l'IA. Les gabarits SVG de `templates/covers/` restent utilisés pour la 4ème de couverture. |
| 05 | **Rédaction** | Introduction et conclusion dédiées (promesse du livre, plan d'action final) + encadrés à valeur ajoutée (À retenir, Chiffre clé, Conseil, Exemple, FAQ, Attention) insérés par l'IA et rendus en boîtes flat design dans tous les exports. Écriture section par section par Gemini Pro, **avancement en direct** (%, mots, chapitre, temps restant), **journal en direct**, **points de contrôle en base** : toute coupure (réseau, quota, fermeture du navigateur) reprend exactement où elle s'est arrêtée, sans doublon. |
| 06 | **Chapitres** | Lecture confortable chapitre par chapitre, actions IA (réécrire avec un autre ton, allonger, resserrer, ajouter un exercice, vérification factuelle), contrôle qualité calculé (lisibilité, répétitions, longueur), upload des visuels dans les emplacements réservés. |
| 07 | **Mise en page** | Épreuve intérieure professionnelle : belle page, lettrines, titres courants, folios, sommaire paginé, marges en miroir avec gouttière KDP. Aperçu double page, export PDF (navigateur **et** serveur), .docx Kindle, conformité KDP, prix/redevances, et **publication assistée sur KDP**. |

## Pile technique

- **PHP 8+ natif** — aucune dépendance, aucun Composer, aucun framework.
- **MySQL / phpMyAdmin** — schéma dans `database/schema.sql`.
- **API JSON** — `public/api.php?r=...` (toutes les routes du back).
- **HTML/CSS/JS vanilla** — `public/` uniquement exposé au web.
- **Google Gemini** — flash (analyse/concepts/sommaire/couverture/métadonnées) + pro (rédaction/retouches), configurable.
- **Aucun `.env`** — toutes les variables dans `config/config.php` (+ surcharge `config/config.local.php` non versionnée).

## Arborescence

```
├── public/               ← SEUL dossier exposé au web
│   ├── index.php         ← application (connexion + 7 étapes)
│   ├── api.php           ← API JSON
│   ├── setup.php         ← installation (tables + 1er compte) — à supprimer ensuite
│   ├── print.php         ← épreuve intérieure paginée (aperçu + impression PDF)
│   ├── cover.php         ← couverture complète (4ème + dos + 1ère, fond perdu)
│   ├── media.php         ← service des images uploadées
│   ├── font.php          ← cache local des polices (export JPG couverture)
│   └── assets/           ← css, js, userscript KDP
├── app/                  ← code applicatif (hors web)
│   ├── Core/             ← Config, Db (PDO), Auth, Csrf, Http, Util
│   ├── Services/         ← Gemini, Market, Concepts, Toc, Covers, Writer,
│   │                        ChapterTools, Layout, PdfBook, Docx, Kdp
│   └── Api/Router.php    ← routage de l'API
├── config/
│   ├── config.php        ← TOUTES les variables (BDD, Gemini, KDP, chemins…)
│   └── config.local.sample.php ← modèle de surcharge locale
├── database/schema.sql   ← schéma MySQL
├── templates/covers/     ← gabarits de couverture flat design (déposez les vôtres)
├── storage/              ← uploads, exports, logs, cache (hors web)
└── tools/kdp-autofill.user.js ← userscript de remplissage du formulaire KDP
```

## Installation (hébergement mutualisé ou local)

1. **Fichiers** : déposez le projet sur votre hébergement.
   - Idéal : pointez le domaine sur `public/`.
   - Sinon : le `.htaccess` racine réécrit tout vers `public/` automatiquement,
     et des `.htaccess` bloquent l'accès direct à `app/`, `config/`, `storage/`…
2. **Base de données** : créez une base MySQL (phpMyAdmin), puis soit importez
   `database/schema.sql`, soit laissez `setup.php` créer les tables.
3. **Configuration** : éditez `config/config.php` (ou mieux : copiez
   `config/config.local.sample.php` → `config/config.local.php`) :
   - `db` : hôte, base, utilisateur, mot de passe ;
   - `gemini.api_key` : clé créée sur https://aistudio.google.com/apikey ;
   - `gemini.model_fast` / `model_pro` : modèles à utiliser (modifiables à tout moment).
4. **Compte** : ouvrez `https://votre-site/setup.php`, créez votre compte,
   puis **supprimez `public/setup.php`** du serveur.
5. Connectez-vous sur `index.php` — c'est parti.

> **Local (XAMPP/WAMP/MAMP)** : mêmes étapes, `base_url` facultatif.
> Extensions PHP requises : `pdo_mysql`, `curl`, `mbstring`, `gd`, `zip`, `iconv`
> (présentes par défaut chez O2switch, OVH, Ionos…).

## Connecteurs (clés API dans l'interface)

Bouton **⚡ Connecteurs** en haut à droite : collez-y vos clés API sans toucher aux
fichiers — elles sont enregistrées en base (table `settings`) et **priment sur
`config/config.php`**. Chaque connecteur a un bouton « Tester la connexion ».

- **Google Gemini** (obligatoire) — clé sur https://aistudio.google.com/apikey,
  modèles rapide/qualité modifiables.
- **Canopy API** (optionnel) — vraies données Amazon, voir ci-dessous.

## Canopy API — vraies données Amazon (optionnel)

Créez un compte gratuit sur https://www.canopyapi.co, copiez la clé API du
tableau de bord et collez-la dans **⚡ Connecteurs**. Dès qu'elle est active :

- **Étape 1 (Niche)** : l'analyse de votre idée interroge la vraie recherche
  Amazon (jusqu'à 2 requêtes par analyse) — titres du top, prix médians, notes,
  volumes d'avis — et Gemini ancre ses scores dessus. Badge
  « ✓ Ancré sur les résultats réels Amazon » affiché.
- **Étape 2 (Concept)** : le top réel de la niche choisie est fourni au modèle
  pour détecter les angles morts des livres qui se vendent vraiment.

- **◉ Veille marché** (bouton en haut à droite) : un tableau de bord de vos
  niches Amazon, **mis à jour uniquement au clic**. Suivre une niche est
  gratuit ; chaque « Actualiser » consomme volontairement 1 crédit (avec
  confirmation et compteur de crédits restants). Les relevés sont persistés en
  base — consultation gratuite à vie — et chaque nouveau relevé archive le
  précédent pour afficher l'évolution (▲▼ prix médian, % d'avis cumulés,
  proxy de la demande). Bouton « Créer un livre » pour transformer une niche
  suivie en projet. Avec 100 crédits/mois : ~25 niches suivies au rythme
  d'un relevé hebdomadaire.

**Circuit de la donnée** — vos clics alimentent toute la solution. Quand une
analyse (étape 1) ou une génération de concepts (étape 2) a besoin de données
marché, l'ordre de priorité est :

1. **vos relevés de veille** dont le terme correspond à l'idée ou au thème
   (réutilisation **gratuite**, datée dans le prompt : « relevé de votre
   veille du 2026-08-10 ») ;
2. sinon le **cache Canopy 7 jours** (une recherche déjà faite ne recoûte rien) ;
3. sinon un **appel API réel** (plafonné à `searches_per_analysis`) ;
4. sinon (pas de clé, quota atteint, erreur) : **Gemini + Google Search seul**.

Pensé pour l'**offre gratuite** (~100 requêtes/mois) :

- cache disque 7 jours (`canopy.cache_ttl`) : une même recherche ne consomme
  qu'un crédit par semaine, regénérer des concepts ne coûte rien ;
- garde-fou `canopy.monthly_budget` (95 par défaut) : quota atteint → le
  connecteur se met en veille et **tout continue de fonctionner sur Gemini seul** ;
- compteur visible dans l'écran Connecteurs et sur l'étape 1 ;
- place de marché configurable (Amazon.fr par défaut).

Toute erreur Canopy (clé, réseau, quota, schéma) est non bloquante : repli
automatique sur l'analyse Gemini + Google Search.

## Vos gabarits de couverture (flat design)

Chaque gabarit = un dossier dans `templates/covers/` :

```
templates/covers/mon-modele/
├── meta.json    {"name":"Mon modèle","palette":{"c1":"#1B2A4A","c2":"#C4571F","c3":"#F4EFE4","c4":"#1A1A17"}}
├── front.svg    1ère de couverture
└── back.svg     4ème de couverture
```

Les SVG (viewBox conseillé `0 0 600 900`) utilisent des **jetons** remplacés au rendu :
`{{TITLE}}`, `{{SUBTITLE}}`, `{{TAGLINE}}`, `{{AUTHOR}}`, `{{BACK_TEXT}}` (paragraphes HTML),
`{{BIO}}`, et les couleurs `{{C1}}…{{C4}}`. Les textes longs se placent dans des
`<foreignObject>` (voir les 4 gabarits fournis en exemple : ils reprennent exactement
cette mécanique). Tout dossier déposé apparaît immédiatement dans l'étape 04 —
votre design est respecté au trait près, seuls les jetons changent.

## Rédaction : reprise sur incident

L'unité de rédaction est la **section** (3 par chapitre). Chaque section terminée est
immédiatement enregistrée en base (point de contrôle `chN §M`). Le navigateur pilote la
boucle d'écriture (compatible mutualisé : aucun processus long côté serveur) :

- coupure réseau / erreur API → bandeau « Interruption détectée — reprise automatique »,
  relance avec backoff progressif, reprise exacte au dernier point de contrôle ;
- fermeture du navigateur → au retour sur l'étape 05, la rédaction repart d'elle-même ;
- jamais de paragraphe perdu, jamais de doublon (la section en échec est réinitialisée).

## Publication Amazon KDP (remplissage automatique)

1. Étape 07 → **Publier sur Amazon KDP** : générez/éditez les métadonnées
   (sous-titre, description HTML, 7 mots-clés, catégories, prix) et créez un **jeton d'accès**.
2. Installez [Tampermonkey](https://www.tampermonkey.net/) puis le userscript
   `tools/kdp-autofill.user.js` (aussi servi sur `assets/kdp-autofill.user.js`).
3. Sur **kdp.amazon.com**, le panneau « Tirage » apparaît : URL du studio + jeton →
   « Charger mes projets » → choisissez le livre → **« Remplir cette page »** sur chaque
   écran du formulaire (détails, contenu, tarification). Le script remplit titre,
   sous-titre, auteur, description, mots-clés, prix, ISBN via les événements natifs.
4. Téléversez les fichiers générés (PDF intérieur, couverture, ou .docx pour l'eBook)
   et cliquez vous-même sur « Publier ».

> ⚠️ Volontairement, le **clic final reste manuel** : c'est votre compte KDP, vous validez.
> Amazon fait évoluer son formulaire ; les sélecteurs de champs sont regroupés en tête du
> userscript (`FIELD_SELECTORS`) pour être ajustés en quelques secondes si besoin.
> L'automatisation de saisie dans votre propre navigateur relève de votre responsabilité
> vis-à-vis des conditions d'utilisation d'Amazon.

## Exports

- **PDF intérieur « qualité studio »** : `print.php` compose le livre page à page
  (polices du design incorporées) → Imprimer > Enregistrer en PDF, format exact, marges 0.
- **PDF intérieur automatique** : généré 100 % côté serveur (writer PDF natif maison,
  Times, texte justifié, marges miroir, sommaire paginé) — pratique pour tout automatiser.
- **Couverture complète** : `cover.php` calcule dos (épaisseur = pages × 0,0572 mm) et
  fond perdu, réserve la zone code-barres KDP.
- **JPG eBook** : 1ère de couverture rasterisée en 1600 × 2560.
- **.docx Kindle** : manuscrit complet pour la version numérique.

## Notes importantes

- Les données « marché Amazon » (scores, demande, concurrence, prix) sont des **estimations
  éditoriales produites par Gemini avec ancrage Google Search** — fiables pour orienter un
  choix, mais ce ne sont pas des chiffres certifiés Amazon (aucune API publique n'existe).
- Sécurité : mots de passe hachés (`password_hash`), sessions HttpOnly/SameSite, CSRF sur
  toutes les écritures, anti force brute au login, requêtes préparées PDO partout,
  jetons d'API révocables pour le userscript.
