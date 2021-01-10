
CREATE TABLE `characters` (
    `id` bigint(20) NOT NULL,
    `name` varchar(255) NOT NULL,
    `username` varchar(255) NOT NULL,
    `last_update` datetime DEFAULT NULL,
    `corporation_name` varchar(255) DEFAULT NULL,
    `alliance_name` varchar(255) DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `character_groups` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `character_id` bigint(20) DEFAULT NULL,
    `name` varchar(255) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `IDX_532FFAE31136BE75` (`character_id`),
    CONSTRAINT `FK_F06D39701136BE75` FOREIGN KEY (`character_id`) REFERENCES `characters` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
