<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use TriCab\Core\Finance\Ap\Contract\ApOutboundStore;
use TriCab\Core\Finance\Ap\Domain\ApOutboundMessage;
use TriCab\Core\Finance\Ap\Domain\ApOutboundTarget;

/**
 * `finance.invoice_outbound_messages` (`create-finance-invoice-schema.sql`, decision D73). Foreign keys to the invoice, the email
 * and the approval batch; none to the ledger, so a link may name any delivery id. Exactly one of the three record columns is set.
 */
final class AzureSqlApOutboundStore extends AzureSqlApSupport implements ApOutboundStore
{
    private const COLUMN = [
        ApOutboundTarget::INVOICE => 'invoice_id',
        ApOutboundTarget::EMAIL => 'email_id',
        ApOutboundTarget::APPROVAL_BATCH => 'approval_batch_id',
    ];

    private const SELECT = 'SELECT [id], [invoice_id], [email_id], [approval_batch_id], [delivery_id], [sender_user_id], [body], [attach_invoice], [created_date]
                              FROM [finance].[invoice_outbound_messages]';

    public function add(ApOutboundMessage $message): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[invoice_outbound_messages] ([id], [invoice_id], [email_id], [approval_batch_id], [delivery_id], [sender_user_id], [body], [attach_invoice], [created_date])
             VALUES (:id, :invoice, :email, :batch, :delivery, :userId, :body, :attach, :created)',
            [
                'id' => $message->id,
                'invoice' => $message->target->kind === ApOutboundTarget::INVOICE ? $message->target->id : null,
                'email' => $message->target->kind === ApOutboundTarget::EMAIL ? $message->target->id : null,
                'batch' => $message->target->kind === ApOutboundTarget::APPROVAL_BATCH ? $message->target->id : null,
                'delivery' => $message->deliveryId,
                'userId' => $message->senderUserId,
                'body' => $message->body,
                'attach' => $message->attachInvoice ? 1 : 0,
                'created' => self::at($message->createdAt),
            ],
        );
    }

    public function of(ApOutboundTarget $target, int $limit): array
    {
        $column = self::COLUMN[$target->kind];
        $rows = $this->connection->fetchAll(
            'SELECT TOP (' . max(0, $limit) . ') [id], [invoice_id], [email_id], [approval_batch_id], [delivery_id], [sender_user_id], [body], [attach_invoice], [created_date]
               FROM [finance].[invoice_outbound_messages] WHERE [' . $column . '] = :record ORDER BY [created_date] DESC, [id]',
            ['record' => $target->id],
        );

        return array_map(self::row(...), $rows);
    }

    public function findByDelivery(string $deliveryId): ?ApOutboundMessage
    {
        $rows = $this->connection->fetchAll(self::SELECT . ' WHERE [delivery_id] = :delivery', ['delivery' => $deliveryId]);

        return $rows === [] ? null : self::row($rows[0]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function row(array $row): ApOutboundMessage
    {
        $target = match (true) {
            $row['invoice_id'] !== null => ApOutboundTarget::invoice(self::id($row['invoice_id'])),
            $row['email_id'] !== null => ApOutboundTarget::email(self::id($row['email_id'])),
            default => ApOutboundTarget::approvalBatch(self::id($row['approval_batch_id'])),
        };

        return new ApOutboundMessage(
            self::id($row['id']),
            $target,
            self::id($row['delivery_id']),
            $row['sender_user_id'] === null ? null : self::int($row['sender_user_id']),
            self::text($row['body']),
            self::bool($row['attach_invoice']),
            self::when($row['created_date'], 'created_date'),
        );
    }
}
