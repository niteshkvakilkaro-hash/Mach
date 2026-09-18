<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
redirect(auth_user() ? auth_home() : url('admin/login'));
