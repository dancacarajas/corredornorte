<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Config\Database;
use PDO;
use Throwable;

final class Db
{
    public static function tryConnection(): ?PDO
    {
        try {
            return Database::connection();
        } catch (Throwable) {
            return null;
        }
    }
}
