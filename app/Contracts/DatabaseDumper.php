<?php

namespace App\Contracts;

/**
 * Writes a restorable dump of the application database to a local path.
 *
 * An interface rather than a concrete class for two reasons. The obvious one is
 * that a different driver needs a different tool. The load-bearing one is that
 * the test suite runs on in-memory sqlite, where there is nothing to dump and
 * no mysqldump binary to call — binding a fake here is what lets the archive,
 * listing, download, retention and permission behaviour all be tested without
 * a MySQL server. See AppServiceProvider for the driver map.
 */
interface DatabaseDumper
{
    /**
     * @param  string  $absolutePath  Destination for the dump; the caller owns the file.
     *
     * @throws \RuntimeException when the dump could not be produced.
     */
    public function dump(string $absolutePath): void;
}
