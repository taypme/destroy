<?php

use Symfony\Component\Dotenv\Dotenv;

$envFile = dirname(__DIR__).'/.env';

if (is_file($envFile)) {
    (new Dotenv())->usePutenv()->loadEnv($envFile);
}
