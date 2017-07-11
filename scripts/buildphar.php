<?php

// php -d phar.readonly=false buildphar.php

$in = __DIR__ . '/../src';
$out = __DIR__ . '/../bin/tex2wp';

@unlink($out);

$tmp = "$out.phar";

$phar = new Phar($tmp, 0, basename($out));
$phar->buildFromDirectory($in, '#/[^/_][^/]*#');
$stub = $phar->createDefaultStub('convert.php');
$stub = "#!/usr/bin/php \n".$stub;
$phar->setStub($stub);

$phar->stopBuffering();

exec('chmod +x '.escapeshellarg($tmp));
rename($tmp, $out);

