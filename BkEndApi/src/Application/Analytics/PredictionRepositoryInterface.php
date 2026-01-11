<?php
declare(strict_types=1);

namespace FinHub\Application\Analytics;

/**
 * Repositorio para persistir predicciones por instrumento y horizonte.
 */
interface PredictionRepositoryInterface
{
    /**
     * Reemplaza las predicciones calculadas para un run y usuario.
     *
     * @param int $runId
     * @param int $userId
     * @param array<int,array{symbol:string,horizon:int,prediction:string,confidence:float|null}> $items
     */
    public function replacePredictions(int $runId, int $userId, array $items): void;

    /**
     * Devuelve las predicciones del último run terminado del usuario.
     *
     * @return array<int,array{symbol:string,horizon:int,prediction:string,confidence:float|null,created_at:string}>
     */
    public function findLatestByUser(int $userId): array;
}
