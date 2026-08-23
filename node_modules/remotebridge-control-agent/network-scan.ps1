param(
  [Parameter(Mandatory=$true)] [string]$First,
  [Parameter(Mandatory=$true)] [string]$Last
)

# RemoteBridge Windows LAN discovery. The parent PHP process supplies the
# exact host range. Every probe is asynchronous so one blocked host does not
# serialize the whole scan. Ping is used to force Windows to resolve the
# neighbor's MAC into the local ARP/neighbor cache; the PHP endpoint then reads
# that cache with arp -a / Get-NetNeighbor.

function ConvertTo-Int([string]$ip) {
  $parts = $ip.Split('.') | ForEach-Object { [int]$_ }
  return ([uint64]$parts[0] * 16777216 + [uint64]$parts[1] * 65536 + [uint64]$parts[2] * 256 + [uint64]$parts[3])
}
function ConvertTo-Ip([uint64]$n) {
  return "{0}.{1}.{2}.{3}" -f ([math]::Floor($n / 16777216) % 256), ([math]::Floor($n / 65536) % 256), ([math]::Floor($n / 256) % 256), ($n % 256)
}

$start = ConvertTo-Int $First
$end = ConvertTo-Int $Last
if ($end -lt $start) { exit 1 }

$tasks = New-Object 'System.Collections.Generic.List[System.Threading.Tasks.Task]'
$pings = New-Object 'System.Collections.Generic.List[System.Net.NetworkInformation.Ping]'

for ($n = $start; $n -le $end; $n++) {
  $ip = ConvertTo-Ip $n
  try {
    $ping = New-Object System.Net.NetworkInformation.Ping
    $pings.Add($ping)
    [void]$tasks.Add($ping.SendPingAsync($ip, 800))
  } catch {
    # One failed probe must never stop the complete LAN sweep.
  }
}

try {
  if ($tasks.Count -gt 0) {
    [System.Threading.Tasks.Task]::WaitAll($tasks.ToArray(), 13000) | Out-Null
  }
} catch {
  # Timed-out individual probes are expected.
}

foreach ($ping in $pings) {
  try { $ping.Dispose() } catch {}
}

try {
  Get-NetNeighbor -AddressFamily IPv4 -ErrorAction SilentlyContinue | Out-Null
} catch {}

exit 0
