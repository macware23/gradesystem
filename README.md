# GradeFlow — Dynamic Grading & Records System

A complete, offline grading system for teachers. Build any grading scheme you
like (custom criteria and weights per class), enter grades in a spreadsheet-style
grid (with copy-paste from Excel), generate PDF reports, and get **offline AI
intervention analysis** that tells you which students need help and where to
focus it.

Built with **PHP** (web UI, database, PDF reports via FPDF) + **Python**
(local statistical analysis). **No internet required. No API keys. Free.**

---

## What it does

- **Flexible criteria & weights** — Add any number of criteria per term
  (Quizzes, Projects, Exams, Recitation…), each with its own weight and max
  score. Set how much each term (Prelim/Midterm/Finals, or anything you define)
  counts toward the final grade. Weights are validated to total 100%.
- **Spreadsheet gradebook** — Click any cell to edit, navigate with arrow keys,
  and **paste a whole block** copied from Excel or Google Sheets directly onto
  the grid. Term grades update live as you type.
- **PDF reports** — One click generates a per-term report (criterion breakdown)
  or a final-grade report (all terms + pass/fail + class summary).
- **AI intervention analysis** — For each student, a local engine scores their
  intervention priority, detects grade trends, finds the weak areas that hurt
  the grade most (weighted by impact), flags missing work, and lists concrete,
  prioritized actions to help them improve.
- **Multi-teacher** — Each teacher has their own login and only sees their own
  classes. Any teacher can register and use it.

---

## Setup on XAMPP (Windows) — 5 minutes

1. **Install XAMPP** (if you haven't) from apachefriends.org. Start **Apache**
   and **MySQL** from the XAMPP Control Panel.

2. **Install Python** from python.org. During install, tick
   *"Add Python to PATH"*. (Used only for the offline analysis.)

3. **Copy the project** — Put the `gradesystem` folder into your XAMPP
   `htdocs` directory:
   ```
   C:\xampp\htdocs\gradesystem
   ```

4. **Check config** — Open `config/config.php`. The defaults already match a
   standard XAMPP install (host `127.0.0.1`, user `root`, empty password).
   - If Python isn't on your PATH, set the full path, e.g.
     `define('PYTHON_BIN', 'C:\\Python311\\python.exe');`

5. **Run the installer** — In your browser, go to:
   ```
   http://localhost/gradesystem/install.php
   ```
   It creates the database and tables and checks your environment. Green checks
   = ready.

6. **Sign in** — Go to `http://localhost/gradesystem/login.php`
   - Demo account: **demo@gradeflow.local** / **demo1234**
   - Or click *Create an account* to make your own.

---

## How to use it

1. **Create a class** (Dashboard → *New Class*). Set its terms (e.g.
   `Prelim,Midterm,Finals`) and passing grade.
2. **Set up criteria** (Gradebook → *Setup Criteria & Weights*). Add your
   components per term with weights that total 100%, and set the term weights
   that total 100%.
3. **Add students** (*+ Students*). Paste a list — one per line as
   `LastName, FirstName` (optionally `StudentNo, Last, First`).
4. **Enter grades** — Type in cells, or paste a block from Excel. Click
   *Save changes*.
5. **Final Grades tab** — See computed finals, pass/fail, and click *Analyze*
   on any student for the intervention report.
6. **PDF Report** button — Download term or final reports.

---

## How grades are computed

- **Term grade** = sum over criteria of `(score / max_score) × weight`.
  If a term is only partly graded, the running grade is scaled to the weight
  entered so far, so it stays meaningful mid-term.
- **Final grade** = weighted average of term grades using your term weights
  (equal split if you don't set them).
- A student passes if their final grade ≥ the class passing grade.

---

## Folder structure

```
gradesystem/
├─ install.php            ← run once to set up
├─ login.php / register.php / logout.php
├─ dashboard.php          ← list & create classes
├─ gradebook.php          ← the spreadsheet + analysis UI
├─ config/
│  ├─ config.php          ← DB credentials & Python path (edit here)
│  └─ schema.sql          ← database structure
├─ api/
│  ├─ data.php            ← all data operations (JSON)
│  ├─ analyze.php         ← PHP → Python analysis bridge
│  └─ report.php          ← FPDF report generator
├─ includes/
│  ├─ auth.php            ← login/session
│  ├─ grade_engine.php    ← grade math (one source of truth)
│  ├─ topbar.php          ← shared header
│  ├─ fpdf.php + font/    ← PDF library
├─ python/
│  └─ analyze.py          ← offline statistical analysis engine
└─ assets/
   ├─ css/style.css
   └─ js/app.js
```

---

## Notes & ideas for later

- The analysis is **decision-support**, not automated grading — it suggests,
  you decide.
- Easy future additions: student self-view portal, attendance, CSV/Excel
  export, charts of class performance, emailing reports, and grade-curve tools.
- To reset everything, drop the `gradeflow` database in phpMyAdmin and re-run
  `install.php`.

Enjoy — and tweak it freely to fit how you teach.
