<?php

declare(strict_types=1);

namespace App\Model\Chat;

final class LocalSafetyDetector
{
    public function detect(string $text, string $stage): ?string
    {
        if ($this->containsPersonalData($text)) {
            return 'personal_data';
        }

        if ($stage === 'input' && $this->requestsCopyrightedReproduction($text)) {
            return 'copyright_reproduction';
        }

        return null;
    }

    private function containsPersonalData(string $text): bool
    {
        if (preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', $text) === 1) {
            return true;
        }

        // Deliberately conservative: ordinary years and short game numbers are
        // allowed, while phone/account-like digit sequences are blocked.
        return preg_match('/(?<!\d)(?:\+?\d[\s().-]*){10,}(?!\d)/u', $text) === 1;
    }

    private function requestsCopyrightedReproduction(string $text): bool
    {
        $normalized = mb_strtolower($text);

        if (preg_match(
            '/\b(full|entire|complete|whole|verbatim|ceo|cela|celo|čitav|čitavu|kompletan|kompletnu|'
            . 'vollständig\w*|gesamt\w*|complet|complète|intégral|intégrale|полный|полностью)\b'
            . '.{0,80}\b(lyrics?|song|chapter|book|script|screenplay|poem|novel|'
            . 'tekst\s+pesme|pesmu|poglavlje|knjigu|scenario|pesmu|'
            . 'liedtext|lied|kapitel|buch|drehbuch|'
            . 'paroles|chanson|chapitre|livre|scénario|'
            . 'текст\s+песни|песню|главу|книгу|сценарий)\b/iu',
            $normalized
        ) === 1) {
            return true;
        }

        return preg_match(
            '/\b(paroles|chanson|chapitre|livre|scénario)\b.{0,80}'
            . '\b(complet|complète|complets|complètes|intégral|intégrale)\b/iu',
            $normalized
        ) === 1;
    }
}
