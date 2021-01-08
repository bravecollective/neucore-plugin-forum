#!/usr/bin/env bash

DIR=$(dirname "$(realpath "$0")")

if [[ ! -f "${DIR}"/phpBB-3.2.2.tar.bz2 ]]; then
  wget https://www.phpbb.com/files/release/phpBB-3.2.2.tar.bz2 -O "${DIR}"/phpBB-3.2.2.tar.bz2
fi
if [[ ! -f "${DIR}"/phpBB-3.2.2.tar.bz2 ]]; then
  echo Error: failed to download https://www.phpbb.com/files/release/phpBB-3.2.2.tar.bz2
  exit 1
fi

if [[ -d "${DIR}"/phpBB3 ]]; then
  rm -R "${DIR}"/phpBB3
fi

tar jxf "${DIR}"/phpBB-3.2.2.tar.bz2 -C "${DIR}"
chmod 0777 "${DIR}"/phpBB3/config.php
chmod 0777 "${DIR}"/phpBB3/config
