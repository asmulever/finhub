# Endpoints gratuitos – Twelve Data y EODHD

> Documento de referencia rápido para integración y diseño de ingesta de datos de mercado usando **planes gratuitos**.
>
> **Advertencia general**: los límites y alcances pueden cambiar. Siempre validar con la API key real en entorno de prueba.

---

## 1. Twelve Data – Plan gratuito (Basic)

### Límites del plan
- **8 requests por minuto**
- **~800 requests por día**
- Requiere API key

---

### Endpoints disponibles

#### `/time_series`
**Propósito**: series históricas OHLCV.
- Acciones, ETFs, Forex, Cripto
- Intervalos: 1min, 5min, 15min, 1h, 1day, etc.
- Uso típico: análisis técnico, gráficos, data lake histórico

---

#### `/quote`
**Propósito**: cotización actual.
- Precio, open, high, low, close, volumen
- Uso típico: dashboards, precio spot

---

#### `/exchange_rate`
**Propósito**: tipo de cambio actual entre dos monedas.
- Forex y cripto
- Uso típico: normalización de precios

---

#### `/currency_conversion`
**Propósito**: conversión de montos entre monedas.
- Basado en exchange_rate
- Uso típico: mostrar valores convertidos

---

#### `/instrument_type`
**Propósito**: lista de tipos de instrumentos soportados.
- Ej.: Stock, ETF, Forex, Crypto
- Uso típico: metadata / catálogos

---

#### `/cryptocurrency_exchanges`
**Propósito**: lista de exchanges cripto disponibles.
- Uso típico: validación de mercados cripto

---

### Notas clave Twelve Data
- No existe endpoint gratuito para **listar todos los tickers** disponibles.
- Los tickers se validan por prueba directa o documentación.
- Ideal para **ingesta incremental** y precios recientes.

---

## 2. EODHD – Plan gratuito

### Límites del plan
- **~20 requests por día**
- Requiere API key
- Datos limitados en profundidad histórica y features

---

### Endpoints disponibles (free en la práctica)

#### `/api/eod/{symbol}`
**Propósito**: histórico End‑Of‑Day (EOD).
- OHLCV diario
- Cobertura típica: último año aprox.
- Uso típico: análisis de tendencia, data lake diario

---

#### `/api/intraday/{symbol}`
**Propósito**: precios intradía.
- Intervalos: 1m, 5m, etc. (limitado)
- Uso típico: análisis intradiario simple

---

#### `/api/live/{symbol}`
**Propósito**: precio actual (real‑time o delayed).
- Uso típico: cotización spot

---

#### `/api/dividends/{symbol}`
**Propósito**: historial de dividendos.
- Uso típico: análisis de retorno total

---

#### `/api/splits/{symbol}`
**Propósito**: eventos de split.
- Uso típico: ajuste de series históricas

---

#### `/api/exchanges-list`
**Propósito**: lista de exchanges soportados.
- Devuelve códigos como NASDAQ, NYSE, BATS, etc.
- Uso típico: descubrimiento de mercados

---

#### `/api/search/{query}`
**Propósito**: búsqueda de símbolos por texto.
- Ej.: "AAPL", "Apple"
- Uso típico: autocomplete, descubrimiento de tickers

---

#### `/api/user`
**Propósito**: información de la cuenta.
- Plan activo
- Requests restantes
- Uso típico: monitoreo de límites

---

### Endpoints NO incluidos en free (requieren pago)
- Fundamentals completos
- Bulk EOD por exchange
- Opciones
- Índices avanzados
- Economic calendar / eventos macro

---

## 3. Comparativa rápida

| Feature | Twelve Data (Free) | EODHD (Free) |
|------|------------------|-------------|
| Histórico OHLC | Sí | Sí (EOD) |
| Intradía | Sí | Limitado |
| Precio actual | Sí | Sí |
| Listar exchanges | Parcial | Sí |
| Buscar tickers | No | Sí |
| Forex / Cripto | Sí | Parcial |
| Límites | Más altos | Muy bajos |

---

## 4. Recomendación de uso combinado

**Estrategia práctica**:
- **Twelve Data** → precios frecuentes, intradía, forex, cripto
- **EODHD** → discovery de símbolos + EOD histórico + dividendos

Esto permite:
- Reducir consumo de Twelve Data
- Usar EODHD como fuente de catálogo y eventos corporativos

---

## 5. Checklist de validación técnica

- [ ] Probar cada endpoint con API key real
- [ ] Loguear headers de rate limit
- [ ] Manejar HTTP 429
- [ ] Cachear respuestas de catálogo
- [ ] Normalizar timestamps y monedas

---

**Estado del documento**: estable para diseño de ingesta y data lake simple.

