<?php

include 'helper/vendor/autoload.php';

$j = new JuggleCode();
$j->masterfile = 'src/FCGI.php';
$j->outfile = 'bin/FCGI.php';
$j->mergeScripts = true;
$j->run();
