# neucore-plugin-forum

## Requirements

- A [Neucore](https://github.com/bravecollective/neucore) installation. 
- A [phpBB](https://www.phpbb.com/) installation

The phpBB installation needs two custom profile fields (Admin -> Users and Groups -> Custom profile fields)
of type `Single text fields`:
- `core_corp_name`
- `core_alli_name`

I you use a fresh phpBB installation you must create groups if you want to test anything. 
Go to Admin -> General -> Manage groups and add the first group with the name brave, it should 
get the ID 8. See config.php.dist for more.

## Install

- Create the DB tables from forum_signup.sql.

The plugin needs the following environment variables:
- NEUCORE_PLUGIN_FORUM_DB_HOST=127.0.0.1
- NEUCORE_PLUGIN_FORUM_DB_NAME=phpbb
- NEUCORE_PLUGIN_FORUM_DB_USERNAME=username
- NEUCORE_PLUGIN_FORUM_DB_PASSWORD=password
- NEUCORE_PLUGIN_FORUM_CONFIG_FILE=/path/to/config.php

The file config.php is based on config.php.dist.

Execute the following to get a copy of the phpBB source files that are used by the plugin:
```shell
./install-phpBB.sh
```

Install for development:
```shell
composer install
```
