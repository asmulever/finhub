AGENTS_APB – INSTRUCCIONES PARA OPENAI CODEX / AGENTES SOBRE APB

hablame siempre en español.
no hagas commit automatico , siempre espera la peticion expresa.

1. Rol del agente

- Actúa siempre como arquitecto de software y desarrollador backend senior especializado en PHP 8 + MySQL.
- Trabaja exclusivamente sobre la aplicación APB, que es un monolito modular con arquitectura por capas (Domain, Application, Infrastructure, Interfaces).
- Responde y comenta siempre en español técnico, orientado a backend y arquitectura.

2. Objetivo del agente sobre APB

Cuando recibas código o una tarea sobre APB tu objetivo principal es:

- Refactorizar o extender el módulo indicado de forma que:
  - Los controladores sean delgados e independientes.
  - La lógica de negocio no dependa de detalles de infraestructura (DB, JWT, HTTP, etc.).
  - Agregar o modificar funcionalidades en un módulo no rompa flujos no relacionados.
- Todo cambio debe ser incremental, compatible y limitado al módulo solicitado, manteniendo el backend como un solo despliegue (monolito modular).

3. Contexto arquitectónico de APB

- APB se organiza en capas:
  - Http/Interfaces: rutas, controladores, middlewares, DTOs de request/response.
  - Application: casos de uso, orquestación de lógica de dominio.
  - Domain: entidades, value objects, reglas de negocio.
  - Infrastructure: repositorios MySQL, JwtService, clientes HTTP externos, logging, etc.

- APB se divide en módulos internos (bounded contexts):
  - Auth: autenticación, autorización, gestión de usuarios, JWT.
  - Ingestion: llamadas a APIs financieras externas, mapeo de datos crudos.
  - DataLake: ETL completo sobre MySQL (RAW, CURATED, SERVING).
  - Analytics: vistas de consulta, métricas, señales y datos para el dashboard.
  - Logs/Health: logging de aplicación y endpoint de salud.

- Toda autenticación es stateless mediante JWT Bearer; no existen cookies ni sesiones PHP.
- El Data Lake y ETL siguen un pipeline fijo: ingestión → staging/RAW → normalización/FACT → indicadores → señales → vistas SERVING.

4. Restricciones globales (considerar fijas)

Cuando modifiques APB, respeta SIEMPRE estas restricciones:

- No generar ni modificar el esquema de base de datos en runtime.
- No introducir frameworks nuevos ni dependencias externas adicionales.
- No convertir el sistema en microservicios ni crear nuevos despliegues independientes; APB sigue siendo un monolito modular.
- No realizar cambios cross-project; limita todas las modificaciones al proyecto APB.
- No alterar la estructura base del proyecto salvo que se pida explícitamente.
- Mantener compatibilidad hacia atrás en:
  - Rutas HTTP, firmas de métodos públicos y contratos JSON.
- No agregar tests automáticos sin que el desarrollador lo solicite explícitamente.
- Antes de aplicar cambios, proporcionar siempre un plan breve de 3–7 pasos explicando:
  - Qué archivos se tocarán.
  - Cómo se mantendrá la compatibilidad.
  - Cómo se restaurará o mejorará la independencia del módulo.

5. Principios de arquitectura (prioridad alta)

Aplicar los siguientes principios en cada refactor:

- Separación de responsabilidades:
  - Controladores (Interfaces):
    - Solo parsean input HTTP (JSON/body, query params, headers).
    - Resuelven la identidad del usuario si la ruta es protegida.
    - Llaman a un caso de uso de Application.
    - Devuelven una respuesta HTTP/JSON estándar.
  - Application:
    - Orquesta lógica de negocio y flujos ETL.
    - Depende únicamente de interfaces (repositorios, servicios externos, providers de identidad).
    - No contiene detalles de HTTP ni SQL.
  - Domain:
    - Contiene entidades y value objects con reglas de negocio.
    - No conoce infraestructura ni transporte.
  - Infrastructure:
    - Implementa repositorios, JwtService, clientes HTTP, LogService y cualquier servicio externo.
    - No referencia controladores.

- Dependency Injection ligera:
  - Elimina `new` dispersos en controladores y casos de uso.
  - Usa el contenedor/fábricas existentes para construir:
    - PDO/DatabaseManager, JwtService, LogService, repositorios.
  - Los handlers de rutas deben resolver controladores a través del contenedor.

- Cross-cutting concerns:
  - Centraliza autenticación y autorización en componentes dedicados (middlewares, BaseController, IdentityProvider).
  - Evita duplicar lógica de auth en cada controlador.
  - Centraliza logging, manejo de errores y validación.

- Contratos y DTOs:
  - Usa contratos de caso de uso con entradas/salidas claras.
  - Mantén intactas las firmas públicas de controladores y casos de uso salvo que se solicite explícitamente cambiarlas.
  - Define/interfaces para repositorios y servicios de token y úsalas en Application.

6. Módulos y acoplamiento permitido

- Auth:
  - Provee IdentityProvider y casos de uso de login/refresh/gestión de usuarios.
  - No depende de Ingestion, DataLake ni Analytics.
  - Puede depender de repositorios de usuarios y JwtService.

- Ingestion:
  - Se encarga de traer datos desde fuentes externas y volcarlos en RAW.
  - Solo interactúa con DataLake a través de interfaces de Application (por ejemplo DataLakeRawWriterInterface).
  - No lee ni escribe directamente entidades ajenas a su responsabilidad.

- DataLake:
  - Implementa repositorios y servicios para staging_prices, fact_price_daily, instrument, calendar, indicator_daily, signal_daily y vistas SERVING.
  - No accede a controladores ni a lógica de Auth.
  - Expone interfaces para que Analytics e Ingestion operen sobre él.

- Analytics:
  - Solo lee datos de DataLake (FACT y SERVING).
  - No escribe RAW ni altera pipelines ETL.
  - No accede directamente a tablas de otros módulos.

Regla: si un módulo necesita algo de otro, define o utiliza una interfaz de Application y realiza la dependencia a través de esa interfaz, nunca mediante un acceso directo a clases concretas de infraestructura de otro módulo.

7. Estrategia de migración y refactor

Cuando recibas una tarea para refactorizar APB:

1) Analiza el módulo afectado:
   - Identifica controladores, casos de uso, entidades y repositorios relevantes.
   - Localiza acoplamientos indebidos (controladores hablando directo con PDO, lógica de negocio en Interfaces, dependencias circulares entre módulos, etc.).

2) Propón un plan breve (3–7 pasos):
   - Lista concreta de archivos a modificar/crear.
   - Describe cómo el cambio será local al módulo.
   - Explica cómo se mantiene la compatibilidad de rutas y contratos.

3) Aplica el refactor siguiendo estos ejes:
   - Mover lógica de negocio de controladores a casos de uso Application.
   - Introducir interfaces cuando haya dependencia directa a infraestructura.
   - Ajustar el contenedor para construir los nuevos servicios/repositorios.
   - Mantener la firma de métodos públicos existente siempre que sea posible.

4) Conserva el comportamiento actual:
   - No cambies el flujo funcional ni el shape de los JSON sin motivo explícito.
   - Si necesitas cambiar la respuesta de un endpoint, documenta la diferencia en comentarios y, de ser posible, introduce una versión nueva de la ruta en vez de romper la anterior.

5) Documenta cambios:
   - Añade comentarios breves en nuevas clases/métodos explicando:
     - Rol del componente.
     - A qué módulo pertenece (Auth, Ingestion, DataLake, Analytics).
   - No escribas documentación extensa dentro del código; sé conciso y claro.

8. Tratamiento específico del ETL y Data Lake

Cuando la tarea afecte al Data Lake o ETL:

- Respeta el pipeline fijo:
  - External API → RAW (staging_prices) → FACT (fact_price_daily + dimensiones) → INDICATORS (indicator_daily) → SIGNALS (signal_daily) → VISTAS SERVING.

- Jobs ETL:
  - Implementa la lógica de ingestión, normalización, indicadores y señales en servicios de Application/Dominio.
  - Controladores / CLI solo disparan esos servicios y devuelven estados simples.

- Control de concurrencia:
  - Revisa EtlRun y mecanismos existentes; evita ejecuciones simultáneas del mismo job.
  - No elimines ni debilites registros de auditoría.

- Extensibilidad:
  - Si agregas una nueva fuente externa:
    - Crea un cliente HTTP dedicado.
    - Usa los mismos DTOs de barra.
    - Conéctalo al pipeline existente sin romper la estructura RAW/CURATED/SERVING.

9. Contratos y pruebas

- Trata cada endpoint descrito en el requerimiento maestro APB como un contrato:
  - Cambios de nombres de campos, tipos o estructuras deben considerarse breaking changes.
  - Solo realiza cambios de contrato si el desarrollador lo ha pedido explícitamente.

- Pruebas:
  - Puedes sugerir scripts de smoke test (por ejemplo, shell con curl) para rutas clave:
    - /auth/login, /logs, /etl/start-*, /analytics/*.
  - Solo implementa estos scripts o tests unitarios si el desarrollador lo solicita.

10. Checklist operativo al recibir una tarea

Para cada nueva tarea sobre APB, sigue este checklist:

1) Leer el contexto de la tarea y mapearlo a un módulo (Auth, Ingestion, DataLake, Analytics, Logs/Health).
2) Identificar contratos HTTP y casos de uso impactados.
3) Verificar restricciones globales (no microservicios, no schema en runtime, no romper rutas).
4) Escribir un plan corto de refactor/evolución con 3–7 pasos.
5) Implementar cambios:
   - Controladores delgados.
   - Casos de uso claros.
   - Interfaces en Application.
   - Implementaciones en Infrastructure.
6) Ajustar el contenedor DI si es necesario para construir nuevas dependencias.
7) Proponer, si corresponde, scripts de smoke test mínimos (sin crearlos por defecto) que ayuden al desarrollador a detectar regresiones.
8) Resumir al final qué se cambió, qué se mantuvo compatible y qué se recomienda como refactor futuro opcional.

Si algún requerimiento de la tarea chocara con estas reglas (por ejemplo, pedir un cambio de contrato masivo o convertir APB en microservicios), debes señalar el conflicto y proponer una alternativa que respete la arquitectura acordada o explicitar que se trata de una excepción a la norma.
