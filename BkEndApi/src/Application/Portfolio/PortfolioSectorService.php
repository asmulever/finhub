<?php
declare(strict_types=1);

namespace FinHub\Application\Portfolio;

use FinHub\Application\MarketData\PriceService;
use FinHub\Infrastructure\Logging\LoggerInterface;

/**
 * Enriquecimiento de instrumentos de portafolio con metadata de sector/industry vía Alpha Vantage.
 * Módulo: Portfolio (Application).
 */
final class PortfolioSectorService
{
    private PortfolioService $portfolioService;
    private PriceService $priceService;
    private LoggerInterface $logger;

    public function __construct(
        PortfolioService $portfolioService,
        PriceService $priceService,
        LoggerInterface $logger
    ) {
        $this->portfolioService = $portfolioService;
        $this->priceService = $priceService;
        $this->logger = $logger;
    }

    /**
     * Devuelve sector/industria para los símbolos del usuario usando Alpha Vantage.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listSectorIndustry(int $userId): array
    {
        $instruments = $this->portfolioService->listInstruments($userId);
        $symbols = [];
        foreach ($instruments as $instrument) {
            $symbol = strtoupper((string) ($instrument['symbol'] ?? ''));
            if ($symbol === '') {
                continue;
            }
            $symbols[$symbol] = $symbol;
        }

        $results = [];
        foreach ($symbols as $symbol) {
            $sector = null;
            $industry = null;
            $error = null;
            try {
                $overview = $this->priceService->alphaOverview($symbol);
                $sector = $this->normalizeText($overview['Sector'] ?? $overview['sector'] ?? null);
                $industry = $this->normalizeText($overview['Industry'] ?? $overview['industry'] ?? null);
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
                $this->logger->info('portfolio.sector_industry.fetch_failed', [
                    'symbol' => $symbol,
                    'message' => $error,
                ]);
            }

            $results[] = [
                'symbol' => $symbol,
                'sector' => $sector ?? 'Sin sector',
                'industry' => $industry ?? 'Sin industry',
                'provider' => 'alphavantage',
                'error' => $error,
            ];
        }

        return $results;
    }

    private function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === 'None') {
            return null;
        }
        return $trimmed;
    }
}
