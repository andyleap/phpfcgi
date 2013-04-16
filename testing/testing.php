<?php
include_once '../src/FCGI.php';

$server = new FCGI_Server();
$num = 0;
while ($req = $server->Accept()) {
	$req->Session_Start();
	?><html><head></head><body><pre><?
				print_r($req->SERVER);
				print_r($req->COOKIE);
				var_dump($req->POST);
				echo $num++;
				echo "\n";
				$req->SESSION['test']++;
				print_r($req->SESSION);
				?>
</pre>
<form method="post" enctype="multipart/form-data">
	<input type="text" name="test" value="blah"/>
	<input type="text" name="another" value="blue"/>
	<input type="submit"/>
</form>
</body></html><?
}