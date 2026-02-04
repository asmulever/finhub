<?php
declare(strict_types=1);

namespace FinHub\Application\Backtest;

/**
 * DTO de request para backtesting bar-by-bar.
 */
final class BacktestRequest
{
    public string $strategyId;
    /** @var array<int,string> */
    public array $universe;
    public \DateTimeImmutable $startDate;
    public \DateTimeImmutable $endDate;
    public float $initialCapital;
    public float $riskPerTradePct;
    public float $commissionPct;
    public float $minFee;
    public float $slippageBps;
    public float $spreadBps;
    public int $breakoutLookbackBuy;
    public int $breakoutLookbackSell;
    public float $atrMultiplier;
    public ?int $userId;

    /**
     * @param array<int,string> $universe
     */
    public function __construct(
        string $strategyId,
        array $universe,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        float $initialCapital,
        float $riskPerTradePct,
        float $commissionPct,
        float $minFee,
        float $slippageBps,
        float $spreadBps,
        int $breakoutLookbackBuy,
        int $breakoutLookbackSell,
        float $atrMultiplier,
        ?int $userId = null
    ) {
        $this->strategyId = $strategyId;
        $this->universe = $universe;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->initialCapital = $initialCapital;
        $this->riskPerTradePct = $riskPerTradePct;
        $this->commissionPct = $commissionPct;
        $this->minFee = $minFee;
        $this->slippageBps = $slippageBps;
        $this->spreadBps = $spreadBps;
        $this->breakoutLookbackBuy = $breakoutLookbackBuy;
        $this->breakoutLookbackSell = $breakoutLookbackSell;
        $this->atrMultiplier = $atrMultiplier;
        $this->userId = $userId;
    }

    public function toArray(): array
    {
        return [
            'strategy_id' => $this->strategyId,
            'universe' => $this->universe,
            'start' => $this->startDate->format('Y-m-d'),
            'end' => $this->endDate->format('Y-m-d'),
            'initial_capital' => $this->initialCapital,
            'risk_per_trade_pct' => $this->riskPerTradePct,
            'commission_pct' => $this->commissionPct,
            'min_fee' => $this->minFee,
            'slippage_bps' => $this->slippageBps,
            'spread_bps' => $this->spreadBps,
            'breakout_lookback_buy' => $this->breakoutLookbackBuy,
            'breakout_lookback_sell' => $this->breakoutLookbackSell,
            'atr_multiplier' => $this->atrMultiplier,
            'user_id' => $this->userId,
        ];
    }
}
