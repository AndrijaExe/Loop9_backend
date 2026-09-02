<?php

declare(strict_types=1);

namespace App\Model\Chat;

final class PlayerFindingClassifier
{
    public function reportedFinding(string $playerMessage, RuntimeContext $context): bool
    {
        if ($context->isOfftopic()) {
            return false;
        }

        return $this->looksLikeAFinding($this->normalizePlayerMessage($playerMessage));
    }

    private function normalizePlayerMessage(string $message): string
    {
        $lower = mb_strtolower(trim($message));

        return strtr($lower, [
            'č' => 'c',
            'ć' => 'c',
            'š' => 's',
            'đ' => 'dj',
            'ž' => 'z',
            'ё' => 'е',
        ]);
    }

    private function looksLikeAFinding(string $normalized): bool
    {
        if ($normalized === '') {
            return false;
        }

        if (preg_match(
            '/\b(?:'
            . '(?:everything|all|it)\s+(?:looks?|seems?|is)\s+(?:fine|normal|unchanged|the\s+same)|'
            . 'all\s+good|nothing\s+(?:looks?|seems?|is)\s+(?:wrong|different|strange|odd)|'
            . '(?:sve|ovde)\s+(?:je\s+)?(?:u\s+redu|okej|normalno|isto)|'
            . 'nista\s+(?:nije\s+)?(?:cudno|drugacije|promenjeno)|'
            . 'alles\s+(?:ist\s+)?(?:in\s+ordnung|normal|gleich)|'
            . 'tout\s+(?:est|semble)\s+(?:normal|pareil)|'
            . 'rien\s+(?:ne\s+)?(?:semble|parait)\s+(?:anormal|different)|'
            . 'все\s+(?:в\s+порядке|нормально|как\s+раньше)|'
            . 'ничего\s+(?:не\s+)?(?:изменилось|странного)'
            . ')\b/u',
            $normalized,
        ) === 1) {
            return true;
        }

        return (bool) preg_match(
            '/\b('
            . 'missing|hidden|gone|moved|rotated|flicker|flickering|lamp|light|sound|noise|audio|'
            . 'door|locked|lock|note|paper|bigger|smaller|follow|following|behind|'
            . 'stapler|chair|clock|printer|monitor|radio|cabinet|desk|'
            . 'nothing|normal|clean|unchanged|empty|same|changed|different|strange|odd|wrong|weird|'
            . 'saw|seen|found|noticed|heard|'
            . 'nestal|nema|fali|pomer|treper|trepti|svetlo|zvuk|vrata|zakljuc|poruka|'
            . 'prati|nista|cisto|normaln|isto|promen|cudn|drugac|koraci|'
            . 'vidim|video|videla|nasao|nasla|cuo|cula|cujem|izgleda|'
            . 'gefunden|gesehen|nichts|verander|'
            . 'disparu|boug|rien|'
            . 'пропал|сдвин|ничего|нормал|видел|слыш'
            . ')\w*/u',
            $normalized,
        );
    }
}
