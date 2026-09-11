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
    'Director'    => ['icon' => 'eye',         'desc' => 'Institution-wide overview & reports'],
    'Dean'        => ['icon' => 'award',       'desc' => 'Academic oversight & institution-wide approvals'],
    'HoD'         => ['icon' => 'graduation',  'desc' => 'Department head, approve records'],
    'Coordinator' => ['icon' => 'target',      'desc' => 'Upload data & generate reports'],
    'Faculty'     => ['icon' => 'user',        'desc' => 'Submit academic records & track status'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Your session or security token has expired. Please try signing in again.';
        // Ensure a fresh token is generated for the new attempt
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $selectedRole = trim((string) input('role'));
    } else {
        // Check if an authenticated Admin is selecting academic year
        if (is_logged_in()) {
            $currUser = current_user();
            if ($currUser && $currUser['role'] === 'Admin') {
                if (input('action') === 'logout') {
                    logout();
                    redirect('/login.php');
                }
                $academicYear = trim((string) input('academic_year'));
                [$ok, $msg] = activate_academic_year($academicYear, (int) $currUser['id']);
                if ($ok) {
                    admin_year_gate_set();
                    redirect('/dashboard.php');
                } else {
                    $error = $msg;
                    $showStep3 = true;
                }
            }
        } else {
            $email        = trim((string) input('email'));
            $password     = (string) input('password');
            $selectedRole = trim((string) input('role'));
            $academicYear = trim((string) input('academic_year'));

            if ($selectedRole === '') {
                $error = 'Please select your role before signing in.';
            } else {
                $failReason = null;
                $user = attempt_login($email, $password, $selectedRole, $failReason);

                if ($user) {
                    if ($user['role'] === 'Admin') {
                        if ($academicYear !== '') {
                            [$ok, $msg] = activate_academic_year($academicYear, (int) $user['id']);
                        } else {
                            $ok = false; $msg = '';
                        }
                        if ($ok) {
                            admin_year_gate_set();
                            redirect('/dashboard.php');
                        } else {
                            // Credentials valid, now require academic year selection
                            $showStep3 = true;
                            if ($academicYear !== '') {
                                $error = $msg;
                            }
                        }
                    } else {
                        redirect('/dashboard.php');
                    }
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
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in · ATTS IQAC</title>
  <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
  <style>
    /* Role selector */
    .role-selector { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:8px; }
    .role-card {
      position:relative; display:flex; align-items:center; gap:10px;
      padding:12px 14px; border:2px solid var(--hairline); border-radius:12px;
      cursor:pointer; transition:all .2s ease; background:var(--surface);
    }
    .role-card:hover { border-color:var(--navy-300); background:var(--navy-50); }
    .role-card.selected { border-color:var(--orange-500); background:var(--orange-50); }
    .role-card.selected .role-icon { background:var(--orange-500); color:#fff; }
    .role-card input { position:absolute; opacity:0; pointer-events:none; }
    .role-icon {
      width:36px; height:36px; border-radius:10px; flex-shrink:0;
      display:grid; place-items:center; background:var(--navy-50); color:var(--navy-600);
      transition:all .2s ease;
    }
    .role-card .role-name { font-size:13px; font-weight:600; color:var(--ink); line-height:1.2; }
    .role-card .role-desc { font-size:10px; color:var(--ink-faint); line-height:1.3; margin-top:2px; }
    .role-card:last-child:nth-child(odd) { grid-column: span 2; }

    /* Animated glow for selected role */
    .role-card.selected::after {
      content:''; position:absolute; inset:-2px; border-radius:13px; z-index:-1;
      background:linear-gradient(135deg, var(--orange-200) 0%, transparent 60%);
      opacity:.3;
    }

    /* Step indicator */
    .login-steps { display:flex; gap:8px; margin-bottom:24px; }
    .login-step {
      flex:1; height:4px; border-radius:999px; background:var(--navy-100);
      transition:background .3s ease;
    }
    .login-step.active { background:var(--orange-500); }

    /* Animated transition for step content */
    .step-content { animation:fadeInUp .3s ease; }
    @keyframes fadeInUp {
      from { opacity:0; transform:translateY(8px); }
      to { opacity:1; transform:translateY(0); }
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
    </div>
    <div class="foot">Internal Quality Assurance Cell</div>
  </div>

  <div class="login-form-side">
    <div class="login-card" style="max-width:420px">
      <h1 id="loginTitle"><?= $showStep3 ? 'Select Academic Year' : 'Sign in' ?></h1>
      <p class="lead" id="loginSubtitle"><?= $showStep3 ? 'Choose the academic year you want to manage.' : 'Select your role and enter credentials to access the portal.' ?></p>

      <!-- Step indicators -->
      <div class="login-steps" style="margin-top:16px">
        <div class="login-step active" id="step1-bar"></div>
        <div class="login-step <?= $showStep3 ? 'active' : '' ?>" id="step2-bar"></div>
        <div class="login-step <?= $showStep3 ? 'active' : '' ?>" id="step3-bar" style="<?= ($selectedRole === 'Admin' || $showStep3) ? '' : 'display:none' ?>"></div>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom:16px"><?= e($error) ?></div>
      <?php endif; ?>

      <!-- Hidden logout form if authenticated admin clicks back -->
      <form method="post" id="logoutForm" style="display:none">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="logout">
      </form>

      <form method="post" id="loginForm">
        <?= csrf_field() ?>
        <input type="hidden" name="role" id="roleInput" value="<?= e($selectedRole) ?>">

        <!-- Step 1: Role selector -->
        <div id="step1" class="step-content" style="<?= $showStep3 ? 'display:none' : '' ?>">
          <div style="font-size:13px; font-weight:500; color:var(--ink-muted); margin-bottom:10px">Select your role</div>
          <div class="role-selector">
            <?php foreach ($roles as $roleName => $info): ?>
              <label class="role-card <?= $selectedRole === $roleName ? 'selected' : '' ?>" data-role="<?= e($roleName) ?>">
                <input type="radio" name="role_select" value="<?= e($roleName) ?>" <?= $selectedRole === $roleName ? 'checked' : '' ?>>
                <div class="role-icon"><?= icon($info['icon'], 16) ?></div>
                <div>
                  <div class="role-name"><?= e($roleName) ?></div>
                  <div class="role-desc"><?= e($info['desc']) ?></div>
                </div>
              </label>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn btn-primary" id="nextBtn" style="width:100%; height:44px; margin-top:12px" <?= $selectedRole ? '' : 'disabled' ?>>
            Continue
          </button>
        </div>

        <!-- Step 2: Credentials -->
        <div id="step2" class="step-content" style="display:none">
          <div style="display:flex; align-items:center; gap:8px; margin-bottom:16px;">
            <button type="button" class="btn btn-ghost btn-sm" id="backBtn" style="padding:0 8px; height:32px;">
              ← Back
            </button>
            <div style="flex:1">
              <span class="badge badge-brand" id="selectedRoleBadge" style="font-size:12px"></span>
            </div>
          </div>

          <div class="field">
            <label for="email">Email</label>
            <input class="input" type="email" id="email" name="email" placeholder="you@college.edu"
                   value="<?= e($email) ?>">
          </div>

          <div class="field">
            <label for="password">Password</label>
            <input class="input" type="password" id="password" name="password" placeholder="••••••••">
          </div>

          <!-- If Admin, show Continue to Step 3; otherwise Sign In directly -->
          <button type="button" class="btn btn-primary" id="step2NextBtn" style="width:100%; height:48px; margin-top:8px; display:none">
            Continue
          </button>
          <button type="submit" class="btn btn-primary" id="step2SubmitBtn" style="width:100%; height:48px; margin-top:8px">
            Sign In
          </button>
        </div>

        <!-- Step 3: Academic Year Selection (Admin Only) -->
        <div id="step3" class="step-content" style="<?= $showStep3 ? 'display:block' : 'display:none' ?>">
          <div class="field" style="margin-top:4px;">
            <label for="academic_year" style="font-size:13px; font-weight:600; color:var(--ink); margin-bottom:8px; display:block;">
              Select Academic Year
            </label>
            <div style="position:relative;">
              <select class="select" id="academic_year" name="academic_year" style="width:100%; height:46px; border-radius:10px; font-size:14px; padding:0 36px 0 14px; appearance:none; -webkit-appearance:none; background-color:var(--surface);">
                <?php
                $currentYear = current_academic_year();
                $defaultYear = active_academic_year();   // whatever is active now, not always "today's" year
                foreach (academic_years() as $ay): ?>
                  <option value="<?= e($ay) ?>" <?= $ay === $defaultYear ? 'selected' : '' ?>>
                    <?= e($ay) ?><?= $ay === $currentYear ? ' (Current)' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div style="position:absolute; right:14px; top:50%; transform:translateY(-50%); pointer-events:none; font-size:11px; color:var(--ink-muted);">
                ▼
              </div>
            </div>
            <div style="font-size:11px; color:var(--ink-faint); margin-top:6px;">
              Past academic years from 2000-01 to <?= e($currentYear) ?> are available.
            </div>
          </div>

          <button type="submit" class="btn btn-primary" id="enterBtn" style="width:100%; height:48px; margin-top:20px">
            Activate Academic Year
          </button>

          <button type="button" class="btn btn-ghost" id="step3BackBtn" style="width:100%; height:40px; margin-top:8px">
            ← Back
          </button>
        </div>
      </form>
    </div>
  </div>

</div>

<script>
  const isLoggedInAdmin = <?= (is_logged_in() && current_user()['role'] === 'Admin') ? 'true' : 'false' ?>;
  const roleCards = document.querySelectorAll('.role-card');
  const roleInput = document.getElementById('roleInput');
  const nextBtn = document.getElementById('nextBtn');
  const backBtn = document.getElementById('backBtn');
  const step3BackBtn = document.getElementById('step3BackBtn');
  const step2NextBtn = document.getElementById('step2NextBtn');
  const step2SubmitBtn = document.getElementById('step2SubmitBtn');
  const enterBtn = document.getElementById('enterBtn');
  const step1 = document.getElementById('step1');
  const step2 = document.getElementById('step2');
  const step3 = document.getElementById('step3');
  const step1Bar = document.getElementById('step1-bar');
  const step2Bar = document.getElementById('step2-bar');
  const step3Bar = document.getElementById('step3-bar');
  const roleBadge = document.getElementById('selectedRoleBadge');
  const loginTitle = document.getElementById('loginTitle');
  const loginSubtitle = document.getElementById('loginSubtitle');
  const emailInput = document.getElementById('email');
  const passwordInput = document.getElementById('password');
  const loginForm = document.getElementById('loginForm');
  const logoutForm = document.getElementById('logoutForm');

  function updateRoleMode(role) {
    if (role === 'Admin') {
      step3Bar.style.display = 'block';
      step2NextBtn.style.display = 'block';
      step2SubmitBtn.style.display = 'none';
      if (emailInput) emailInput.required = !isLoggedInAdmin;
      if (passwordInput) passwordInput.required = !isLoggedInAdmin;
    } else {
      step3Bar.style.display = 'none';
      step2NextBtn.style.display = 'none';
      step2SubmitBtn.style.display = 'block';
      if (emailInput) emailInput.required = true;
      if (passwordInput) passwordInput.required = true;
    }
  }

  // Initial role setup if preselected
  if (roleInput.value) {
    updateRoleMode(roleInput.value);
  }

  roleCards.forEach(card => {
    card.addEventListener('click', () => {
      roleCards.forEach(c => c.classList.remove('selected'));
      card.classList.add('selected');
      card.querySelector('input').checked = true;
      roleInput.value = card.dataset.role;
      nextBtn.disabled = false;
      updateRoleMode(card.dataset.role);
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
  });

  step2NextBtn.addEventListener('click', () => {
    if (!emailInput.reportValidity() || !passwordInput.reportValidity()) {
      return;
    }
    step2.style.display = 'none';
    step3.style.display = 'block';
    step3.style.animation = 'none';
    step3.offsetHeight;
    step3.style.animation = 'fadeInUp .3s ease';
    step3Bar.classList.add('active');
    loginTitle.textContent = 'Select Academic Year';
    loginSubtitle.textContent = 'Choose the academic year you want to manage.';
    document.getElementById('academic_year').focus();
  });

  step3BackBtn.addEventListener('click', () => {
    if (isLoggedInAdmin) {
      // If already logged in, back signs out cleanly
      logoutForm.submit();
      return;
    }
    step3.style.display = 'none';
    step2.style.display = 'block';
    step2.style.animation = 'none';
    step2.offsetHeight;
    step2.style.animation = 'fadeInUp .3s ease';
    step3Bar.classList.remove('active');
    loginTitle.textContent = 'Sign in';
    loginSubtitle.textContent = 'Select your role and enter credentials to access the portal.';
  });

  // Handle form submissions
  loginForm.addEventListener('submit', (e) => {
    const activeSubmit = document.activeElement;
    const submitBtn = (activeSubmit && activeSubmit.type === 'submit')
      ? activeSubmit
      : (step3.style.display !== 'none' ? enterBtn : step2SubmitBtn);

    if (submitBtn && submitBtn.disabled) {
      e.preventDefault();
      return;
    }
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = submitBtn === enterBtn ? 'Activating...' : 'Signing In...';
    }
  });

  // Refresh page if restored from browser back-forward cache (bfcache)
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
      window.location.reload();
    }
  });

  // If there's an error and a role was selected, show appropriate step
  <?php if ($error && $selectedRole): ?>
    <?php if ($showStep3): ?>
      step1.style.display = 'none';
      step2.style.display = 'none';
      step3.style.display = 'block';
      step2Bar.classList.add('active');
      step3Bar.classList.add('active');
      step3Bar.style.display = 'block';
      loginTitle.textContent = 'Select Academic Year';
      loginSubtitle.textContent = 'Choose the academic year you want to manage.';
    <?php else: ?>
      step1.style.display = 'none';
      step2.style.display = 'block';
      step2Bar.classList.add('active');
      roleBadge.textContent = '<?= e($selectedRole) ?>';
    <?php endif; ?>
  <?php endif; ?>
</script>
</body>
</html>
