param(
  [Parameter(Mandatory=$true)]
  [string]$Prefix
)

# Windows/XAMPP LAN discovery helper.
# Sends short asynchronous ICMP probes so the OS learns local ARP entries
# without keeping the PHP request open.
$tasks = New-Object 'System.Collections.Generic.List[System.Threading.Tasks.Task]'
$pings = New-Object 'System.Collections.Generic.List[System.Net.NetworkInformation.Ping]'

1..254 | ForEach-Object {
  $ip = "$Prefix.$_"
  try {
    $ping = New-Object System.Net.NetworkInformation.Ping
    $pings.Add($ping)
    [void]$tasks.Add($ping.SendPingAsync($ip, 350))
  } catch {
    # Ignore an individual address; discovery is best effort.
  }
}

try {
  [System.Threading.Tasks.Task]::WaitAll($tasks.ToArray(), 3500) | Out-Null
} catch {
  # Timed-out probes are expected on a LAN sweep.
}

foreach ($ping in $pings) {
  try { $ping.Dispose() } catch {}
}
