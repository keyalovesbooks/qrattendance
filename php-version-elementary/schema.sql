-- QRAttendance MySQL schema (MySQL 5.7+/8.0, MariaDB 10.3+)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('professor','student') NOT NULL DEFAULT 'student',
  student_number VARCHAR(50) NULL,
  teacher_level VARCHAR(20) NULL,  -- professor: primary|secondary|tertiary
  primary_attendance_mode ENUM('subject','direct') NULL, -- primary professor workflow choice
  student_level VARCHAR(20) NULL, -- student:   elementary|highschool|college
  avatar VARCHAR(120) NULL,
  first_name VARCHAR(60) NULL,
  middle_name VARCHAR(60) NULL,
  last_name VARCHAR(60) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS terms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_year VARCHAR(20) NOT NULL,
  semester VARCHAR(30) NOT NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  professor_id INT UNSIGNED NULL,           -- storage is per professor
  UNIQUE KEY uq_term_owner (professor_id, school_year, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subjects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  term_id INT UNSIGNED NOT NULL,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(150) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_id INT UNSIGNED NULL,                  -- NULL for primary-teacher direct sections
  term_id INT UNSIGNED NOT NULL,
  professor_id INT UNSIGNED NOT NULL,
  section_mode ENUM('subject','direct') NOT NULL DEFAULT 'subject',
  name VARCHAR(60) NOT NULL,
  schedule VARCHAR(120) NOT NULL DEFAULT '',
  room VARCHAR(60) NOT NULL DEFAULT '',
  enroll_code CHAR(16) NOT NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sections_term (term_id),
  KEY idx_sections_professor (professor_id),
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE,
  FOREIGN KEY (professor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Roster rows (imported by CSV or added manually). user_id is linked when the student enrolls via QR.
-- Repeatable meeting times per section (a section can meet on several days)
CREATE TABLE IF NOT EXISTS section_schedules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  section_id INT UNSIGNED NOT NULL,
  day ENUM('Mon','Tue','Wed','Thu','Fri','Sat','Sun') NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  room VARCHAR(60) NOT NULL DEFAULT '',
  FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS students (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  section_id INT UNSIGNED NOT NULL,
  student_number VARCHAR(50) NOT NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NULL,
  user_id INT UNSIGNED NULL,
  enrolled_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  qr_code CHAR(24) NULL, -- permanent semester-long QR (elementary)
  UNIQUE KEY uq_roster (section_id, student_number),
  UNIQUE KEY uq_students_qr_code (qr_code),
  FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS class_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  section_id INT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  started_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  session_date DATE NULL,
  attendance_slot ENUM('am_in','am_out','pm_in','pm_out') NULL,
  UNIQUE KEY uq_section_attendance_slot (section_id, session_date, attendance_slot),
  FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qr_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL UNIQUE,
  session_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  FOREIGN KEY (session_id) REFERENCES class_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  scanned_at DATETIME NOT NULL,
  UNIQUE KEY uq_att (session_id, student_id),
  FOREIGN KEY (session_id) REFERENCES class_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-section time in / time out, morning and afternoon
CREATE TABLE IF NOT EXISTS section_attendance_times (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  section_id INT UNSIGNED NOT NULL,
  attendance_mode ENUM('time_in','time_out','both') NOT NULL DEFAULT 'both',
  am_in TIME NULL,
  am_out TIME NULL,
  pm_in TIME NULL,
  pm_out TIME NULL,
  UNIQUE KEY uq_section_attendance_times (section_id),
  FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  KEY idx_password_resets_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Brute-force protection: failed sign-in attempts per (email + IP).
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_login_attempt (email, ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- School holidays — dates shown yellow on the attendance calendar.
CREATE TABLE IF NOT EXISTS holidays (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  professor_id INT UNSIGNED NOT NULL,
  holiday_date DATE NOT NULL,
  name VARCHAR(120) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_holiday_owner_date (professor_id, holiday_date),
  FOREIGN KEY (professor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
