# neucore-plugin-forum

The plugin needs the following environment variables:
- NEUCORE_PLUGIN_FORUM_DB_HOST=127.0.0.1
- NEUCORE_PLUGIN_FORUM_DB_NAME=phpbb
- NEUCORE_PLUGIN_FORUM_DB_USERNAME=username
- NEUCORE_PLUGIN_FORUM_DB_PASSWORD=password
- NEUCORE_PLUGIN_FORUM_CONFIG_FILE=/path/to/config.php

The file config.php is based on config.php.dist.

See also https://github.com/bravecollective/forum-auth

Install
```shell
./install-phpBB.sh
```

Install for development:
```shell
composer install
```
