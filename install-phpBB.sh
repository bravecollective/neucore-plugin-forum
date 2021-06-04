#!/usr/bin/env bash

DIR=$(dirname "$(realpath "$0")")

FILE=phpBB-3.3.4.tar.bz2
URL=https://download.phpbb.com/pub/release/3.3/3.3.4/"$FILE"

if [[ ! -f "${DIR}"/"$FILE" ]]; then
  wget $URL -O "${DIR}"/"$FILE"
fi
if [[ ! -f "${DIR}"/"$FILE" ]]; then
  echo Error: failed to download $URL
  exit 1
fi

if [[ -d "${DIR}"/phpBB3 ]]; then
  rm -R "${DIR}"/phpBB3
fi

tar jxf "${DIR}"/"$FILE" -C "${DIR}"
chmod 0777 "${DIR}"/phpBB3/config.php
chmod 0777 "${DIR}"/phpBB3/config
