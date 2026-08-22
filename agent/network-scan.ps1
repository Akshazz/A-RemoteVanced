param(
  [Parameter(Mandatory=$true)]
  [string]$Prefix
)

# Windows/XAMPP LAN discovery helper.
# Sends short asynchronous ICMP probes so the OS learns local ARP entries
# without keeping the PHP request open.
#
# Probes are sent in small batches rather than all 254 at once. Firing every
# address simultaneously creates a brief but real spike (new socket per ping,
# all in the same instant) that can make the whole machine feel like it
# stutters for a moment — not just this app. Batching keeps the same overall
# scan time (each batch's waits overlap with the next batch being queued)
# while spreading that load out instead of front-loading all of it.
$batchSize = 32
$perPingTimeoutMs = 350
$overallDeadline = (Get-Date).AddMilliseconds(3500)

for ($start = 1; $start -le 254; $start += $batchSize) {
  $end = [Math]::Min($start + $batchSize - 1, 254)
  $tasks = New-Object 'System.Collections.Generic.List[System.Threading.Tasks.Task]'
  $pings = New-Object 'System.Collections.Generic.List[System.Net.NetworkInformation.Ping]'

  for ($i = $start; $i -le $end; $i++) {
    $ip = "$Prefix.$i"
    try {
      $ping = New-Object System.Net.NetworkInformation.Ping
      $pings.Add($ping)
      [void]$tasks.Add($ping.SendPingAsync($ip, $perPingTimeoutMs))
    } catch {
      # Ignore an individual address; discovery is best effort.
    }
  }

  $remainingMs = [Math]::Max(0, ($overallDeadline - (Get-Date)).TotalMilliseconds)
  try {
    [System.Threading.Tasks.Task]::WaitAll($tasks.ToArray(), [int]$remainingMs) | Out-Null
  } catch {
    # Timed-out probes are expected on a LAN sweep.
  }

  foreach ($ping in $pings) {
    try { $ping.Dispose() } catch {}
  }

  if ((Get-Date) -ge $overallDeadline) { break }
}
