# Tirage — Studio de livres Amazon KDP

Solution complète en **PHP natif** (aucun framework, aucune dépendance Composer) pour écrire,
mettre en page et publier des livres sur **Amazon KDP**, avec l'IA **Google Gemini**.
Interface fidèle à la maquette « KDP Studio » (palette crème/marine/orange, Instrument Serif/Sans,
IBM Plex Mono).

## Le parcours en 7 étapes

| # | Étape | Ce qui se passe |
|---|-------|-----------------|
| 01 | **Niche** | **Langue du livre** choisie d'entrée (elle commande tout ce qui suit). Décrivez votre idée → Gemini (+ ancrage Google Search) la confronte au marché Amazon.fr et propose 6 thématiques scorées (demande, concurrence, prix médian) ; ou partez des catégories les plus consultées ; ou **importez un livre PDF existant** : le texte est extrait localement, et vous dites en clair ce que vous voulez en faire — reprise à l'identique (votre livre, direction la couverture) ou source d'inspiration, avec une **consigne libre** qui retravaille le plan avant qu'il n'arrive au sommaire. |
| 02 | **Concept** | 8 livres à écrire (titre, accroche, description, badge marché, prix conseillé, pagination estimée), chacun comblant un angle mort des meilleures ventes. Régénérables à volonté. |
| 03 | **Sommaire** | Nombre de pages (curseur 60–400), photos/illustrations (activables, visuels par chapitre, style), ton d'écriture, langue, format d'impression. Sommaire **entièrement éditable** : titres et sous-parties cliquables, parties ajoutées ou retirées, chapitres réordonnés ou **supprimés** (avec le texte déjà rédigé si vous confirmez), chapitre supplémentaire demandé en une phrase. |
| 04 | **Couverture** | **Votre propre couverture** peut être téléversée (PDF broché complet ou image de 1ère) : elle s'affiche dans le studio — aperçu extrait du PDF, sans outil externe — et remplace la couverture composée dans les exports. Sinon, **studio de couvertures** façon générateur de logo : 8 versions flat design proposées (mises en page × palettes × motifs vectoriels), régénérables, cliquables. **Illustration IA optionnelle** (Gemini image « nano banana ») avec prompt libre et image d'inspiration, style flat imposé. Rendu **haute résolution côté serveur** (GD + polices embarquées) : aperçu fidèle, export JPG eBook 1600×2560 fiable, textes de 4ème rédigés par l'IA. Les gabarits SVG de `templates/covers/` restent utilisés pour la 4ème de couverture. |
| 05 | **Rédaction** | Introduction et conclusion dédiées (promesse du livre, plan d'action final) + encadrés à valeur ajoutée (À retenir, Chiffre clé, Conseil, Exemple, FAQ, Attention) insérés par l'IA et rendus en boîtes flat design dans tous les exports. Écriture section par section par Gemini Pro, **avancement en direct** (%, mots, chapitre, temps restant), **journal en direct**, **points de contrôle en base** : toute coupure (réseau, quota, fermeture du navigateur) reprend exactement où elle s'est arrêtée, sans doublon. |
| 06 | **Chapitres** | Lecture confortable chapitre par chapitre, actions IA (réécrire avec un autre ton, allonger, resserrer, ajouter un exercice, vérification factuelle), contrôle qualité calculé (lisibilité, répétitions, longueur), upload des visuels dans les emplacements réservés. |
| 07 | **Mise en page** | Thème d'intérieur, **polices au choix** (titres et texte courant, incorporées au PDF), couleurs du livre, composeur d'ingrédients. Épreuve intérieure professionnelle : belle page, lettrines, titres courants, folios, sommaire paginé, marges en miroir avec gouttière KDP. Aperçu double page, export PDF (navigateur **et** serveur), .docx Kindle, conformité KDP, prix/redevances, et **publication assistée sur KDP**. |

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
3. Sur **kdp.amazon.com**, le panneau « Tirage » apparaît : URL du studio + jeton (demandés
   une seule fois, puis mémorisés) → **choisissez votre livre**. C'est le seul geste.
4. Naviguez normalement dans le formulaire : **chaque page se remplit toute seule** dès que
   ses champs apparaissent — titre, sous-titre, auteur, description, 7 mots-clés, droits
   d'auteur, contenu adulte, ISBN gratuit KDP, format d'impression, fond perdu, encre &
   papier, finition de couverture, et le prix sur **chaque boutique Amazon** (converti depuis
   l'euro via `config.php → kdp.fx`). Un bouton « Remplir cette page maintenant » et un
   interrupteur « remplissage automatique » restent disponibles dans le panneau.
5. Téléversez les fichiers générés (PDF intérieur, couverture, ou .docx pour l'eBook)
   et cliquez vous-même sur « Publier ».

> ⚠️ Volontairement, le **clic final reste manuel** : c'est votre compte KDP, vous validez.
> Un champ **déjà rempli n'est jamais écrasé** (le vôtre gagne toujours), et les catégories
> restent à choisir dans la fenêtre KDP — le panneau vous les rappelle.
> Amazon fait évoluer son formulaire : chaque champ est cherché par identifiant (`FIELD_SELECTORS`,
> `CHOICES`) **puis par libellé visible** FR/EN, et le remplissage est rejoué tant que la page
> bouge — un changement d'identifiants côté Amazon ne casse donc pas le remplissage.
> L'automatisation de saisie dans votre propre navigateur relève de votre responsabilité
> vis-à-vis des conditions d'utilisation d'Amazon.

## Importer sa propre couverture (la « planche »)

Sur KDP, la couverture d'un broché est **un seul fichier** : 4ème de couverture · dos · 1ère de
couverture, plus 3,175 mm de fond perdu tout autour. C'est cette planche qui s'importe à l'étape 04,
en **PDF ou en image**, et elle remplace alors la couverture composée dans les exports.

Le studio la **mesure** et vous dit ce qu'elle raconte : format du livre déduit de sa hauteur,
épaisseur du dos, et donc **la pagination qu'elle suppose**. Puis il la confronte à votre livre —
c'est ce contrôle qui évite le refus au téléversement sur KDP.

Quand ça ne correspond pas, le studio **corrige** au lieu de se contenter d'alerter :

- **Corriger la planche** (planche fournie en image) : vos deux faces sont conservées telles quelles,
  seul le **dos est refabriqué à la bonne épaisseur** — son visuel est recentré, jamais étiré. Les
  panneaux et le PDF pour KDP sont refaits dans la foulée.
- **Régler le livre sur le format de la planche**, quand c'est le format qui diffère.
- **Fixer la pagination du livre** sur celle qu'implique le dos.

Le verdict est **recalculé à chaque changement de pagination définitive**, à l'étape 04 comme à
l'étape 07 — où il apparaît dans la liste de conformité KDP.

|  | Planche PDF | Planche image (JPG/PNG) |
|---|---|---|
| Fichier envoyé à KDP | le vôtre, intact | un PDF fabriqué à la taille exacte |
| Aperçu dans le studio | visionneuse du navigateur (ou image extraite si le PDF est aplati) | l'image elle-même |
| Découpe en 4ème / dos / 1ère | non | oui |
| JPG eBook, vignettes, mockup 3D | — | tirés du panneau de 1ère |

> Un PDF exporté par Photoshop empile ses calques et peint son texte à travers un masque : aucun
> aperçu image ne peut en être tiré fidèlement sans moteur de rendu, et le studio le dit plutôt que
> d'afficher un calque isolé. Le fichier part malgré tout **intact** sur KDP. Pour profiter des
> panneaux découpés et des déclinaisons, exportez aussi votre planche en JPEG.

> ⚠️ Une planche 300 dpi pèse souvent 5 à 40 Mo. Les limites d'envoi sont relevées dans
> `public/.user.ini` (PHP en CGI/FastCGI) et `.htaccess` (PHP en module Apache) ; si votre hébergeur
> plafonne plus bas, le studio affiche la limite réelle et où la changer.

## Vos consignes (prioritaires sur tout le reste)

La zone **📌 Vos consignes** (étape 01 et étape 03) porte votre intention d'auteur : sujet
imposé, angle, public visé, ce qu'il faut garder d'un PDF importé, ce qu'il faut bannir.
Elle est enregistrée sur le livre (`projects.brief`) et **réinjectée en tête de chaque
appel à l'IA**, à toutes les étapes et pour toujours :

| Étape | Appel concerné |
|---|---|
| 02 Concept | les 8 livres proposés |
| 03 Sommaire | génération et **régénération** du sommaire, chapitre ajouté à la demande |
| 04 Couverture | sous-titre, accroche, 4ᵉ de couverture, biographie |
| 05 Rédaction | chaque section écrite |
| 06 Chapitres | réécriture d'une section |
| Import PDF | le plan importé est retravaillé selon vos consignes |

Le bloc envoyé au modèle est explicite : vos consignes **priment** sur le titre, le concept,
le plan importé et les usages du genre ; seules la langue du livre et le format de réponse
ne se négocient pas. Un rappel de vérification est ajouté en fin de prompt, et le modèle
rend compte de ce qu'il a appliqué — affiché sous la zone de consignes (« ✓ Appliquées à la
dernière génération du sommaire ») et tracé dans le journal du projet.

Les consignes suivent le livre partout : duplication, sauvegarde/restauration JSON et
traduction les emportent avec elles (`App\Services\Brief`).

## Générer une mise en page (étape 07)

À côté du sélecteur de thèmes, **✨ Générer une mise en page** demande au studio
d'imaginer des partis pris complets — thème, polices de titres et de texte, couleurs,
ingrédients de composition — à partir de **vos consignes** et de ce que le livre contient
réellement : nombre et longueur des sections, encadrés par type, tableaux, emplacements de
visuels, format et pagination. Le profil est *mesuré* sur le texte écrit, jamais deviné.

Chaque proposition s'essaie sur une **planche de 7 pages** : la page de sommaire et les
6 premières pages de contenu, composées par **le vrai moteur PDF** — mêmes polices
incorporées, mêmes marges, mêmes folios, sommaire paginé pour de bon. Le livre entier est
composé puis seules ces pages sont conservées : l'aperçu ne peut donc pas mentir sur le
rendu final. Tant que vous n'avez pas validé, **rien n'est appliqué** ; « ← Aperçu du
livre » revient à la mise en page en cours.

« ✓ Appliquer à tout le livre » écrit la recette sur le projet (thème, polices, couleurs,
ingrédients) et le livre entier bascule dessus — le sélecteur de thèmes, le composeur et
les listes de polices se resynchronisent aussitôt. « ↻ D'autres propositions » relance
l'IA en lui disant ce qu'elle a déjà proposé, pour qu'elle change vraiment de direction.

L'IA ne choisit que dans le catalogue réel (`PdfBook::THEMES`, `INTERIOR_FONTS`,
`LAYOUT_OPTIONS`) et tout est revalidé côté serveur : une proposition ne peut jamais
produire un rendu impossible (`App\Services\LayoutStudio`).

## Longueur des chapitres

**Étape 03 → Pages par chapitre.** Laissé en *auto*, le studio déduit le nombre de chapitres
du nombre de pages (6 à 14 chapitres, ~20 pages chacun). Dès que vous déplacez le curseur,
c'est **votre** découpage qui commande, sans être rabattu sur ces bornes : 200 pages en
chapitres de 40 pages donnent 5 chapitres de ~11 400 mots.

Le nombre de sous-parties suit la longueur du chapitre (3 pour ~20 pages, jusqu'à 8 pour un
gros chapitre), et le calibre est répercuté partout : prompt du sommaire, mots visés par
chapitre, mots visés par section à la rédaction. Les sous-parties que vous ajoutez ou
retirez à la main dans le sommaire sont créées **telles quelles** à la validation.

## Langue du livre

Un livre a **une** langue, et tout le studio la suit — métadonnées Amazon (sous-titre,
description, 7 mots-clés, catégories), textes de 4ᵉ de couverture, contenu A+, rédaction
des sections, **et** les libellés composés dans le livre lui-même : sommaire, « Chapitre 2 »,
mention de copyright, étiquettes des encadrés, pages de fin. Langues gérées : français,
anglais, allemand, espagnol, italien, portugais, néerlandais (`App\Services\Lang`).

**Elle ne change jamais toute seule en cours de route** : régénérer un sommaire, ajouter un
chapitre, proposer des concepts, retoucher une section ou relire un chapitre — tout reste écrit
dans la langue du livre.

Elle est déduite toute seule, dans cet ordre :

1. le choix enregistré sur le livre ;
2. la langue cible si le livre est une **traduction** (étape « 🌍 Traduire ») ;
3. la **détection automatique** sur le texte du livre (titre, couverture, premières sections) ;
4. la langue par défaut du studio (`config.php → kdp.language`).

Le résultat est mémorisé sur le projet, et corrigeable à tout moment dans **étape 07 →
Publier sur Amazon KDP → Langue du livre**. La boutique de référence suit (Amazon.com pour
l'anglais, Amazon.de pour l'allemand…) : c'est elle qui oriente les mots-clés, les catégories
et le champ « Language » rempli dans le formulaire KDP.

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
