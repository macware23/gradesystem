<?php
require_once __DIR__ . '/includes/auth.php';
$msg = ''; $ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (!$name || !$email || !$pass) {
        $msg = 'All fields are required.';
    } elseif ($pass !== $pass2) {
        $msg = 'Passwords do not match.';
    } else {
        [$ok, $msg] = register_teacher($name, $email, $pass, 'teacher');
    }
}

$ss = school_settings();
$accent = htmlspecialchars($ss['web_accent'] ?? '#c97b1f');
$ink    = htmlspecialchars($ss['web_ink']    ?? '#1d2433');
?>
<!doctype html><html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Teacher Registration — <?= htmlspecialchars($ss['system_subtitle'] ?? 'GradeFlow') ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=4">
<style>:root{--amber:<?= $accent ?>;--ink:<?= $ink ?>;}</style>
</head>
<body>
<div class="auth-wrap">
  <div class="card auth-card" style="max-width:440px">

    <div class="logo">
      <div class="big">Create Teacher Account</div>
      <div class="tag" style="margin-top:4px">GradeFlow — Grading &amp; Records System</div>
    </div>

    <!-- Role notice -->
    <div style="background:var(--blue-soft);border-radius:10px;padding:10px 14px;margin:16px 0;font-size:.88rem">
      <strong style="color:var(--blue)">&#128101; This creates a Teacher account.</strong>
      <div class="muted" style="margin-top:3px">
        Teachers can create classes, enter grades, and generate reports for their own subjects.
      </div>
    </div>

    <?php if ($msg): ?>
      <div class="alert <?= $ok ? 'alert-ok' : 'alert-error' ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if (!$ok): ?>
    <form method="post">
      <div class="field">
        <label>Full Name</label>
        <input name="name" required placeholder="e.g. Prof. Maria Santos"
               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Email Address</label>
        <input type="email" name="email" required placeholder="you@school.edu"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Password <span class="muted">(min 6 characters)</span></label>
        <input type="password" name="password" required placeholder="••••••••">
      </div>
      <div class="field">
        <label>Confirm Password</label>
        <input type="password" name="password2" required placeholder="••••••••">
      </div>
      <button class="btn btn-primary" style="width:100%;justify-content:center;padding:12px" type="submit">
        Create Teacher Account
      </button>
    </form>
    <?php else: ?>
      <a class="btn btn-primary" style="width:100%;justify-content:center;padding:12px" href="index.php">
        Go to Login →
      </a>
    <?php endif; ?>

    <hr style="border:none;border-top:1px solid var(--line);margin:18px 0">
    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;font-size:.85rem">
      <a href="index.php" class="muted">← Back to login</a>
      <a href="install.php" style="color:var(--amber)">
        &#128274; Create an Admin account instead
      </a>
    </div>

  </div>
</div>
<footer style="display:block;width:100%;text-align:center;font-size:11px;color:#aaa;padding:10px 0 14px;margin-top:16px;letter-spacing:.02em;">Copyright &copy; 2026 Arnel Maghinay. All rights reserved.</footer>
</body></html>
