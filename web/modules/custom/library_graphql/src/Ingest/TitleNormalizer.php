<?php

declare(strict_types=1);

namespace Drupal\library_graphql\Ingest;

class TitleNormalizer {

    public static function normalize(string $title): string {
    
        $colonPos = strpos($title, ':');
        $parenPos = strpos($title, '(');

        $positions = [];
        if ($colonPos !== false) {
            $positions[] = $colonPos;
        }
        if ($parenPos !== false) {
            $positions[] = $parenPos;
        }
        
        if (!empty($positions)) {
            $cut = min($positions);
            $title = substr($title, 0, $cut);
        }
        return trim($title);
    }
}