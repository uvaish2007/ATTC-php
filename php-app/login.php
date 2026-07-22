<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/icons.php';   // the role cards draw icons

auth_boot();

if (is_logged_in()) {
    redirect('/dashboard.php');
}

$error = '';
$email = '';
$selectedRole = '';

// Fetch available roles for the role selector
$roles = [
    'Admin'       => ['icon' => 'shield',     'desc' => 'Full system access, manage users & departments'],
    'Director'    => ['icon' => 'eye',         'desc' => 'Institution-wide overview & reports'],
    'HoD'         => ['icon' => 'graduation',  'desc' => 'Department head, approve records'],
    'Coordinator' => ['icon' => 'target',      'desc' => 'Upload data & generate reports'],
    'Faculty'     => ['icon' => 'user',        'desc' => 'Submit academic records & track status'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email    = trim((string) input('email'));
    $password = (string) input('password');
    $selectedRole = trim((string) input('role'));

    if ($selectedRole === '') {
        $error = 'Please select your role before signing in.';
    } else {
        $user = attempt_login($email, $password);

        if ($user) {
            // Validate the selected role matches the user's actual role
            if ($user['role'] !== $selectedRole) {
                // Log the user out since they selected the wrong role
                logout();
                auth_boot();
                $error = 'The selected role does not match your account. You are registered as "' . htmlspecialchars($user['role']) . '".';
            } else {
                redirect('/dashboard.php');
            }
        } else {
            $error = 'Invalid email or password.';
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
      <h1>Sign in</h1>
      <p class="lead">Select your role and enter credentials to access the portal.</p>

      <!-- Step indicators -->
      <div class="login-steps" style="margin-top:16px">
        <div class="login-step" id="step1-bar"></div>
        <div class="login-step" id="step2-bar"></div>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom:16px"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" id="loginForm">
        <?= csrf_field() ?>
        <input type="hidden" name="role" id="roleInput" value="<?= e($selectedRole) ?>">

        <!-- Step 1: Role selector -->
        <div id="step1" class="step-content">
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
          <button type="button" class="btn btn-primary" id="nextBtn" style="width:100%; height:44px; margin-top:12px" disabled>
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
                   value="<?= e($email) ?>" required>
          </div>

          <div class="field">
            <label for="password">Password</label>
            <input class="input" type="password" id="password" name="password" placeholder="••••••••" required>
          </div>

          <button type="submit" class="btn btn-primary" style="width:100%; height:48px; margin-top:8px">Sign In</button>
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
  const step1 = document.getElementById('step1');
  const step2 = document.getElementById('step2');
  const step1Bar = document.getElementById('step1-bar');
  const step2Bar = document.getElementById('step2-bar');
  const roleBadge = document.getElementById('selectedRoleBadge');

  // Init step bars
  step1Bar.classList.add('active');

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
    document.getElementById('email').focus();
  });

  backBtn.addEventListener('click', () => {
    step2.style.display = 'none';
    step1.style.display = 'block';
    step1.style.animation = 'none';
    step1.offsetHeight;
    step1.style.animation = 'fadeInUp .3s ease';
    step2Bar.classList.remove('active');
  });

  // If there's an error and a role was selected, show step 2
  <?php if ($error && $selectedRole): ?>
    step1.style.display = 'none';
    step2.style.display = 'block';
    step2Bar.classList.add('active');
    roleBadge.textContent = '<?= e($selectedRole) ?>';
  <?php endif; ?>
</script>
</body>
</html>
