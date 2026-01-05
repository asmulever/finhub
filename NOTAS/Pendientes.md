## Pendientes de alto valor para analistas y traders

1) Riesgo y P&L en tiempo real  
   - Exposición, VaR/ES intradía, P&L realizado/no realizado por portfolio e instrumento  
   - Alertas por límites y brechas

2) Órdenes y ejecución  
   - Integración con brokers/OMS (FIX/REST) para enviar/seguir órdenes  
   - Estados de órdenes y fills; libros de órdenes y latencias

3) Alertas de mercado e incidencias  
   - Alertas configurables por precio/volumen/noticias  
   - SLA de entrega (push/email/SMS) y trazabilidad de disparos vs. acciones

4) Análisis histórico avanzado  
   - Backtesting con slippage/fees; estadísticas (Sharpe, drawdown, hit ratio)  
   - Simulación de rebalances y optimización de carteras

5) Calendarios y eventos corporativos  
   - Dividendos, splits, earnings, macro calendars  
   - Impacto esperado y propagación a precios/posiciones

6) Cumplimiento y auditoría operativa  
   - Bitácora de acciones críticas (órdenes, límites, aprobaciones)  
   - Segregación de roles y flujos de aprobación

### Qué falta para lograrlos (de mayor a menor)
- Data providers robustos y fallback (precio en tiempo real y corporativos: earnings, dividendos, splits, calendario macro)
- Integración de ejecución (FIX/REST) con brokers/OMS de mercado real
- Motor de riesgo/P&L intradía con límites y alertas (VaR/ES, exposure)
- Motor de alertas confiable (colas, reintentos, multicanal push/email/SMS)
- Backtesting/analytics con slippage/fees y métricas estándar
- Auditoría y cumplimiento (bitácora, aprobaciones, segregación de roles)
- Librería UI de gráficos avanzados (libro de órdenes, heatmaps, perf charts)
