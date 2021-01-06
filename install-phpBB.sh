#!/usr/bin/env bash

DIR=$(dirname "$(realpath "$0")")

if [[ ! -f "${DIR}"/phpBB-3.2.2.tar.bz2 ]]; then
  wget https://www.phpbb.com/files/release/phpBB-3.2.2.tar.bz2
fi

if [[ -d "${DIR}"/phpBB3 ]]; then
  rm -R "${DIR}"/phpBB3
fi

tar jxf phpBB-3.2.2.tar.bz2
rm "${DIR}"/phpBB3/config.php
