<?php
/**
 * Upload Data entry flow — the two selection screens Faculty and Coordinator
 * users see before upload.php's form: Academic Year, then Data Type.
 *
 * Included by upload.php (between the header and footer) with $user,
 * $uploadFlowStep ('year' | 'data_type') and $flowState (upload_flow_state()).
 * This file only renders; every choice is validated server-side in upload.php
 * through models/UploadFlow.php.
 */

$ufYears     = upload_flow_years();
$ufDataTypes = upload_flow_data_types();
$ufTypes     = record_types();
$ufActive    = active_academic_year();
$ufIsYear    = $uploadFlowStep === 'year';
$ufBackUrl   = $ufIsYear ? url(($flowState['return_to'] ?? '') ?: 'dashboard.php') : url('upload.php?step=year');
?>
<style>
  .uf { max-width:820px; margin:0 auto 40px; }
  .uf-header { text-align:center; margin-bottom:28px; }
  .uf-heading { font-size:26px; font-weight:700; color:var(--ink,#131D3B); letter-spacing:-0.01em; margin:0 0 8px; text-transform:uppercase; }
  .uf-subtitle { font-size:15px; color:var(--ink-muted,#64748b); margin:0; line-height:1.5; font-weight:500; }

  .uf-card { max-width:540px; margin:0 auto; padding:32px 28px; border-radius:14px; border:1px solid var(--hairline,#E4E9F2); box-shadow:0 4px 16px rgba(19,29,59,0.04); background:#fff; }
  .uf-actions { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:28px; }
  .uf-actions.center { justify-content:center; }

  .uf-year-pill { display:inline-flex; align-items:center; gap:8px; padding:6px 16px; border-radius:999px;
    background:var(--orange-50,#FFF3EC); color:var(--brand,#FF4F01); border:1px solid var(--orange-200,#FFC3A8);
    font-size:13px; font-weight:600; margin-bottom:12px; }

  .uf-choices { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:24px; align-items:stretch; margin-bottom:28px; }
  .uf-choice { display:flex; flex-direction:column; align-items:center; text-align:center; padding:36px 24px 28px;
    border-radius:16px; border:2px solid var(--hairline,#E4E9F2); background:#fff; box-shadow:0 4px 16px rgba(19,29,59,0.04);
    transition:transform .15s ease, border-color .15s ease, box-shadow .15s ease; }
  .uf-choice:hover { border-color:var(--brand,#FF4F01); transform:translateY(-2px); box-shadow:0 8px 24px rgba(255,79,1,0.08); }
  .uf-choice.is-current { border-color:var(--brand,#FF4F01); }

  .uf-choice-ic { width:64px; height:64px; border-radius:16px; display:grid; place-items:center; margin-bottom:18px;
    background:var(--navy-50,#F4F6FA); color:var(--ink,#131D3B); }
  .uf-choice-ic.brand { background:var(--orange-50,#FFF3EC); color:var(--brand,#FF4F01); }

  .uf-choice-title { font-size:18px; font-weight:700; color:var(--ink,#131D3B); letter-spacing:0.04em; margin:0 0 8px; text-transform:uppercase; }
  .uf-choice-sub { font-size:14px; color:var(--ink-muted,#64748b); margin:0 0 28px; line-height:1.5; }

  .uf-choice-foot { margin-top:auto; width:100%; }
  .uf-choice-foot .btn { width:100%; border-radius:8px; padding:10px 18px; font-weight:600; font-size:13px; letter-spacing:0.04em; }

  @media (max-width:640px) {
    .uf-card { padding:24px 18px; }
    .uf-choices { grid-template-columns:1fr; gap:16px; }
    .uf-choice { padding:28px 20px 22px; }
  }
</style>

<div class="uf">
  <?php if ($ufIsYear): ?>
    <?php
      $ufSelected = $flowState['year'] ?: $ufActive;
      $ufLocked   = academic_year_is_locked($ufActive);
      $ufEmBlock  = em_submission_block_reason($user['role'], $ufActive);
    ?>
    <div class="uf-header">
      <h1 class="uf-heading">UPLOAD DATA</h1>
      <p class="uf-subtitle">Select an Academic Year to continue</p>
    </div>

    <section class="card uf-card">
      <?php if ($flowState['stale_year'] !== null): ?>
        <div class="alert alert-warning" style="margin:0 0 20px;">
          Academic year <?= e($flowState['stale_year']) ?> is no longer open for uploads. Please select an open academic year.
        </div>
      <?php endif; ?>

      <form method="post" action="<?= e(url('upload.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="upload_flow_step" value="year">

        <div class="field" style="margin-bottom:24px;">
          <label for="ufYear" style="display:block; font-size:14px; font-weight:600; color:var(--ink,#131D3B); margin-bottom:8px;">
            Academic Year <span class="req" style="color:var(--brand,#FF4F01);">*</span>
          </label>
          <select class="select" id="ufYear" name="academic_year" required style="width:100%; height:44px; font-size:14px; border-radius:8px;">
            <option value="">Select Academic Year</option>
            <?php foreach ($ufYears as $y): ?>
              <?php
                $isAct = ($y === $ufActive);
                $isLck = academic_year_is_locked($y);
                $optText = $y . ($isAct ? ' (Active)' : '') . ($isLck ? ' — Locked' : '');
              ?>
              <option value="<?= e($y) ?>" <?= $ufSelected === $y ? 'selected' : '' ?>>
                <?= e($optText) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($ufLocked): ?>
          <div class="alert alert-warning" style="margin-bottom:20px;">
            Academic year <?= e($ufActive) ?> cycle is currently locked by the Administrator. New record submissions are frozen.
          </div>
        <?php elseif ($ufEmBlock): ?>
          <div class="alert alert-warning" style="margin-bottom:20px;"><?= e($ufEmBlock) ?></div>
        <?php endif; ?>

        <div class="uf-actions">
          <a class="btn btn-outline btn-sm" href="<?= e($ufBackUrl) ?>" style="border-radius:8px; padding:9px 18px;">
            <?= icon('arrow-left', 15) ?> Back
          </a>
          <button type="submit" class="btn btn-primary" style="border-radius:8px; padding:10px 26px; font-weight:600;">
            Continue <?= icon('chevron', 14) ?>
          </button>
        </div>
      </form>
    </section>

  <?php else: ?>
    <div class="uf-header">
      <h1 class="uf-heading">UPLOAD DATA</h1>
      
      <div style="margin-bottom:12px;">
        <span class="uf-year-pill">
          <?= icon('calendar', 14) ?> Academic Year: <?= e($flowState['year']) ?>
        </span>
      </div>

      <p class="uf-subtitle" style="font-weight:600; color:var(--ink,#131D3B);">Select Data Type</p>
    </div>

    <form method="post" action="<?= e(url('upload.php')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="upload_flow_step" value="data_type">

      <div class="uf-choices">
        <!-- Faculty Data Card -->
        <section class="card uf-choice">
          <div class="uf-choice-ic brand">
            <?= icon('file-text', 28) ?>
          </div>
          <h2 class="uf-choice-title">FACULTY DATA</h2>
          <p class="uf-choice-sub">Faculty academic records</p>
          <div class="uf-choice-foot">
            <button type="submit" name="data_type" value="faculty" class="btn btn-primary">
              SELECT
            </button>
          </div>
        </section>

        <!-- Student Data Card -->
        <section class="card uf-choice">
          <div class="uf-choice-ic">
            <?= icon('users', 28) ?>
          </div>
          <h2 class="uf-choice-title">STUDENT DATA</h2>
          <p class="uf-choice-sub">Student academic records</p>
          <div class="uf-choice-foot">
            <button type="submit" name="data_type" value="student" class="btn btn-primary">
              SELECT
            </button>
          </div>
        </section>
      </div>

      <div class="uf-actions center">
        <a class="btn btn-outline" href="<?= e($ufBackUrl) ?>" style="border-radius:8px; padding:9px 26px; font-weight:600;">
          <?= icon('arrow-left', 15) ?> BACK
        </a>
      </div>
    </form>
  <?php endif; ?>
</div>
