#!/bin/sh
# Runs the benchmark matrix of docs/benchmarks.md in the current PHP (inside the container) and writes a phpbench
# XML dump. Used by `make bench-report`; run it through Docker:
#
#     docker compose run --rm php sh benchmarks/run-report.sh <off|on> <dump.xml>
#
# pcov and the debug ini file are left out of the benchmark processes (pcov, even disabled, makes PHP refuse to
# enable the JIT); opcache stays on. "on" means opcache.jit=tracing with a 128M buffer. The base settings repeat
# phpbench.json, because --php-config replaces runner.php_config as a whole.
set -eu

jit="${1:?usage: run-report.sh off|on dump.xml}"
dump="${2:?usage: run-report.sh off|on dump.xml}"

case "$jit" in
    on) jitini='"opcache.jit":"tracing","opcache.jit_buffer_size":"128M"' ;;
    off) jitini='"opcache.jit":"disable"' ;;
    *) echo "first argument must be off or on" >&2; exit 1 ;;
esac
config="{\"opcache.enable_cli\":1,$jitini,\"zend.assertions\":-1,\"memory_limit\":\"2G\"}"

ini="$(mktemp -d)"
trap 'rm -rf "$ini"' EXIT
for f in /usr/local/etc/php/conf.d/*.ini; do
    case "$f" in */*pcov.ini | */zz-dev.ini) ;; *) cp "$f" "$ini/" ;; esac
done
export PHP_INI_SCAN_DIR="$ini"

mkdir -p "$(dirname "$dump")"
# Prove the setting took effect in a process configured like the benchmark processes.
php -d opcache.enable_cli=1 $(echo "$config" | php -r '
    foreach (json_decode(stream_get_contents(STDIN), true) as $k => $v) { echo "-d $k=$v "; }') \
    -r 'echo "PHP ", PHP_VERSION, ", JIT on: ", var_export(opcache_get_status(false)["jit"]["on"] ?? false, true), "\n";'

vendor/bin/phpbench run \
    benchmarks/SourceBench.php benchmarks/StrategyBench.php benchmarks/SinkBench.php \
    benchmarks/NaiveBaselineBench.php benchmarks/StreamingBench.php \
    --report=aggregate --iterations=8 --retry-threshold=5 --php-config="$config" --dump-file="$dump"
