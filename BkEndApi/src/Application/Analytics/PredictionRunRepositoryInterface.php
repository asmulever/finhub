<?php
declare(strict_types=1);

namespace FinHub\Application\Analytics;

/**
 * Repositorio para gestionar ejecuciones de análisis/predicción.
 */
interface PredictionRunRepositoryInterface
{
    /**
     * Crea un registro de run en estado running.
     *
     * @param string $scope 'global' o 'user'
     * @param int|null $userId
     * @return int runId
     */
    public function startRun(string $scope, ?int $userId): int;

    /**
     * Marca un run como terminado.
     *
     * @param int $runId
     * @param string $status 'success'|'partial'|'failed'
     * @param array<string,mixed> $summary
     */
    public function finishRun(int $runId, string $status, array $summary): void;

    /**
     * Devuelve el último run en estado running para evitar reentradas.
     *
     * @return array<string,mixed>|null
     */
    public function findRunning(string $scope, ?int $userId): ?array;

    /**
     * Devuelve el último run finalizado para un usuario (o global).
     *
     * @return array<string,mixed>|null
     */
    public function findLatestFinished(string $scope, ?int $userId): ?array;
}
