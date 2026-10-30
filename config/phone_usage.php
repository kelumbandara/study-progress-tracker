<?php

/**
 * How phone apps are grouped on the dashboard. Matching is by Android package name (exact), then by prefix.
 * Anything not listed is "other". The phone sends only the app name and minutes, never any content.
 */
return [
    'categories' => ['study', 'messaging', 'social', 'video', 'games', 'browser', 'other'],

    'exact' => [
        'net.edusl.ezexam' => 'study',
        'com.google.android.apps.classroom' => 'study',
        'org.khanacademy.android' => 'study',
        'com.duolingo' => 'study',
        'com.whatsapp' => 'messaging', 'com.whatsapp.w4b' => 'messaging', 'org.telegram.messenger' => 'messaging',
        'org.thoughtcrime.securesms' => 'messaging', 'com.facebook.orca' => 'messaging', 'com.viber.voip' => 'messaging',
        'com.imo.android.imoim' => 'messaging', 'com.google.android.apps.messaging' => 'messaging', 'com.discord' => 'messaging',
        'com.facebook.katana' => 'social', 'com.facebook.lite' => 'social', 'com.instagram.android' => 'social',
        'com.zhiliaoapp.musically' => 'social', 'com.ss.android.ugc.trill' => 'social', 'com.twitter.android' => 'social',
        'com.snapchat.android' => 'social', 'com.reddit.frontpage' => 'social', 'com.pinterest' => 'social', 'com.linkedin.android' => 'social',
        'com.google.android.youtube' => 'video', 'com.google.android.apps.youtube.music' => 'video', 'com.netflix.mediaclient' => 'video',
        'com.vimeo.android.videoapp' => 'video', 'in.startv.hotstar' => 'video', 'com.amazon.avod.thirdpartyclient' => 'video',
        'com.android.chrome' => 'browser', 'org.mozilla.firefox' => 'browser', 'com.microsoft.emmx' => 'browser',
        'com.opera.browser' => 'browser', 'com.brave.browser' => 'browser', 'com.sec.android.app.sbrowser' => 'browser',
    ],

    // Common game publishers and package prefixes.
    'prefix' => [
        'com.supercell.' => 'games', 'com.king.' => 'games', 'com.garena.' => 'games', 'com.tencent.ig' => 'games',
        'com.activision.' => 'games', 'com.mojang.' => 'games', 'com.roblox.' => 'games', 'com.dts.freefireth' => 'games',
        'com.miniclip.' => 'games', 'com.ea.game.' => 'games', 'com.epicgames.' => 'games',
    ],

    'max_apps_per_day' => 300,
];
