<?php
/**
 * Railway / Docker health check — no DB required.
 */
header('Content-Type: text/plain; charset=utf-8');
http_response_code(200);
echo "ok\n";
