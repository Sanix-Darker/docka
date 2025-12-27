#!/usr/bin/env bash

set -euo pipefail

# clean_docker_container; clean_docker_volume_rmall; clean_docker_images_all && sudo bash ./start.sh
exec php8.4 \
    -d max_execution_time=450 \
    -d default_socket_timeout=450 \
    -d display_errors=0 \
    -d log_errors=1 \
    -d error_log=logs/php_errors.log \
    -d display_startup_errors=1 \
    -d error_reporting=E_ALL \
    -d output_buffering=0 \
    -d implicit_flush=1 \
    -S 0.0.0.0:7711 \
    -t public
