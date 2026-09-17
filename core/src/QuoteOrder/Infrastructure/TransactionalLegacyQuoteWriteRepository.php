<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Infrastructure;

use Throwable;
use TriCab\Core\QuoteOrder\Application\Command\PersistQuoteDraft;
use TriCab\Core\QuoteOrder\Application\DTO\PersistedQuote;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteDisabledException;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteFailedException;
use TriCab\Core\QuoteOrder\Application\Service\QuoteWriteAuditLogger;
use TriCab\Core\QuoteOrder\Contract\LegacyQuoteWriteConnection;
use TriCab\Core\QuoteOrder\Contract\QuoteWriteCorrelationRepository;
use TriCab\Core\QuoteOrder\Contract\QuoteWriteRepository;
use TriCab\Core\QuoteOrder\Domain\Mapping\PersistQuoteDraftMapper;
use TriCab\Core\QuoteOrder\Live\LegacyQuoteWriteConfig;
use TriCab\Core\QuoteOrder\Live\NonProductionQuoteWriteSafety;

/**
 * Transactional persist: tqoth → correlation → tqoti (+ tqote) → tqoth.tnet.
 * Quote id from AUTO_INCREMENT / lastInsertId only.
 * PlatformOperationId correlation is written in the same MySQL TX.
 */
final class TransactionalLegacyQuoteWriteRepository implements QuoteWriteRepository
{
    public function __construct(
        private readonly LegacyQuoteWriteConfig $config,
        private readonly LegacyQuoteWriteConnection $connection,
        private readonly QuoteWriteCorrelationRepository $correlation,
        private readonly PersistQuoteDraftMapper $mapper = new PersistQuoteDraftMapper(),
        private readonly QuoteWriteAuditLogger $audit = new NullQuoteWriteAuditLogger(),
        private readonly NonProductionQuoteWriteSafety $writeSafety = new NonProductionQuoteWriteSafety(),
    ) {
    }

    public function persistDraft(PersistQuoteDraft $draft): PersistedQuote
    {
        if (!$this->config->enabled) {
            $this->audit->log([
                'operation' => 'persistDraft',
                'success' => false,
                'errorClass' => QuoteWriteDisabledException::class,
            ]);
            throw QuoteWriteDisabledException::disabled();
        }

        $this->config->assertExecutable();
        $this->writeSafety->assertDevTableSuffixRequired($this->config);

        $operationId = trim((string) $draft->platformOperationId);
        if ($operationId === '') {
            throw QuoteWriteFailedException::because(
                'platformOperationId is required before MySQL persist for crash recovery.',
            );
        }

        try {
            $mapped = $this->mapper->map($draft);
        } catch (Throwable $e) {
            $this->audit->log([
                'operation' => 'persistDraft',
                'success' => false,
                'errorClass' => $e::class,
            ]);
            throw $e;
        }

        $headerTable = $this->config->headerTable();
        $lineTable = $this->config->lineTable();
        $extendedTable = $this->config->extendedTable();

        $this->connection->beginTransaction();
        try {
            $header = $mapped['header'];
            $this->connection->execute(
                'INSERT INTO ' . $headerTable
                . ' (dt,st,de,tme,cod,sa,sa_op,mtt,cus,ptm,cy,tnet,tcval,dvalid,cref,cus_op,cus_gen)'
                . ' VALUES (:dt,:st,:de,:tme,:cod,:sa,:sa_op,:mtt,:cus,:ptm,:cy,:tnet,:tcval,:dvalid,:cref,:cus_op,:cus_gen)',
                [
                    'dt' => $header['dt'],
                    'st' => $header['st'],
                    'de' => $header['de'],
                    'tme' => $header['tme'],
                    'cod' => $header['cod'],
                    'sa' => $header['sa'],
                    'sa_op' => $header['sa_op'],
                    'mtt' => $header['mtt'],
                    'cus' => $header['cus'],
                    'ptm' => $header['ptm'],
                    'cy' => $header['cy'],
                    'tnet' => $header['tnet'],
                    'tcval' => $header['tcval'],
                    'dvalid' => $header['dvalid'],
                    'cref' => $header['cref'],
                    'cus_op' => $header['cus_op'],
                    'cus_gen' => $header['cus_gen'],
                ],
            );

            $quoteId = $this->connection->lastInsertId();
            if ($quoteId === '' || $quoteId === '0') {
                throw QuoteWriteFailedException::because('MySQL did not return an AUTO_INCREMENT quote id.');
            }

            $this->correlation->insert($operationId, $quoteId);

            $lineIds = [];
            foreach ($mapped['lines'] as $entry) {
                $line = $entry['line'];
                $this->connection->execute(
                    'INSERT INTO ' . $lineTable
                    . ' (qot,itm,cata,catdt,col,qty,pm,nprc,net,cprc,cval,qotqty,ref)'
                    . ' VALUES (:qot,:itm,:cata,:catdt,:col,:qty,:pm,:nprc,:net,:cprc,:cval,:qotqty,:ref)',
                    [
                        'qot' => (int) $quoteId,
                        'itm' => $line['itm'],
                        'cata' => $line['cata'],
                        'catdt' => $line['catdt'],
                        'col' => $line['col'],
                        'qty' => $line['qty'],
                        'pm' => $line['pm'],
                        'nprc' => $line['nprc'],
                        'net' => $line['net'],
                        'cprc' => $line['cprc'],
                        'cval' => $line['cval'],
                        'qotqty' => $line['qotqty'],
                        'ref' => $line['ref'],
                    ],
                );
                $lineId = $this->connection->lastInsertId();
                if ($lineId === '' || $lineId === '0') {
                    throw QuoteWriteFailedException::because('MySQL did not return an AUTO_INCREMENT line id.');
                }
                $lineIds[] = (int) $lineId;

                foreach ($entry['extended'] as $ext) {
                    $this->connection->execute(
                        'INSERT INTO ' . $extendedTable
                        . ' (qoti,pk,qty,comments) VALUES (:qoti,:pk,:qty,:comments)',
                        [
                            'qoti' => (int) $lineId,
                            'pk' => $ext['pk'],
                            'qty' => $ext['qty'],
                            'comments' => $ext['comments'],
                        ],
                    );
                }
            }

            $this->connection->execute(
                'UPDATE ' . $headerTable . ' SET tnet = :tnet WHERE id = :id',
                [
                    'tnet' => $mapped['totalNet'],
                    'id' => (int) $quoteId,
                ],
            );

            $this->connection->commit();

            $result = new PersistedQuote(
                quoteId: $quoteId,
                statusCode: (string) $header['st'],
                totalNet: $mapped['totalNet'],
                lineIds: $lineIds,
            );

            $this->audit->log([
                'operation' => 'persistDraft',
                'success' => true,
                'quoteId' => $quoteId,
            ]);

            return $result;
        } catch (Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            $this->audit->log([
                'operation' => 'persistDraft',
                'success' => false,
                'errorClass' => $e::class,
            ]);
            if ($e instanceof QuoteWriteFailedException) {
                throw $e;
            }

            throw QuoteWriteFailedException::because($e->getMessage(), $e);
        }
    }
}
