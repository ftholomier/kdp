<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;

/**
 * LANGUE DU LIVRE — source de vérité unique.
 *
 * Un livre écrit en anglais doit être anglais PARTOUT : métadonnées Amazon,
 * mots-clés, catégories, textes de 4e de couverture, mais aussi les libellés
 * composés par le studio dans le PDF (sommaire, copyright, « Chapitre 2 »,
 * encadrés, pages de fin) et l'ePub.
 *
 * La langue est résolue dans cet ordre :
 *   1. le choix explicite enregistré sur le projet (projects.lang) ;
 *   2. la langue cible si le livre est une traduction (projects.translate_lang) ;
 *   3. la détection automatique sur le texte du livre (titre + 1re section) ;
 *   4. la langue par défaut du studio (config.php → kdp.language).
 * Les cas 2 et 3 sont mémorisés sur le projet : la détection ne coûte qu'une fois.
 */
final class Lang
{
    /**
     * Langues gérées par Amazon KDP pour le broché, avec leur boutique
     * principale — c'est elle qui sert de référence aux mots-clés, aux
     * catégories et au prix conseillé.
     */
    public const LANGS = [
        'fr' => ['fr' => 'Français',   'native' => 'Français',   'kdp' => 'French',     'marketplace' => 'Amazon.fr',    'store' => 'fr', 'currency' => 'EUR'],
        'en' => ['fr' => 'Anglais',    'native' => 'English',    'kdp' => 'English',    'marketplace' => 'Amazon.com',   'store' => 'us', 'currency' => 'USD'],
        'de' => ['fr' => 'Allemand',   'native' => 'Deutsch',    'kdp' => 'German',     'marketplace' => 'Amazon.de',    'store' => 'de', 'currency' => 'EUR'],
        'es' => ['fr' => 'Espagnol',   'native' => 'Español',    'kdp' => 'Spanish',    'marketplace' => 'Amazon.es',    'store' => 'es', 'currency' => 'EUR'],
        'it' => ['fr' => 'Italien',    'native' => 'Italiano',   'kdp' => 'Italian',    'marketplace' => 'Amazon.it',    'store' => 'it', 'currency' => 'EUR'],
        'pt' => ['fr' => 'Portugais',  'native' => 'Português',  'kdp' => 'Portuguese', 'marketplace' => 'Amazon.com.br','store' => 'br', 'currency' => 'BRL'],
        'nl' => ['fr' => 'Néerlandais','native' => 'Nederlands', 'kdp' => 'Dutch',      'marketplace' => 'Amazon.nl',    'store' => 'nl', 'currency' => 'EUR'],
    ];

    /**
     * Libellés composés par le studio dans le livre lui-même. Traduits ici et
     * nulle part ailleurs : PdfBook, Epub et Docx les reçoivent tout faits.
     */
    private const LABELS = [
        'fr' => [
            'toc' => 'Sommaire', 'intro' => 'Introduction', 'conclusion' => 'Conclusion', 'chapter' => 'Chapitre',
            'rights' => 'Tous droits réservés.',
            'no_repro' => 'Aucune partie de ce livre ne peut être reproduite sans autorisation écrite.',
            'selfpub' => 'Publié en autoédition via Amazon Kindle Direct Publishing.',
            'last_word' => 'Un dernier mot', 'review_title' => 'Votre avis compte',
            'review_1' => 'Vous voici à la fin de ce livre — merci de l\'avoir lu jusqu\'ici. Si les pages qui précèdent vous ont apporté des repères, des idées ou l\'envie de passer à l\'action, vous pouvez rendre un immense service à son auteur indépendant : laisser un avis sur Amazon.',
            'review_2' => 'Quelques lignes suffisent. Les avis sont le principal signal qui permet à un livre autoédité d\'être découvert par d\'autres lecteurs : chacun compte réellement.',
            'review_3' => 'Rendez-vous sur la page Amazon du livre (rubrique « Donner un avis ») — et merci d\'avance.',
            'further' => 'Pour aller plus loin', 'same_author' => 'Du même auteur',
            'same_author_intro' => 'Si ce livre vous a plu, d\'autres titres du même auteur pourraient vous accompagner :',
            'author' => 'L\'auteur', 'about' => 'À propos de',
            'section' => 'Section',
            'intro_s1' => 'Ce que ce livre va changer pour vous', 'intro_s2' => 'Comment tirer le meilleur de ce livre',
            'concl_s1' => 'L\'essentiel à emporter', 'concl_s2' => 'Votre plan d\'action',
            'callouts' => ['retenir' => 'À retenir', 'chiffre' => 'Chiffre clé', 'conseil' => 'Conseil',
                           'exemple' => 'Exemple', 'faq' => 'Question fréquente', 'attention' => 'Attention'],
        ],
        'en' => [
            'toc' => 'Contents', 'intro' => 'Introduction', 'conclusion' => 'Conclusion', 'chapter' => 'Chapter',
            'rights' => 'All rights reserved.',
            'no_repro' => 'No part of this book may be reproduced without written permission.',
            'selfpub' => 'Self-published through Amazon Kindle Direct Publishing.',
            'last_word' => 'One last thing', 'review_title' => 'Your review matters',
            'review_1' => 'You have reached the end of this book — thank you for reading it. If these pages gave you landmarks, ideas or the urge to take action, you can do its independent author an enormous favour: leave a review on Amazon.',
            'review_2' => 'A few lines are enough. Reviews are the main signal that lets a self-published book be discovered by other readers: every single one counts.',
            'review_3' => 'Head over to the book\'s Amazon page (the "Write a review" section) — and thank you in advance.',
            'further' => 'Going further', 'same_author' => 'Also by this author',
            'same_author_intro' => 'If you enjoyed this book, these other titles by the same author might help you too:',
            'author' => 'The author', 'about' => 'About',
            'section' => 'Section',
            'intro_s1' => 'What this book will change for you', 'intro_s2' => 'How to get the most out of this book',
            'concl_s1' => 'The essentials to take away', 'concl_s2' => 'Your action plan',
            'callouts' => ['retenir' => 'Key takeaway', 'chiffre' => 'Key figure', 'conseil' => 'Tip',
                           'exemple' => 'Example', 'faq' => 'Frequently asked', 'attention' => 'Watch out'],
        ],
        'de' => [
            'toc' => 'Inhalt', 'intro' => 'Einleitung', 'conclusion' => 'Fazit', 'chapter' => 'Kapitel',
            'rights' => 'Alle Rechte vorbehalten.',
            'no_repro' => 'Kein Teil dieses Buches darf ohne schriftliche Genehmigung reproduziert werden.',
            'selfpub' => 'Im Selbstverlag über Amazon Kindle Direct Publishing veröffentlicht.',
            'last_word' => 'Ein letztes Wort', 'review_title' => 'Ihre Meinung zählt',
            'review_1' => 'Sie sind am Ende dieses Buches angekommen — danke, dass Sie bis hierher gelesen haben. Wenn Ihnen die vorangegangenen Seiten Orientierung, Ideen oder Lust auf Veränderung gegeben haben, erweisen Sie dem unabhängigen Autor einen großen Dienst: Hinterlassen Sie eine Rezension bei Amazon.',
            'review_2' => 'Ein paar Zeilen genügen. Rezensionen sind das wichtigste Signal, damit ein selbstverlegtes Buch von anderen Leserinnen und Lesern entdeckt wird: Jede einzelne zählt.',
            'review_3' => 'Gehen Sie zur Amazon-Seite des Buches (Bereich „Rezension schreiben") — vielen Dank im Voraus.',
            'further' => 'Weiterlesen', 'same_author' => 'Vom selben Autor',
            'same_author_intro' => 'Wenn Ihnen dieses Buch gefallen hat, könnten diese Titel Sie ebenfalls begleiten:',
            'author' => 'Der Autor', 'about' => 'Über',
            'section' => 'Abschnitt',
            'intro_s1' => 'Was dieses Buch für Sie verändert', 'intro_s2' => 'So holen Sie das Beste aus diesem Buch',
            'concl_s1' => 'Das Wichtigste zum Mitnehmen', 'concl_s2' => 'Ihr Aktionsplan',
            'callouts' => ['retenir' => 'Zum Merken', 'chiffre' => 'Kennzahl', 'conseil' => 'Tipp',
                           'exemple' => 'Beispiel', 'faq' => 'Häufige Frage', 'attention' => 'Achtung'],
        ],
        'es' => [
            'toc' => 'Índice', 'intro' => 'Introducción', 'conclusion' => 'Conclusión', 'chapter' => 'Capítulo',
            'rights' => 'Todos los derechos reservados.',
            'no_repro' => 'Ninguna parte de este libro puede reproducirse sin autorización escrita.',
            'selfpub' => 'Autopublicado a través de Amazon Kindle Direct Publishing.',
            'last_word' => 'Una última cosa', 'review_title' => 'Tu opinión cuenta',
            'review_1' => 'Has llegado al final de este libro: gracias por leerlo hasta aquí. Si estas páginas te han dado referencias, ideas o ganas de pasar a la acción, puedes hacerle un enorme favor a su autor independiente: dejar una reseña en Amazon.',
            'review_2' => 'Bastan unas pocas líneas. Las reseñas son la principal señal que permite que un libro autopublicado sea descubierto por otros lectores: cada una cuenta de verdad.',
            'review_3' => 'Entra en la página del libro en Amazon (apartado «Escribir una opinión») y gracias de antemano.',
            'further' => 'Para ir más lejos', 'same_author' => 'Del mismo autor',
            'same_author_intro' => 'Si este libro te ha gustado, otros títulos del mismo autor podrían acompañarte:',
            'author' => 'El autor', 'about' => 'Sobre',
            'section' => 'Sección',
            'intro_s1' => 'Lo que este libro cambiará para ti', 'intro_s2' => 'Cómo sacar el máximo partido a este libro',
            'concl_s1' => 'Lo esencial para llevarte', 'concl_s2' => 'Tu plan de acción',
            'callouts' => ['retenir' => 'Para recordar', 'chiffre' => 'Cifra clave', 'conseil' => 'Consejo',
                           'exemple' => 'Ejemplo', 'faq' => 'Pregunta frecuente', 'attention' => 'Atención'],
        ],
        'it' => [
            'toc' => 'Indice', 'intro' => 'Introduzione', 'conclusion' => 'Conclusione', 'chapter' => 'Capitolo',
            'rights' => 'Tutti i diritti riservati.',
            'no_repro' => 'Nessuna parte di questo libro può essere riprodotta senza autorizzazione scritta.',
            'selfpub' => 'Autopubblicato tramite Amazon Kindle Direct Publishing.',
            'last_word' => 'Un\'ultima cosa', 'review_title' => 'La tua opinione conta',
            'review_1' => 'Sei arrivato alla fine di questo libro: grazie per averlo letto fin qui. Se queste pagine ti hanno dato punti di riferimento, idee o voglia di passare all\'azione, puoi rendere un enorme servizio al suo autore indipendente: lasciare una recensione su Amazon.',
            'review_2' => 'Bastano poche righe. Le recensioni sono il principale segnale che permette a un libro autopubblicato di essere scoperto da altri lettori: ognuna conta davvero.',
            'review_3' => 'Vai alla pagina Amazon del libro (sezione «Scrivi una recensione») — e grazie in anticipo.',
            'further' => 'Per approfondire', 'same_author' => 'Dello stesso autore',
            'same_author_intro' => 'Se questo libro ti è piaciuto, altri titoli dello stesso autore potrebbero accompagnarti:',
            'author' => 'L\'autore', 'about' => 'Su',
            'section' => 'Sezione',
            'intro_s1' => 'Ciò che questo libro cambierà per te', 'intro_s2' => 'Come trarre il meglio da questo libro',
            'concl_s1' => 'L\'essenziale da portare con sé', 'concl_s2' => 'Il tuo piano d\'azione',
            'callouts' => ['retenir' => 'Da ricordare', 'chiffre' => 'Dato chiave', 'conseil' => 'Consiglio',
                           'exemple' => 'Esempio', 'faq' => 'Domanda frequente', 'attention' => 'Attenzione'],
        ],
        'pt' => [
            'toc' => 'Sumário', 'intro' => 'Introdução', 'conclusion' => 'Conclusão', 'chapter' => 'Capítulo',
            'rights' => 'Todos os direitos reservados.',
            'no_repro' => 'Nenhuma parte deste livro pode ser reproduzida sem autorização escrita.',
            'selfpub' => 'Publicado de forma independente através da Amazon Kindle Direct Publishing.',
            'last_word' => 'Uma última palavra', 'review_title' => 'A sua opinião conta',
            'review_1' => 'Chegou ao fim deste livro — obrigado por o ter lido até aqui. Se estas páginas lhe deram referências, ideias ou vontade de agir, pode prestar um enorme serviço ao seu autor independente: deixar uma avaliação na Amazon.',
            'review_2' => 'Bastam algumas linhas. As avaliações são o principal sinal que permite a um livro independente ser descoberto por outros leitores: cada uma conta mesmo.',
            'review_3' => 'Visite a página do livro na Amazon (secção «Escrever uma avaliação») — e obrigado desde já.',
            'further' => 'Para ir mais longe', 'same_author' => 'Do mesmo autor',
            'same_author_intro' => 'Se gostou deste livro, outros títulos do mesmo autor podem acompanhá-lo:',
            'author' => 'O autor', 'about' => 'Sobre',
            'section' => 'Secção',
            'intro_s1' => 'O que este livro vai mudar para si', 'intro_s2' => 'Como tirar o máximo partido deste livro',
            'concl_s1' => 'O essencial a reter', 'concl_s2' => 'O seu plano de ação',
            'callouts' => ['retenir' => 'A reter', 'chiffre' => 'Número-chave', 'conseil' => 'Dica',
                           'exemple' => 'Exemplo', 'faq' => 'Pergunta frequente', 'attention' => 'Atenção'],
        ],
        'nl' => [
            'toc' => 'Inhoud', 'intro' => 'Inleiding', 'conclusion' => 'Conclusie', 'chapter' => 'Hoofdstuk',
            'rights' => 'Alle rechten voorbehouden.',
            'no_repro' => 'Niets uit dit boek mag worden verveelvoudigd zonder schriftelijke toestemming.',
            'selfpub' => 'In eigen beheer uitgegeven via Amazon Kindle Direct Publishing.',
            'last_word' => 'Nog één ding', 'review_title' => 'Uw mening telt',
            'review_1' => 'U bent aan het einde van dit boek gekomen — bedankt dat u tot hier hebt gelezen. Als deze pagina\'s u houvast, ideeën of zin om in actie te komen hebben gegeven, kunt u de onafhankelijke auteur een enorme dienst bewijzen: laat een recensie achter op Amazon.',
            'review_2' => 'Een paar regels volstaan. Recensies zijn het belangrijkste signaal waardoor een boek in eigen beheer door andere lezers wordt ontdekt: elke recensie telt echt.',
            'review_3' => 'Ga naar de Amazon-pagina van het boek (onderdeel «Een recensie schrijven») — en alvast bedankt.',
            'further' => 'Verder lezen', 'same_author' => 'Van dezelfde auteur',
            'same_author_intro' => 'Als dit boek u beviel, kunnen andere titels van dezelfde auteur u ook helpen:',
            'author' => 'De auteur', 'about' => 'Over',
            'section' => 'Deel',
            'intro_s1' => 'Wat dit boek voor u verandert', 'intro_s2' => 'Hoe u het meeste uit dit boek haalt',
            'concl_s1' => 'Het belangrijkste om mee te nemen', 'concl_s2' => 'Uw actieplan',
            'callouts' => ['retenir' => 'Onthoud dit', 'chiffre' => 'Kerncijfer', 'conseil' => 'Tip',
                           'exemple' => 'Voorbeeld', 'faq' => 'Veelgestelde vraag', 'attention' => 'Let op'],
        ],
    ];

    /** Mots outils très fréquents, propres à chaque langue (détection). */
    private const MARKERS = [
        'fr' => ['le', 'la', 'les', 'des', 'une', 'vous', 'pour', 'dans', 'que', 'qui', 'est', 'plus', 'avec', 'sur', 'pas', 'votre'],
        'en' => ['the', 'and', 'you', 'for', 'with', 'this', 'that', 'your', 'are', 'from', 'have', 'will', 'can', 'not', 'they'],
        'de' => ['der', 'die', 'das', 'und', 'sie', 'mit', 'für', 'ist', 'nicht', 'auf', 'ein', 'eine', 'sich', 'auch', 'dass'],
        'es' => ['el', 'la', 'los', 'las', 'que', 'con', 'para', 'una', 'por', 'como', 'más', 'pero', 'sus', 'este', 'tu'],
        'it' => ['il', 'la', 'che', 'per', 'con', 'una', 'del', 'della', 'sono', 'più', 'come', 'nel', 'questo', 'tuo', 'anche'],
        'pt' => ['que', 'para', 'com', 'uma', 'você', 'mais', 'como', 'seu', 'sua', 'não', 'por', 'dos', 'das', 'este', 'muito'],
        'nl' => ['het', 'een', 'van', 'dat', 'niet', 'zijn', 'voor', 'met', 'die', 'maar', 'ook', 'aan', 'kan', 'uw', 'wordt'],
    ];

    /** Code de langue effectif d'un projet, mémorisé dès qu'il est déduit. */
    public static function codeOf(array $project): string
    {
        $explicit = strtolower(trim((string) ($project['lang'] ?? '')));
        if (isset(self::LANGS[$explicit])) {
            return $explicit;
        }

        // Livre traduit : la langue cible est connue (« anglais », « allemand »…)
        $translated = self::fromFrenchName((string) ($project['translate_lang'] ?? ''));
        $code = $translated ?: self::detectProject($project);

        if ($code !== '' && !empty($project['id'])) {
            self::remember((int) $project['id'], $code);
        }
        return $code ?: self::defaultCode();
    }

    /** Fiche complète (nom natif, nom KDP, boutique, devise) d'un projet. */
    public static function of(array $project): array
    {
        $code = self::codeOf($project);
        return ['code' => $code] + self::LANGS[$code];
    }

    /** Libellés d'intérieur (sommaire, copyright, encadrés, pages de fin). */
    public static function labels(string $code): array
    {
        return self::LABELS[$code] ?? self::LABELS['fr'];
    }

    /** Nom de la langue tel qu'on l'écrit dans un prompt IA (« anglais »). */
    public static function promptName(string $code): string
    {
        return mb_strtolower(self::LANGS[$code]['fr'] ?? 'français');
    }

    /** Langue par défaut du studio (config.php → kdp.language). */
    public static function defaultCode(): string
    {
        $configured = mb_strtolower(trim((string) Config::get('kdp.language', 'Français')));
        foreach (self::LANGS as $code => $lang) {
            if (mb_strtolower($lang['fr']) === $configured || mb_strtolower($lang['native']) === $configured
                || mb_strtolower($lang['kdp']) === $configured || $code === $configured) {
                return $code;
            }
        }
        return 'fr';
    }

    /** « anglais » → « en » (valeur enregistrée par la traduction). */
    private static function fromFrenchName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        if ($name === '') {
            return '';
        }
        foreach (self::LANGS as $code => $lang) {
            if (mb_strtolower($lang['fr']) === $name) {
                return $code;
            }
        }
        return '';
    }

    /** Détection sur le livre lui-même : titre, sous-titre et premières pages. */
    private static function detectProject(array $project): string
    {
        $projectId = (int) ($project['id'] ?? 0);
        $sample = (string) ($project['title'] ?? '');
        if ($projectId > 0) {
            $cover = Db::one('SELECT texts FROM covers WHERE project_id = ?', [$projectId]);
            $texts = $cover ? (json_decode((string) $cover['texts'], true) ?: []) : [];
            $sample .= ' ' . implode(' ', array_map('strval', array_intersect_key(
                $texts, array_flip(['title', 'subtitle', 'tagline', 'back_text'])
            )));
            $rows = Db::all(
                "SELECT s.content FROM sections s JOIN chapters c ON c.id = s.chapter_id
                 WHERE c.project_id = ? AND s.content IS NOT NULL AND s.content != '' ORDER BY c.num, s.num LIMIT 3",
                [$projectId]
            );
            foreach ($rows as $row) {
                $sample .= ' ' . (string) $row['content'];
            }
        }
        return self::detect($sample);
    }

    /**
     * Détection par mots outils : on compte les occurrences (en mots entiers)
     * des marqueurs de chaque langue. Renvoie '' si l'échantillon est trop
     * pauvre pour trancher — on ne devine jamais au hasard.
     */
    public static function detect(string $text): string
    {
        $words = preg_split('/[^\p{L}\p{N}\']+/u', mb_strtolower(mb_substr($text, 0, 20000))) ?: [];
        $words = array_filter($words, fn ($w) => $w !== '');
        if (count($words) < 25) {
            return '';
        }
        $counts = array_count_values($words);
        $scores = [];
        foreach (self::MARKERS as $code => $markers) {
            $score = 0;
            foreach ($markers as $marker) {
                $score += $counts[$marker] ?? 0;
            }
            $scores[$code] = $score;
        }
        arsort($scores);
        $best = array_key_first($scores);
        $bestScore = $scores[$best];
        $total = count($words);
        // Il faut une vraie densité de marqueurs (≥ 3 %) ET une avance nette
        // sur la deuxième langue, sinon on préfère ne pas se prononcer.
        $second = array_values($scores)[1] ?? 0;
        if ($bestScore < max(6, $total * 0.03) || $bestScore < $second * 1.4) {
            return '';
        }
        return (string) $best;
    }

    /** Enregistre la langue déduite pour ne plus jamais la recalculer. */
    private static function remember(int $projectId, string $code): void
    {
        if (!isset(self::LANGS[$code])) {
            return;
        }
        Db::run('UPDATE projects SET lang = ? WHERE id = ? AND (lang IS NULL OR lang = ?)', [$code, $projectId, '']);
    }

    /** Enregistre un choix explicite de l'utilisateur. */
    public static function set(int $projectId, string $code): string
    {
        if (!isset(self::LANGS[$code])) {
            throw new \RuntimeException('Langue non gérée.');
        }
        Db::run('UPDATE projects SET lang = ?, updated_at = ? WHERE id = ?', [$code, Db::now(), $projectId]);
        return $code;
    }
}
