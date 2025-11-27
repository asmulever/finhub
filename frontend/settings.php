<?php
declare(strict_types=1);
require_once __DIR__ . '/../App/Infrastructure/SecurityHeaders.php';
apply_security_headers();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>FinHub | Configuración Finnhub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body {
            margin: 0;
            font-family: "Inter", system-ui, sans-serif;
            background: #020617;
            color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            width: 100%;
            max-width: 720px;
            background: rgba(15,23,42,0.85);
            border-radius: 32px;
            padding: 32px;
            border: 1px solid rgba(148,163,184,0.25);
            box-shadow: 0 24px 48px rgba(2,6,23,0.6);
        }
        h1 {
            margin-top: 0;
            margin-bottom: 8px;
        }
        .field {
            display: flex;
            flex-direction: column;
            margin-bottom: 16px;
        }
        label {
            font-weight: 600;
            margin-bottom: 6px;
        }
        input, select {
            border-radius: 16px;
            border: 1px solid rgba(148,163,184,0.25);
            padding: 12px 16px;
            background: #0f172a;
            color: #f8fafc;
        }
        button {
            border: 0;
            border-radius: 999px;
            padding: 14px 32px;
            font-size: 1rem;
            font-weight: 600;
            background: linear-gradient(120deg,#6366f1,#a855f7);
            color: #fff;
            cursor: pointer;
        }
        .message {
            margin-top: 16px;
            font-weight: 500;
        }
    </style>
</head>
<body>
<div class="card">
    <h1>Parámetros Finnhub</h1>
    <p>Los valores se guardan en el archivo <code>.env</code>. Recordá que el cron se ejecutará mediante Cron-Job.org dentro del horario configurado.</p>
    <form id="settingsForm">
        <div class="field">
            <label for="USR_KEY">Correo registrado</label>
            <input type="email" id="USR_KEY" name="USR_KEY" required>
        </div>
        <div class="field">
            <label for="FINNHUB_API_KEY">API Key</label>
            <input type="text" id="FINNHUB_API_KEY" name="FINNHUB_API_KEY" required>
        </div>
        <div class="field">
            <label for="X_FINNHUB_SECRET">X-Finnhub-Secret</label>
            <input type="text" id="X_FINNHUB_SECRET" name="X_FINNHUB_SECRET">
        </div>
        <div class="field">
            <label for="CRON_ACTIVO">Cron activo (1 = externo, 0 = interno)</label>
            <select id="CRON_ACTIVO" name="CRON_ACTIVO">
                <option value="1">1</option>
                <option value="0">0</option>
            </select>
        </div>
        <div class="field">
            <label for="CRON_INTERVALO">Intervalo (segundos, mínimo 60)</label>
            <input type="number" min="60" id="CRON_INTERVALO" name="CRON_INTERVALO">
        </div>
        <div class="field">
            <label for="CRON_HR_START">Horario inicio (HH:MM)</label>
            <input type="text" id="CRON_HR_START" name="CRON_HR_START" placeholder="09:00">
        </div>
        <div class="field">
            <label for="CRON_HR_END">Horario fin (HH:MM)</label>
            <input type="text" id="CRON_HR_END" name="CRON_HR_END" placeholder="18:00">
        </div>
        <button type="submit">Guardar</button>
        <div class="message" id="message"></div>
    </form>
</div>
<script>
    const form = document.getElementById("settingsForm");
    const message = document.getElementById("message");

    async function loadSettings() {
        const res = await fetch("/api/settings/finnhub");
        const data = await res.json();
        if (!data.ok) {
            message.textContent = data.error || "Error al cargar los datos";
            return;
        }
        Object.entries(data.settings).forEach(([key, value]) => {
            if (form.elements[key]) {
                form.elements[key].value = value;
            }
        });
    }

    form.addEventListener("submit", async (e) => {
        e.preventDefault();
        message.textContent = "Guardando...";
        const payload = Object.fromEntries(new FormData(form).entries());
        payload.CRON_ACTIVO = parseInt(payload.CRON_ACTIVO, 10);
        payload.CRON_INTERVALO = parseInt(payload.CRON_INTERVALO, 10);

        const res = await fetch("/api/settings/finnhub", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        message.textContent = data.message || data.error || "Error al guardar";
        if (data.ok) {
            setTimeout(() => message.textContent = "", 3000);
        }
    });

    loadSettings().catch(() => {
        message.textContent = "No se pudo cargar la configuración actual.";
    });
</script>
</body>
</html>
