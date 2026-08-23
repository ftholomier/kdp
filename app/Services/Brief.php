<?php
declare(strict_types=1);

namespace App\Services;

/**
 * LES CONSIGNES DE L'AUTEUR — ce que VOUS demandez explicitement pour ce livre.
 *
 * Elles vivent dans projects.brief et ne s'évaporent jamais en cours de
 * parcours : elles sont réinjectées EN TÊTE et EN PIED de chaque appel à l'IA
 * (concepts, sommaire, chapitre ajouté, rédaction, retouche, relecture,
 * couverture), avec une règle de priorité explicite — elles priment sur le
 * concept, sur le plan importé et sur les usages du genre.
 *
 * Avant, une consigne donnée à l'import ne servait qu'une fois puis se diluait
 * dans le champ « idée » : régénérer le sommaire l'oubliait purement et
 * simplement. C'est fini.
 */
final class Brief
{
    public const MAX = 4000;

    /** La consigne du projet, ou '' si l'auteur n'en a pas donné. */
    public static function of(array $project): string
    {
        return trim((string) ($project['brief'] ?? ''));
    }

    public static function clean(string $brief): string
    {
        return mb_substr(trim($brief), 0, self::MAX);
    }

    public static function has(array $project): bool
    {
        return self::of($project) !== '';
    }

    /**
     * Bloc à placer EN TÊTE du prompt — vide s'il n'y a pas de consigne.
     * $applies décrit ce que l'appel en cours produit (« le sommaire », « cette
     * section »…) pour que la consigne soit rattachée à quelque chose de concret.
     */
    public static function block(array $project, string $applies = ''): string
    {
        $brief = self::of($project);
        if ($brief === '') {
            return '';
        }
        $target = $applies !== '' ? $applies : 'ce que tu produis ici';

        return "══════ CONSIGNES DE L'AUTEUR — PRIORITÉ ABSOLUE ══════\n"
            . $brief . "\n"
            . "═════════════════════════════════════════════════════\n"
            . "Ces consignes sont l'intention de l'auteur. Elles PRIMENT sur tout le reste de ce "
            . "message : titre, concept, promesse, plan importé, conventions du genre. Applique-les "
            . "à {$target}, littéralement et intégralement, point par point — y compris quand elles "
            . "demandent de sortir du cadre habituel. En cas de contradiction avec une contrainte "
            . "énoncée plus bas, ce sont les consignes de l'auteur qui l'emportent ; deux exceptions "
            . "seulement, non négociables : la langue de rédaction et le format de réponse exigé.\n\n";
    }

    /**
     * Rappel de fin de prompt. La dernière chose lue est la mieux suivie : on
     * redemande une vérification explicite avant la réponse.
     */
    public static function reminder(array $project): string
    {
        if (!self::has($project)) {
            return '';
        }
        return "\n\nVÉRIFICATION AVANT DE RÉPONDRE : relis les CONSIGNES DE L'AUTEUR en tête de ce "
            . "message et contrôle que ta réponse les applique réellement, une par une. Si ce n'est "
            . "pas le cas, corrige ta réponse — pas la consigne.";
    }

    /** Complément de message système. */
    public static function systemLine(array $project): string
    {
        return self::has($project)
            ? " L'auteur t'a donné des consignes explicites : tu les suis à la lettre, elles priment sur tes habitudes."
            : '';
    }

    /**
     * Champ JSON demandé en plus à l'IA pour qu'elle rende compte, en une ligne
     * par consigne, de la façon dont elle l'a appliquée. Sert de preuve à
     * l'écran (« Vos consignes appliquées ») et pousse le modèle à se relire.
     */
    public static function reportField(array $project): string
    {
        return self::has($project) ? ',"applied":["…","…"]' : '';
    }

    public static function reportRule(array $project): string
    {
        return self::has($project)
            ? "- \"applied\" : une ligne courte par consigne de l'auteur, disant concrètement comment "
              . "tu l'as appliquée dans cette réponse (max 6 lignes) ;\n"
            : '';
    }

    /** Normalise le compte rendu renvoyé par l'IA. */
    public static function report(array $data): array
    {
        $out = [];
        foreach (array_slice((array) ($data['applied'] ?? []), 0, 6) as $line) {
            $line = mb_substr(trim((string) $line), 0, 200);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }
}
