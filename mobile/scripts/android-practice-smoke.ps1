<#
  Drives the PRACTICE screens (Record a question, Theory study, Mistakes) on a running Android emulator/phone, like a person would,
  and then checks what reached the server through the API.

  Prerequisites: as for android-smoke.ps1 (app open in Expo Go, adb sees one device, a throw-away test server reachable from the
  device, Android 17 local-network permission granted), plus a FRESHLY SEEDED test database with the syllabus loaded and no attempts.
  WRITES DATA to the server you point it at.

  Usage:  powershell -File scripts\android-practice-smoke.ps1 -Server http://10.0.2.2:8081 -ApiFromPc http://127.0.0.1:8081 -Email ... -Password "..." -OutDir shots
#>
param(
    [Parameter(Mandatory)] [string]$Server,
    [Parameter(Mandatory)] [string]$ApiFromPc,
    [Parameter(Mandatory)] [string]$Email,
    [Parameter(Mandatory)] [string]$Password,
    [string]$OutDir = 'android-shots'
)
. (Join-Path $PSScriptRoot 'android-lib.ps1')

# Independent check: ask the server itself (from this PC) what it holds.
$login = Invoke-RestMethod -Method Post -Uri "$ApiFromPc/api/v1/auth/login" -ContentType 'application/json' -Body (@{ email = $Email; password = $Password; device_name = 'android-practice-smoke' } | ConvertTo-Json)
$hdr = @{ Authorization = "Bearer $($login.token)"; Accept = 'application/json' }
function Server-Get($path) { Invoke-RestMethod -Uri "$ApiFromPc/api/v1$path" -Headers $hdr }
if ((Server-Get '/attempts').meta.total -ne 0) { throw 'The test database already has attempts; re-seed it first.' }

Check 'Sign in' {
    $signedOut = $null
    try { $signedOut = Wait-For 'Server address' -Seconds 15 } catch { }
    if (-not $signedOut) { Wait-For 'Practice' -Contains -Seconds 30 | Out-Null; return 'already signed in' }
    Type-Text 'Server address' $Server
    Type-Text 'E-mail' $Email
    Type-Text 'Password' $Password
    & $adb shell input keyevent 66
    Wait-For 'Practice' -Contains -Seconds 40 | Out-Null
    Hide-Keyboard
    'signed in'
}
Check 'Practice tab shows the three tasks' {
    Tap 'Practice' -Contains
    Wait-For 'Record a question' | Out-Null
    Reach 'Theory study' | Out-Null
    Reach 'Mistakes to review' | Out-Null
    Shot 'c01-practice-hub'
    'hub shown'
}
Check 'Add a paper and a question from the phone' {
    Tap 'Record a question'
    Wait-For 'Subject: Physics' | Out-Null
    Tap 'Subject: Physics'
    Tap 'Exam year: 2025'
    Tap 'Paper not listed? Add it'
    Type-Text 'Paper name' 'Paper I'
    Hide-Keyboard
    Tap 'Add this paper'
    Tap 'Question not listed? Add it'
    Type-Text 'Question number' '1'
    Type-Text 'Marks it is worth' '10'
    Hide-Keyboard
    Tap 'Add this question'
    Start-Sleep -Seconds 3
    Reach 'Marks you scored' -Contains | Out-Null   # the form is below the pickers, so scroll to it
    $papers = (Server-Get '/papers').data
    if (@($papers).Count -ne 1 -or $papers[0].name -ne 'Paper I') { throw "paper on the server: $($papers | ConvertTo-Json -Compress)" }
    Shot 'c02-attempt-form'
    'paper "Paper I" and question 1 registered'
}
Check 'Save an attempt' {
    Type-Text 'Marks you scored (out of 10)' '6'
    Hide-Keyboard
    Choose 'How did it go?: Partially Correct'
    Choose 'Did you do it without help?: Yes, on my own'
    Tap 'Save attempt'
    Start-Sleep -Seconds 3
    Reach 'is saved' -Contains | Out-Null
    Shot 'c03-attempt-saved'
    $a = Server-Get '/attempts'
    if ($a.meta.total -ne 1) { throw "attempts on the server: $($a.meta.total)" }
    $x = $a.data[0]
    if ($x.marks_earned -ne 6 -or $x.max_marks -ne 10 -or $x.result -ne 'partial' -or -not $x.independent) { throw "attempt: $($x | ConvertTo-Json -Compress)" }
    'server has attempt 1: 6/10, partial, independent'
}
Check 'Theory study: topic, time, save' {
    Hide-Keyboard
    & $adb shell input keyevent 4; Start-Sleep -Seconds 1   # back to the Practice list
    Tap 'Theory study'
    Wait-For 'Subject: Physics' | Out-Null
    Tap 'Subject: Physics'
    Type-Text 'Search topics' 'Dimensions'
    Hide-Keyboard
    Tap 'Dimensions of physical quantities' -Contains   # the topic row, not the search box that now holds 'Dimensions'
    Type-Text 'Time studied minutes' '40'
    Hide-Keyboard
    Tap 'Save theory study'
    Reach 'is saved' -Contains | Out-Null
    Shot 'c04-theory-saved'
    $t = (Server-Get '/theory').data
    if (@($t).Count -ne 1 -or $t[0].minutes -ne 40) { throw "theory on the server: $($t | ConvertTo-Json -Compress -Depth 4)" }
    'server has 40 min of theory'
}
Check 'Mistakes screen opens' {
    Hide-Keyboard
    & $adb shell input keyevent 4; Start-Sleep -Seconds 1
    Tap 'Mistakes to review'
    Wait-For 'A question stays here' -Contains | Out-Null
    Shot 'c05-mistakes'
    'shown'
}

""
'===== ANDROID PRACTICE SMOKE TEST ====='
$results | ForEach-Object { '{0}  {1}  -  {2}' -f ($(if ($_.Ok) { 'PASS' } else { 'FAIL' })), $_.Name, $_.Note }
$ok = @($results | Where-Object { $_.Ok }).Count
"`n$ok/$($results.Count) steps passed. Screenshots in $OutDir"
if ($ok -ne $results.Count) { exit 1 }
