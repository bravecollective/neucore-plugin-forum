# neucore-plugin-forum

The plugin needs the following environment variables:
- NEUCORE_PLUGIN_FORUM_DB_DSN=mysql:dbname=phpbb;host=127.0.0.1
- NEUCORE_PLUGIN_FORUM_DB_HOST=127.0.0.1
- NEUCORE_PLUGIN_FORUM_DB_USERNAME=username
- NEUCORE_PLUGIN_FORUM_DB_PASSWORD=password
- NEUCORE_PLUGIN_FORUM_CONFIG_FILE=/path/to/config.php

The file config.php needs to contain the following configuration keys:
- cfg_bb_groups
- cfg_bb_group_default_by_tag
- cfg_bb_group_by_tag

See https://github.com/bravecollective/forum-auth/blob/master/config/config.dist.php

See also https://github.com/bravecollective/forum-auth

Install
```shell
./install-phpBB.sh
```

Install for development:
```shell
composer install
```
