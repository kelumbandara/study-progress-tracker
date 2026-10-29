<#
  Drives the PHASE D screens (Syllabus, Reports, History and corrections, all under the More tab) on a running Android emulator/phone,
  like a person would, and then checks what reached the server through the API.

  Prerequisites: as for android-smoke.ps1 (app open in Expo Go, adb sees one device, a throw-away test server reachable from the
  device, Android 17 local-network permission granted), plus a FRESHLY SEEDED test database with the syllabus loaded and no attempts.
  The script creates a day, one paper with two questions, two attempts and one theory entry through the API first, so there is
  something to look at and correct. WRITES DATA to the server you point it at.

  Usage:  powershell -File scripts\android-more-smoke.ps1 -Server http://10.0.2.2:8081 -ApiFromPc http://127.0.0.1:8081 -Email ... -Password "..." -OutDir shots
#>
param(
    [Parameter(Mandatory)] [string]$Server,
    [Parameter(Mandatory)] [string]$ApiFromPc,
    [Parameter(Mandatory)] [string]$Email,
    [Parameter(Mandatory)] [string]$Password,
    [string]$OutDir = 'android-shots'
)
. (Join-Path $PSScriptRoot 'android-lib.ps1')

# Independent access to the server from this PC, used to set things up and to check what the phone really saved.
$login = Invoke-RestMethod -Method Post -Uri "$ApiFromPc/api/v1/auth/login" -ContentType 'application/json' -Body (@{ email = $Email; password = $Password; device_name = 'android-more-smoke' } | ConvertTo-Json)
$hdr = @{ Authorization = "Bearer $($login.token)"; Accept = 'application/json' }
function Server-Get($path) { Invoke-RestMethod -Uri "$ApiFromPc/api/v1$path" -Headers $hdr }
function Server-Send($method, $path, $body) { Invoke-RestMethod -Method $method -Uri "$ApiFromPc/api/v1$path" -Headers $hdr -ContentType 'application/json' -Body ($body | ConvertTo-Json -Depth 5) }
if ((Server-Get '/attempts').meta.total -ne 0) { throw 'The test database already has attempts; re-seed it first.' }

$boot = Server-Get '/bootstrap'
$today = $boot.today
$physics = $boot.subjects | Where-Object { $_.name -match 'Physics' } | Select-Object -First 1
$topics = (Server-Get "/topics?subject_id=$($physics.id)").data
$topicA = $topics[0]; $topicB = $topics[1]
Server-Send 'Put' "/daily-logs/$today" @{ minutes = @{ school = 300; tuition = 0; theory = 120; papers = 120 }; is_rest_day = $false; rating = 3; notes = $null } | Out-Null
$paper = (Server-Send 'Post' '/papers' @{ subject_id = $physics.id; exam_year = ($boot.paper_years.to - 1); paper_name = 'Paper I' }).paper
$q1 = (Server-Send 'Post' "/papers/$($paper.id)/questions" @{ question_no = '1'; max_marks = 10; topic_ids = @($topicA.id) }).question
$q2 = (Server-Send 'Post' "/papers/$($paper.id)/questions" @{ question_no = '2'; max_marks = 10; topic_ids = @($topicB.id) }).question
$a1 = (Server-Send 'Post' '/attempts' @{ question_id = $q1.id; attempt_date = $today; marks_earned = 9; result = 'correct'; independent = $true; worked_minutes = 30 }).attempt
$a2 = (Server-Send 'Post' '/attempts' @{ question_id = $q2.id; attempt_date = $today; marks_earned = 4; result = 'wrong'; independent = $false }).attempt
Server-Send 'Post' '/theory' @{ study_date = $today; topic_id = $topicB.id; minutes = 40; marked_complete = $false; confidence = 2 } | Out-Null
function Topic-Status($id) { ((Server-Get "/topics?subject_id=$($physics.id)").data | Where-Object { $_.id -eq $id }).status }

function Go-Back { Hide-Keyboard; & $adb shell input keyevent 4; Start-Sleep -Seconds 1 }

Check 'Sign in' {
    $signedOut = $null
    try { $signedOut = Wait-For 'Server address' -Seconds 15 } catch { }
    if (-not $signedOut) { Wait-For 'More' -Contains -Seconds 30 | Out-Null; return 'already signed in' }
    Type-Text 'Server address' $Server
    Type-Text 'E-mail' $Email
    Type-Text 'Password' $Password
    & $adb shell input keyevent 66
    Wait-For 'More' -Contains -Seconds 40 | Out-Null
    Hide-Keyboard
    'signed in'
}
Check 'More tab offers Syllabus, Reports and History' {
    Tap 'More' -Contains
    Wait-For 'Syllabus' | Out-Null
    Reach 'Reports' | Out-Null
    Reach 'History and corrections' | Out-Null
    Shot 'd01-more'
    'three links shown'
}
Check 'Syllabus: change a topic status, the server has it' {
    Tap 'Syllabus'
    Wait-For 'Subject: Physics' | Out-Null
    Tap 'Subject: Physics'
    Wait-For 'topics. Theory done' -Contains | Out-Null
    Tap 'Unit 1' -Contains
    Tap "$($topicB.name)," -Contains
    Choose 'Status: In Progress'
    Reach 'Save status' | Out-Null
    Shot 'd02-topic-editor'
    Tap 'Save status'
    Start-Sleep -Seconds 3
    Reach 'is updated' -Contains | Out-Null
    Shot 'd03-topic-saved'
    $s = Topic-Status $topicB.id
    if ($s -ne 'in_progress') { throw "status on the server: $s" }
    "server shows $($topicB.name) as In Progress"
}
Check 'Syllabus: Mastered without evidence is refused' {
    Tap "$($topicB.name)," -Contains
    Choose 'Status: Mastered'
    Reach 'Save status' | Out-Null
    Tap 'Save status'
    Start-Sleep -Seconds 3
    Reach 'Not changed' -Contains | Out-Null
    Shot 'd04-mastered-refused'
    $s = Topic-Status $topicB.id
    if ($s -eq 'mastered') { throw 'the server accepted Mastered without evidence' }
    "refused; server still $s"
}
Check 'Reports: the numbers match the server' {
    Go-Back
    Tap 'Reports'
    Wait-For 'Days logged' | Out-Null
    $r = Server-Get "/reports?type=weekly&anchor=$today"
    $want = "$($r.consistency.days_logged) of $($r.consistency.days_in_range)"
    $n = Find-Node $want
    if (-not $n) { Reach $want | Out-Null }
    Shot 'd05-report'
    $q = Server-Get "/reports?type=weekly&anchor=$today"
    $wantQ = "$($q.questions.attempts) on $($q.questions.distinct)"
    Reach $wantQ -Contains | Out-Null
    "days $want, questions $wantQ"
}
Check 'Reports: monthly adds its sections' {
    Scroll-Up; Scroll-Up
    Tap 'Report: Monthly'
    Reach 'Syllabus coverage' | Out-Null
    Shot 'd06-report-monthly'
    'coverage shown'
}
Check 'History: correct an attempt' {
    Go-Back
    Tap 'History and corrections'
    Wait-For 'Correct attempt 1 of question 1' | Out-Null
    Shot 'd07-history'
    Tap 'Correct attempt 1 of question 1'
    Type-Text 'Marks you scored (out of 10)' '8.5'
    Hide-Keyboard
    Choose 'Did you do it without help?: No, with help or hints'
    Tap 'Save correction'
    Start-Sleep -Seconds 3
    Reach 'is corrected' -Contains | Out-Null
    Shot 'd08-corrected'
    $list = Server-Get '/attempts'
    if ($list.meta.total -ne 2) { throw "attempts on the server: $($list.meta.total)" }
    $x = $list.data | Where-Object { $_.id -eq $a1.id }
    if ($x.marks_earned -ne 8.5 -or $x.independent -or $x.attempt_no -ne 1) { throw "attempt: $($x | ConvertTo-Json -Compress)" }
    $s = Topic-Status $topicA.id
    "server has attempt 1 as 8.5/10, with help; still 2 attempts; its topic is now $s"
}

""
'===== ANDROID PHASE D SMOKE TEST ====='
$results | ForEach-Object { '{0}  {1}  -  {2}' -f ($(if ($_.Ok) { 'PASS' } else { 'FAIL' })), $_.Name, $_.Note }
$ok = @($results | Where-Object { $_.Ok }).Count
"`n$ok/$($results.Count) steps passed. Screenshots in $OutDir"
if ($ok -ne $results.Count) { exit 1 }
