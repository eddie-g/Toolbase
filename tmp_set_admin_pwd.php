<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$a = App\Models\Admin::find(1);
$a->password = 'TestPwd123!';
$a->save();
var_dump(Hash::check('TestPwd123!', $a->fresh()->password));
