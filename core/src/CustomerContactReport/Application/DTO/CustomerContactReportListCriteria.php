<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportErrorCode;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportLocation;
use TriCab\Core\CustomerContactReport\Domain\ValueObject\CustomerContactReportStatus;

final readonly class CustomerContactReportListCriteria
{
    public ?string $status;

    public ?string $location;

    public ?string $errorCode;

    public function __construct(
        public int $page,
        public int $pageSize,
        ?string $status,
        public ?int $month,
        public ?int $year,
        public ?string $search,
        public ?string $customer,
        public ?string $assignee,
        ?string $location,
        ?string $errorCode,
    ) {
        if ($this->page < 1) {
            throw InvalidCustomerContactReportValue::for('page', 'must be at least 1.');
        }
        if ($this->pageSize < 1 || $this->pageSize > 100) {
            throw InvalidCustomerContactReportValue::for('pageSize', 'must be between 1 and 100.');
        }
        $this->status = $status === null ? null : CustomerContactReportStatus::fromString($status)->toString();
        if ($this->month !== null && ($this->month < 1 || $this->month > 12)) {
            throw InvalidCustomerContactReportValue::for('month', 'must be between 1 and 12.');
        }
        if ($this->year !== null && ($this->year < 2000 || $this->year > 2100)) {
            throw InvalidCustomerContactReportValue::for('year', 'must be between 2000 and 2100.');
        }
        $this->location = $location === null ? null : CustomerContactReportLocation::fromString($location)->toString();
        $this->errorCode = $errorCode === null ? null : CustomerContactReportErrorCode::fromString($errorCode)->toString();
    }

    public function withoutStatus(): self
    {
        return new self(
            page: $this->page,
            pageSize: $this->pageSize,
            status: null,
            month: $this->month,
            year: $this->year,
            search: $this->search,
            customer: $this->customer,
            assignee: $this->assignee,
            location: $this->location,
            errorCode: $this->errorCode,
        );
    }
}
