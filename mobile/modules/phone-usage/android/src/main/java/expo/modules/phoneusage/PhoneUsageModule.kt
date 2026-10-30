package expo.modules.phoneusage

import android.app.AppOpsManager
import android.app.usage.UsageEvents
import android.app.usage.UsageStatsManager
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Process
import android.provider.Settings
import expo.modules.kotlin.exception.Exceptions
import expo.modules.kotlin.modules.Module
import expo.modules.kotlin.modules.ModuleDefinition
import java.time.LocalDate
import java.time.ZoneId

/**
 * Reads how many minutes each app was in the foreground on one calendar day (Android "Usage access").
 * It returns ONLY the app's name, its package id and the minutes. It never reads messages, links, videos or screen content.
 */
class PhoneUsageModule : Module() {
  private val context: Context
    get() = appContext.reactContext ?: throw Exceptions.ReactContextLost()

  override fun definition() = ModuleDefinition {
    Name("PhoneUsage")

    Function("isAccessGranted") { accessGranted() }

    // Opens Android's "Usage access" list, where the student turns ezexam on or off.
    Function("openAccessSettings") {
      val intent = Intent(Settings.ACTION_USAGE_ACCESS_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
      context.startActivity(intent)
    }

    // date = "YYYY-MM-DD" in the server's time zone (zoneId, e.g. "Asia/Colombo"), so the day matches the website's day.
    AsyncFunction("getDay") { date: String, zoneId: String -> usageForDay(date, zoneId) }
  }

  private fun accessGranted(): Boolean {
    val appOps = context.getSystemService(Context.APP_OPS_SERVICE) as AppOpsManager
    val mode = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
      appOps.unsafeCheckOpNoThrow(AppOpsManager.OPSTR_GET_USAGE_STATS, Process.myUid(), context.packageName)
    } else {
      @Suppress("DEPRECATION")
      appOps.checkOpNoThrow(AppOpsManager.OPSTR_GET_USAGE_STATS, Process.myUid(), context.packageName)
    }
    return mode == AppOpsManager.MODE_ALLOWED
  }

  private fun usageForDay(date: String, zoneId: String): List<Map<String, Any>> {
    if (!accessGranted()) return emptyList()

    val zone = ZoneId.of(zoneId)
    val day = LocalDate.parse(date)
    val start = day.atStartOfDay(zone).toInstant().toEpochMilli()
    val end = minOf(day.plusDays(1).atStartOfDay(zone).toInstant().toEpochMilli(), System.currentTimeMillis())
    if (end <= start) return emptyList()

    val usm = context.getSystemService(Context.USAGE_STATS_SERVICE) as UsageStatsManager
    val events = usm.queryEvents(start, end)
    val event = UsageEvents.Event()

    // Per app: how many of its screens are open now, when the app came to the foreground, and the total so far.
    val resumed = HashMap<String, Int>()
    val since = HashMap<String, Long>()
    val millis = HashMap<String, Long>()
    val seen = HashSet<String>()

    fun add(pkg: String, ms: Long) {
      if (ms > 0) millis[pkg] = (millis[pkg] ?: 0L) + ms
    }

    while (events.hasNextEvent()) {
      events.getNextEvent(event)
      val pkg = event.packageName ?: continue
      val time = event.timeStamp
      when (event.eventType) {
        1 -> { // an app screen came to the foreground (ACTIVITY_RESUMED / MOVE_TO_FOREGROUND)
          val n = resumed[pkg] ?: 0
          if (n == 0) since[pkg] = time
          resumed[pkg] = n + 1
          seen.add(pkg)
        }
        2 -> { // an app screen left the foreground (ACTIVITY_PAUSED / MOVE_TO_BACKGROUND)
          val n = resumed[pkg] ?: 0
          if (n > 0) {
            resumed[pkg] = n - 1
            if (n - 1 == 0) add(pkg, time - (since.remove(pkg) ?: time))
          } else if (!seen.contains(pkg)) {
            // It was already open when this day began (for example left open past midnight).
            add(pkg, time - start)
          }
          seen.add(pkg)
        }
        26 -> { // the phone shut down: everything that was open stops here
          for ((p, s) in since) add(p, time - s)
          since.clear(); resumed.clear()
        }
      }
    }
    // Apps still open right now (or at midnight) count up to the end of the window.
    for ((p, s) in since) add(p, end - s)

    val pm = context.packageManager
    // Skip only the phone's own home screen. (Many apps, including Android Settings, also declare a "fallback home"
    // component, so excluding everything that can act as a home screen would wrongly hide them.)
    val homeApps = setOfNotNull(
      pm.resolveActivity(Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_HOME), PackageManager.MATCH_DEFAULT_ONLY)
        ?.activityInfo?.packageName
    )

    val out = ArrayList<Map<String, Any>>()
    for ((pkg, ms) in millis) {
      val minutes = ((ms + 30_000L) / 60_000L).toInt()
      if (minutes < 1) continue
      if (pkg in homeApps || pkg == "android" || pkg == "com.android.systemui") continue
      // Only apps a person can open from the home screen (skips keyboards, system services and uninstalled apps).
      if (pm.getLaunchIntentForPackage(pkg) == null) continue
      val label = try {
        pm.getApplicationLabel(pm.getApplicationInfo(pkg, 0)).toString()
      } catch (e: PackageManager.NameNotFoundException) {
        continue
      }
      out.add(mapOf("package" to pkg, "label" to label, "minutes" to minOf(minutes, 1440)))
    }
    out.sortByDescending { it["minutes"] as Int }
    return out.take(300)
  }
}
