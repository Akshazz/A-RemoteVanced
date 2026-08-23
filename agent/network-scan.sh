#!/bin/sh
PREFIX="$1"
[ -n "$PREFIX" ] || exit 1
PIDS=""
cleanup() {
  for pid in $PIDS; do kill "$pid" 2>/dev/null || true; done
}
trap cleanup INT TERM EXIT
for i in $(seq 1 254); do
  ping -c1 -W1 "$PREFIX.$i" >/dev/null 2>&1 &
  PIDS="$PIDS $!"
done
wait
trap - INT TERM EXIT
exit 0
