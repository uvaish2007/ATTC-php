<?php
// The server's document root is the project folder, but the app lives in
// php-app/ (BASE_URL=/php-app). Send a bare http://localhost:8000/ there.
header('Location: /php-app/index.php', true, 302);
exit;
