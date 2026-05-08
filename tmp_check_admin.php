<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$a = App\Models\Admin::find(1);
var_dump(Hash::check('TestPwd123!', $a->password));
echo $a->password.PHP_EOL;
echo 'guard model: '.get_class($a).PHP_EOL;
echo 'fillable: '.json_encode($a->getFillable()).PHP_EOL;
