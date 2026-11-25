#!/usr/bin/env bash
# Start an isolated, unpublished 22.7 instance. No existing service or credentials required.
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
image='hub.zentao.net/app/zentao@sha256:b0ddfeb5cd94b04a82d5052d847767f3186843ccd88aab2cfe1d093e32ecea20'
cid=$(docker run --detach --rm --network none -e MYSQL_INTERNAL=true "$image")
trap 'docker stop --time 5 "$cid" >/dev/null' EXIT
for ((i=0; i<60; i++)); do
    if docker exec "$cid" /opt/zbox/bin/mysql --socket=/data/mysql/tmp/mysql.sock -uroot zentao -Nse 'SELECT 1' >/dev/null 2>&1; then break; fi
    sleep 2
done
docker cp "$root" "$cid:/tmp/spec-trace"
docker exec "$cid" php /tmp/spec-trace/tests/fixture.php
docker exec "$cid" sh -ec 'for f in /tmp/spec-trace/*.php /tmp/spec-trace/tests/*.php; do php -n -l "$f"; done'
docker exec "$cid" php /tmp/spec-trace/tests/integration.php
