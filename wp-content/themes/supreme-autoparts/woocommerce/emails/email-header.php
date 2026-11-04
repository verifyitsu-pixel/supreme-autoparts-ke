<?php
/** Private companion shell header. Matched footer owns buffer. */
defined('ABSPATH') || exit;
$GLOBALS['sa_private_woo_shell_stack'][]=['level'=>ob_get_level(),'heading'=>(string)($email_heading??''),'email'=>$email??null];
ob_start();
