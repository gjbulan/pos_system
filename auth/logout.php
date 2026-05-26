<?php
require_once __DIR__ . '/../config/app.php';

session_start();
session_destroy();
redirect_to('auth/login.php');
