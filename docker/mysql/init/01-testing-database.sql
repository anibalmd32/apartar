-- Base de datos de la suite de pruebas (REQ-QAX-003 RB-8).
--
-- Los tests corren sobre MySQL real, no SQLite: el sistema depende de
-- FULLTEXT, de lockForUpdate y de índices UNIQUE compuestos que SQLite no
-- reproduce. Necesitan su propio esquema porque `RefreshDatabase` lo borra
-- entero en cada ejecución, y hacerlo sobre `apartar` se llevaría por delante
-- los datos de desarrollo.
CREATE DATABASE IF NOT EXISTS `apartar_testing`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `apartar_testing`.* TO 'apartar'@'%';
FLUSH PRIVILEGES;
