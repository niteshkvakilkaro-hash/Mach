# Website live karne ki guide (Hostinger)

Yeh guide aapki website + CRM ko aapke apne domain par (jaise `https://www.aapkadomain.in`) live karne ke liye hai.
Har step ek baar karna hai. Kul samay: lagbhag **1–2 ghante**.

> Hostinger ke menu ke naam kabhi-kabhi thode badal jaate hain. Agar koi option na mile to hPanel ke upar wale **search box** me uska naam likhiye (jaise "SSL", "Cron", "MySQL").

---

## Aapko kya chahiye

| Cheez | Kyun |
|---|---|
| Hostinger **Premium / Business** web hosting (ya koi bhi PHP + MySQL hosting) | Website aur database chalane ke liye |
| Ek domain (jaise `aapkadomain.in`) jo Hostinger se juda ho | Website ka address |
| Aapka computer jisme XAMPP chal raha ho | Files aur database taiyaar karne ke liye |

**Zaroori:** hosting me **PHP 8.2 ya naya**, **MySQL/MariaDB** aur **.htaccess** support hona chahiye (Hostinger me teeno hote hain).

---

## Poora kaam ek nazar me

```
[Aapka computer]                          [Hostinger]
1. Zip + database file banao    ──►  3. SSL (HTTPS) chalu karo
                                     4. PHP settings
                                     5. Database banao
                                     6. Database file import karo
                                     7. Files upload karo
                                     8. config files me details bharo
                                     9. Demo data hatao
                                    10. Admin password/email badlo
                                    11. Business details, logo, reviews
                                    12. Email (SMTP) setup + test
                                    13. Cron jobs (emails + reminders)
                                    14. Poori website test karo
                                    15. Google par submit karo
```

---

## Step 1 — Upload ki files banao (aapke computer par)

1. XAMPP Control Panel me **Apache** aur **MySQL** chalu hon.
2. Command Prompt kholiye aur yeh chalaiye:

```bat
cd C:\xampp\htdocs\mach
C:\xampp\php\php.exe tools\build-deploy.php
```

3. Isse `C:\xampp\htdocs\mach\deploy\` folder me do files banengi:

| File | Kya hai |
|---|---|
| `mach-live.zip` | Website ki saari files (security wali `.htaccess` files samet). Docs, tools aur test files isme nahi hoti. |
| `mach-database.sql` | Aapka database — services, prices, settings, FAQs sab (demo leads bhi, jo Step 9 me hatenge) |

> Agar aapne local admin me services/settings badli hain to woh bhi isi file me chali jaati hain. Isliye pehle local par sab theek karke phir yeh step chalaiye.

**Agar script chal na paaye:** phpMyAdmin (`http://localhost/phpmyadmin`) → left me `mach_crm` → **Export** → Quick → SQL → **Export**. Is file ko `mach-database.sql` naam de dijiye.

---

## Step 2 — Yeh files upload NAHI karni

Zip script inhe pehle se chhod deta hai. Haath se upload kar rahe hain to dhyaan rakhiye:

`docs/` · `tools/` · `database/` · `deploy/` · `netlify-preview/` · `netlify-preview.zip` · `storage/logs/*.log`

---

## Step 3 — HTTPS (SSL) chalu karo

hPanel → **Websites** → aapki website → **Manage** → **Security → SSL**:

1. Aapke domain par **Install SSL** (free) — agar pehle se "Active" hai to kuch nahi karna.
2. **Force HTTPS** ko **ON** kijiye.

> HTTPS hone par login cookies apne aap "Secure" ho jaati hain aur browser ko HTTPS hi use karne ka nirdesh (HSTS) chala jaata hai. Bina HTTPS ke live mat kijiye.

---

## Step 4 — PHP settings

hPanel → **Advanced → PHP Configuration**:

1. **PHP Version:** `8.2` (ya `8.3`) chuniye → Save.
2. **PHP Options / PHP Extensions** me yeh ON hon: `pdo_mysql`, `mbstring`, `gd`, `fileinfo`, `zip` (aam taur par pehle se ON hote hain).
3. **PHP Options** me:

| Setting | Value |
|---|---|
| `upload_max_filesize` | `4M` |
| `post_max_size` | `10M` |
| `max_execution_time` | `60` |
| `display_errors` | `Off` |
| `expose_php` | `Off` (agar option ho) |

---

## Step 5 — Database banao

hPanel → **Databases → MySQL Databases**:

1. **Database name:** jaise `mach` likhiye → Hostinger ise `u123456789_mach` jaisa bana dega.
2. **Username:** jaise `machuser` → ban jaayega `u123456789_machuser`.
3. **Password:** **Generate** dabaiye (lamba aur random). **Teeno cheezein kahin save kar lijiye** — Step 8 me chahiye.
4. **Create** dabaiye.

> Yeh user sirf isi database ke liye hai — `root` jaisa sab-kuch wala user nahi. Yahi surakshit tareeka hai.

---

## Step 6 — Database import karo

1. Usi page par neeche aapka database dikhega → **Enter phpMyAdmin**.
2. Left me aapka database (`u123456789_mach`) select hona chahiye.
3. Upar **Import** → **Choose file** → `mach-database.sql` → neeche **Import** (ya Go).
4. Hara message aana chahiye: *"Import has been successfully finished"*. Left me ~35 tables dikhengi.

**Error aaye to:**

| Error | Upaay |
|---|---|
| `#1044 Access denied … to database 'mach_crm'` | Aapne galti se `database/schema.sql` import ki. Uski jagah `deploy/mach-database.sql` import kariye. |
| File bahut badi | Aam taur par nahi hoga (file ~200 KB hai). Ho to zip karke import kariye. |

---

## Step 7 — Files upload karo

hPanel → **Files → File Manager** → `public_html` folder kholiye.

1. `public_html` me pehle se koi `default.php` / `index.html` (Hostinger ka welcome page) ho to **delete** kar dijiye.
2. Upar **Upload** → `mach-live.zip` chuniye.
3. Upload hone ke baad zip par **right-click → Extract** → folder: `public_html` (khali/`.` rakhiye taaki files seedhe `public_html` me aayein, kisi `mach-live` sub-folder me nahi).
4. Extract ke baad zip file delete kar dijiye.
5. Check kijiye ki `public_html` me `index.php`, `admin/`, `includes/` aur **`.htaccess`** dikh rahe hain.
   > `.htaccess` chhupi file hai — File Manager ke **Settings → Show hidden files** ON kijiye.

**Folder permissions:** `uploads/` aur `storage/logs/` folder likhne layak hone chahiye (`755`). Hostinger par aam taur par pehle se hote hain.

---

## Step 8 — Config files me details bharo

File Manager me yeh do files **Edit** kijiye:

### `public_html/config/app.php`

```php
'url' => 'https://www.aapkadomain.in',   // apna domain — aakhir me / nahi
```

> Baaki kuch nahi badalna. Live server par "production mode" apne aap chalu ho jaata hai (visitors ko koi error detail nahi dikhegi — error `storage/logs/` me likhe jaate hain).
> Agar domain `www` ke bina chalta hai to `https://aapkadomain.in` likhiye — jo address browser me khulta hai, bilkul wahi.

### `public_html/config/database.php`

```php
'host'    => 'localhost',
'port'    => 3306,
'name'    => 'u123456789_mach',        // Step 5 wala database name
'user'    => 'u123456789_machuser',    // Step 5 wala username
'pass'    => 'Step-5-wala-password',
'charset' => 'utf8mb4',
```

Save kijiye. Ab `https://www.aapkadomain.in` kholiye — website dikhni chahiye.

---

## Step 9 — Demo data hatao (sirf ek baar)

phpMyAdmin → aapka database → **SQL** tab → apne computer ki file `C:\xampp\htdocs\mach\database\cleanup-demo.sql` Notepad me kholiye → sab copy karke yahan paste → **Go**.

Aakhri result me `leads, bookings, payments, customers, technicians` sab **0** hone chahiye.

Yeh hatata hai: saare demo leads, bookings, payments, customers, notifications, audit log, 4 demo technicians aur demo logins (`neha@…`, `ramesh@…`).
Yeh rakhta hai: services, prices, locations, FAQs, reviews, pages, coupons, settings aur aapka admin account.

> ⚠️ Yeh script sirf **live hone se pehle** chalaiye. Asli customers aane ke baad chalaya to unka data bhi mit jaayega.

---

## Step 10 — Admin login surakshit karo (sabse zaroori)

1. `https://www.aapkadomain.in/admin/login` → `admin@mach.local` / `Admin@12345`
2. **Staff** → apne account par ✏️ → **Login email** me apna asli email, **New password** me naya mazboot password (10+ akshar, letters + numbers) → Save.
3. Logout karke naye email/password se login kariye. Laal "default password" wala banner ab nahi dikhna chahiye.
4. Baaki staff ke account **Staff → Add staff** se banaiye (har vyakti ka alag account — kabhi password share mat kijiye).
5. Technicians: **Technicians → Add technician** → "Technician app login" ON → email + password. Technician isi `/admin/login` se login karega aur seedha apne app par pahunchega.

---

## Step 11 — Apni business details bharo

| Kahan | Kya karna hai |
|---|---|
| **Settings → Business & contact** | Asli naam, phone, WhatsApp (91 ke saath, jaise `919812345678`), email, address, working hours, Google Maps link |
| **Settings → Logo & images** | Logo (PNG), favicon, social share image |
| **Settings → Trust numbers** | ⚠️ Rating, reviews, jobs done, years, technicians — **sirf asli numbers** (Google Business profile se). Galat numbers dikhana graahakon ko gumraah karna hai aur Google ki policy ke khilaf hai. |
| **Testimonials** | ⚠️ Abhi wale reviews **sample** hain — unhe hatakar sirf asli graahakon ke reviews (unki anumati se) daaliye. |
| **Settings → Social media** | Facebook / Instagram / YouTube links |
| **Settings → Booking & leads** | Time slots, kitne din aage tak booking |
| **Settings → Feedback & complaints** | **Google review link** daaliye (Google Business Profile → "Ask for reviews" → link copy). Complaint ka jawab dene ka samay (SLA) — normal 4 ghante, urgent 2 ghante |
| **Services** | Prices, duration, warranty apne hisaab se; jo service nahi dete use "Hidden" kijiye |
| **Locations** | Jin areas me service dete hain |
| **Pages** | Privacy, Terms, Refund policy padhkar apne hisaab se badliye (zaroorat ho to kisi jaankar se check karwa lijiye) |

---

## Step 12 — Email chalu karo

Website se yeh emails jaate hain (har ek Settings me on/off):
- Naye lead par **aapko / team ko alert**
- Customer ko **"request mil gayi"** (agar form me email diya ho)
- Customer ko **booking confirmation** (date, time, technician)
- Customer ko **payment receipt**

### 12a. Email account banao (Hostinger)
hPanel → **Emails → Email Accounts** → apne domain par email banaiye, jaise `booking@aapkadomain.in`, aur password save kar lijiye.

> Gmail bhi chalega, par Gmail ka **normal password nahi chalta** — Google Account → Security → 2-Step Verification ON → **App passwords** se 16-akshar ka password banaiye.

### 12b. Admin me settings bharo
Admin → **Settings → Email & notifications**:

1. **Quick fill** me **Hostinger email** dabaiye (host `smtp.hostinger.com`, port `465`, SSL apne aap bhar jaayenge).
2. **Username:** `booking@aapkadomain.in` · **Password:** us email ka password
3. **"From" email:** wahi `booking@aapkadomain.in` · **"From" name:** aapke business ka naam
4. **Team email addresses:** jin par naye lead ka alert chahiye, comma laga kar (jaise `owner@gmail.com, sales@aapkadomain.in`)
5. **Send emails** ON → jo emails chahiye unke switch ON → **Save**.
6. Neeche **Send a test email** me apna email daal kar bhejiye. Inbox (aur **Spam**) check kijiye.
   - Error aaye to wahi screen par saaf kaaran likha aata hai (galat password, galat port wagairah).

> Password encrypt hokar save hota hai aur dobara screen par kabhi nahi dikhta. Badalna ho to naya likhiye; khali chhodne par purana hi rehta hai.

### 12c. Email spam me na jaaye
Hostinger email use kar rahe hain to hPanel → **Emails → (domain) → DNS / Email authentication** me **SPF, DKIM, DMARC** "Active" hone chahiye (Hostinger ke domain par aam taur par apne aap hote hain). Pehle kuch email Spam me jaayein to "Not spam" mark kijiye.

### 12d. "Recent emails" list
Usi page par neeche har bheja gaya email dikhta hai — Sent / Waiting / Failed. Mail server thodi der band ho to email **khoata nahi**: 5, 15 aur 60 minute baad apne aap dobara koshish hoti hai. "Retry failed" button se phir bhej sakte hain.

---

## Step 13 — Cron jobs (reminders + emails)

Emails aur reminders website/admin khulne par bhi chalte hain, par cron lagane se woh bina kisi ke page khole bhi samay par jaate hain.

hPanel → **Advanced → Cron Jobs** → **Create new** — do cron banaiye:

| Kaam | Command | Schedule |
|---|---|---|
| Email bhejna | `/usr/bin/php /home/u123456789/domains/aapkadomain.in/public_html/cron/send-emails.php` | Har 5 minute (`*/5 * * * *`) |
| Overdue follow-up + complaint SLA reminder | `/usr/bin/php /home/u123456789/domains/aapkadomain.in/public_html/cron/notify-overdue.php` | Har 10 minute (`*/10 * * * *`) |

> Hostinger me "PHP" type chunne par sirf path dena hota hai: `public_html/cron/send-emails.php`.
> `u123456789` aur domain apne hisaab se badaliye — sahi path File Manager me upar dikhta hai.

---

## Step 14 — Live test checklist

Ek-ek karke tick kijiye:

- [ ] `https://…` khulta hai, browser me 🔒 dikhta hai; `http://` apne aap `https://` par jaata hai
- [ ] Home, Services, ek service page, About, Contact, Book Service, Privacy — sab khulte hain
- [ ] Mobile par website theek dikhti hai; neeche Call / WhatsApp / Book buttons kaam karte hain
- [ ] **Book Service** form bhariye → "Thank you" page + reference number (`LEAD-2026-000001`)
- [ ] Admin → Leads me woh lead dikhta hai, bell 🔔 par notification aati hai
- [ ] Lead → **Schedule booking** → technician chuniye → booking banti hai
- [ ] Technician login (phone par) → job dikhta hai → "Accept job" se "Completed" tak
- [ ] Booking page → **Record payment** → Receipt khulti hai
- [ ] Booking **Completed** hone par booking page par "Customer feedback" box → WhatsApp se rating link bhejiye → 5★ dene par "Review us on Google" button aata hai
- [ ] Website footer → **Complaint / Warranty** form bhariye → `CMP-2026-000001` number milta hai, Admin → **Complaints** me dikhta hai
- [ ] Settings → Email → **Send test email** inbox me aata hai; booking form me apna email daalne par "Request received" email aata hai
- [ ] **Settings** me phone badliye → website par turant badalta hai (phir sahi wapas kariye)
- [ ] Yeh URLs **403 / 404** dene chahiye (khulne nahi chahiye):
  - `https://…/config/database.php`
  - `https://…/includes/db.php`
  - `https://…/storage/logs/`
  - `https://…/uploads/`
- [ ] `https://…/sitemap.xml` me aapke domain wale links hain (localhost nahi)

---

## Step 15 — Google par laao

1. **Google Search Console** (search.google.com/search-console) → apna domain add kijiye → **Sitemaps** → `sitemap.xml` submit.
2. **Google Business Profile** me website link daaliye — local search ke liye sabse zaroori. Wahi se "Ask for reviews" link copy karke **Settings → Feedback & complaints** me daaliye — khush graahak (4–5★) seedha Google review par jaayenge.
3. Google Ads / Facebook ads me links par UTM tags lagaiye (jaise `?utm_source=google&utm_medium=cpc&utm_campaign=ac-summer`) — CRM har lead ka source apne aap pehchaan lega aur **Reports → Leads** me dikhayega.

---

## Roz ka aur baad ka kaam

### Backup
- hPanel → **Files → Backups**: Hostinger rozana/weekly backup rakhta hai (plan ke hisaab se). Mahine me ek baar **Database backup download** karke apne paas bhi rakhiye.
- `uploads/` folder (logo, photos) bhi kabhi-kabhi download kar lijiye.

### Website me badlaav (update) karna
1. Local (XAMPP) par badlaav karke test kijiye.
2. Sirf badli hui files File Manager se upload kijiye (overwrite).
3. **Kabhi upload mat kijiye:** `config/app.php` aur `config/database.php` (live wale me asli details hain), `uploads/` (live photos mit jaayengi).
4. Database structure badla ho (`database/migrations/` me nayi file) to use phpMyAdmin → SQL me chalaiye.

### Error logs
Kuch gadbad ho to `public_html/storage/logs/app-YYYY-MM-DD.log` dekhiye — asli error wahan likha hota hai (visitors ko nahi dikhta).

---

## Samasya aur hal

| Samasya | Kaaran / hal |
|---|---|
| **Website par "Something went wrong"** | `storage/logs/` ka latest log dekhiye. Aksar `config/database.php` me galat password/name. |
| **Home khulta hai, baaki pages 404** | `.htaccess` upload nahi hui (hidden file). File Manager me "Show hidden files" ON karke check/upload kijiye. |
| **Saare pages 500 error** | `.htaccess` me koi line server support nahi karta. `LimitRequestBody` wali line ke aage `#` lagakar dekhiye. |
| **Login ke baad wapas login page** | `config/app.php` ka `url` browser ke address se match nahi karta (`www` / `https`). Bilkul same kijiye. |
| **CSS/design toota hua** | `url` galat hai, ya `assets/` folder upload nahi hua. |
| **Photo upload fail** | `uploads/` folder permission `755` kijiye; image 2 MB se chhoti ho; JPG/PNG/WEBP hi ho. |
| **Form: "session expired"** | Page refresh karke dobara bhejiye. Baar-baar ho to `url` aur HTTPS setting check kijiye. |
| **"Too many failed attempts"** | 5 galat password ke baad 15 minute rukna padta hai (security). |
| **Reminders nahi aa rahe** | Cron job ka path check kijiye (Step 13). |
| **Test email: "535 … Authentication failed"** | Username/password galat. Gmail ho to App Password chahiye (Step 12a). |
| **Test email: "Could not connect"** | Host/port/encryption galat. Hostinger: `smtp.hostinger.com`, `465`, SSL. |
| **Email jaata hai par Spam me** | SPF/DKIM check kijiye (Step 12c); "From" email wahi rakhiye jisse login kiya. |

---

## Abhi kya nahi hai (baad me jod sakte hain)

- **WhatsApp auto-message / SMS**: WhatsApp Business API provider chahiye.
- **Online payment (Razorpay / PhonePe)**, **GST invoice**, **AMC plans**, **customer login** — database in sab ke liye taiyaar hai, feature banana baaki hai.

---

*Security details: `docs/SECURITY.md` · System design: `docs/ARCHITECTURE.md`*
