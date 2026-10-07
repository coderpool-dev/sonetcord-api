<?php

header('Content-Type: text/html');
// No Content-Length: the limit must work while receiving an unknown-sized body.
for ($i = 0; $i < 128; $i++) {
    echo str_repeat('x', 8192);
    flush();
}
