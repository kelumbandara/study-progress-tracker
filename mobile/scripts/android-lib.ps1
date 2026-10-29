# Shared helpers for the adb/uiautomator drivers (dot-sourced by android-smoke.ps1 and android-practice-smoke.ps1).
# Expects $OutDir to be set by the calling script.
# adb writes progress text to stderr; that must not be mistaken for a failure, so only my own `throw` statements stop a step.
$ErrorActionPreference = 'Continue'
$adb = "$env:LOCALAPPDATA\Android\Sdk\platform-tools\adb.exe"
if (-not (Test-Path $adb)) { throw "adb not found at $adb" }
New-Item -ItemType Directory -Force $OutDir | Out-Null
$results = New-Object System.Collections.ArrayList

function Shot($name) { cmd /c "`"$adb`" exec-out screencap -p > `"$OutDir\$name.png`"" }
function Ui {
    & $adb shell uiautomator dump /sdcard/ui.xml 2>&1 | Out-Null
    $tmp = Join-Path $env:TEMP 'ui.xml'
    & $adb pull /sdcard/ui.xml $tmp 2>&1 | Out-Null
    [xml](Get-Content $tmp -Raw -Encoding UTF8)
}
function Find-Node($label, [switch]$Contains, [switch]$EditBox) {
    $xml = Ui
    $nodes = $xml.SelectNodes('//node') | Where-Object {
        if ($EditBox -and $_.class -notlike '*EditText') { return $false }
        $d = $_.'content-desc'; $t = $_.text
        if ($Contains) { ($d -and $d -like "*$label*") -or ($t -and $t -like "*$label*") } else { $d -eq $label -or $t -eq $label }
    }
    $nodes | Select-Object -First 1
}
function Center($node) {
    if (-not ($node.bounds -match '\[(\d+),(\d+)\]\[(\d+),(\d+)\]')) { throw 'no bounds' }
    [pscustomobject]@{ X = [int](([int]$Matches[1] + [int]$Matches[3]) / 2); Y = [int](([int]$Matches[2] + [int]$Matches[4]) / 2) }
}
function Scroll-Up { & $adb shell input swipe 1000 700 1000 1900 300; Start-Sleep -Milliseconds 700 }
function Scroll-Down { & $adb shell input swipe 1000 1800 1000 700 300; Start-Sleep -Milliseconds 700 }
function Wait-For($label, [int]$Seconds = 20, [switch]$Contains, [switch]$EditBox) {
    $end = (Get-Date).AddSeconds($Seconds)
    while ((Get-Date) -lt $end) { $n = Find-Node $label -Contains:$Contains -EditBox:$EditBox; if ($n) { return $n }; Start-Sleep -Milliseconds 700 }
    throw "Timed out waiting for '$label' on screen"
}
# Android only lists what is on screen, so scroll until the control is visible.
function Reach($label, [switch]$Contains, [switch]$EditBox) {
    $n = Find-Node $label -Contains:$Contains -EditBox:$EditBox
    if ($n) { return $n }
    foreach ($i in 1..3) { Scroll-Up; $n = Find-Node $label -Contains:$Contains -EditBox:$EditBox; if ($n) { return $n } }
    foreach ($i in 1..6) { Scroll-Down; $n = Find-Node $label -Contains:$Contains -EditBox:$EditBox; if ($n) { return $n } }
    throw "Could not scroll to '$label'"
}
function Tap($label, [switch]$Contains) {
    $n = Reach $label -Contains:$Contains
    $c = Center $n
    & $adb shell input tap $c.X $c.Y
    Start-Sleep -Milliseconds 600
}
function Type-Text($label, $text) {
    # Target the input box itself (not the label above it), then clear whatever it already holds.
    # The screen can still be moving (keyboard opening, scroll settling) when it is measured, so check which box
    # really has focus after the tap and tap again if it is the wrong one.
    $n = $null
    foreach ($try in 1..4) {
        Hide-Keyboard   # an open keyboard covers the lower boxes, so a tap there would hit the keys
        $n = Reach $label -EditBox
        $c = Center $n
        & $adb shell input tap $c.X $c.Y
        Start-Sleep -Milliseconds 700
        $focused = (Ui).SelectNodes('//node') | Where-Object { $_.class -like '*EditText' -and $_.focused -eq 'true' } | Select-Object -First 1
        if ($focused -and $focused.'content-desc' -eq $label) { $n = $focused; break }
        $n = $null
    }
    if (-not $n) { throw "Could not focus the '$label' box" }
    & $adb shell input keyevent 123
    $len = [Math]::Max(0, ([string]$n.text).Length) + 3
    & $adb shell input keyevent (@(67) * $len)
    & $adb shell input text ($text -replace ' ', '%s')
    Start-Sleep -Milliseconds 400
}
function Box-Value($label) { $n = Find-Node $label -EditBox; if ($n) { [string]$n.text } else { $null } }
function Hide-Keyboard {
    # Back closes the keyboard, but if the keyboard has already gone Back would leave the screen (or the app), so look twice.
    $shown = { (& $adb shell dumpsys input_method) | Select-String 'mInputShown=true' }
    if (& $shown) {
        Start-Sleep -Milliseconds 400
        if (& $shown) { & $adb shell input keyevent 4; Start-Sleep -Milliseconds 700 }
    }
}
# Taps a radio-style choice and checks it really became selected (the layout can still be settling after the keyboard closes).
function Choose($label) {
    foreach ($try in 1..4) {
        Tap $label
        Start-Sleep -Milliseconds 500
        $n = Find-Node $label
        if ($n -and ($n.selected -eq 'true' -or $n.checked -eq 'true')) { return }
    }
    throw "Could not select '$label'"
}
function Check($name, [scriptblock]$body) {
    try { $note = & $body; [void]$results.Add([pscustomobject]@{ Name = $name; Ok = $true; Note = "$note" }) }
    catch { [void]$results.Add([pscustomobject]@{ Name = $name; Ok = $false; Note = $_.Exception.Message }) }
}

