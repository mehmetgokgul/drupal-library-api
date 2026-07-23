<?php

declare(strict_types=1);

namespace Drupal\library_graphql\Ingest;

class GenreSlugMapper {

    public static function mapToTermName(string $slug): ?string {
        
        $mapping = [
            'bilim-kurgu' => 'Sci-Fi',
            'kurgu' => 'Fiction',
            'tarih' => 'History',
            'biyografi' => 'Biography', 
            'suç' => 'Crime',
            'drama' => 'Drama',
            'komedi' => 'Comedy',
            'şiir' => 'Poetry',
        ];

        return $mapping[$slug] ?? NULL; 
    }

}

