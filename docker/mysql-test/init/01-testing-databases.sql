CREATE DATABASE IF NOT EXISTS `apartar_testing`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `apartar\_testing%`.* TO 'apartar'@'%';
FLUSH PRIVILEGES;
