<?php
declare(strict_types=1);

namespace FinHub\Application\Analytics;

use FinHub\Infrastructure\Logging\LoggerInterface;

/**
 * Caso de uso: trending de Prediction Markets (Yahoo Finance).
 */
final class PredictionMarketService
{
    private const SOURCE = 'yahoo_prediction_trending';
    private const CACHE_TTL_SECONDS = 600; // 10 minutos

    private PredictionMarketFetcherInterface $fetcher;
    private PredictionMarketRepositoryInterface $repository;
    private LoggerInterface $logger;

    public function __construct(
        PredictionMarketFetcherInterface $fetcher,
        PredictionMarketRepositoryInterface $repository,
        LoggerInterface $logger
    ) {
        $this->fetcher = $fetcher;
        $this->repository = $repository;
        $this->logger = $logger;
    }

    /**
     * Devuelve items, usando cache de 10 minutos y calculando variación vs snapshot previo.
     * @return array<string,mixed>
     */
    public function getTrending(): array
    {
        $this->repository->ensureTables();
        $latest = $this->repository->findLatestSnapshot(self::SOURCE);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $useCache = false;

        if ($latest !== null) {
            $createdAt = new \DateTimeImmutable($latest['created_at'] ?? 'now');
            $age = $now->getTimestamp() - $createdAt->getTimestamp();
            if ($age >= 0 && $age < self::CACHE_TTL_SECONDS) {
                $useCache = true;
            }
        }

        if ($useCache) {
            $previous = $this->repository->findPreviousSnapshot(self::SOURCE, (int) $latest['id']);
            return $this->withDeltas($latest, $previous) + ['cache' => 'hit'];
        }

        $fetched = $this->fetcher->fetchTrending();
        $asOf = $fetched['as_of'] ?? $now->format(\DateTimeInterface::ATOM);
        $items = $this->filterNonCrypto($fetched['items'] ?? []);

        $snapshotId = $this->repository->storeSnapshot(self::SOURCE, $asOf, $items);
        $current = $this->repository->findLatestSnapshot(self::SOURCE) ?? [
            'id' => $snapshotId,
            'as_of' => $asOf,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'items' => $items,
        ];
        $previous = $this->repository->findPreviousSnapshot(self::SOURCE, (int) $current['id']);

        return $this->withDeltas($current, $previous) + ['cache' => 'miss'];
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed>|null $previous
     * @return array<string,mixed>
     */
    private function withDeltas(array $current, ?array $previous): array
    {
        $prevIndex = $this->indexOutcomes($previous['items'] ?? []);
        $items = [];
        foreach ($current['items'] ?? [] as $item) {
            $stableId = (string) ($item['id'] ?? '');
            $outcomes = [];
            foreach ($item['outcomes'] ?? [] as $outcome) {
                $name = (string) ($outcome['name'] ?? '');
                $prob = isset($outcome['probability']) ? (float) $outcome['probability'] : null;
                $prevProb = $prevIndex[$stableId][$name]['probability'] ?? null;
                $delta = ($prob !== null && $prevProb !== null) ? ($prob - $prevProb) : null;
                $outcomes[] = $outcome + [
                    'delta_probability' => $delta,
                    'previous_probability' => $prevProb,
                ];
            }
            $items[] = $item + ['outcomes' => $outcomes];
        }

        return [
            'id' => $current['id'] ?? null,
            'source' => self::SOURCE,
            'as_of' => $current['as_of'] ?? null,
            'created_at' => $current['created_at'] ?? null,
            'items' => $this->filterNonCrypto($items),
            'previous_snapshot' => $previous ? [
                'id' => $previous['id'] ?? null,
                'as_of' => $previous['as_of'] ?? null,
                'created_at' => $previous['created_at'] ?? null,
            ] : null,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<string,array<string,array<string,float>>>
     */
    private function indexOutcomes(array $items): array
    {
        $idx = [];
        foreach ($items as $item) {
            $sid = (string) ($item['id'] ?? '');
            foreach ($item['outcomes'] ?? [] as $outcome) {
                $name = (string) ($outcome['name'] ?? '');
                if ($sid === '' || $name === '') {
                    continue;
                }
                $idx[$sid][$name] = [
                    'probability' => isset($outcome['probability']) ? (float) $outcome['probability'] : null,
                ];
            }
        }
        return $idx;
    }

    /**
     * Filtra mercados relacionados a criptomonedas para excluirlos del endpoint.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function filterNonCrypto(array $items): array
    {
        $keywords = ['bitcoin', 'btc', 'ether', 'ethereum', 'eth', 'solana', 'xrp', 'crypto', 'token', 'doge', 'shib'];
        $filtered = [];
        foreach ($items as $item) {
            $haystack = strtolower(
                ($item['title'] ?? '') . ' ' .
                ($item['category'] ?? '') . ' ' .
                ($item['id'] ?? '')
            );
            $isCrypto = false;
            foreach ($keywords as $kw) {
                if ($kw !== '' && str_contains($haystack, $kw)) {
                    $isCrypto = true;
                    break;
                }
            }
            if (!$isCrypto) {
                $filtered[] = $item;
            }
        }
        return $filtered;
    }
}
