# win-input.ps1
# Reads one JSON command per line from stdin and injects it as a real
# mouse/keyboard event via user32.dll. Spawned and fed by control-agent.js.
# No compiler or native module build required.

Add-Type @"
using System;
using System.Runtime.InteropServices;
public class Win32Input {
    [DllImport("user32.dll")] public static extern bool SetCursorPos(int X, int Y);
    [DllImport("user32.dll")] public static extern void mouse_event(uint dwFlags, uint dx, uint dy, int dwData, UIntPtr dwExtraInfo);
    [DllImport("user32.dll")] public static extern void keybd_event(byte bVk, byte bScan, uint dwFlags, UIntPtr dwExtraInfo);
    [DllImport("user32.dll")] public static extern int GetSystemMetrics(int nIndex);
}
"@

$screenW = [Win32Input]::GetSystemMetrics(0)
$screenH = [Win32Input]::GetSystemMetrics(1)

# mouse_event flags
$MOUSEEVENTF_LEFTDOWN   = 0x0002
$MOUSEEVENTF_LEFTUP     = 0x0004
$MOUSEEVENTF_RIGHTDOWN  = 0x0008
$MOUSEEVENTF_RIGHTUP    = 0x0010
$MOUSEEVENTF_MIDDLEDOWN = 0x0020
$MOUSEEVENTF_MIDDLEUP   = 0x0040
$MOUSEEVENTF_WHEEL      = 0x0800
$KEYEVENTF_KEYUP        = 0x0002

# Browser KeyboardEvent.key -> Windows virtual-key code
$VkMap = @{
    'Enter'=0x0D; 'Backspace'=0x08; 'Tab'=0x09; 'Escape'=0x1B; ' '=0x20; 'Space'=0x20
    'ArrowLeft'=0x25; 'ArrowUp'=0x26; 'ArrowRight'=0x27; 'ArrowDown'=0x28
    'Shift'=0x10; 'Control'=0x11; 'Alt'=0x12; 'Meta'=0x5B
    'Delete'=0x2E; 'Home'=0x24; 'End'=0x23; 'PageUp'=0x21; 'PageDown'=0x22
    'F1'=0x70;'F2'=0x71;'F3'=0x72;'F4'=0x73;'F5'=0x74;'F6'=0x75
    'F7'=0x76;'F8'=0x77;'F9'=0x78;'F10'=0x79;'F11'=0x7A;'F12'=0x7B
}

function Get-VkCode([string]$key) {
    if ($VkMap.ContainsKey($key)) { return $VkMap[$key] }
    if ($key.Length -eq 1) {
        $ch = $key.ToUpper()[0]
        if ($ch -ge 'A' -and $ch -le 'Z') { return [byte][char]$ch }
        if ($ch -ge '0' -and $ch -le '9') { return [byte][char]$ch }
    }
    return $null
}

while ($true) {
    $line = [Console]::In.ReadLine()
    if ($null -eq $line) { break }
    if ($line.Trim().Length -eq 0) { continue }
    try { $cmd = $line | ConvertFrom-Json } catch { continue }

    try {
    switch ($cmd.type) {
        'move' {
            $x = [Math]::Min($screenW - 1, [Math]::Max(0, [int]($cmd.x * $screenW)))
            $y = [Math]::Min($screenH - 1, [Math]::Max(0, [int]($cmd.y * $screenH)))
            [Win32Input]::SetCursorPos($x, $y)
        }
        'button' {
            $x = [Math]::Min($screenW - 1, [Math]::Max(0, [int]($cmd.x * $screenW)))
            $y = [Math]::Min($screenH - 1, [Math]::Max(0, [int]($cmd.y * $screenH)))
            [Win32Input]::SetCursorPos($x, $y)
            $down = ($cmd.action -eq 'down')
            $flag = switch ([int]$cmd.button) {
                2 { if ($down) { $MOUSEEVENTF_RIGHTDOWN } else { $MOUSEEVENTF_RIGHTUP } }
                1 { if ($down) { $MOUSEEVENTF_MIDDLEDOWN } else { $MOUSEEVENTF_MIDDLEUP } }
                default { if ($down) { $MOUSEEVENTF_LEFTDOWN } else { $MOUSEEVENTF_LEFTUP } }
            }
            [Win32Input]::mouse_event([uint32]$flag, 0, 0, 0, [UIntPtr]::Zero)
        }
        'wheel' {
            # deltaY (and therefore $delta) is routinely negative — e.g. scrolling
            # down sends a positive deltaY, so -1*deltaY goes negative. mouse_event's
            # dwData is declared as `int` above (not `uint`) specifically so this
            # signed value marshals straight through; casting a negative number to
            # [uint32] here throws ("Cannot convert value '-111' to type
            # 'System.UInt32'") on every downward scroll and was silently breaking
            # the wheel input on every session.
            $delta = [int](-1 * $cmd.deltaY)
            [Win32Input]::mouse_event([uint32]$MOUSEEVENTF_WHEEL, 0, 0, $delta, [UIntPtr]::Zero)
        }
        'key' {
            $vk = Get-VkCode $cmd.key
            if ($null -ne $vk) {
                $flag = if ($cmd.action -eq 'down') { 0 } else { $KEYEVENTF_KEYUP }
                [Win32Input]::keybd_event([byte]$vk, 0, [uint32]$flag, [UIntPtr]::Zero)
            }
        }
    }
    } catch {
        # Never let one malformed/unexpected command kill the whole input
        # backend — log it and keep reading the next line.
        [Console]::Error.WriteLine("Ignored bad input command: $($_.Exception.Message)")
    }
}
