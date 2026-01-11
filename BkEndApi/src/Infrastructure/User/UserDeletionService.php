<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\User;

/**
 * Servicio de eliminación de usuarios en cascada (portafolios, instrumentos y usuario).
 */
final class UserDeletionService
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Hard delete manual en cascada (sin alterar FK): elimina portafolios e instrumentos y luego el usuario.
     */
    public function deleteCascade(int $userId): bool
    {
        $this->pdo->beginTransaction();
        try {
            // Eliminar instrumentos asociados a los portafolios del usuario.
            $deleteInstruments = $this->pdo->prepare(
                'DELETE pi FROM portfolio_instruments pi INNER JOIN portfolios p ON pi.portfolio_id = p.id WHERE p.user_id = :user_id'
            );
            $deleteInstruments->execute(['user_id' => $userId]);

            // Eliminar portafolios del usuario.
            $deletePortfolios = $this->pdo->prepare('DELETE FROM portfolios WHERE user_id = :user_id');
            $deletePortfolios->execute(['user_id' => $userId]);

            // Finalmente eliminar el usuario.
            $deleteUser = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
            $deleteUser->execute(['id' => $userId]);

            $this->pdo->commit();
            return $deleteUser->rowCount() > 0;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
