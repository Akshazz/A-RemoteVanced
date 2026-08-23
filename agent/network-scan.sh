#!/bin/sh
FIRST="$1"
LAST="$2"
[ -n "$FIRST" ] && [ -n "$LAST" ] || exit 1

# Parallel ping sweep over the complete detected host range. The PHP parent
# owns this process and enforces the overall timeout.
PIDS=""
TMP=$(mktemp 2>/dev/null || printf '/tmp/rb-scan-%s' "$$")
cleanup() {
  for pid in $PIDS; do kill "$pid" 2>/dev/null || true; done
  rm -f "$TMP" 2>/dev/null || true
}
trap cleanup INT TERM EXIT

# Generate the range with awk so IPv4 arithmetic is safe on 32/64-bit shells.
awk -v s="$FIRST" -v e="$LAST" '
function ip2n(ip, a){ split(ip,a,"."); return a[1]*16777216+a[2]*65536+a[3]*256+a[4] }
function n2ip(n){ return int(n/16777216) "." int(n/65536)%256 "." int(n/256)%256 "." n%256 }
BEGIN {
  start=ip2n(s); end=ip2n(e);
  if(end < start) exit 1;
  for(i=start;i<=end;i++) print n2ip(i);
}' > "$TMP" || exit 1

while IFS= read -r ip; do
  ping -c1 -W1 "$ip" >/dev/null 2>&1 &
  PIDS="$PIDS $!"
done < "$TMP"

wait 2>/dev/null || true
trap - INT TERM EXIT
rm -f "$TMP" 2>/dev/null || true
exit 0
