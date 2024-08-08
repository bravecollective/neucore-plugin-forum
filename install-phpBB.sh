#!/usr/bin/env bash

DIR=$(dirname "$(realpath "$0")")

FILE=phpBB-3.3.4.tar.bz2
URL=https://download.phpbb.com/pub/release/3.3/3.3.4/"$FILE"

if [ ! -f "${DIR}"/"$FILE" ]; then
    wget -nv $URL -O "${DIR}"/"$FILE" || exit 1
fi

if [ -d "${DIR}"/phpBB3 ]; then
    rm -R "${DIR}"/phpBB3
fi
tar jxf "${DIR}"/"$FILE" -C "${DIR}" || exit 1

chmod 0777 "${DIR}"/phpBB3/config.php
chmod 0777 "${DIR}"/phpBB3/config
