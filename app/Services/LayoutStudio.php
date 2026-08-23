<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\Util;

/**
 * Étape 7 — GÉNÉRATION D'UNE MISE EN PAGE.
 *
 * Le studio propose des « recettes » complètes (thème, polices, couleurs,
 * ingrédients de composition), imaginées à partir de VOS CONSIGNES et de ce que
 * le livre contient réellement — encadrés, tableaux, chiffres, longueur des
 * sections. Chaque proposition s'essaie sur une PLANCHE de 7 pages (le sommaire
 * + 6 pages de contenu) composée par le vrai moteur PDF, et ne s'applique au
 * livre entier que si vous la validez. Même esprit que les propositions de
 * couverture : on regarde, on relance, on choisit.
 */
final class LayoutStudio
{
    /** Pages de contenu montrées dans la planche, après la page de sommaire. */
    public const CONTENT_PAGES = 6;

    /** Index (base 0) de la 1ʳᵉ page de sommaire et de la 1ʳᵉ page de corps. */
    private const TOC_PAGE  = 4;   // p.5 du livre
    private const BODY_PAGE = 8;   // p.9 du livre

    /** Les pages de la planche, dans l'ordre d'affichage. */
    public static function previewPages(): array
    {
        $pages = [self::TOC_PAGE];
        for ($i = 0; $i < self::CONTENT_PAGES; $i++) {
            $pages[] = self::BODY_PAGE + $i;
        }
        return $pages;
    }

    // ── Recettes ───────────────────────────────────────────────────────────

    /**
     * Recette actuellement appliquée au livre (réglages réels du projet).
     * Sert de point de comparaison en face des propositions.
     */
    public static function current(array $project, array $colors): array
    {
        $recipe = json_decode((string) ($project['layout_recipe'] ?? ''), true);
        return [
            'name'    => is_array($recipe) ? (string) ($recipe['name'] ?? '') : '',
            'why'     => is_array($recipe) ? (string) ($recipe['why'] ?? '') : '',
            'theme'   => (string) ($project['interior_theme'] ?? 'editorial'),
            'fonts'   => PdfBook::interiorFonts($project),
            'colors'  => ['accent' => $colors['accent'], 'ink' => $colors['ink']],
            'options' => PdfBook::layoutOptions($project),
        ];
    }

    /**
     * Ce que le livre contient VRAIMENT — mesuré, pas deviné. C'est ce profil
     * qui permet à l'IA de proposer une mise en page adaptée : un livre bourré
     * de tableaux ne se compose pas comme un essai de 400 pages sans encadré.
     */
    public static function profile(array $project, array $book): array
    {
        $sections = 0;
        $words = 0;
        $callouts = [];
        $tables = 0;
        $figures = 0;
        $longest = 0;

        foreach ($book['chapters'] as $chapter) {
            $figures += count($chapter['images'] ?? []);
            foreach ($chapter['sections'] as $section) {
                $sections++;
                $w = 0;
                foreach ($section['paragraphs'] as $paragraph) {
                    $w += Util::wordCount($paragraph);
                }
                $words += $w;
                $longest = max($longest, $w);
                foreach ($section['blocks'] as $block) {
                    // Util::blocks : 't' vaut p | list | h | call | table
                    $type = (string) ($block['t'] ?? '');
                    if ($type === 'table') {
                        $tables++;
                    } elseif ($type === 'call') {
                        $kind = (string) ($block['kind'] ?? 'encadré');
                        $callouts[$kind] = ($callouts[$kind] ?? 0) + 1;
                    }
                }
            }
        }
        arsort($callouts);

        return [
            'chapters'      => count(array_filter($book['chapters'], fn ($c) => ($c['role'] ?? '') === 'chapter')),
            'sections'      => $sections,
            'words'         => $words,
            'words_section' => $sections ? (int) round($words / $sections) : 0,
            'longest'       => $longest,
            'tables'        => $tables,
            'figures'       => $figures,
            'callouts'      => $callouts,
            'callouts_total'=> array_sum($callouts),
            'pages'         => Layout::realPages($project),
            'trim'          => (string) $project['trim_format'],
        ];
    }

    /**
     * Demande N propositions à l'IA. VOS CONSIGNES commandent (Brief), le
     * profil du livre cadre, et le catalogue borne : l'IA ne peut choisir que
     * des thèmes, polices et ingrédients qui existent réellement.
     *
     * @return array<int,array> recettes nettoyées
     */
    public static function propose(array $project, array $book, array $colors, int $count = 3, string $avoid = ''): array
    {
        $profile = self::profile($project, $book);
        $langName = Lang::promptName(Lang::codeOf($project));

        $themes = [];
        foreach (PdfBook::THEMES as $slug => $meta) {
            $themes[] = "  · {$slug} — {$meta['name']} : {$meta['desc']}";
        }
        $titleFonts = [];
        $bodyFonts = [];
        foreach (PdfBook::INTERIOR_FONTS as $slug => $def) {
            $line = "  · {$slug} — {$def[0]}";
            if ($def[3] === 'title') {
                $titleFonts[] = $line;
            } else {
                $bodyFonts[] = $line;
            }
        }
        $options = [];
        foreach (PdfBook::LAYOUT_OPTIONS as $key => $meta) {
            $options[] = "  · {$key} — {$meta['name']} : {$meta['desc']}";
        }

        $calloutLine = $profile['callouts']
            ? implode(', ', array_map(fn ($k, $n) => "{$k} ×{$n}", array_keys($profile['callouts']), $profile['callouts']))
            : 'aucun';

        $prompt = Brief::block($project, 'à la mise en page que tu proposes')
            . "Tu es directeur artistique de collection. Tu conçois la MISE EN PAGE INTÉRIEURE d'un livre "
            . "pratique qui part à l'impression chez Amazon KDP.\n\n"
            . "LE LIVRE\n"
            . "Titre : « {$book['title']} »" . ($book['subtitle'] !== '' ? " — {$book['subtitle']}" : '') . "\n"
            . "Langue : {$langName}. Ton : {$project['tone']}. Format : {$profile['trim']} po, "
            . "{$profile['pages']} pages.\n"
            . "Contenu réellement écrit : {$profile['chapters']} chapitres, {$profile['sections']} sections, "
            . Util::nf($profile['words']) . " mots (~{$profile['words_section']} mots par section, "
            . "la plus longue " . Util::nf($profile['longest']) . ").\n"
            . "Encadrés présents : {$calloutLine}. Tableaux : {$profile['tables']}. "
            . "Emplacements de visuels : {$profile['figures']}.\n\n"
            . "LE CATALOGUE — tu ne peux choisir QUE dans ces listes\n"
            . "Thèmes (\"theme\") :\n" . implode("\n", $themes) . "\n"
            . "Polices de titres (\"fonts.title\", \"\" = celle du thème) :\n" . implode("\n", $titleFonts) . "\n"
            . "Polices de texte courant (\"fonts.body\", \"\" = celle du thème) :\n" . implode("\n", $bodyFonts) . "\n"
            . "Ingrédients (\"options\", true/false) :\n" . implode("\n", $options) . "\n\n"
            . "À SAVOIR\n"
            . "- les ingrédients ne s'appliquent qu'aux thèmes premium et premium2 ; sur les autres thèmes "
            . "ils sont ignorés, alors n'annonce pas un effet qu'ils ne produiront pas ;\n"
            . "- col1/col2/col3 arment les gabarits de colonnes utilisables : il en faut AU MOINS un ;\n"
            . "- des sections courtes (< 900 mots) supportent mal 3 colonnes ; des tableaux nombreux "
            . "s'accommodent mal de colonnes serrées ;\n"
            . "- l'accent par défaut du livre est {$colors['accent']} (repris de la couverture) et l'encre "
            . "{$colors['ink']} : ne t'en écarte que si ta proposition y gagne vraiment.\n\n"
            . ($avoid !== '' ? "DÉJÀ PROPOSÉ — propose autre chose, franchement différent :\n{$avoid}\n\n" : '')
            . "Propose {$count} mises en page NETTEMENT différentes les unes des autres.\n"
            . "Réponds UNIQUEMENT avec un objet JSON valide :\n"
            . '{"layouts":[{"name":"…","why":"…","theme":"…","fonts":{"title":"…","body":"…"},'
            . '"colors":{"accent":"#RRGGBB","ink":"#RRGGBB"},"options":{"col1":true,"col2":true,…}}]}' . "\n"
            . "Contraintes :\n"
            . "- \"name\" : nom de la mise en page, 2 à 4 mots, en {$langName} (ex. « Cahier pratique aéré ») ;\n"
            . "- \"why\" : 1 à 2 phrases en {$langName} disant ce que ce parti pris apporte À CE LIVRE-LÀ, "
            . "en citant ce qui le justifie (consignes de l'auteur, encadrés, tableaux, longueur des sections) ;\n"
            . "- \"options\" : les 13 clés, chacune true ou false ;\n"
            . "- aucun texte hors du JSON."
            . Brief::reminder($project);

        $data = Gemini::json($prompt, [
            'model'       => 'fast',
            'temperature' => 0.95,
            'search'      => false,
            'timeout'     => 90,
            'system'      => "Tu es directeur artistique de livres imprimés. Tu proposes des partis pris de "
                . "composition tranchés et cohérents, jamais décoratifs pour rien. Tu écris en {$langName}."
                . Brief::systemLine($project),
        ]);

        $out = [];
        foreach (array_slice((array) ($data['layouts'] ?? []), 0, max(1, $count)) as $raw) {
            $recipe = self::sanitize((array) $raw, $colors);
            if ($recipe !== null) {
                $out[] = $recipe;
            }
        }
        if (!$out) {
            throw new \RuntimeException('Aucune mise en page exploitable n\'a été proposée, relancez.');
        }
        return $out;
    }

    /**
     * Nettoyage strict : tout ce qui ne figure pas au catalogue est écarté.
     * Une proposition ne peut donc jamais produire un rendu impossible.
     */
    public static function sanitize(array $raw, array $colors): ?array
    {
        $theme = (string) ($raw['theme'] ?? '');
        if (!isset(PdfBook::THEMES[$theme])) {
            return null;
        }
        $font = function (string $role) use ($raw): string {
            $slug = (string) (($raw['fonts'] ?? [])[$role] ?? '');
            $def = PdfBook::INTERIOR_FONTS[$slug] ?? null;
            return ($def && $def[3] === $role) ? $slug : '';
        };
        $hex = function ($value, string $fallback): string {
            $value = is_string($value) ? trim($value) : '';
            return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtoupper($value) : $fallback;
        };

        $options = [];
        $given = (array) ($raw['options'] ?? []);
        foreach (array_keys(PdfBook::LAYOUT_OPTIONS) as $key) {
            $options[$key] = array_key_exists($key, $given) ? (bool) $given[$key] : true;
        }
        if (!$options['col1'] && !$options['col2'] && !$options['col3']) {
            $options['col2'] = true;
        }

        return [
            'name'    => mb_substr(trim((string) ($raw['name'] ?? '')), 0, 60) ?: PdfBook::THEMES[$theme]['name'],
            'why'     => mb_substr(trim((string) ($raw['why'] ?? '')), 0, 400),
            'theme'   => $theme,
            'fonts'   => ['title' => $font('title'), 'body' => $font('body')],
            'colors'  => [
                'accent' => $hex(($raw['colors'] ?? [])['accent'] ?? null, $colors['accent']),
                'ink'    => $hex(($raw['colors'] ?? [])['ink'] ?? null, $colors['ink']),
            ],
            'options' => $options,
        ];
    }

    /**
     * Projet FICTIF portant la recette : sert à composer un aperçu sans rien
     * enregistrer. Le moteur PDF lit les mêmes colonnes que d'habitude.
     */
    public static function virtualProject(array $project, array $recipe): array
    {
        $project['interior_theme'] = $recipe['theme'];
        $project['layout_options'] = json_encode($recipe['options']);
        $project['interior_fonts'] = json_encode(array_filter($recipe['fonts'], fn ($v) => $v !== ''));
        return $project;
    }

    /** Planche d'aperçu d'une recette : sommaire + 6 pages, vrai moteur PDF. */
    public static function preview(array $project, array $book, array $recipe): string
    {
        return PdfBook::buildPreview(
            self::virtualProject($project, $recipe),
            $book,
            self::previewPages(),
            $recipe['theme'],
            $recipe['colors']['accent'],
            $recipe['colors']['ink'],
            'planche'
        );
    }

    /** Applique la recette AU LIVRE ENTIER. */
    public static function apply(array $project, array $recipe): void
    {
        $projectId = (int) $project['id'];
        $fonts = array_filter($recipe['fonts'], fn ($v) => $v !== '');
        Db::run(
            'UPDATE projects SET interior_theme = ?, layout_options = ?, interior_fonts = ?,
                    interior_colors = ?, layout_recipe = ?, updated_at = ? WHERE id = ?',
            [
                $recipe['theme'],
                json_encode($recipe['options']),
                $fonts ? json_encode($fonts) : null,
                json_encode(['accent' => $recipe['colors']['accent'], 'ink' => $recipe['colors']['ink']]),
                json_encode(['name' => $recipe['name'], 'why' => $recipe['why']], JSON_UNESCAPED_UNICODE),
                Db::now(),
                $projectId,
            ]
        );
        Util::journal($projectId, 'ok',
            'Mise en page appliquée à tout le livre : « ' . $recipe['name'] . ' » — thème '
            . (PdfBook::THEMES[$recipe['theme']]['name'] ?? $recipe['theme'])
            . ', accent ' . $recipe['colors']['accent']);
    }

    /** Résumé lisible d'une recette, pour l'affichage sous la vignette. */
    public static function describe(array $recipe): string
    {
        $bits = [PdfBook::THEMES[$recipe['theme']]['name'] ?? $recipe['theme']];
        foreach (['title' => 'titres', 'body' => 'texte'] as $role => $label) {
            $slug = $recipe['fonts'][$role] ?? '';
            if ($slug !== '' && isset(PdfBook::INTERIOR_FONTS[$slug])) {
                $bits[] = $label . ' ' . explode(' —', PdfBook::INTERIOR_FONTS[$slug][0])[0];
            }
        }
        $labels = [];
        foreach (['col1' => '1', 'col2' => '2', 'col3' => '3'] as $key => $n) {
            if (!empty($recipe['options'][$key])) {
                $labels[] = $n;
            }
        }
        if ($labels) {
            $bits[] = implode('/', $labels) . ' colonne' . (count($labels) > 1 || $labels !== ['1'] ? 's' : '');
        }
        return implode(' · ', $bits);
    }
}
