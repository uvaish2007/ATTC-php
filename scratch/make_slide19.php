<?php
$h = file_get_contents(__DIR__ . '/executive_meeting_demo.html');
$h = str_replace('renderSlide(0, false)', 'renderSlide(18, false)', $h);
file_put_contents(__DIR__ . '/slide19_demo.html', $h);
echo "Wrote slide19_demo.html\n";
