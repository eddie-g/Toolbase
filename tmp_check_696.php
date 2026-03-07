<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$doc = DB::table('documents')->where('id', 696)->first();
echo "PATH: " . $doc->path . "\n";
