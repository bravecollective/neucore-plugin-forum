
CREATE TABLE `neucore_characters` (
    `id` bigint(20) NOT NULL,
    `name` varchar(255) NOT NULL,
    `username` varchar(255) NOT NULL,
    `last_update` datetime DEFAULT NULL,
    `corporation_name` varchar(255) DEFAULT NULL,
    `alliance_name` varchar(255) DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
