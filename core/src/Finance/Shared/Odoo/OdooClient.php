<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Odoo;

/**
 * Reads Odoo. Read-only by design: there is no method that writes, and the API key the
 * implementation holds is never in an error or a log.
 *
 * Throws {@see OdooError}; never returns "nothing" for "could not read".
 */
interface OdooClient
{
    /**
     * Every row of `$model` matching `$domain`, in id order, with `$fields`, archived
     * records included (the context asks for `active_test = false`, so a record that was
     * archived arrives and can be marked inactive instead of looking deleted). Paging is
     * the implementation's: the caller gets the whole answer.
     *
     * @param list<array{0: string, 1: string, 2: mixed}> $domain
     * @param list<string> $fields
     * @return list<array<string, mixed>> each row carries its `id`
     *
     * @throws OdooError
     */
    public function searchRead(string $model, array $domain, array $fields): array;
}
