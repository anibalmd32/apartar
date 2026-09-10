Actúa como un Staff Software Engineer y Arquitecto de Software especialista en Spec-Driven Development (SDD), metodologías BDD/TDD y el stack Laravel + Inertia.js (React + TypeScript). 

Tienes a tu disposición el skill de SDD (`indexion-sdd`), herramientas de diseño frontend y las directivas de arquitectura del proyecto. 

Tu objetivo inmediato **NO es escribir código de implementación**, sino crear la arquitectura formal de especificaciones dentro del directorio `.sdd/specs/` (o generar el archivo consolidado `spec.md`) ejecutando un flujo interactivo tipo *Spec Clarifier / Definer*.

---

### 1. REQUERIMIENTOS DE NEGOCIO Y FUNCIONALES DE LA APLICACIÓN

#### A. Identidad y Frontend Público (Storefront)
- **Dominio:** `apartar.store`.
- **Estética:** Estilo e-commerce moderno, orientado a conversión, interactivo y mobile-first (totalmente responsive).
- **Catálogo y Productos:**
  - Exploración de productos con filtros por categorías y etiquetas (tags).
  - Manejo bimoneda (USD y VES): La base de datos almacena exclusivamente valores base en USD. La conversión a VES se calcula y proyecta en tiempo real consultando una API de tasa de cambio oficial (BCV / Paralelo según configuración).
  - Toggle de visualización de moneda en UI:
    - *Default:* Monto en USD destacado con monto en VES secundario entre paréntesis `ej: $20.00 (Bs. 850.00)`.
    - *Invertido:* Monto en VES destacado con monto en USD secundario entre paréntesis `ej: Bs. 850.00 ($20.00)`.
  - Soporte para visualización de fichas técnicas dinámicas asociadas a productos.
  - Sección pública para explorar y acceder a catálogos curados compartibles vía URL única.
- **Flujo de Apartado (Layaway / Reserva):**
  - El cliente puede apartar sin registrarse previamente de forma obligatoria.
  - Formulario de apartado: Selección de cantidad, Nombre completo, Cédula de Identidad, Teléfono de contacto y Correo electrónico.
  - Autenticación OAuth opcional mediante Google (Gmail). Si la sesión está activa, precargar y bloquear la edición del correo electrónico.
  - Parámetros financieros por producto: Precio base, monto de cuota inicial, número de cuotas y frecuencia de pago (default: cada 15 días / quincenal).
- **Portal del Cliente (Historial y Seguimiento):**
  - Panel privado accesible tras autenticación o verificación por token seguro de cliente.
  - Visualización consolidada: Productos apartados, saldo acumulado pagado, saldo restante pendiente, cronograma de amortización y fechas exactas de vencimiento.
  - Carga de comprobantes de pago (soporte para subida de archivos adjuntos/capturas) que quedan en estado `PENDIENTE_DE_REVISION`.
- **Canales de Comunicación y Telemetría:**
  - Soporte de chat en tiempo real / diferido entre cliente y administradores de soporte.
  - Registro pasivo de telemetría e interacciones (clics, vistas de productos, tiempo de permanencia, búsquedas) en un almacén de eventos/metadata estructurado para consumo futuro por algoritmos de recomendación.

#### B. Panel de Administración y Backoffice
- **Acceso:** Ruta protegida `/backoffice`. Creación del primer superadmin mediante Seeders de base de datos.
- **Gestión de Accesos:** Creación y mantenimiento de usuarios administrativos del backoffice (sin RBAC granular por ahora; todos comparten permisos administrativos básicos).
- **Gestión de Inventario y Productos:**
  - CRUD de productos: Nombre, descripción, precio (USD), cuota inicial, cantidad de cuotas, periodicidad de pago y asignación de etiquetas.
  - Constructor dinámico de ficha técnica: Capacidad de añadir pares clave-valor dinámicos (`label` / `valor`) por producto.
  - Creación, publicación y compartición de catálogos temáticos vía enlaces directos.
- **Gestión de Clientes y Cobranza:**
  - Directorio integral de clientes con vista de perfil y balance consolidado.
  - Filtros operativos avanzados: Clientes con cuotas vencidas, clientes con pagos pendientes próximos a vencer, filtros por rangos de fechas (ej. cortes quincenales).
  - Exportación asíncrona a Excel de clientes e inventario según los filtros activos, delegada estrictamente a colas de trabajo (Queues / Jobs) con notificación o enlace temporal de descarga.
  - Disparador manual para enviar notificaciones Web Push puntuales a clientes específicos o segmentos, complementando las notificaciones automáticas del ciclo de cobro.
- **Conciliación de Pagos:**
  - Bandeja de entrada de comprobantes de pago con alertas en tiempo real.
  - Acciones administrativas: Aprobar o Rechazar el comprobante con feedback/motivo. La aprobación impacta automáticamente el balance y amortiza la cuota correspondiente.
- **Módulo de Chat Administrativo:**
  - Bandeja de mensajería unificada con separación por hilos de clientes y persistencia de historial.

---

### 2. ARQUITECTURA TÉCNICA Y ESTÁNDARES OBLIGATORIOS

- **Stack:** Laravel 11+, React 18+ (vía Inertia.js), TypeScript estricto.
- **Base de Datos:** MySQL 8.0+.
- **Infraestructura:** Dockerizado con `docker-compose` (servicios aislados para `app`, `nginx`/`fpm`, `mysql`, `redis`, `queue-worker`). Proxy inverso y SSL gestionados con **Traefik** mediante etiquetas (`labels`).
- **Arquitectura Backend (Laravel):**
  - Principios SOLID, Inyección de Dependencias en constructores y uso de Action Classes / Service Layer para lógica transaccional de negocio.
  - Thin Controllers (única responsabilidad: recibir Requests validadas por Form Requests y devolver respuestas Inertia o JSON).
  - Manejo de tareas pesadas (exportaciones masivas a Excel, ingesta de eventos de telemetría, notificaciones push) mediante **Laravel Queues / Redis**.
  - Documentación técnica estricta mediante **PHPDoc** en clases, interfaces y métodos. Evitar comentarios obvios en el cuerpo del código; el código debe ser auto-explicativo.
- **Arquitectura Frontend (React + TypeScript):**
  - Estructura atómica y modular. Prohibidos los componentes monolíticos que superen 150-200 líneas.
  - Separación de responsabilidades: Lógica compleja encapsulada en **Custom Hooks**.
  - Integración limpia con **TanStack Suite** donde aplique y sea compatible con el ciclo de vida de Inertia:
    - `@tanstack/react-table` para el manejo de paginación, filtros y ordenamiento en tablas del backoffice.
    - `@tanstack/react-form` para orquestación de formularios complejos y constructores dinámicos.
    - `@tanstack/react-query` únicamente si se requieren consultas reactivas desacopladas de los Inertia Page Props (ej. chat en vivo o tasas de cambio en tiempo real).
  - Documentación técnica mediante **JSDoc / TSDoc** e interfaces TypeScript fuertemente tipadas.

---

### 3. TU MISIÓN Y SECUENCIA DE EJECUCIÓN (SDD WORKFLOW)

Debes estructurar el trabajo en fases respetando el framework `indexion-sdd`:

#### PASO 1: Descubrimiento y Extracción de Asunciones (Spec Clarifier)
1. Analiza los requerimientos expuestos y genera un listado numerado de **Asunciones Funcionales y Técnicas** que consideres ambiguas o que requieran validación (por ejemplo: persistencia de clientes anónimos vs registrados, TTL del caché de la tasa de cambio, estrategia de subida de comprobantes en local/S3, persistencia del chat vía WebSockets con Reverb/Pusher o long-polling).
2. Espera mi retroalimentación sobre dichas asunciones antes de dar por cerrada la especificación.

#### PASO 2: Estructura del Archivo de Especificación (`spec.md`)
Una vez consensuadas las asunciones, compila el `spec.md` definitivo dividiéndolo en módulos atómicos con la siguiente estructura por cada funcionalidad:
- **ID y Título del Requerimiento:** Identificador único trazable (ej. `REQ-PAY-001`).
- **Historia de Usuario:** Formato *Como / Quiero / Para*.
- **Contratos de Datos / Modelos:** Esquema de entidades, tipos TypeScript e interfaces PHP involucradas.
- **Reglas de Negocio y Precondiciones:** Restricciones duras de validación.
- **Happy Path:** Secuencia de eventos exitosos paso a paso.
- **Sad Paths & Edge Cases:** Fallos de red, comprobantes duplicados, cuotas vencidas, tasas de cambio no disponibles.
- **Fuera de Alcance Inmediato:** Elementos visuales o funcionales explícitamente excluidos en esta iteración.
- **Gate de Validación:** Criterios para auditoría de `indexion spec align` (marcando tipos esperados y cobertura contra stubs vacíos `SHALLOW`).

Comienza presentándome el **PASO 1: Listado de Asunciones Críticas a Validar**.