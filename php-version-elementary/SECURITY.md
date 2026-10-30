# QRAttendance — Security Architecture

**Build:** Elementary / Primary only. The general build (primary + secondary + tertiary) lives in the sibling `php-version/` folder. Level selection is removed here — every account is elementary by design — so the level picker cannot be abused to select a different privilege tier.

**Threat model:** a single professor (trusted operator) plus many students (semi-trusted), on shared hosting, over the public internet. Assets: credentials, attendance records (used for academic decisions), personal data (names, emails, student IDs), and the integrity of the check-in process (students must not be able to mark themselves or others present).

**Approach:** defense in depth. Every control below is one layer; no single layer is load-bearing.

---

## 1. Authentication

### 1.1 Password storage
| | |
|---|---|
| **Threats mitigated** | Database disclosure → credential theft; rainbow tables; offline brute force |
| **Implementation** | `password_hash($password, PASSWORD_DEFAULT)` (bcrypt, auto-upgrading cost). Verification via `password_verify()`, which is constant-time. Passwords are never logged, echoed, or emailed. Minimum length 8 enforced at registration and reset. |

### 1.2 Session management
| | |
|---|---|
| **Threats mitigated** | Session fixation, session hijacking, XSS cookie theft, CSRF |
| **Implementation** | `session_regenerate_id(true)` on every successful login (destroys the old ID). Cookies set with `httponly` (no JS access), `secure` (HTTPS only, auto-detected via `IS_HTTPS`), `samesite=Lax` (CSRF), and `path=/`. `session.use_strict_mode=1` prevents adoption of attacker-supplied session IDs. Server-side `uid` is the only authority; no role is trusted from the client. |

### 1.3 Brute-force / credential stuffing lockout
| | |
|---|---|
| **Threats mitigated** | Online password guessing, credential stuffing, account enumeration via differential responses |
| **Implementation** | Failures are tracked **per (email + IP)** in the `login_attempts` table. **After 6 failures the pair is locked for 30 minutes** (`login_lock_remaining()`, `login_record_failure()`, `login_reset_failures()` in `config.php`). |
| **Details** | The lockout is checked **before** any `password_verify()` call, so a locked attempt costs no CPU. `REMOTE_ADDR` is used rather than `X-Forwarded-For` because it cannot be spoofed by the client — a forged header would let an attacker rotate IPs and reset the counter. Errors are **generic and identical** for "no such email" and "wrong password", so the response cannot be used to enumerate accounts. The counter is cleared on success. |
| **Failure mode** | The helpers **fail open** (allow the attempt) if the table is missing, so an un-run migration can never lock legitimate users out — availability is preserved at the cost of temporarily reduced brute-force protection. |

### 1.4 Password reset
| | |
|---|---|
| **Threats mitigated** | Account takeover via reset link; token replay; token leakage |
| **Implementation** | `forgot-password.php` generates a **256-bit random token** (`random_bytes(32)`), stores only its **SHA-256 hash**, with a **60-minute TTL**, and deletes any prior token for that user. `reset-password.php` marks the token `used_at` in the same statement that validates it, making it **single-use**. The response is identical whether or not the email exists. |
| **Known limitation** | This deployment has **no outbound mail**, so the link is displayed on-screen rather than emailed. On a host with `mail()`, wire `$resetUrl` into an email before relying on this flow. |

---

## 2. Authorization

### 2.1 Role enforcement
| | |
|---|---|
| **Threats mitigated** | Privilege escalation; a student reaching professor-only functions |
| **Implementation** | `require_role($role)` on every page; role is read from the server-side session on each request. Professor and student page trees are separate (`dashboard.php`, `classes.php`, … vs `student/…`). |

### 2.2 Object-level authorization (IDOR)
| | |
|---|---|
| **Threats mitigated** | A professor reading or mutating another professor's classes, rosters, sessions, readers, or holidays |
| **Implementation** | Ownership helpers `owns_term()`, `owns_subject()`, `owns_section()`, `owns_session()` are called before every read or mutation, always resolving through `terms.professor_id` — the single ownership root that every other table inherits. The calendar API (`api/attendance_calendar.php`) re-verifies that the **section belongs to the professor** *and* that the **student is on that section's roster**, so a guessed student ID cannot be used to probe another class. Holiday deletes are scoped `WHERE id = ? AND professor_id = ?`. |

### 2.3 Reader (IoT) authentication
| | |
|---|---|
| **Threats mitigated** | Forged check-ins; a browser being used to spoof the door reader; replay of a stolen reader |
| **Implementation** | Reader endpoints require **POST + JSON** and the exact `User-Agent: QRAttendance-ESP32/1.0` (`require_reader_request()`). `device_token` is 32 random bytes, stored **only as a SHA-256 hash**, and compared with `hash_equals()` (constant time). Revocation is a `revoked_at` timestamp checked on every call. Reader endpoints deliberately do **not** use CSRF — the device token is the authenticator. |

---

## 3. Input handling

### 3.1 SQL injection
| | |
|---|---|
| **Threats mitigated** | SQL injection (all variants) |
| **Implementation** | **Every** query goes through `q($sql, $params)` → PDO prepared statements with `ATTR_EMULATE_PREPARES => false` (native server-side prepares). No user input is ever concatenated into SQL. `IN (...)` clauses are built with generated placeholders, never by string interpolation. |

### 3.2 Cross-site scripting (XSS)
| | |
|---|---|
| **Threats mitigated** | Stored and reflected XSS |
| **Implementation** | **Every** dynamic output passes through `e()` (`htmlspecialchars` with `ENT_QUOTES`). This includes names, emails, subject codes, section labels, session titles, holiday names and URLs. Inline event handlers are never used; all behaviour is in external JS. CSP (below) is the backstop. |

### 3.3 CSRF
| | |
|---|---|
| **Threats mitigated** | Cross-site request forgery on state-changing forms |
| **Implementation** | `csrf_field()` emits a per-session 256-bit token; `csrf_check()` validates it with `hash_equals()` on **every** POST, and regenerates it on login. Reader endpoints are exempt because they authenticate with the device token instead of a cookie. |

### 3.4 File upload
| | |
|---|---|
| **Threats mitigated** | Unrestricted upload → remote code execution; DoS via oversized files; content-type spoofing |
| **Implementation** | Three layers. (1) `save_avatar_upload()`: 2 MB limit, MIME type verified with **`finfo`** (content inspection, not the client-supplied extension), allow-list of jpg/png/webp/gif, and a **randomised filename** (`u{id}-{12 random bytes}`). (2) The previous file is deleted, preventing orphan accumulation. (3) `uploads/.htaccess` disables the PHP engine and denies any script extension in that directory, so even a bypassed upload cannot execute. |

### 3.5 CSV handling
| | |
|---|---|
| **Threats mitigated** | CSV injection (formula execution when opened in Excel); malformed-file DoS |
| **Implementation** | `export.php` prefixes any cell starting with `=`, `+`, `-` or `@` with an apostrophe, and writes a UTF-8 BOM. Import is line-by-line with `fgetcsv()`, capped at 500 rows, with per-field trimming and BOM stripping. |

### 3.6 Redirect handling
| | |
|---|---|
| **Threats mitigated** | Open redirect (phishing) |
| **Implementation** | `$safeNext` accepts only values beginning with a single `/` and rejects `//` (protocol-relative), so `next` can never point off-origin. |

---

## 4. Transport & browser hardening

| Control | Threat mitigated | Implementation |
|---|---|---|
| HTTPS enforcement | MITM, credential interception, camera blocking | `.htaccess` 301-redirects all HTTP to HTTPS |
| CSP | XSS, data exfiltration, clickjacking | `Content-Security-Policy`: `default-src 'self'`, `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'self'` |
| `X-Frame-Options: SAMEORIGIN` | Clickjacking | `.htaccess` |
| `X-Content-Type-Options: nosniff` | MIME confusion | `.htaccess` |
| `Referrer-Policy` | URL/query leakage to third parties | `.htaccess` |
| `Permissions-Policy` | Unnecessary API exposure | Only `camera=(self)` granted; microphone/geolocation/payment denied |
| Directory listing off | Information disclosure | `Options -Indexes` |
| Source/config files denied | Source disclosure | `vendor/`, `*.sql`, `*.md`, `config*.php`, `migrate.php` blocked in `.htaccess` |
| HSTS | SSL stripping | Present but **commented out** — enable only after confirming HTTPS works, then leave on |

**CSP note:** `script-src` includes `'unsafe-inline'` because pages use inline `<script>` blocks (the calendar renderer and scanner config). No inline event handlers exist. Moving all JS to external files would allow removing `'unsafe-inline'` entirely — the single highest-value remaining hardening step.

---

## 5. Application-integrity controls (attendance)

### 5.1 QR passes
| | |
|---|---|
| **Threats mitigated** | Forged check-ins; screenshot/replay of another student's pass; sharing a pass with an absent friend |
| **Implementation** | Token is **256 bits** of `random_bytes`, stored **only as a SHA-256 hash**, **single-use** (`used_at` set in the same transaction that validates, with `SELECT … FOR UPDATE` to close the race), and expires after **90 seconds**. Passes are bound to a specific `session_id`, and the server verifies the session is `open`, unexpired, and owned by the scanning professor. |

### 5.2 Elementary permanent QR
| | |
|---|---|
| **Threats mitigated** | Brute-force guessing of the semester code |
| **Implementation** | Codes are **96 bits** (`random_bytes(12)`) with a `UNIQUE` constraint. Check-in still resolves the student's **own roster row in the currently open session**, and `UNIQUE(session_id, student_id)` plus `INSERT IGNORE` make a repeat scan a no-op. A lost/stolen code can be regenerated, and the old one stops matching. |

### 5.3 Attendance uniqueness
| | |
|---|---|
| **Threats mitigated** | Double-marking; inflated attendance |
| **Implementation** | `UNIQUE(session_id, student_id)` enforced in the database, not just in application logic. |

### 5.4 Reader rate limiting
| | |
|---|---|
| **Threats mitigated** | Tap flooding / log spam from a compromised reader |
| **Implementation** | Rolling **30 taps per minute** per reader, persisted in `rfid_readers.rate_count` with a window start, so the limit survives restarts. |

### 5.5 Database resilience
| | |
|---|---|
| **Threats mitigated** | 500s and information leakage from schema drift |
| **Implementation** | `current_user()` inspects `information_schema` and selects only columns that exist, so a not-yet-run migration degrades gracefully instead of fataling. The Composer autoloader and `qr_data_uri()` are guarded so a missing `vendor/` produces a readable message rather than a blank 500. Errors returned to users are generic; detail goes to the server log only. |

---

## 6. Residual risks / recommended next steps

1. **Move all JavaScript to external files** and drop `'unsafe-inline'` from `script-src`. Highest-value remaining XSS hardening.
2. **Enable HSTS** once HTTPS is confirmed stable.
3. **Add real email delivery** (PHPMailer + SMTP) for the password-reset flow.
4. **Set a restrictive `session.save_path`** — on shared hosting, PHP's default session directory can be world-readable.
5. **Enable an error log and monitor it**; currently `display_errors` behaviour depends on host defaults.
6. **Per-student data isolation**: a professor can currently see only their own roster (correct), but consider auditing `students.user_id` linkage if multiple professors ever share a campus roster.
7. **Rotate `DB_*` credentials** out of `config.php` into environment variables or a file outside the web root when the host allows it.

---

## 7. Running the security migration

The lockout and holiday tables must exist before those features work:

```
phpMyAdmin → import schema_security_holidays.sql
```
or upload `migrate.php`, open it as the professor, press **Apply all changes**, then **delete `migrate.php`**.
