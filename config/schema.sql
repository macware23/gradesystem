-- ============================================================
-- GradeFlow - Dynamic Grading & Records System
-- Database schema (MySQL / MariaDB - works on XAMPP/WAMP)
-- ============================================================
-- Transmutation model: Term -> Criterion -> Activities (Q1..Qn),
-- each activity has its own perfect score; raw scores are transmuted
-- to equivalents via the MagsEquivalent formula, averaged per
-- criterion, weighted, and summed into the term grade.
-- ============================================================

CREATE DATABASE IF NOT EXISTS gradeflow
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gradeflow;

-- ---- Teachers ----
CREATE TABLE IF NOT EXISTS teachers (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(150) NOT NULL,
  email         VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('teacher','admin','chair') NOT NULL DEFAULT 'teacher',
  approved      TINYINT(1) NOT NULL DEFAULT 0,
  college       VARCHAR(150) DEFAULT '',
  department    VARCHAR(150) DEFAULT '',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---- Classes / Subjects ----
-- cutoff & zero_equiv drive the transmutation per class.
CREATE TABLE IF NOT EXISTS classes (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id    INT NOT NULL,
  subject_code  VARCHAR(50),
  subject_name  VARCHAR(150) NOT NULL,
  section       VARCHAR(50),
  term_system   VARCHAR(50)  DEFAULT 'Prelim,Midterm,Finals',
  school_year   VARCHAR(20),
  semester      VARCHAR(20),
  passing_grade DECIMAL(5,2) DEFAULT 75.00,
  cutoff        DECIMAL(5,2) DEFAULT 50.00,   -- raw % that maps to 75
  zero_equiv    DECIMAL(5,2) DEFAULT 65.00,   -- grade for a raw score of 0
  use_transmutation TINYINT(1) DEFAULT 1,     -- 1 = transmute, 0 = plain %
  is_archived   TINYINT(1) NOT NULL DEFAULT 0, -- 0 = active, 1 = archived
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---- Criteria (Quizzes, Term Exam, Outputs, Class Participation) ----
-- Each belongs to a term and carries a weight (%). Weights within a
-- term should total 100.
CREATE TABLE IF NOT EXISTS criteria (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  class_id    INT NOT NULL,
  term        VARCHAR(50)  NOT NULL DEFAULT 'All',
  name        VARCHAR(120) NOT NULL,
  weight      DECIMAL(6,3) NOT NULL,
  sort_order  INT DEFAULT 0,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---- Activities (Q1, Q2, P1, CP1 ...) under each criterion ----
-- Each activity has its OWN perfect score. This is the new layer
-- that matches the Excel sheet (multiple columns per criterion).
CREATE TABLE IF NOT EXISTS activities (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  criterion_id  INT NOT NULL,
  label         VARCHAR(60) NOT NULL,       -- e.g. 'Q1', 'P1', 'CP1'
  perfect_score DECIMAL(10,2) NOT NULL DEFAULT 100.00,
  sort_order    INT DEFAULT 0,
  FOREIGN KEY (criterion_id) REFERENCES criteria(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---- Term weights -> Final grade ----
CREATE TABLE IF NOT EXISTS term_weights (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  class_id  INT NOT NULL,
  term      VARCHAR(50)  NOT NULL,
  weight    DECIMAL(6,3) NOT NULL,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---- Students ----
CREATE TABLE IF NOT EXISTS students (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  class_id    INT NOT NULL,
  student_no  VARCHAR(50),
  last_name   VARCHAR(100) NOT NULL,
  first_name  VARCHAR(100) NOT NULL,
  email       VARCHAR(150),
  sort_order  INT DEFAULT 0,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---- Scores: one raw score per (student, activity) ----
-- The atomic grade cell. Equivalents are computed, not stored.
CREATE TABLE IF NOT EXISTS scores (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  student_id   INT NOT NULL,
  activity_id  INT NOT NULL,
  raw_score    DECIMAL(10,2),
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_cell (student_id, activity_id),
  FOREIGN KEY (student_id)  REFERENCES students(id)   ON DELETE CASCADE,
  FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---- Cached analysis (optional) ----
CREATE TABLE IF NOT EXISTS analysis_cache (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  student_id   INT NOT NULL,
  payload      JSON,
  generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---- Demo teacher (password: demo1234) ----
INSERT INTO teachers (full_name, email, password_hash)
SELECT 'Demo Teacher', 'demo@gradeflow.local',
       '$2y$10$K8Elt.0zx0fReiCeSYcKMe668iRNegvakSwxHux15A7ZeWtMLAiKS'
WHERE NOT EXISTS (SELECT 1 FROM teachers WHERE email='demo@gradeflow.local');

-- Attendance sessions (one row per class meeting)
CREATE TABLE IF NOT EXISTS attendance_sessions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  class_id    INT NOT NULL,
  term        VARCHAR(50) NOT NULL,
  session_date DATE,
  label       VARCHAR(80) NOT NULL,
  sort_order  INT DEFAULT 0,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Attendance records: P=Present, A=Absent, L=Late
CREATE TABLE IF NOT EXISTS attendance_records (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  session_id  INT NOT NULL,
  student_id  INT NOT NULL,
  status      ENUM('P','A','L') NOT NULL DEFAULT 'P',
  UNIQUE KEY uniq_record (session_id, student_id),
  FOREIGN KEY (session_id)  REFERENCES attendance_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id)  REFERENCES students(id)            ON DELETE CASCADE
) ENGINE=InnoDB;

-- Role column for teachers (teacher | admin)
-- ALTER only needed when upgrading; fresh installs get it from CREATE TABLE below.
-- For existing installs: ALTER TABLE teachers ADD COLUMN IF NOT EXISTS role ENUM('teacher','admin') NOT NULL DEFAULT 'teacher';

-- School-wide settings
CREATE TABLE IF NOT EXISTS school_settings (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  setting_key  VARCHAR(80) NOT NULL UNIQUE,
  setting_val  TEXT
) ENGINE=InnoDB;

INSERT IGNORE INTO school_settings (setting_key, setting_val) VALUES
  ('school_name','My School'),('school_address',''),('logo_path',''),
  ('web_accent','#c97b1f'),('web_ink','#1d2433'),
  ('pdf_header_bg','29,36,51'),('pdf_accent_rgb','201,123,31'),
  ('pdf_pass_rgb','47,125,84'),('pdf_fail_rgb','178,59,59');

-- New settings (v5+)
INSERT IGNORE INTO school_settings (setting_key, setting_val) VALUES
  ('system_subtitle', 'GradeFlow Grading System'),
  ('pdf_body_font',   'Helvetica'),
  ('pdf_title_font',  'Times'),
  ('pdf_text_rgb',    '0,0,0'),
  ('web_font',        'Outfit'),
  ('web_text_color',  '#1d2433');

-- Per-user settings (colors, fonts — each teacher/admin has their own)
CREATE TABLE IF NOT EXISTS user_settings (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id  INT NOT NULL,
  setting_key VARCHAR(80)  NOT NULL,
  setting_val TEXT,
  UNIQUE KEY  uniq_user_setting (teacher_id, setting_key),
  FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- school_settings holds ONLY global keys:
-- school_name, school_address, logo_path, system_subtitle
-- All other settings (colors, fonts) live in user_settings per teacher.

-- College and Department lookup tables (for dropdowns)
CREATE TABLE IF NOT EXISTS colleges (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(200) NOT NULL UNIQUE,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS departments (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  college_id INT NOT NULL,
  name       VARCHAR(200) NOT NULL,
  sort_order INT DEFAULT 0,
  FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Multiple college+department assignments per Program Chair
CREATE TABLE IF NOT EXISTS chair_assignments (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  chair_id   INT NOT NULL,
  college    VARCHAR(150) NOT NULL,
  department VARCHAR(150) NOT NULL,
  FOREIGN KEY (chair_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB;
