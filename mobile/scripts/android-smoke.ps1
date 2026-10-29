<#
  Drives the app on a running Android emulator/phone (adb) like a person would: sign in, enter a day, save, look at the other tabs.
  Finds controls through Android's accessibility tree (the same labels a screen reader uses), so it also checks those labels.

  Prerequisites: the app is already open in Expo Go (npx expo start --android), adb sees one device, and a server is reachable
  from it. For the emulator the computer's localhost is http://10.0.2.2:<port>.
  Android 17 note: reaching a private address (10.0.2.2, your Wi-Fi IP) needs the "local network" permission:
      adb shell pm grant host.exp.exponent android.permission.ACCESS_LOCAL_NETWORK
  WRITES DATA to the server you point it at: use a throw-away test server.

  Usage:  powershell -File scripts\android-smoke.ps1 -Server http://10.0.2.2:8081 -Email you@example.com -Password "..." -OutDir shots
#>
param(
    [Parameter(Mandatory)] [string]$Server,
    [Parameter(Mandatory)] [string]$Email,
    [Parameter(Mandatory)] [string]$Password,
    [string]$OutDir = 'android-shots'
)
. (Join-Path $PSScriptRoot 'android-lib.ps1')

Check 'Sign-in screen is shown' { Wait-For 'Server address' | Out-Null; Shot '01-sign-in'; 'ok' }
Check 'Sign in with server, e-mail and password' {
    Type-Text 'Server address' $Server
    Type-Text 'E-mail' $Email
    Type-Text 'Password' $Password
    & $adb shell input keyevent 66   # Enter on the password box submits, like the keyboard's "go" key
    Wait-For 'Not logged yet' -Seconds 40 -Contains | Out-Null
    'signed in, Today screen shown'
}
Check 'A missing day says so' { Hide-Keyboard; Scroll-Up; Shot '02-today-missing'; 'banner shown' }
Check 'Tapping from one box straight to the next works (one tap each)' {
    Type-Text 'School hours' '5'
    Type-Text 'School minutes' '30'
    Type-Text 'Study Theory hours' '1'
    Type-Text 'Study Theory minutes' '15'
    $sh = Box-Value 'School hours'; $sm = Box-Value 'School minutes'; $th = Box-Value 'Study Theory hours'; $tm = Box-Value 'Study Theory minutes'
    if ($th -ne '1' -or $tm -ne '15') { throw "theory boxes hold '$th' h '$tm' min, expected 1 and 15" }
    Scroll-Up
    $sh = Box-Value 'School hours'; $sm = Box-Value 'School minutes'
    if ($sh -ne '5' -or $sm -ne '30') { throw "school boxes hold '$sh' h '$sm' min, expected 5 and 30" }
    'every value landed in the right box'
}
Check 'The day total is shown live' { Hide-Keyboard; Reach '6 h 45 min' -Contains | Out-Null; Shot '03-today-entered'; 'total 6 h 45 min' }
Check 'Save the day' {
    Tap 'Save day'
    Start-Sleep -Seconds 3
    Reach 'This day is saved' -Contains | Out-Null   # the banner is at the top of the page, so scroll to it
    Shot '04-today-saved'
    'saved'
}
Check 'Dashboard tab' { Tap 'Dashboard'; Wait-For 'Study period' -Seconds 20 | Out-Null; Shot '05-dashboard'; 'shown' }
Check 'More tab shows the account and the server' { Tap 'More'; Wait-For 'Test connection' | Out-Null; Shot '06-more'; 'shown' }
Check 'Test connection works from the phone' { Tap 'Test connection'; Wait-For 'Connected to the server' -Contains -Seconds 20 | Out-Null; Shot '07-connected'; 'connected' }

""
'===== ANDROID SMOKE TEST ====='
$results | ForEach-Object { '{0}  {1}  -  {2}' -f ($(if ($_.Ok) { 'PASS' } else { 'FAIL' })), $_.Name, $_.Note }
$ok = @($results | Where-Object { $_.Ok }).Count
"`n$ok/$($results.Count) steps passed. Screenshots in $OutDir"
if ($ok -ne $results.Count) { exit 1 }
