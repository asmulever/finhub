<?php

declare(strict_types=1);

use App\Application\PortfolioService;
use App\Domain\FinancialObject;
use App\Domain\FinancialObjectRepository;
use App\Domain\Portfolio;
use App\Domain\PortfolioRepository;
use App\Domain\PortfolioTickerRepository;

require_once __DIR__ . '/../Domain/Portfolio.php';
require_once __DIR__ . '/../Domain/PortfolioRepository.php';
require_once __DIR__ . '/../Domain/PortfolioTickerRepository.php';
require_once __DIR__ . '/../Domain/FinancialObject.php';
require_once __DIR__ . '/../Domain/FinancialObjectRepository.php';
require_once __DIR__ . '/../Application/PortfolioService.php';

class InMemoryPortfolioRepository implements PortfolioRepository
{
    private array $items = [];
    private int $nextId = 1;

    public function createForUser(int $userId, string $name): int
    {
        $id = $this->nextId++;
        $this->items[$userId] = new Portfolio($id, $userId, $name);
        return $id;
    }

    public function findByUserId(int $userId): ?Portfolio
    {
        return $this->items[$userId] ?? null;
    }

    public function deleteByUserId(int $userId): void
    {
        unset($this->items[$userId]);
    }
}

class InMemoryPortfolioTickerRepository implements PortfolioTickerRepository
{
    private array $tickers = [];
    private array $tickerOwnerMap = [];
    private int $nextId = 1;

    public function findDetailedByPortfolio(int $portfolioId, int $userId): array
    {
        return array_values(
            array_filter(
                $this->tickers,
                fn($ticker) => $ticker['portfolio_id'] === $portfolioId && $this->tickerOwnerMap[$ticker['id']] === $userId
            )
        );
    }

    public function findDetailedById(int $tickerId, int $userId): ?array
    {
        if (($this->tickerOwnerMap[$tickerId] ?? null) !== $userId) {
            return null;
        }
        return $this->tickers[$tickerId] ?? null;
    }

    public function create(int $portfolioId, int $financialObjectId, float $quantity, float $avgPrice, int $userId): int
    {
        $id = $this->nextId++;
        $this->tickers[$id] = [
            'id' => $id,
            'portfolio_id' => $portfolioId,
            'financial_object_id' => $financialObjectId,
            'quantity' => $quantity,
            'avg_price' => $avgPrice,
        ];
        $this->tickerOwnerMap[$id] = $userId;
        return $id;
    }

    public function update(int $tickerId, float $quantity, float $avgPrice, int $userId): bool
    {
        if (($this->tickerOwnerMap[$tickerId] ?? null) !== $userId) {
            return false;
        }
        $this->tickers[$tickerId]['quantity'] = $quantity;
        $this->tickers[$tickerId]['avg_price'] = $avgPrice;
        return true;
    }

    public function delete(int $tickerId, int $userId): bool
    {
        if (($this->tickerOwnerMap[$tickerId] ?? null) !== $userId) {
            return false;
        }
        unset($this->tickers[$tickerId], $this->tickerOwnerMap[$tickerId]);
        return true;
    }
}

class InMemoryFinancialObjectRepository implements FinancialObjectRepository
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
        throw new \RuntimeException('Read-only repository');
    }

    public function update(FinancialObject $financialObject): void
    {
        throw new \RuntimeException('Read-only repository');
    }

    public function delete(int $id): void
    {
        throw new \RuntimeException('Read-only repository');
    }
}

function runPortfolioOwnershipTests(): void
{
    $portfolioRepo = new InMemoryPortfolioRepository();
    $tickerRepo = new InMemoryPortfolioTickerRepository();
    $foRepo = new InMemoryFinancialObjectRepository([
        1 => new FinancialObject(1, 'Sample', 'SMP', 'stock'),
    ]);

    $service = new PortfolioService($portfolioRepo, $tickerRepo, $foRepo);

    $snapshot = $service->getPortfolioWithTickers(1);
    assert($snapshot['portfolio']['user_id'] === 1);
    assert(empty($snapshot['tickers']));

    $created = $service->addTicker(1, [
        'financial_object_id' => 1,
        'quantity' => 10,
        'avg_price' => 100,
    ]);
    assert($created['financial_object_id'] === 1);

    $thrown = false;
    try {
        $service->updateTicker(2, $created['id'], ['quantity' => 5, 'avg_price' => 90]);
    } catch (\RuntimeException $e) {
        $thrown = true;
    }
    assert($thrown === true, 'Ownership guard should reject cross-user updates.');
}

runPortfolioOwnershipTests();
