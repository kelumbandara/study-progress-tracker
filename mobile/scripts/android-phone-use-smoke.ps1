<#
  Drives the PHONE USE feature of the INSTALLED ezexam app (the emulator-test build) on an Android emulator, like a person would,
  with REAL app use on the emulator, and checks what reached the server through its API.

  Prerequisites: the emulator-test APK is installed (talks to http://10.0.2.2:8081), a throw-away test server runs on this PC
  at port 8081 on a *_test database, and the app is signed out. Run from the project folder. WRITES DATA to that server.

  Usage:  powershell -File scripts\android-phone-use-smoke.ps1 -ApiFromPc http://127.0.0.1:8081 -Email ... -Password "..." -OutDir shots
#>
param(
    [Parameter(Mandatory)] [string]$ApiFromPc,
    [Parameter(Mandatory)] [string]$Email,
    [Parameter(Mandatory)] [string]$Password,
    [string]$OutDir = 'android-shots'
)
. (Join-Path $PSScriptRoot 'android-lib.ps1')
$pkg = 'net.edusl.ezexam'

$login = Invoke-RestMethod -Method Post -Uri "$ApiFromPc/api/v1/auth/login" -ContentType 'application/json' -Body (@{ email = $Email; password = $Password; device_name = 'android-phone-use-smoke' } | ConvertTo-Json)
$hdr = @{ Authorization = "Bearer $($login.token)"; Accept = 'application/json' }
function Server-Get($path) { Invoke-RestMethod -Uri "$ApiFromPc/api/v1$path" -Headers $hdr }
if ((Server-Get '/phone-usage?days=7').days_with_data -ne 0) { throw 'The test database already has phone use; re-seed it first.' }

# The switch itself (not its text label, which sits first in the same row).
function Tap-Switch($label) {
    foreach ($try in 1..6) {
        $xml = Ui
        $n = $xml.SelectNodes('//node') | Where-Object { $_.class -like '*Switch*' -and $_.'content-desc' -eq $label } | Select-Object -First 1
        if ($n) { $c = Center $n; & $adb shell input tap $c.X $c.Y; Start-Sleep -Milliseconds 800; return }
        Scroll-Up
    }
    throw "Switch '$label' not found"
}
function Switch-On($label) {
    $xml = Ui
    $n = $xml.SelectNodes('//node') | Where-Object { $_.class -like '*Switch*' -and $_.'content-desc' -eq $label } | Select-Object -First 1
    return ($n -and $n.checked -eq 'true')
}
function Foreground { (& $adb shell dumpsys window | Select-String 'mCurrentFocus' | Select-Object -First 1).Line }

Check 'Make REAL app use: Settings open for 2 minutes, Chrome for 1 minute' {
    & $adb shell input keyevent 3; Start-Sleep 2
    & $adb shell monkey -p com.android.settings -c android.intent.category.LAUNCHER 1 2>&1 | Out-Null
    Start-Sleep -Seconds 125
    & $adb shell input keyevent 3; Start-Sleep 2
    & $adb shell monkey -p com.android.chrome -c android.intent.category.LAUNCHER 1 2>&1 | Out-Null
    Start-Sleep -Seconds 65
    & $adb shell input keyevent 3; Start-Sleep 2
    'Settings ~2 min, Chrome ~1 min'
}
Check 'Open ezexam and sign in' {
    & $adb shell monkey -p $pkg -c android.intent.category.LAUNCHER 1 2>&1 | Out-Null
    Wait-For 'Server address' -Seconds 40 | Out-Null
    $srv = Box-Value 'Server address'
    if ($srv -ne 'http://10.0.2.2:8081') { throw "server field holds '$srv'" }
    Type-Text 'E-mail' $Email
    Type-Text 'Password' $Password
    & $adb shell input keyevent 66
    Wait-For 'More' -Contains -Seconds 40 | Out-Null
    Hide-Keyboard
    "signed in; server pre-filled: $srv"
}
Check 'More tab has a Phone use entry; the screen says what is shared and sharing is OFF' {
    Tap 'More' -Contains
    Wait-For 'Phone use' | Out-Null
    Shot 'e01-more'
    Tap 'Phone use'
    Wait-For 'What is shared' | Out-Null
    Reach 'no messages, no links' -Contains | Out-Null
    if (Switch-On 'Share my phone use with my dashboard') { throw 'sharing is ON by default' }
    if ((Server-Get '/phone-usage?days=7').days_with_data -ne 0) { throw 'something was sent before the student agreed' }
    Shot 'e02-phone-use-off'
    'explained, off by default, nothing sent'
}
Check 'Turn sharing on without Android permission: asked for it, and nothing is sent' {
    Tap-Switch 'Share my phone use with my dashboard'
    Wait-For 'One more step' -Contains | Out-Null
    Shot 'e03-needs-access'
    Start-Sleep -Seconds 3
    if ((Server-Get '/phone-usage?days=7').days_with_data -ne 0) { throw 'data was sent without permission' }
    'warning shown, 0 days on the server'
}
Check 'The button opens Android Usage access settings' {
    Tap 'Open Android settings'
    Start-Sleep -Seconds 3
    $f = Foreground
    Shot 'e04-android-usage-access'
    if ($f -notmatch 'settings|Settings|UsageAccess') { throw "foreground is: $f" }
    "foreground: $($f.Trim())"
}
Check 'Student turns ezexam on in Android; coming back, the app shows today''s real use' {
    & $adb shell appops set $pkg GET_USAGE_STATS allow
    Start-Sleep -Seconds 1
    & $adb shell am start -n "$pkg/.MainActivity" 2>&1 | Out-Null
    Wait-For 'Today so far' -Seconds 30 | Out-Null
    Reach 'Settings' | Out-Null
    Shot 'e05-today'
    'Today so far is shown with Settings in it'
}
Check 'Send now: the server gets real minutes, rounded sensibly, and only app names and minutes' {
    Tap 'Send now'
    Start-Sleep -Seconds 6
    $s = Server-Get '/phone-usage?days=7'
    $today = $s.days | Select-Object -Last 1
    Shot 'e06-sent'
    if ($s.days_with_data -lt 1) { throw 'nothing reached the server' }
    $apps = $today.apps
    $settings = $apps | Where-Object { $_.package -eq 'com.android.settings' } | Select-Object -First 1
    $chrome = $apps | Where-Object { $_.package -eq 'com.android.chrome' } | Select-Object -First 1
    if (-not $settings) { throw "Settings missing; apps: $(($apps | ForEach-Object { $_.package }) -join ', ')" }
    if ($settings.minutes -lt 2 -or $settings.minutes -gt 5) { throw "Settings minutes $($settings.minutes), expected about 2" }
    if ($chrome -and ($chrome.minutes -lt 1 -or $chrome.minutes -gt 4)) { throw "Chrome minutes $($chrome.minutes), expected about 1" }
    $bad = $apps | Where-Object { $_.package -in 'android', 'com.android.systemui' -or $_.package -match 'launcher' }
    if ($bad) { throw "system/home apps leaked: $($bad.package -join ', ')" }
    $keys = ($apps[0].PSObject.Properties.Name | Sort-Object) -join ','
    "server: Settings $($settings.minutes) min, Chrome $(if ($chrome) { $chrome.minutes } else { 'n/a' }) min, total $($today.total_minutes) min, fields: $keys"
}
Check 'Sharing stays on after the app is closed and reopened (the choice is remembered)' {
    & $adb shell am force-stop $pkg; Start-Sleep 2
    & $adb shell monkey -p $pkg -c android.intent.category.LAUNCHER 1 2>&1 | Out-Null
    Wait-For 'More' -Contains -Seconds 40 | Out-Null
    Tap 'More' -Contains; Tap 'Phone use'
    Wait-For 'What is shared' | Out-Null
    Start-Sleep -Seconds 1
    if (-not (Switch-On 'Share my phone use with my dashboard')) { throw 'sharing was forgotten' }
    'still on'
}
Check 'Stop sharing and erase: server data deleted, sharing off' {
    Tap 'Stop sharing and erase everything'
    Wait-For 'Stop and erase' -Seconds 10 | Out-Null
    Tap 'Stop and erase'
    Wait-For 'Erased' -Contains -Seconds 15 | Out-Null
    Shot 'e07-erased'
    $s = Server-Get '/phone-usage?days=7'
    if ($s.days_with_data -ne 0) { throw "server still has $($s.days_with_data) days" }
    if (Switch-On 'Share my phone use with my dashboard') { throw 'sharing still on' }
    'erased on server, switch off'
}

""
'===== ANDROID PHONE USE SMOKE TEST ====='
$results | ForEach-Object { '{0}  {1}  -  {2}' -f ($(if ($_.Ok) { 'PASS' } else { 'FAIL' })), $_.Name, $_.Note }
$ok = @($results | Where-Object { $_.Ok }).Count
"`n$ok/$($results.Count) steps passed. Screenshots in $OutDir"
if ($ok -ne $results.Count) { exit 1 }
