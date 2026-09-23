-- Base de datos de la suite, en su propio MySQL sobre `tmpfs` (`T-003b`).
--
-- Es la misma `apartar_testing` de siempre, en otro servidor: el de aquí
-- guarda su `datadir` en memoria y se recrea en cada `docker compose up`, que
-- es exactamente lo que se quiere de una base que `RefreshDatabase` borra
-- entera en cada ejecución — y exactamente lo que **no** se quiere de la de
-- desarrollo, donde están los datos sembrados con los que se mira la
-- aplicación en http://localhost:8080.
CREATE DATABASE IF NOT EXISTS `apartar_testing`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- **El permiso es sobre el patrón, no sobre la base.** Al correr en paralelo,
-- Laravel crea una base por proceso —`apartar_testing_test_1`,
-- `apartar_testing_test_2`…— y las crea **con este usuario**. Con el grant
-- acotado a `apartar_testing` a secas, el primer proceso fallaría con un
-- «access denied» que no se parece en nada a lo que es.
--
-- El comodín no abre nada que importe: este servidor no tiene más que bases
-- de prueba, vive en memoria y desaparece al apagar el stack.
GRANT ALL PRIVILEGES ON `apartar\_testing%`.* TO 'apartar'@'%';
FLUSH PRIVILEGES;
