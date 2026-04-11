<?php
// Esto corrige el ruteo en Vercel
$_SERVER['SCRIPT_NAME'] = '/api/index.php';

require __DIR__ . '/../public/index.php';