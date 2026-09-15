<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/icons.php';   // the role cards draw icons
require_once __DIR__ . '/models/Target.php';

auth_boot();

$error = '';
$email = '';
$selectedRole = '';
$showStep3 = false;

// If already logged in, check if Admin still needs to step through Academic
// Year Selection this login (the active year itself is a system-wide value,
// not a session one — see active_academic_year() in models/Target.php).
if (is_logged_in()) {
    $currUser = current_user();
    if ($currUser && $currUser['role'] === 'Admin' && !admin_year_gate_passed()) {
        $showStep3 = true;
        $selectedRole = 'Admin';
    } else {
        redirect('/dashboard.php');
    }
}

// Fetch available roles for the role selector
$roles = [
    'Admin'       => ['icon' => 'shield',     'desc' => 'Full system access, manage users & departments'],
    'Principal'   => ['icon' => 'eye',        'desc' => 'Institution-wide overview & reports'],
    'Dean'        => ['icon' => 'award',      'desc' => 'Academic oversight & institution-wide approvals'],
    'HoD'         => ['icon' => 'graduation', 'desc' => 'Department head, approve records'],
    'Coordinator' => ['icon' => 'target',     'desc' => 'Upload data & generate reports'],
    'Faculty'     => ['icon' => 'user',       'desc' => 'Submit academic records & track status'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Your session or security token has expired. Please try logging in again.';
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $selectedRole = trim((string) input('role'));
    } else {
        $email        = trim((string) input('email'));
        $password     = (string) input('password');
        $selectedRole = trim((string) input('role'));

        if ($selectedRole === '') {
            $error = 'Please select your role before logging in.';
        } else {
            $failReason = null;
            $user = attempt_login($email, $password, $selectedRole, $failReason);

            if ($user) {
                admin_year_gate_set();
                redirect('/dashboard.php');
            } elseif ($failReason === 'deactivated') {
                $error = 'Your account has been deactivated. Please contact an administrator.';
            } elseif ($failReason && str_starts_with($failReason, 'role_mismatch:')) {
                $actualRole = substr($failReason, 14);
                $error = 'The selected role does not match your account. You are registered as "' . htmlspecialchars($actualRole) . '".';
            } else {
                $error = 'Invalid email or password.';
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login · ATTS IQAC</title>
  <meta name="theme-color" content="#131D3B">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
  <style>
    /* ---- Role picker (step 1) -------------------------------------------
       Cards two-up; the last one spans the row when the count is odd, so the
       grid never ends on a ragged half-row. Descriptions wrap rather than
       truncate, so nothing about a role is hidden. */
    .role-selector { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
    .role-card {
      position:relative; display:flex; align-items:center; gap:11px;
      padding:12px 26px 12px 13px; border:1px solid var(--hairline); border-radius:var(--r-lg);
      cursor:pointer; background:var(--surface);
      transition:border-color var(--dur) var(--ease), background var(--dur) var(--ease),
                 box-shadow var(--dur) var(--ease), transform var(--dur) var(--ease-out);
    }
    .role-card:hover { border-color:var(--navy-200); box-shadow:var(--shadow-card); transform:translateY(-1px); }
    .role-card.selected { border-color:var(--orange-500); background:var(--orange-50); box-shadow:var(--ring); transform:none; }
    .role-card.selected .role-icon { background:var(--orange-500); color:#fff; box-shadow:var(--shadow-brand); }
    .role-card input { position:absolute; opacity:0; pointer-events:none; }
    .role-icon {
      width:34px; height:34px; border-radius:var(--r-md); flex-shrink:0;
      display:grid; place-items:center; background:var(--navy-50); color:var(--navy-600);
      transition:background var(--dur) var(--ease), color var(--dur) var(--ease), box-shadow var(--dur) var(--ease);
    }
    .role-card .role-txt  { display:block; min-width:0; }
    .role-card .role-name { display:block; font-size:13px; font-weight:600; color:var(--ink); line-height:1.2; }
    .role-card .role-desc { display:block; font-size:10.5px; color:var(--ink-faint); line-height:1.35; margin-top:2px; }
    .role-card:last-child:nth-child(odd) { grid-column:span 2; }
    /* A tick in the corner, so the choice is not carried by colour alone. */
    .role-tick {
      position:absolute; top:8px; right:8px; width:16px; height:16px; border-radius:var(--r-pill);
      background:var(--orange-500); color:#fff; display:grid; place-items:center;
      opacity:0; transform:scale(.6);
      transition:opacity var(--dur) var(--ease), transform var(--dur) var(--ease-out);
    }
    .role-card.selected .role-tick { opacity:1; transform:scale(1); }

    /* ---- Step indicator -------------------------------------------------- */
    .login-steps { display:flex; gap:6px; margin:18px 0 22px; }
    .login-step { flex:1; height:3px; border-radius:var(--r-pill); background:var(--navy-100);
      overflow:hidden; position:relative; }
    .login-step::after {
      content:""; position:absolute; inset:0; border-radius:inherit; background:var(--orange-500);
      transform:scaleX(0); transform-origin:left; transition:transform var(--dur-slow) var(--ease-out);
    }
    .login-step.active::after { transform:scaleX(1); }

    .step-label { font-size:12.5px; font-weight:500; color:var(--ink-muted); margin-bottom:10px; }
    .step-content { animation:fadeInUp .34s var(--ease-out); }
    @keyframes fadeInUp {
      from { opacity:0; transform:translateY(8px); }
      to   { opacity:1; transform:translateY(0); }
    }
    .login-back { display:flex; align-items:center; gap:10px; margin-bottom:18px; }
    .btn-block { width:100%; }

    @media (max-width:420px) {
      .role-selector { grid-template-columns:1fr; }
      .role-card:last-child:nth-child(odd) { grid-column:auto; }
    }

    /* Password field with show/hide toggle */
    .password-field-wrap {
      position:relative;
      display:flex;
      align-items:center;
      width:100%;
    }
    .password-field-wrap .input {
      padding-right:44px;
    }
    .password-toggle-btn {
      position:absolute;
      right:8px;
      top:50%;
      transform:translateY(-50%);
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:32px;
      height:32px;
      background:none;
      border:none;
      border-radius:8px;
      color:var(--ink-muted);
      cursor:pointer;
      transition:color .15s ease, background-color .15s ease;
      padding:0;
    }
    .password-toggle-btn:hover {
      color:var(--orange-500);
      background:var(--orange-50);
    }
    .password-toggle-btn:focus-visible { outline:none; box-shadow:var(--ring); }
    .password-toggle-btn svg {
      display:block;
    }
  </style>
</head>
<body>
<div class="login-wrap">

  <div class="login-brand">
    <div class="flex items-center gap-3">
      <div class="brand-logo">A</div>
      <div>
        <div class="brand-title">ATTS</div>
        <div class="brand-sub">IQAC Portal</div>
      </div>
    </div>
    <div>
      <h2>Academic Target<br>Tracking System</h2>
      <p>Configure targets, record achievements, and generate IQAC reports across the institution.</p>
      <ul class="login-points">
        <li><span class="pt-ic"><?= icon('target', 14) ?></span> Set and track departmental targets</li>
        <li><span class="pt-ic"><?= icon('approvals', 14) ?></span> Review and approve faculty records</li>
        <li><span class="pt-ic"><?= icon('reports', 14) ?></span> Generate IQAC proformas on demand</li>
      </ul>
    </div>
    <div class="foot">Internal Quality Assurance Cell</div>
  </div>

  <div class="login-form-side">
    <div class="login-card">
      <h1 id="loginTitle">Login</h1>
      <p class="lead" id="loginSubtitle">Select your role and enter credentials to access the portal.</p>

      <!-- Step indicators -->
      <div class="login-steps">
        <div class="login-step active" id="step1-bar"></div>
        <div class="login-step" id="step2-bar"></div>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-error"><?= icon('alert-triangle', 16) ?><span><?= e($error) ?></span></div>
      <?php endif; ?>

      <form method="post" id="loginForm">
        <?= csrf_field() ?>
        <input type="hidden" name="role" id="roleInput" value="<?= e($selectedRole) ?>">

        <!-- Step 1: Role selector -->
        <div id="step1" class="step-content">
          <div class="step-label">Select your role</div>
          <div class="role-selector">
            <?php foreach ($roles as $roleName => $info): ?>
              <label class="role-card <?= $selectedRole === $roleName ? 'selected' : '' ?>" data-role="<?= e($roleName) ?>">
                <input type="radio" name="role_select" value="<?= e($roleName) ?>" <?= $selectedRole === $roleName ? 'checked' : '' ?>>
                <span class="role-icon"><?= icon($info['icon'], 16) ?></span>
                <span class="role-txt">
                  <span class="role-name"><?= e($roleName) ?></span>
                  <span class="role-desc"><?= e($info['desc']) ?></span>
                </span>
                <span class="role-tick"><?= icon('check', 10) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn btn-primary btn-block" id="nextBtn" style="margin-top:14px" <?= $selectedRole ? '' : 'disabled' ?>>
            Continue <?= icon('arrow-right', 16) ?>
          </button>
        </div>

        <!-- Step 2: Credentials -->
        <div id="step2" class="step-content" style="display:none">
          <div class="login-back">
            <button type="button" class="btn btn-ghost btn-sm" id="backBtn" style="padding:0 10px">
              <?= icon('arrow-left', 14) ?> Back
            </button>
            <span class="badge badge-brand" id="selectedRoleBadge"></span>
          </div>

          <div class="field">
            <label for="email">Email</label>
            <input class="input" type="email" id="email" name="email" placeholder="you@college.edu"
                   autocomplete="username" value="<?= e($email) ?>" required>
          </div>

          <div class="field">
            <label for="password">Password</label>
            <div class="password-field-wrap">
              <input class="input" type="password" id="password" name="password" placeholder="••••••••" required autocomplete="current-password">
              <button type="button" class="password-toggle-btn" id="togglePasswordBtn" aria-label="Show password" title="Show password">
                <span class="eye-show"><?= icon('eye', 18) ?></span>
                <span class="eye-hide" style="display:none;"><?= icon('eye-off', 18) ?></span>
              </button>
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-block" id="step2SubmitBtn" style="height:46px; margin-top:8px">
            Login
          </button>
        </div>
      </form>
    </div>
  </div>

</div>

<script>
  const roleCards = document.querySelectorAll('.role-card');
  const roleInput = document.getElementById('roleInput');
  const nextBtn = document.getElementById('nextBtn');
  const backBtn = document.getElementById('backBtn');
  const step2SubmitBtn = document.getElementById('step2SubmitBtn');
  const step1 = document.getElementById('step1');
  const step2 = document.getElementById('step2');
  const step1Bar = document.getElementById('step1-bar');
  const step2Bar = document.getElementById('step2-bar');
  const roleBadge = document.getElementById('selectedRoleBadge');
  const emailInput = document.getElementById('email');
  const passwordInput = document.getElementById('password');
  const loginForm = document.getElementById('loginForm');
  const togglePasswordBtn = document.getElementById('togglePasswordBtn');
  const eyeShow = togglePasswordBtn ? togglePasswordBtn.querySelector('.eye-show') : null;
  const eyeHide = togglePasswordBtn ? togglePasswordBtn.querySelector('.eye-hide') : null;

  roleCards.forEach(card => {
    card.addEventListener('click', () => {
      roleCards.forEach(c => c.classList.remove('selected'));
      card.classList.add('selected');
      card.querySelector('input').checked = true;
      roleInput.value = card.dataset.role;
      nextBtn.disabled = false;
    });
  });

  nextBtn.addEventListener('click', () => {
    step1.style.display = 'none';
    step2.style.display = 'block';
    step2.style.animation = 'none';
    step2.offsetHeight; // trigger reflow
    step2.style.animation = 'fadeInUp .3s ease';
    step2Bar.classList.add('active');
    roleBadge.textContent = roleInput.value;
    emailInput.focus();
  });

  backBtn.addEventListener('click', () => {
    step2.style.display = 'none';
    step1.style.display = 'block';
    step1.style.animation = 'none';
    step1.offsetHeight;
    step1.style.animation = 'fadeInUp .3s ease';
    step2Bar.classList.remove('active');

    // Reset password toggle to masked when returning to role selection
    if (passwordInput.type === 'text') {
      passwordInput.type = 'password';
      if (eyeShow && eyeHide) {
        eyeShow.style.display = 'inline-flex';
        eyeHide.style.display = 'none';
      }
      togglePasswordBtn.setAttribute('aria-label', 'Show password');
      togglePasswordBtn.title = 'Show password';
    }
  });

  // Show / hide password toggle
  if (togglePasswordBtn && passwordInput) {
    togglePasswordBtn.addEventListener('click', () => {
      const isCurrentlyPassword = passwordInput.type === 'password';
      passwordInput.type = isCurrentlyPassword ? 'text' : 'password';

      if (eyeShow && eyeHide) {
        eyeShow.style.display = isCurrentlyPassword ? 'none' : 'inline-flex';
        eyeHide.style.display = isCurrentlyPassword ? 'inline-flex' : 'none';
      }

      const newLabel = isCurrentlyPassword ? 'Hide password' : 'Show password';
      togglePasswordBtn.setAttribute('aria-label', newLabel);
      togglePasswordBtn.title = newLabel;
      passwordInput.focus();
    });
  }

  // Handle form submissions
  loginForm.addEventListener('submit', (e) => {
    if (step2SubmitBtn && step2SubmitBtn.disabled) {
      e.preventDefault();
      return;
    }
    if (step2SubmitBtn) {
      step2SubmitBtn.disabled = true;
      step2SubmitBtn.textContent = 'Logging In...';
    }
  });

  // Refresh page if restored from browser back-forward cache (bfcache)
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
      window.location.reload();
    }
  });

  // If there's an error and a role was selected, show step 2
  <?php if ($error && $selectedRole): ?>
    step1.style.display = 'none';
    step2.style.display = 'block';
    step2Bar.classList.add('active');
    roleBadge.textContent = '<?= e($selectedRole) ?>';
    emailInput.focus();
  <?php endif; ?>
</script>
</body>
</html>
