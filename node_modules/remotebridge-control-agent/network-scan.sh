#!/bin/sh
PREFIX="$1"
[ -n "$PREFIX" ] || exit 1
PIDS=""
cleanup() {
  for pid in $PIDS; do kill "$pid" 2>/dev/null || true; done
}
trap cleanup INT TERM EXIT

# Probe in small batches rather than forking all 254 pings at the same
# instant. Launching 254 processes simultaneously is a brief but real spike
# on the host; batching spreads that out while keeping roughly the same
# total scan time.
BATCH_SIZE=32
i=1
while [ "$i" -le 254 ]; do
  end=$((i + BATCH_SIZE - 1))
  [ "$end" -gt 254 ] && end=254
  j=$i
  while [ "$j" -le "$end" ]; do
    ping -c1 -W1 "$PREFIX.$j" >/dev/null 2>&1 &
    PIDS="$PIDS $!"
    j=$((j + 1))
  done
  wait
  i=$((end + 1))
done

trap - INT TERM EXIT
exit 0
