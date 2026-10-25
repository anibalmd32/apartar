<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

enum SearchMode
{
    case FullText;

    case Like;
}
