<?php

declare(strict_types=1);

use App\Application\PortfolioService;
use App\Domain\Account;
use App\Domain\AccountRepository;
use App\Domain\FinancialObject;
use App\Domain\FinancialObjectRepository;
use App\Domain\PortfolioTickerRepository;

require_once __DIR__ . '/../Domain/Account.php';
require_once __DIR__ . '/../Domain/AccountRepository.php';
require_once __DIR__ . '/../Domain/PortfolioTickerRepository.php';
require_once __DIR__ . '/../Domain/FinancialObject.php';
require_once __DIR__ . '/../Domain/FinancialObjectRepository.php';
require_once __DIR__ . '/../Application/PortfolioService.php';

class InMemoryAccountRepository implements AccountRepository
{
    /** @var array<int, Account> */
    private array $accounts = [];

    public function __construct()
    {
        $this->save(new Account(1, 1, 'Broker A', 'USD', true));
        $this->save(new Account(2, 2, 'Broker B', 'USD', false));
    }

    public function findById(int $id): ?Account
    {
        return $this->accounts[$id] ?? null;
    }

    public function findAll(): array
    {
        return array_values($this->accounts);
    }

    public function findByUserId(int $userId): array
    {
        return array_values(
            array_filter(
                $this->accounts,
                static fn(Account $account): bool => $account->getUserId() === $userId
            )
        );
    }

    public function save(Account $account): int
    {
        $id = $account->getId() ?? (count($this->accounts) + 1);
        $this->accounts[$id] = new Account(
            $id,
            $account->getUserId(),
            $account->getBrokerName(),
            $account->getCurrency(),
            $account->isPrimary()
        );
        return $id;
    }

    public function update(Account $account): void
    {
        $this->accounts[$account->getId()] = $account;
    }

    public function delete(int $id): void
    {
        unset($this->accounts[$id]);
    }

    public function findDetailed(?int $userId = null): array
    {
        return [];
    }

    public function findDetailedById(int $id): ?array
    {
        return null;
    }
}

class InMemoryTickerRepository implements PortfolioTickerRepository
{
    private array $tickers = [];
    private array $ownership = [];
    private int $nextId = 1;

    public function findDetailedByBroker(int $brokerId, int $userId): array
    {
        return array_values(
            array_filter(
                $this->tickers,
                fn($ticker) => $ticker['account_id'] === $brokerId && $this->ownership[$ticker['id']] === $userId
            )
        );
    }

    public function findDetailedById(int $tickerId, int $userId): ?array
    {
        if (($this->ownership[$tickerId] ?? null) !== $userId) {
            return null;
        }
        return $this->tickers[$tickerId] ?? null;
    }

    public function create(int $brokerId, int $financialObjectId, float $quantity, float $avgPrice, int $userId): int
    {
        $id = $this->nextId++;
        $this->tickers[$id] = [
            'id' => $id,
            'account_id' => $brokerId,
            'financial_object_id' => $financialObjectId,
            'quantity' => $quantity,
            'avg_price' => $avgPrice,
            'financial_object_name' => 'Mock',
            'financial_object_symbol' => 'MCK',
            'financial_object_type' => 'stock',
        ];
        $this->ownership[$id] = $userId;
        return $id;
    }

    public function update(int $tickerId, float $quantity, float $avgPrice, int $userId): bool
    {
        if (($this->ownership[$tickerId] ?? null) !== $userId) {
            return false;
        }
        $this->tickers[$tickerId]['quantity'] = $quantity;
        $this->tickers[$tickerId]['avg_price'] = $avgPrice;
        return true;
    }

    public function delete(int $tickerId, int $userId): bool
    {
        if (($this->ownership[$tickerId] ?? null) !== $userId) {
            return false;
        }
        unset($this->tickers[$tickerId], $this->ownership[$tickerId]);
        return true;
    }
}

class InMemoryFORepository implements FinancialObjectRepository
{
    public function __construct(private readonly array $objects = [])
    {
    }

    public function findAll(): array
    {
        return $this->objects;
    }

    public function findById(int $id): ?FinancialObject
    {
        return $this->objects[$id] ?? null;
    }

    public function save(FinancialObject $financialObject): int
    {
        throw new \RuntimeException('read only');
    }

    public function update(FinancialObject $financialObject): void
    {
        throw new \RuntimeException('read only');
    }

    public function delete(int $id): void
    {
        throw new \RuntimeException('read only');
    }
}

function runOwnershipTests(): void
{
    $accountRepo = new InMemoryAccountRepository();
    $tickerRepo = new InMemoryTickerRepository();
    $foRepo = new InMemoryFORepository([
        1 => new FinancialObject(1, 'Sample', 'SMP', 'stock'),
    ]);

    $service = new PortfolioService($accountRepo, $tickerRepo, $foRepo);

    $result = $service->getTickersForBroker(1, 1);
    assert(empty($result));

    $created = $service->addTicker(1, [
        'broker_id' => 1,
        'financial_object_id' => 1,
        'quantity' => 10,
        'avg_price' => 5,
    ]);
    assert($created['account_id'] === 1);

    $thrown = false;
    try {
        $service->getTickersForBroker(2, 1);
    } catch (\RuntimeException $e) {
        $thrown = true;
    }
    assert($thrown, 'User 2 should not access broker 1.');

    $thrown = false;
    try {
        $service->updateTicker(2, $created['id'], [
            'quantity' => 5,
            'avg_price' => 4,
        ]);
    } catch (\RuntimeException $e) {
        $thrown = true;
    }
    assert($thrown, 'User 2 must not update tickets of user 1.');
}

runOwnershipTests();
