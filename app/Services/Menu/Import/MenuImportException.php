<?php

declare(strict_types=1);

namespace App\Services\Menu\Import;

use RuntimeException;

/**
 * The file was wrong, and this says where.
 *
 * Every message here names the row or the key that caused it, because the
 * person reading it is looking at a four-hundred-line spreadsheet somebody
 * emailed them and "invalid menu file" tells them nothing they can act on.
 */
final class MenuImportException extends RuntimeException
{
    public static function at(string $where, string $problem): self
    {
        return new self(sprintf('%s: %s', $where, $problem));
    }
}
