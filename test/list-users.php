<?php
declare(strict_types=1);

require_once __DIR__ . '/../App/Infrastructure/Config.php';
require_once __DIR__ . '/../App/Infrastructure/DatabaseConnection.php';

use App\Infrastructure\Config;
use App\Infrastructure\DatabaseConnection;

try {
    $pdo = DatabaseConnection::getInstance();
    $stmt = $pdo->query("SELECT id, email, role, is_active, created_at FROM users ORDER BY is_active DESC, id ASC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h1>Error al obtener usuarios</h1>";
    echo "<pre>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</pre>";
    exit;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Diagnóstico de Usuarios</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #0f172a;
            color: #f8fafc;
            margin: 0;
            padding: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 10px;
            border: 1px solid #1e293b;
            text-align: left;
        }
        th {
            background: #1e293b;
        }
        tr:nth-child(even) {
            background: #1f2937;
        }
        .badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.85rem;
        }
        .active {
            background: #16a34a;
            color: #fff;
        }
        .inactive {
            background: #dc2626;
            color: #fff;
        }
    </style>
</head>
<body>
    <h1>Usuarios (ordenados por is_active)</h1>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Email</th>
                <th>Rol</th>
                <th>Estado</th>
                <th>Creado</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><?= (int)$user['id']; ?></td>
                <td><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                    <?php if ((int)$user['is_active'] === 1): ?>
                        <span class="badge active">Activo</span>
                    <?php else: ?>
                        <span class="badge inactive">Inactivo</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars((string)$user['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
