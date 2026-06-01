<?php
require_once __DIR__ . '/includes/auth.php';
if (current_teacher_id()) {
    header('Location: ' . (is_admin() ? 'admin.php' : (is_chair() ? 'chair.php' : 'dashboard.php'))); exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = login_user(trim($_POST['email'] ?? ''), $_POST['password'] ?? '');
    if ($result['ok']) {
        header('Location: ' . ($result['role'] === 'admin' ? 'admin.php' : ($result['role'] === 'chair' ? 'chair.php' : 'dashboard.php'))); exit;
    } elseif (!empty($result['pending'])) {
        $error = 'Your account is pending approval. The administrator needs to approve your account before you can log in.';
    } else {
        $error = 'Invalid email or password. Please try again.';
    }
}
$ss         = school_settings();
$logoPath   = $ss['logo_path']       ?? '';
$schoolName = $ss['school_name']     ?? 'GradeFlow';
$subtitle   = $ss['system_subtitle'] ?? 'GradeFlow Grading System';
$accent     = htmlspecialchars($ss['web_accent']     ?? '#c97b1f');
$ink        = htmlspecialchars($ss['web_ink']        ?? '#1d2433');
$paper      = htmlspecialchars($ss['web_paper']      ?? '#f5f0e6');
$card       = htmlspecialchars($ss['web_card']       ?? '#fffdf8');
$muted      = htmlspecialchars($ss['web_muted']      ?? '#495066');
$webFont    = $ss['web_font'] ?? 'Outfit';
$googleFontMap = [
    'Outfit'=>'Outfit:wght@300;400;500;600;700','Inter'=>'Inter:wght@300;400;500;700',
    'Lato'=>'Lato:wght@300;400;700','Merriweather'=>'Merriweather:wght@300;400;700',
    'Roboto'=>'Roboto:wght@300;400;500;700',
];
$fontImport = isset($googleFontMap[$webFont])
    ? "@import url('https://fonts.googleapis.com/css2?family={$googleFontMap[$webFont]}&display=swap');"
    : '';
$fontFamily = isset($googleFontMap[$webFont]) ? "'{$webFont}', system-ui, sans-serif" : 'system-ui, sans-serif';
?>
<!doctype html><html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in — <?= htmlspecialchars($schoolName) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
<?= $fontImport ?>
:root { --amber: <?= $accent ?>; --ink: <?= $ink ?>; --paper: <?= $paper ?>; --card: <?= $card ?>; --ink-soft: <?= $muted ?>; }
body, input, select, button { font-family: <?= $fontFamily ?>; }
</style>
</head>
<body>
<div class="auth-wrap">
  <div class="card auth-card" style="max-width:440px">
    <!-- School branding -->
    <div class="logo" style="margin-bottom:20px">
      <?php if ($logoPath && file_exists($logoPath)): ?>
        <img src="<?= htmlspecialchars($logoPath) ?>"
             style="max-height:72px;margin:0 auto 10px;display:block;object-fit:contain" alt="Logo">
      <?php endif; ?>
      <div class="big" style="font-size:2rem"><?= htmlspecialchars($schoolName) ?></div>
      <?php if ($subtitle): ?>
        <div class="tag" style="margin-top:4px;font-size:.88rem">
          <?= htmlspecialchars($subtitle) ?>
        </div>
      <?php endif; ?>
    </div>
    <!-- Role indicators -->
    <div style="display:flex;gap:8px;justify-content:center;margin-bottom:18px;flex-wrap:wrap">
      <span style="background:var(--blue-soft);color:var(--blue);padding:5px 10px;border-radius:8px;font-size:.82rem;font-weight:500">
        &#128101; Teacher
      </span>
      <span class="muted" style="align-self:center">&amp;</span>
      <span style="background:var(--amber-soft);color:var(--amber);padding:5px 10px;border-radius:8px;font-size:.82rem;font-weight:500">
        &#128274; Admin
      </span>
      <span class="muted" style="align-self:center;font-size:.82rem">use this same login</span>
    </div>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post">
      <div class="field">
        <label>Email Address</label>
        <input type="email" name="email" required autofocus placeholder="your@email.com"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" required placeholder="••••••••">
      </div>
      <button class="btn btn-primary" style="width:100%;justify-content:center;padding:12px" type="submit">
        Sign In
      </button>
    </form>
    <hr style="border:none;border-top:1px solid var(--line);margin:18px 0">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:.82rem">
      <div style="background:var(--blue-soft);border-radius:10px;padding:10px 12px">
        <strong style="color:var(--blue)">&#128101; Teachers</strong>
        <div class="muted" style="margin-top:4px">Go to their class dashboard to manage grades</div>
      </div>
      <div style="background:var(--amber-soft);border-radius:10px;padding:10px 12px">
        <strong style="color:var(--amber)">&#128274; Admins</strong>
        <div class="muted" style="margin-top:4px">Go to the admin panel to view all teachers' records</div>
      </div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:18px;flex-wrap:wrap;gap:8px">
      <a href="register.php" class="muted" style="font-size:.88rem">New teacher? Create account</a>
      <a href="install.php" class="muted" style="font-size:.82rem">Setup / Install</a>
    </div>
  </div>
</div>
<footer style="display:block;width:100%;text-align:center;font-size:11px;color:#aaa;padding:10px 0 14px;margin-top:16px;letter-spacing:.02em;">Copyright &copy; 2026 Arnel Maghinay. All rights reserved.</footer>
</body></html>
