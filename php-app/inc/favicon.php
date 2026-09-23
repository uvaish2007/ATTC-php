<?php
/**
 * The browser-tab icon, in one place.
 *
 * The college seal is thin navy line art, which all but disappears once a
 * browser scales it to 16px, so the tab icon is the mark reversed out of the
 * brand navy: the silhouette still reads at the smallest size. Every file is
 * generated from assets/img/logo.png (the seal, keyed off its background),
 * and assets/img/favicon.ico carries 16/32/48 for browsers that ask for it.
 *
 * Included from inside <head>. Expects inc/helpers.php for url().
 */
?>
  <link rel="icon" href="<?= e(url('assets/img/favicon.ico')) ?>" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= e(url('assets/img/favicon-32.png')) ?>">
  <link rel="icon" type="image/png" sizes="16x16" href="<?= e(url('assets/img/favicon-16.png')) ?>">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= e(url('assets/img/apple-touch-icon.png')) ?>">
