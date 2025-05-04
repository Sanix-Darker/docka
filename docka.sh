#!/usr/bin/env bash
#
# safer scripting
set -euo pipefail

exec php \
  -d display_errors=1 \
  -d display_startup_errors=1 \
  -d log_errors=1 \
  -d error_reporting=E_ALL \
  -d error_log=php://stdout \
  -d output_buffering=0 \
  -d implicit_flush=1 \
  -S 0.0.0.0:7711 -t public
