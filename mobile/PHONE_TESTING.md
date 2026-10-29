# Testing the app on your Android phone (Phase E)

Use your real phone with the free **Expo Go** app. Your phone and this PC must be on the **same Wi-Fi**.

## 1. One-time setup
1. Install **Expo Go** from the Play Store on your phone.
2. Start MySQL (XAMPP Control Panel).
3. In the project folder, create your sign-in (choose your own password; it is stored only in your database):
   ```
   php artisan tracker:create-user you@example.com --name="Your name"
   ```
   It asks for the password. Do not share it with anyone, including me.

## 2. Each time you test
Open two terminals in the project folder.

**Terminal 1 – the server** (this PC's Wi-Fi address is currently `192.168.0.110`; check with `ipconfig` if it changed):
```
php artisan serve --host=0.0.0.0 --port=8080
```

**Terminal 2 – the app:**
```
cd mobile
npx expo start
```
Scan the QR code with Expo Go (Android: open Expo Go, "Scan QR code").

The two use different ports on purpose: **Expo uses 8081, the Laravel server uses 8080**. If both are on 8081, Expo Go shows "Something went wrong".

On the sign-in screen:
- Server address: `http://192.168.0.110:8080`
- your e-mail and password
- tap **Test connection** under More if it does not connect.

If sign-in times out: Android 17 may need Expo Go's "Nearby devices / local network" permission (App info → Permissions), and Windows may ask to allow PHP through the firewall (allow on **Private** networks).

> This uses your REAL database. Entries you save are real. To rehearse first, use a copy: set `DB_DATABASE` to a test database before starting the server.

## 3. Checklist – tick what works, note what does not
- [ ] Sign in; the Today screen opens
- [ ] Today: enter the four times, Save day; reopen the app, the day is still there
- [ ] Dashboard shows the hours you saved (planned hours say "not set" until you enter them)
- [ ] Practice → Record a question: add a paper and a question you type yourself, save an attempt
- [ ] Practice → Theory study: save an entry
- [ ] Practice → Mistakes to review opens
- [ ] More → Syllabus: pick a subject, change a topic's status; "Mastered" is refused without proof
- [ ] More → Reports: weekly and monthly open and show your numbers
- [ ] More → History and corrections: correct an attempt and a theory entry
- [ ] Turn Wi-Fi off, try to save: you get a clear "no connection" message and nothing half-saved
- [ ] Rotate the phone; dark mode; large text size – is everything still readable and tappable?
- [ ] Anything slow, confusing, cut off or hard to tap? (write it down with the screen name)

Send me the unticked items and notes; I will fix them before we build the installable APK (Phase F).
