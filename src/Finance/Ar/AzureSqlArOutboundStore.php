<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ar;

use TriCab\Core\Finance\Ar\Contract\ArOutboundStore;
use TriCab\Core\Finance\Ar\Domain\ArOutboundMessage;

/** `finance.ar_outbound_messages`. A foreign key to the record, none to the ledger (see the schema script). */
final class AzureSqlArOutboundStore extends AzureSqlArSupport implements ArOutboundStore
{
    public function addOutbound(ArOutboundMessage $message): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[ar_outbound_messages] ([id], [email_record_id], [delivery_id], [sender_user_id], [sender_user], [threaded], [body], [created_date])
             VALUES (:id, :record, :delivery, :userId, :sender, :threaded, :body, :created)',
            [
                'id' => $message->id,
                'record' => $message->recordId,
                'delivery' => $message->deliveryId,
                'userId' => $message->senderUserId,
                'sender' => mb_substr($message->senderUser, 0, 320),
                'threaded' => $message->threaded ? 1 : 0,
                'body' => $message->body,
                'created' => self::at($message->createdAt),
            ],
        );
    }

    public function outboundOf(string $recordId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [id], [email_record_id], [delivery_id], [sender_user_id], [sender_user], [threaded], [body], [created_date]
               FROM [finance].[ar_outbound_messages] WHERE [email_record_id] = :record ORDER BY [created_date] DESC, [id]',
            ['record' => $recordId],
        );

        return array_map(static fn (array $row): ArOutboundMessage => new ArOutboundMessage(
            self::id($row['id']),
            self::id($row['email_record_id']),
            self::id($row['delivery_id']),
            $row['sender_user_id'] === null ? null : self::int($row['sender_user_id']),
            self::text($row['sender_user']),
            self::bool($row['threaded']),
            self::text($row['body']),
            self::when($row['created_date'], 'created_date'),
        ), $rows);
    }
}
